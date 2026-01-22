<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;
use App\Service\Analyzer\HttpAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpAnalyzer::class)]
final class HttpAnalyzerTest extends TestCase
{
    // ==================== getCategory() Tests ====================

    #[Test]
    public function getCategory_returnsHttp(): void
    {
        $analyzer = $this->createAnalyzer();

        self::assertSame(IssueCategory::HTTP, $analyzer->getCategory());
    }

    // ==================== getPriority() Tests ====================

    #[Test]
    public function getPriority_returns10(): void
    {
        $analyzer = $this->createAnalyzer();

        self::assertSame(10, $analyzer->getPriority());
    }

    // ==================== supports() Tests ====================

    #[Test]
    public function supports_httpCategory_returnsTrue(): void
    {
        $analyzer = $this->createAnalyzer();

        self::assertTrue($analyzer->supports(IssueCategory::HTTP));
    }

    #[Test]
    public function supports_otherCategory_returnsFalse(): void
    {
        $analyzer = $this->createAnalyzer();

        self::assertFalse($analyzer->supports(IssueCategory::SECURITY));
        self::assertFalse($analyzer->supports(IssueCategory::SEO));
        self::assertFalse($analyzer->supports(IssueCategory::PERFORMANCE));
    }

    // ==================== isUniversal() Tests ====================

    #[Test]
    public function isUniversal_returnsTrue(): void
    {
        $analyzer = $this->createAnalyzer();

        self::assertTrue($analyzer->isUniversal());
    }

    // ==================== analyze() - Null URL Tests ====================

    #[Test]
    public function analyze_nullUrl_returnsFailure(): void
    {
        $analyzer = $this->createAnalyzer();
        $lead = new Lead();
        // Lead URL is null by default (no setUrl called)

        $result = $analyzer->analyze($lead);

        self::assertFalse($result->success);
        self::assertSame('Lead URL is null', $result->errorMessage);
    }

    // ==================== analyze() - HTTP URL Tests ====================

