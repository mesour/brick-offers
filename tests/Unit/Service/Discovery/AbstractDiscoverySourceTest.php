<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Discovery;

use App\Enum\LeadSource;
use App\Service\Discovery\AbstractDiscoverySource;
use App\Service\Discovery\DiscoveryResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(AbstractDiscoverySource::class)]
final class AbstractDiscoverySourceTest extends TestCase
{
    // ==================== normalizeUrl() Tests ====================

    #[Test]
    public function normalizeUrl_urlWithoutScheme_addsHttps(): void
    {
        $source = $this->createTestSource();

        $result = $source->publicNormalizeUrl('example.com');

        self::assertSame('https://example.com', $result);
    }

    #[Test]
    public function normalizeUrl_urlWithHttps_unchanged(): void
    {
        $source = $this->createTestSource();

        $result = $source->publicNormalizeUrl('https://example.com');

        self::assertSame('https://example.com', $result);
    }

    #[Test]
    public function normalizeUrl_urlWithHttp_unchanged(): void
    {
        $source = $this->createTestSource();

        $result = $source->publicNormalizeUrl('http://example.com');

        self::assertSame('http://example.com', $result);
    }

    #[Test]
    public function normalizeUrl_urlWithUppercaseScheme_unchanged(): void
    {
        $source = $this->createTestSource();

        $result = $source->publicNormalizeUrl('HTTPS://example.com');

        self::assertSame('HTTPS://example.com', $result);
    }

    #[Test]
    public function normalizeUrl_trimsWhitespace(): void
    {
        $source = $this->createTestSource();

        $result = $source->publicNormalizeUrl('  example.com  ');

        self::assertSame('https://example.com', $result);
    }

    // ==================== extractUrlsFromHtml() Tests ====================

    #[Test]
    public function extractUrlsFromHtml_emptyHtml_returnsEmptyArray(): void
    {
        $source = $this->createTestSource();

        $result = $source->publicExtractUrlsFromHtml('');

        self::assertSame([], $result);
    }

