<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;
use App\Service\Analyzer\SecurityAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(SecurityAnalyzer::class)]
final class SecurityAnalyzerTest extends TestCase
{
    // ==================== getCategory() Tests ====================

    #[Test]
    public function getCategory_returnsSecurity(): void
    {
        $analyzer = $this->createAnalyzer();

        self::assertSame(IssueCategory::SECURITY, $analyzer->getCategory());
    }

    // ==================== getPriority() Tests ====================

    #[Test]
    public function getPriority_returns20(): void
    {
        $analyzer = $this->createAnalyzer();

        self::assertSame(20, $analyzer->getPriority());
    }

    // ==================== supports() Tests ====================

    #[Test]
    public function supports_securityCategory_returnsTrue(): void
    {
        $analyzer = $this->createAnalyzer();

        self::assertTrue($analyzer->supports(IssueCategory::SECURITY));
    }

    #[Test]
    public function supports_otherCategory_returnsFalse(): void
    {
        $analyzer = $this->createAnalyzer();

        self::assertFalse($analyzer->supports(IssueCategory::HTTP));
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

    // ==================== analyze() - Connection Failed Tests ====================

    #[Test]
    public function analyze_connectionFailed_returnsFailure(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        self::assertFalse($result->success);
        self::assertStringContainsString('Failed to fetch URL', $result->errorMessage);
    }

    // ==================== analyze() - Missing Security Headers Tests ====================

    #[Test]
    public function analyze_noSecurityHeaders_reportsAllMissingHeaders(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        self::assertTrue($result->success);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertContains('security_missing_content_security_policy', $issueCodes);
        self::assertContains('security_missing_x_frame_options', $issueCodes);
        self::assertContains('security_missing_x_content_type_options', $issueCodes);
        self::assertContains('security_missing_strict_transport_security', $issueCodes);
        self::assertContains('security_missing_referrer_policy', $issueCodes);
        self::assertContains('security_missing_permissions_policy', $issueCodes);
    }

    #[Test]
    #[DataProvider('securityHeadersProvider')]
    public function analyze_missingSpecificHeader_reportsIssue(string $header, string $expectedIssueCode): void
    {
        // All headers except the one being tested
        $allHeaders = [
            'content-security-policy' => "default-src 'self'",
            'x-frame-options' => 'DENY',
            'x-content-type-options' => 'nosniff',
            'strict-transport-security' => 'max-age=31536000',
            'referrer-policy' => 'strict-origin-when-cross-origin',
            'permissions-policy' => 'geolocation=()',
        ];
        unset($allHeaders[$header]);

        $responseHeaders = [];
        foreach ($allHeaders as $name => $value) {
            $responseHeaders[$name] = $value;
        }

        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => $responseHeaders,
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertContains($expectedIssueCode, $issueCodes);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function securityHeadersProvider(): iterable
    {
        yield 'Content-Security-Policy' => ['content-security-policy', 'security_missing_content_security_policy'];
        yield 'X-Frame-Options' => ['x-frame-options', 'security_missing_x_frame_options'];
        yield 'X-Content-Type-Options' => ['x-content-type-options', 'security_missing_x_content_type_options'];
        yield 'Strict-Transport-Security' => ['strict-transport-security', 'security_missing_strict_transport_security'];
        yield 'Referrer-Policy' => ['referrer-policy', 'security_missing_referrer_policy'];
        yield 'Permissions-Policy' => ['permissions-policy', 'security_missing_permissions_policy'];
    }

    #[Test]
    public function analyze_allSecurityHeaders_noMissingHeaderIssues(): void
    {
        $responseHeaders = [
            'content-security-policy' => "default-src 'self'",
            'x-frame-options' => 'DENY',
            'x-content-type-options' => 'nosniff',
            'strict-transport-security' => 'max-age=31536000',
            'referrer-policy' => 'strict-origin-when-cross-origin',
            'permissions-policy' => 'geolocation=()',
        ];

        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => $responseHeaders,
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertNotContains('security_missing_content_security_policy', $issueCodes);
        self::assertNotContains('security_missing_x_frame_options', $issueCodes);
        self::assertNotContains('security_missing_x_content_type_options', $issueCodes);
        self::assertNotContains('security_missing_strict_transport_security', $issueCodes);
        self::assertNotContains('security_missing_referrer_policy', $issueCodes);
        self::assertNotContains('security_missing_permissions_policy', $issueCodes);
    }

    // ==================== analyze() - Server Version Disclosure Tests ====================

    #[Test]
    public function analyze_serverVersionDisclosure_reportsIssue(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [
                    'server' => 'Apache/2.4.41',
                ],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertContains('security_server_version_disclosure', $issueCodes);
    }

    #[Test]
    public function analyze_serverWithoutVersion_noVersionDisclosureIssue(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [
                    'server' => 'nginx',
                ],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertNotContains('security_server_version_disclosure', $issueCodes);
    }

    #[Test]
    #[DataProvider('serverVersionProvider')]
    public function analyze_serverVersionPatterns_detectsVersionDisclosure(string $serverHeader, bool $shouldReport): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [
                    'server' => $serverHeader,
                ],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);

        if ($shouldReport) {
            self::assertContains('security_server_version_disclosure', $issueCodes);
        } else {
            self::assertNotContains('security_server_version_disclosure', $issueCodes);
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function serverVersionProvider(): iterable
    {
        yield 'Apache with version' => ['Apache/2.4.41', true];
        yield 'nginx with version' => ['nginx/1.18.0', true];
        yield 'Microsoft-IIS with version' => ['Microsoft-IIS/10.0', true];
        yield 'Apache without version' => ['Apache', false];
        yield 'nginx without version' => ['nginx', false];
        yield 'Cloudflare' => ['cloudflare', false];
        yield 'Empty' => ['', false];
    }

    // ==================== analyze() - X-Powered-By Tests ====================

    #[Test]
    public function analyze_xPoweredByPresent_reportsIssue(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [
                    'x-powered-by' => 'PHP/8.2.0',
                ],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertContains('security_x_powered_by', $issueCodes);
    }

    #[Test]
    public function analyze_noXPoweredBy_noIssue(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertNotContains('security_x_powered_by', $issueCodes);
    }

    #[Test]
    #[DataProvider('xPoweredByProvider')]
    public function analyze_xPoweredByVariants_reported(string $poweredByHeader): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [
                    'x-powered-by' => $poweredByHeader,
                ],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertContains('security_x_powered_by', $issueCodes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function xPoweredByProvider(): iterable
    {
        yield 'PHP' => ['PHP/8.2.0'];
        yield 'ASP.NET' => ['ASP.NET'];
        yield 'Express' => ['Express'];
        yield 'Next.js' => ['Next.js'];
        yield 'Custom' => ['MyFramework/1.0'];
    }

    // ==================== analyze() - Insecure Form Tests ====================

    #[Test]
    public function analyze_insecureFormAction_reportsIssue(): void
    {
        $htmlWithInsecureForm = '<html><body><form action="http://insecure.test/submit" method="post"></form></body></html>';

        $mockClient = new MockHttpClient([
            new MockResponse($htmlWithInsecureForm, [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertContains('security_insecure_form', $issueCodes);
    }

    #[Test]
    public function analyze_secureFormAction_noIssue(): void
    {
        $htmlWithSecureForm = '<html><body><form action="https://secure.test/submit" method="post"></form></body></html>';

        $mockClient = new MockHttpClient([
            new MockResponse($htmlWithSecureForm, [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertNotContains('security_insecure_form', $issueCodes);
    }

    #[Test]
    public function analyze_formWithRelativeAction_noIssue(): void
    {
        $htmlWithRelativeForm = '<html><body><form action="/submit" method="post"></form></body></html>';

        $mockClient = new MockHttpClient([
            new MockResponse($htmlWithRelativeForm, [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertNotContains('security_insecure_form', $issueCodes);
    }

    #[Test]
    public function analyze_formWithNoAction_noIssue(): void
    {
        $htmlWithNoActionForm = '<html><body><form method="post"><input type="text"></form></body></html>';

        $mockClient = new MockHttpClient([
            new MockResponse($htmlWithNoActionForm, [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $issueCodes = array_map(fn ($issue) => $issue->code, $result->issues);
        self::assertNotContains('security_insecure_form', $issueCodes);
    }

    // ==================== analyze() - Raw Data Tests ====================

    #[Test]
    public function analyze_success_containsExpectedRawData(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [
                    'content-security-policy' => "default-src 'self'",
                ],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        self::assertArrayHasKey('url', $result->rawData);
        self::assertArrayHasKey('headers', $result->rawData);
        self::assertArrayHasKey('missingHeaders', $result->rawData);
        self::assertArrayHasKey('presentHeaders', $result->rawData);
        self::assertArrayHasKey('additionalChecks', $result->rawData);
    }

    #[Test]
    public function analyze_missingHeaders_listedInRawData(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [
                    'content-security-policy' => "default-src 'self'",
                ],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        self::assertContains('x-frame-options', $result->rawData['missingHeaders']);
        self::assertContains('strict-transport-security', $result->rawData['missingHeaders']);
    }

    #[Test]
    public function analyze_presentHeaders_listedInRawData(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [
                    'content-security-policy' => "default-src 'self'",
                    'x-frame-options' => 'DENY',
                ],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        self::assertArrayHasKey('content-security-policy', $result->rawData['presentHeaders']);
        self::assertArrayHasKey('x-frame-options', $result->rawData['presentHeaders']);
    }

    // ==================== Issue Evidence Tests ====================

    #[Test]
    public function analyze_missingHeader_issueContainsEvidence(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $cspIssue = null;
        foreach ($result->issues as $issue) {
            if ($issue->code === 'security_missing_content_security_policy') {
                $cspIssue = $issue;
                break;
            }
        }

        self::assertNotNull($cspIssue);
        self::assertNotNull($cspIssue->evidence);
        self::assertStringContainsString('content-security-policy', $cspIssue->evidence);
    }

    #[Test]
    public function analyze_xPoweredBy_issueContainsEvidence(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [
                    'x-powered-by' => 'PHP/8.2.0',
                ],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        $xPoweredByIssue = null;
        foreach ($result->issues as $issue) {
            if ($issue->code === 'security_x_powered_by') {
                $xPoweredByIssue = $issue;
                break;
            }
        }

        self::assertNotNull($xPoweredByIssue);
        self::assertNotNull($xPoweredByIssue->evidence);
        self::assertStringContainsString('PHP/8.2.0', $xPoweredByIssue->evidence);
    }

    // ==================== Issue Structure Tests ====================

    #[Test]
    public function analyze_issues_haveCorrectCategory(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        foreach ($result->issues as $issue) {
            self::assertSame(IssueCategory::SECURITY, $issue->category);
        }
    }

    // ==================== getIssueCount() and getScore() Tests ====================

    #[Test]
    public function analyze_result_hasCorrectIssueCount(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        self::assertSame(count($result->issues), $result->getIssueCount());
    }

    #[Test]
    public function analyze_result_hasNegativeScore(): void
    {
        // Score is negative because issues have negative weights
        $mockClient = new MockHttpClient([
            new MockResponse('<html></html>', [
                'http_code' => 200,
                'response_headers' => [],
            ]),
        ]);

        $analyzer = $this->createAnalyzer($mockClient);
        $lead = $this->createLead('https://example.test');

        $result = $analyzer->analyze($lead);

        // With missing headers, issues are found and score should be negative
        self::assertLessThan(0, $result->getScore());
    }

    // ==================== Helper Methods ====================

    private function createAnalyzer(?MockHttpClient $httpClient = null): SecurityAnalyzer
    {
        $httpClient ??= new MockHttpClient([
            new MockResponse('OK', ['http_code' => 200]),
        ]);

        return new SecurityAnalyzer($httpClient, new NullLogger());
    }

    private function createLead(string $url): Lead
    {
        $lead = new Lead();
        $lead->setUrl($url);

        return $lead;
    }
}