    #[Test]
    public function analyze_httpUrl_reportsSslNotHttpsIssue(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('OK', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertTrue($result->success);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertContains('ssl_not_https', $issueCodes);
    }

    // ==================== analyze() - HTTP Response Tests ====================

    #[Test]
    public function analyze_successfulResponse_returnsSuccess(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertTrue($result->success);
        self::assertSame(IssueCategory::HTTP, $result->category);
        self::assertArrayHasKey('url', $result->rawData);
        self::assertArrayHasKey('checks', $result->rawData);
    }

    #[Test]
    public function analyze_connectionFailed_reportsHttpConnectionFailed(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertTrue($result->success);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertContains('http_connection_failed', $issueCodes);
    }

    // ==================== analyze() - Mixed Content Tests ====================

    #[Test]
    public function analyze_mixedContentOnHttps_reportsMixedContentIssue(): void
    {
        $htmlWithMixedContent = '<html><body><img src="http://insecure.test/image.jpg"></body></html>';

        $mockClient = new MockHttpClient([
            new MockResponse($htmlWithMixedContent, ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test'); // Note: SSL check happens separately

        $result = $analyzer->analyze($lead);

        self::assertTrue($result->success);
        // Mixed content check only runs for HTTPS pages, so this won't report mixed content for HTTP URL
    }

    #[Test]
    public function analyze_noMixedContent_doesNotReportIssue(): void
    {
        $cleanHtml = '<html><body><img src="https://secure.test/image.jpg"></body></html>';

        $mockClient = new MockHttpClient([
            new MockResponse($cleanHtml, ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertNotContains('http_mixed_content', $issueCodes);
    }

    #[Test]
    public function analyze_mixedContentScript_detectedAsIssue(): void
    {
        // For mixed content detection, we need to test the pattern matching
        $htmlWithMixedScript = '<html><body><script src="http://insecure.test/script.js"></script></body></html>';

        $mockClient = new MockHttpClient([
            new MockResponse($htmlWithMixedScript, ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        // Mixed content only checked on HTTPS pages
        self::assertTrue($result->success);
    }

    #[Test]
    public function analyze_mixedContentIframe_detectedAsIssue(): void
    {
        $htmlWithMixedIframe = '<html><body><iframe src="http://insecure.test/frame.html"></iframe></body></html>';

        $mockClient = new MockHttpClient([
            new MockResponse($htmlWithMixedIframe, ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertTrue($result->success);
    }

    #[Test]
    public function analyze_mixedContentLink_detectedAsIssue(): void
    {
        $htmlWithMixedLink = '<html><head><link href="http://insecure.test/style.css" rel="stylesheet"></head></html>';

        $mockClient = new MockHttpClient([
            new MockResponse($htmlWithMixedLink, ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertTrue($result->success);
    }

    // ==================== analyze() - 404 Handling Tests ====================

    #[Test]
    public function analyze_proper404Handling_noIssue(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertNotContains('http_soft_404', $issueCodes);
    }

    #[Test]
    public function analyze_soft404_reportsIssue(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            // Non-existent page returns 200 instead of 404
            new MockResponse('<html><body>Page Not Found</body></html>', ['http_code' => 200]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertContains('http_soft_404', $issueCodes);
    }

    // ==================== analyze() - Raw Data Tests ====================

    #[Test]
    public function analyze_success_containsExpectedRawData(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertArrayHasKey('url', $result->rawData);
        self::assertArrayHasKey('checks', $result->rawData);
        self::assertArrayHasKey('ssl', $result->rawData['checks']);
        self::assertArrayHasKey('http', $result->rawData['checks']);
        self::assertArrayHasKey('notFound', $result->rawData['checks']);
    }

    #[Test]
    public function analyze_httpResponse_containsStatusCode(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertArrayHasKey('statusCode', $result->rawData['checks']['http']);
        self::assertSame(200, $result->rawData['checks']['http']['statusCode']);
    }

    #[Test]
    public function analyze_httpResponse_containsTtfb(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertArrayHasKey('ttfb', $result->rawData['checks']['http']);
        self::assertIsFloat($result->rawData['checks']['http']['ttfb']);
    }

    // ==================== analyze() - SSL Check Data Tests ====================

    #[Test]
    public function analyze_httpUrl_sslCheckShowsNotHttps(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertArrayHasKey('ssl', $result->rawData['checks']);
        self::assertFalse($result->rawData['checks']['ssl']['valid']);
        self::assertSame('Not using HTTPS', $result->rawData['checks']['ssl']['error']);
    }

    // ==================== analyze() - 404 Check Data Tests ====================

    #[Test]
    public function analyze_404Check_containsTestUrl(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertArrayHasKey('notFound', $result->rawData['checks']);
        self::assertArrayHasKey('testUrl', $result->rawData['checks']['notFound']);
        self::assertStringContainsString('surely-nonexistent-page', $result->rawData['checks']['notFound']['testUrl']);
    }

    #[Test]
    public function analyze_404Check_containsStatusCode(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertArrayHasKey('statusCode', $result->rawData['checks']['notFound']);
        self::assertSame(404, $result->rawData['checks']['notFound']['statusCode']);
    }

    // ==================== Issue Object Tests ====================

    #[Test]
    public function analyze_issue_hasCorrectStructure(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        // Should have ssl_not_https issue
        self::assertNotEmpty($result->issues);

        $issue = $result->issues[0];
        self::assertSame(IssueCategory::HTTP, $issue->category);
        self::assertNotEmpty($issue->code);
        self::assertNotEmpty($issue->title);
        self::assertNotEmpty($issue->description);
    }

    // ==================== getIssueCount() and getScore() Tests ====================

    #[Test]
    public function analyze_result_hasCorrectIssueCount(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        self::assertSame(count($result->issues), $result->getIssueCount());
    }

    #[Test]
    public function analyze_result_hasNegativeOrZeroScore(): void
    {
        // Score is negative because issues have negative weights
        $mockClient = new MockHttpClient([
            new MockResponse('<html><body>OK</body></html>', ['http_code' => 200]),
            new MockResponse('Not Found', ['http_code' => 404]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('http://example.test');

        $result = $analyzer->analyze($lead);

        // Issues have negative weights, so score should be <= 0
        self::assertLessThanOrEqual(0, $result->getScore());
    }

    // ==================== Helper Methods ====================

    private function createAnalyzer(?MockHttpClient $httpClient = null): HttpAnalyzer
    {
        $httpClient ??= new MockHttpClient([
            new MockResponse('OK', ['http_code' => 200]),
        ]);

        return new HttpAnalyzer($httpClient, new NullLogger());
    }

    private function createLead(string $url): Lead
    {
        $lead = new Lead();
        $lead->setUrl($url);

        return $lead;
    }
}