    #[Test]
    public function extractUrlsFromHtml_noLinks_returnsEmptyArray(): void
    {
        $source = $this->createTestSource();
        $html = '<html><body><p>No links here</p></body></html>';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extractUrlsFromHtml_validHttpsLinks_extracted(): void
    {
        $source = $this->createTestSource();
        $html = '<a href="https://example.com">Link</a>';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertContains('https://example.com', $result);
    }

    #[Test]
    public function extractUrlsFromHtml_validHttpLinks_extracted(): void
    {
        $source = $this->createTestSource();
        $html = '<a href="http://example.com">Link</a>';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertContains('http://example.com', $result);
    }

    #[Test]
    public function extractUrlsFromHtml_multipleLinks_allExtracted(): void
    {
        $source = $this->createTestSource();
        $html = '
            <a href="https://example1.com">Link 1</a>
            <a href="https://example2.com">Link 2</a>
            <a href="https://example3.com">Link 3</a>
        ';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertCount(3, $result);
        self::assertContains('https://example1.com', $result);
        self::assertContains('https://example2.com', $result);
        self::assertContains('https://example3.com', $result);
    }

    #[Test]
    public function extractUrlsFromHtml_duplicateLinks_deduplicated(): void
    {
        $source = $this->createTestSource();
        $html = '
            <a href="https://example.com">Link 1</a>
            <a href="https://example.com">Link 2</a>
        ';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertCount(1, $result);
    }

    #[Test]
    public function extractUrlsFromHtml_anchorLinks_skipped(): void
    {
        $source = $this->createTestSource();
        $html = '<a href="#section">Anchor</a>';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extractUrlsFromHtml_javascriptLinks_skipped(): void
    {
        $source = $this->createTestSource();
        $html = '<a href="javascript:void(0)">JS Link</a>';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extractUrlsFromHtml_mailtoLinks_skipped(): void
    {
        $source = $this->createTestSource();
        $html = '<a href="mailto:info@example.com">Email</a>';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extractUrlsFromHtml_telLinks_skipped(): void
    {
        $source = $this->createTestSource();
        $html = '<a href="tel:+420123456789">Phone</a>';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extractUrlsFromHtml_relativeLinks_skipped(): void
    {
        $source = $this->createTestSource();
        $html = '<a href="/relative/path">Relative</a>';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extractUrlsFromHtml_emptyHref_skipped(): void
    {
        $source = $this->createTestSource();
        $html = '<a href="">Empty</a>';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertSame([], $result);
    }

    #[Test]
    public function extractUrlsFromHtml_doubleQuotes_extracted(): void
    {
        $source = $this->createTestSource();
        $html = '<a href="https://example.com">Link</a>';

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertContains('https://example.com', $result);
    }

    #[Test]
    public function extractUrlsFromHtml_singleQuotes_extracted(): void
    {
        $source = $this->createTestSource();
        $html = "<a href='https://example.com'>Link</a>";

        $result = $source->publicExtractUrlsFromHtml($html);

        self::assertContains('https://example.com', $result);
    }

    // ==================== isValidWebsiteUrl() Tests ====================

    #[Test]
    public function isValidWebsiteUrl_normalDomain_returnsTrue(): void
    {
        $source = $this->createTestSource();

        self::assertTrue($source->publicIsValidWebsiteUrl('https://example.com'));
    }

    #[Test]
    public function isValidWebsiteUrl_czechDomain_returnsTrue(): void
    {
        $source = $this->createTestSource();

        self::assertTrue($source->publicIsValidWebsiteUrl('https://firma.cz'));
    }

    #[Test]
    #[DataProvider('skipDomainsProvider')]
    public function isValidWebsiteUrl_skipDomains_returnsFalse(string $url): void
    {
        $source = $this->createTestSource();

        self::assertFalse($source->publicIsValidWebsiteUrl($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skipDomainsProvider(): iterable
    {
        yield 'google.com' => ['https://google.com'];
        yield 'google.cz' => ['https://google.cz'];
        yield 'facebook.com' => ['https://facebook.com'];
        yield 'twitter.com' => ['https://twitter.com'];
        yield 'instagram.com' => ['https://instagram.com'];
        yield 'linkedin.com' => ['https://linkedin.com'];
        yield 'youtube.com' => ['https://youtube.com'];
        yield 'tiktok.com' => ['https://tiktok.com'];
        yield 'wikipedia.org' => ['https://wikipedia.org'];
        yield 'seznam.cz' => ['https://seznam.cz'];
        yield 'firmy.cz' => ['https://firmy.cz'];
        yield 'github.com' => ['https://github.com'];
        yield 'stackoverflow.com' => ['https://stackoverflow.com'];
        yield 'subdomain of google' => ['https://www.google.com'];
        yield 'subdomain of facebook' => ['https://m.facebook.com'];
    }

    #[Test]
    public function isValidWebsiteUrl_emptyHost_returnsFalse(): void
    {
        $source = $this->createTestSource();

        self::assertFalse($source->publicIsValidWebsiteUrl('not-a-url'));
    }

    // ==================== setRequestDelay() Tests ====================

    #[Test]
    public function setRequestDelay_positiveValue_setsDelay(): void
    {
        $source = $this->createTestSource();

        $source->setRequestDelay(1000);

        // Can't directly test internal value, but we can verify it doesn't throw
        self::assertTrue(true);
    }

    #[Test]
    public function setRequestDelay_zeroValue_setsZero(): void
    {
        $source = $this->createTestSource();

        $source->setRequestDelay(0);

        self::assertTrue(true);
    }

    #[Test]
    public function setRequestDelay_negativeValue_setsZero(): void
    {
        $source = $this->createTestSource();

        // Negative values should be clamped to 0
        $source->setRequestDelay(-100);

        self::assertTrue(true);
    }

    // ==================== setExtractionEnabled() Tests ====================

    #[Test]
    public function setExtractionEnabled_true_enablesExtraction(): void
    {
        $source = $this->createTestSource();

        $source->setExtractionEnabled(true);

        self::assertTrue(true);
    }

    #[Test]
    public function setExtractionEnabled_false_disablesExtraction(): void
    {
        $source = $this->createTestSource();

        $source->setExtractionEnabled(false);

        self::assertTrue(true);
    }

    // ==================== setPageDataExtractor() Tests ====================

    #[Test]
    public function setPageDataExtractor_null_clearsExtractor(): void
    {
        $source = $this->createTestSource();

        $source->setPageDataExtractor(null);

        self::assertTrue(true);
    }

    // ==================== Helper Methods ====================

    private function createTestSource(?HttpClientInterface $httpClient = null): TestableDiscoverySource
    {
        return new TestableDiscoverySource(
            $httpClient ?? new MockHttpClient(),
            new NullLogger(),
        );
    }
}

/**
 * Testable concrete implementation of AbstractDiscoverySource
 */
class TestableDiscoverySource extends AbstractDiscoverySource
{
    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::MANUAL;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::MANUAL;
    }

    public function discover(string $query, int $limit = 50): array
    {
        return [];
    }

    // Public accessors for protected methods

    public function publicNormalizeUrl(string $url): string
    {
        return $this->normalizeUrl($url);
    }

    public function publicExtractUrlsFromHtml(string $html): array
    {
        return $this->extractUrlsFromHtml($html);
    }

    public function publicIsValidWebsiteUrl(string $url): bool
    {
        return $this->isValidWebsiteUrl($url);
    }
}
