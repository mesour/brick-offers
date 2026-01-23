<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Extractor;

use App\Service\Extractor\CompanyNameExtractor;
use App\Service\Extractor\EmailExtractor;
use App\Service\Extractor\IcoExtractor;
use App\Service\Extractor\PageDataExtractor;
use App\Service\Extractor\PhoneExtractor;
use App\Service\Extractor\SocialMediaExtractor;
use App\Service\Extractor\TechnologyDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(PageDataExtractor::class)]
final class PageDataExtractorTest extends TestCase
{
    /** @var array<string> */
    private array $requestedUrls = [];

    protected function setUp(): void
    {
        $this->requestedUrls = [];
    }

    private function createExtractor(?HttpClientInterface $httpClient = null): PageDataExtractor
    {
        return new PageDataExtractor(
            emailExtractor: new EmailExtractor(),
            phoneExtractor: new PhoneExtractor(),
            icoExtractor: new IcoExtractor(),
            technologyDetector: new TechnologyDetector(),
            socialMediaExtractor: new SocialMediaExtractor(),
            companyNameExtractor: new CompanyNameExtractor(),
            httpClient: $httpClient,
            logger: new NullLogger(),
        );
    }

    /**
     * Create a mock HTTP client that tracks requested URLs and returns appropriate responses.
     *
     * @param array<string, string> $responses URL pattern => HTML content mapping
     */
    private function createMockHttpClient(array $responses, int $defaultCode = 200): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($responses, $defaultCode): MockResponse {
            $this->requestedUrls[] = $url;

            // Normalize URL for matching (remove trailing slash for comparison)
            $normalizedUrl = rtrim($url, '/');

            // Find matching response by checking if URL contains the pattern
            foreach ($responses as $pattern => $html) {
                $normalizedPattern = rtrim($pattern, '/');

                // Exact match or pattern is contained in URL
                if ($normalizedUrl === $normalizedPattern || str_ends_with($normalizedUrl, $normalizedPattern)) {
                    return new MockResponse($html, ['http_code' => 200]);
                }
            }

            return new MockResponse('Not found', ['http_code' => $defaultCode]);
        });
    }

    /**
     * Check if any requested URL contains the given pattern.
     */
    private function hasRequestedUrlContaining(string $pattern): bool
    {
        foreach ($this->requestedUrls as $url) {
            if (str_contains($url, $pattern)) {
                return true;
            }
        }

        return false;
    }

    // ==================== Contact Page Discovery Tests ====================

    #[Test]
    public function extractWithContactPages_emailOnMainPage_doesNotCrawlContactPages(): void
    {
        $mainPageHtml = '<html><body><a href="mailto:info@company.cz">Contact</a><a href="/kontakt">Kontakt</a></body></html>';

        $httpClient = $this->createMockHttpClient([
            'example.cz' => $mainPageHtml,
            '/kontakt' => '<html><body><p>Should not be fetched</p></body></html>',
        ]);

        $extractor = $this->createExtractor($httpClient);
        $result = $extractor->extractWithContactPages('https://example.cz');

        self::assertNotNull($result);
        self::assertContains('info@company.cz', $result->emails);
        // Only main page should be requested (email found, no need to crawl)
        self::assertCount(1, $this->requestedUrls);
        self::assertFalse($this->hasRequestedUrlContaining('/kontakt'));
    }

    #[Test]
    public function extractWithContactPages_noEmailOnMainPage_crawlsContactPages(): void
    {
        $mainPageHtml = '<html><body><a href="/kontakt">Kontakt</a><p>No email here</p></body></html>';
        $contactPageHtml = '<html><body><a href="mailto:info@company.cz">Email us</a></body></html>';

        $httpClient = $this->createMockHttpClient([
            'example.cz' => $mainPageHtml,
            '/kontakt' => $contactPageHtml,
        ]);

        $extractor = $this->createExtractor($httpClient);
        $result = $extractor->extractWithContactPages('https://example.cz');

        self::assertNotNull($result);
        self::assertContains('info@company.cz', $result->emails);
        // Both pages should be requested
        self::assertCount(2, $this->requestedUrls);
        self::assertTrue($this->hasRequestedUrlContaining('/kontakt'));
    }

    #[Test]
    #[DataProvider('contactPagePatternsProvider')]
    public function extractWithContactPages_findsContactPageByPattern(string $linkHref, string $expectedPattern): void
    {
        $mainPageHtml = "<html><body><a href=\"{$linkHref}\">Link</a></body></html>";
        $contactPageHtml = '<html><body><a href="mailto:found@company.cz">Email</a></body></html>';

        $httpClient = $this->createMockHttpClient([
            'example.cz' => $mainPageHtml,
            $expectedPattern => $contactPageHtml,
        ]);

        $extractor = $this->createExtractor($httpClient);
        $result = $extractor->extractWithContactPages('https://example.cz');

        self::assertNotNull($result);
        self::assertContains('found@company.cz', $result->emails);
        self::assertTrue($this->hasRequestedUrlContaining($expectedPattern), "Should request URL containing: {$expectedPattern}");
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function contactPagePatternsProvider(): iterable
    {
        // Czech patterns
        yield 'kontakt (relative)' => ['/kontakt', '/kontakt'];
        yield 'kontakty (relative)' => ['/kontakty', '/kontakty'];
        yield 'kontakt.html' => ['/kontakt.html', '/kontakt.html'];
        yield 'napiste-nam' => ['/napiste-nam', '/napiste-nam'];
        yield 'o-nas' => ['/o-nas', '/o-nas'];

        // English patterns
        yield 'contact' => ['/contact', '/contact'];
        yield 'contacts' => ['/contacts', '/contacts'];
        yield 'contact-us' => ['/contact-us', '/contact-us'];
        yield 'about' => ['/about', '/about'];
        yield 'about-us' => ['/about-us', '/about-us'];

        // German patterns
        yield 'impressum' => ['/impressum', '/impressum'];
    }

    #[Test]
    public function extractWithContactPages_stopsAfterFindingEmail(): void
    {
        $mainPageHtml = '
            <html><body>
                <a href="/kontakt">Kontakt</a>
                <a href="/o-nas">O nás</a>
                <a href="/about">About</a>
            </body></html>
        ';

        $responses = [
            'example.cz' => $mainPageHtml,
            '/kontakt' => '<a href="mailto:found@company.cz">Email</a>',
            '/o-nas' => '<a href="mailto:other@company.cz">Other</a>',
            '/about' => '<a href="mailto:another@company.cz">Another</a>',
        ];

        $httpClient = $this->createMockHttpClient($responses);

        $extractor = $this->createExtractor($httpClient);
        $result = $extractor->extractWithContactPages('https://example.cz');

        self::assertNotNull($result);
        self::assertContains('found@company.cz', $result->emails);
        // Should request main page + first contact page only
        self::assertCount(2, $this->requestedUrls);
        self::assertFalse($this->hasRequestedUrlContaining('/o-nas'));
        self::assertFalse($this->hasRequestedUrlContaining('/about'));
    }

    #[Test]
    public function extractWithContactPages_limitsToThreeContactPages(): void
    {
        $mainPageHtml = '
            <html><body>
                <a href="/kontakt">Kontakt</a>
                <a href="/o-nas">O nás</a>
                <a href="/contact">Contact</a>
                <a href="/about">About</a>
                <a href="/impressum">Impressum</a>
            </body></html>
        ';

        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requestedUrls[] = $url;

            // No emails on any page - should try up to 3 contact pages
            return new MockResponse('<html><body><p>No email</p></body></html>', ['http_code' => 200]);
        });

        $extractor = $this->createExtractor($httpClient);
        $extractor->extractWithContactPages('https://example.cz');

        // Main page + max 3 contact pages = 4 total max
        self::assertLessThanOrEqual(4, count($this->requestedUrls));
    }

    #[Test]
    public function extractWithContactPages_ignoresExternalLinks(): void
    {
        $mainPageHtml = '
            <html><body>
                <a href="https://external.com/kontakt">External kontakt</a>
                <a href="/kontakt">Internal kontakt</a>
            </body></html>
        ';
        $contactPageHtml = '<a href="mailto:info@company.cz">Email</a>';

        $httpClient = $this->createMockHttpClient([
            'example.cz' => $mainPageHtml,
            '/kontakt' => $contactPageHtml,
        ]);

        $extractor = $this->createExtractor($httpClient);
        $extractor->extractWithContactPages('https://example.cz');

        // Should not request external domain
        foreach ($this->requestedUrls as $url) {
            self::assertStringNotContainsString('external.com', $url);
        }
    }

    #[Test]
    public function extractWithContactPages_ignoresMailtoAndTelLinks(): void
    {
        $mainPageHtml = '
            <html><body>
                <a href="mailto:skip@company.cz">Email</a>
                <a href="tel:+420123456789">Call</a>
                <a href="javascript:void(0)">JS Link</a>
                <a href="#section">Anchor</a>
            </body></html>
        ';

        $httpClient = $this->createMockHttpClient([
            'example.cz' => $mainPageHtml,
        ]);

        $extractor = $this->createExtractor($httpClient);
        $result = $extractor->extractWithContactPages('https://example.cz');

        // Should only request main page (mailto is not a page to crawl)
        self::assertCount(1, $this->requestedUrls);
        // But should still extract the email from mailto
        self::assertNotNull($result);
        self::assertContains('skip@company.cz', $result->emails);
    }

    #[Test]
    public function extractWithContactPages_handlesRelativeUrls(): void
    {
        // Test with leading slash - the standard relative URL format
        $mainPageHtml = '
            <html><body>
                <a href="/kontakt">Kontakt link</a>
            </body></html>
        ';

        $responses = [
            'example.cz' => $mainPageHtml,
            '/kontakt' => '<p>No email here either</p>',
        ];

        $httpClient = $this->createMockHttpClient($responses);

        $extractor = $this->createExtractor($httpClient);
        $extractor->extractWithContactPages('https://example.cz');

        // Relative URLs should be resolved correctly
        self::assertTrue(
            $this->hasRequestedUrlContaining('kontakt'),
            'Should have requested a kontakt page. Requested: ' . implode(', ', $this->requestedUrls)
        );
    }

    #[Test]
    public function extractWithContactPages_mergesDataFromMultiplePages(): void
    {
        $mainPageHtml = '
            <html><body>
                <a href="/kontakt">Kontakt</a>
                <p>Tel: 601 123 456</p>
            </body></html>
        ';
        $contactPageHtml = '
            <html><body>
                <a href="mailto:info@company.cz">Email</a>
                <p>Tel: 602 987 654</p>
            </body></html>
        ';

        $httpClient = $this->createMockHttpClient([
            'example.cz' => $mainPageHtml,
            '/kontakt' => $contactPageHtml,
        ]);

        $extractor = $this->createExtractor($httpClient);
        $result = $extractor->extractWithContactPages('https://example.cz');

        self::assertNotNull($result);
        // Email from contact page
        self::assertContains('info@company.cz', $result->emails);
        // Phones from both pages should be merged (normalized with +420 prefix)
        self::assertContains('+420601123456', $result->phones);
        self::assertContains('+420602987654', $result->phones);
    }

    // ==================== Error Handling Tests ====================

    #[Test]
    public function extractWithContactPages_httpClientNotConfigured_returnsNull(): void
    {
        $extractor = $this->createExtractor(null);
        $result = $extractor->extractWithContactPages('https://example.cz');

        self::assertNull($result);
    }

    #[Test]
    public function extractWithContactPages_mainPageError_returnsNull(): void
    {
        $httpClient = $this->createMockHttpClient([], 404);

        $extractor = $this->createExtractor($httpClient);
        $result = $extractor->extractWithContactPages('https://example.cz');

        self::assertNull($result);
    }

    #[Test]
    public function extractWithContactPages_contactPageError_stillReturnsMainPageData(): void
    {
        // When contact page fails, we should still return main page data
        $mainPageHtml = '
            <html><body>
                <a href="/kontakt">Kontakt</a>
                <p>Tel: 601 234 567</p>
            </body></html>
        ';

        $httpClient = $this->createMockHttpClient([
            'example.cz' => $mainPageHtml,
            // /kontakt will return 404 (default) - simulating error
        ], 404);

        $extractor = $this->createExtractor($httpClient);
        $result = $extractor->extractWithContactPages('https://example.cz');

        self::assertNotNull($result);
        // Should still have phone from main page even though contact page failed
        self::assertContains('+420601234567', $result->phones);
    }

    // ==================== URL Pattern Recognition Tests ====================

    #[Test]
    public function extractWithContactPages_recognizesCzechPatterns(): void
    {
        $patterns = ['kontakt', 'kontakty', 'napiste-nam', 'o-nas'];

        foreach ($patterns as $pattern) {
            $this->requestedUrls = [];

            $mainPageHtml = "<html><body><a href=\"/{$pattern}\">Link</a></body></html>";

            $httpClient = new MockHttpClient(function (string $method, string $url) use ($mainPageHtml): MockResponse {
                $this->requestedUrls[] = $url;

                // Main page
                if (str_ends_with(rtrim($url, '/'), 'example.cz')) {
                    return new MockResponse($mainPageHtml, ['http_code' => 200]);
                }

                return new MockResponse('<p>No email</p>', ['http_code' => 200]);
            });

            $extractor = $this->createExtractor($httpClient);
            $extractor->extractWithContactPages('https://example.cz');

            self::assertTrue(
                $this->hasRequestedUrlContaining($pattern),
                "Should recognize Czech pattern: {$pattern}. Requested URLs: " . implode(', ', $this->requestedUrls)
            );
        }
    }

    #[Test]
    public function extractWithContactPages_recognizesEnglishPatterns(): void
    {
        $patterns = ['contact', 'contacts', 'contact-us', 'about', 'about-us'];

        foreach ($patterns as $pattern) {
            $this->requestedUrls = [];

            $mainPageHtml = "<html><body><a href=\"/{$pattern}\">Link</a></body></html>";

            $httpClient = new MockHttpClient(function (string $method, string $url) use ($mainPageHtml): MockResponse {
                $this->requestedUrls[] = $url;

                // Main page
                if (str_ends_with(rtrim($url, '/'), 'example.cz')) {
                    return new MockResponse($mainPageHtml, ['http_code' => 200]);
                }

                return new MockResponse('<p>No email</p>', ['http_code' => 200]);
            });

            $extractor = $this->createExtractor($httpClient);
            $extractor->extractWithContactPages('https://example.cz');

            self::assertTrue(
                $this->hasRequestedUrlContaining($pattern),
                "Should recognize English pattern: {$pattern}. Requested URLs: " . implode(', ', $this->requestedUrls)
            );
        }
    }

    // ==================== Basic Extraction Tests ====================

    #[Test]
    public function extract_simpleHtml_extractsAllData(): void
    {
        $extractor = $this->createExtractor();

        // Using valid ICO that passes modulo 11 checksum: 27082440
        $html = '
            <html>
            <head><title>Firma s.r.o.</title></head>
            <body>
                <a href="mailto:info@firma.cz">Email</a>
                <p>Tel: 601 234 567</p>
                <p>IČO: 27082440</p>
            </body>
            </html>
        ';

        $result = $extractor->extract($html);

        self::assertContains('info@firma.cz', $result->emails);
        // Phone is normalized with +420 prefix
        self::assertContains('+420601234567', $result->phones);
        self::assertSame('27082440', $result->ico);
    }

    #[Test]
    public function extractFromUrl_validUrl_returnsPageData(): void
    {
        $html = '<html><body><a href="mailto:info@test.cz">Email</a></body></html>';

        $httpClient = $this->createMockHttpClient([
            'test.cz' => $html,
        ]);

        $extractor = $this->createExtractor($httpClient);
        $result = $extractor->extractFromUrl('https://test.cz');

        self::assertNotNull($result);
        self::assertContains('info@test.cz', $result->emails);
    }

    #[Test]
    public function extractFromUrl_invalidUrl_returnsNull(): void
    {
        $httpClient = $this->createMockHttpClient([], 404);

        $extractor = $this->createExtractor($httpClient);
        $result = $extractor->extractFromUrl('https://nonexistent.cz');

        self::assertNull($result);
    }

    #[Test]
    public function extractFromUrl_noHttpClient_returnsNull(): void
    {
        $extractor = $this->createExtractor(null);
        $result = $extractor->extractFromUrl('https://test.cz');

        self::assertNull($result);
    }
}
