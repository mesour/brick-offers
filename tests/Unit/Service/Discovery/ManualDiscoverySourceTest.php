<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Discovery;

use App\Enum\LeadSource;
use App\Service\Discovery\DiscoveryResult;
use App\Service\Discovery\ManualDiscoverySource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

#[CoversClass(ManualDiscoverySource::class)]
final class ManualDiscoverySourceTest extends TestCase
{
    private ManualDiscoverySource $source;

    protected function setUp(): void
    {
        $this->source = new ManualDiscoverySource(
            new MockHttpClient(),
            new NullLogger(),
        );
    }

    // ==================== getSource() Tests ====================

    #[Test]
    public function getSource_returnsManual(): void
    {
        self::assertSame(LeadSource::MANUAL, $this->source->getSource());
    }

    // ==================== supports() Tests ====================

    #[Test]
    public function supports_manualSource_returnsTrue(): void
    {
        self::assertTrue($this->source->supports(LeadSource::MANUAL));
    }

    #[Test]
    public function supports_otherSources_returnsFalse(): void
    {
        self::assertFalse($this->source->supports(LeadSource::GOOGLE));
        self::assertFalse($this->source->supports(LeadSource::SEZNAM));
        self::assertFalse($this->source->supports(LeadSource::FIRMY_CZ));
        self::assertFalse($this->source->supports(LeadSource::CRAWLER));
    }

    // ==================== discover() - Valid URL Tests ====================

    #[Test]
    public function discover_validHttpsUrl_returnsResult(): void
    {
        $results = $this->source->discover('https://example.com');

        self::assertCount(1, $results);
        self::assertInstanceOf(DiscoveryResult::class, $results[0]);
        self::assertSame('https://example.com', $results[0]->url);
    }

    #[Test]
    public function discover_validHttpUrl_returnsResult(): void
    {
        $results = $this->source->discover('http://example.com');

        self::assertCount(1, $results);
        self::assertSame('http://example.com', $results[0]->url);
    }

    #[Test]
    public function discover_urlWithPath_returnsResult(): void
    {
        $results = $this->source->discover('https://example.com/path/to/page');

        self::assertCount(1, $results);
        self::assertSame('https://example.com/path/to/page', $results[0]->url);
    }

    #[Test]
    public function discover_urlWithQueryString_returnsResult(): void
    {
        $results = $this->source->discover('https://example.com?param=value');

        self::assertCount(1, $results);
        self::assertStringContainsString('example.com', $results[0]->url);
    }

    // ==================== discover() - URL Normalization Tests ====================

    #[Test]
    public function discover_urlWithoutScheme_addsHttps(): void
    {
        $results = $this->source->discover('example.com');

        self::assertCount(1, $results);
        self::assertSame('https://example.com', $results[0]->url);
    }

    #[Test]
    public function discover_urlWithWww_preservesWww(): void
    {
        $results = $this->source->discover('www.example.com');

        self::assertCount(1, $results);
        self::assertSame('https://www.example.com', $results[0]->url);
    }

    #[Test]
    public function discover_urlWithTrailingSpaces_trimmed(): void
    {
        $results = $this->source->discover('  https://example.com  ');

        self::assertCount(1, $results);
        self::assertSame('https://example.com', $results[0]->url);
    }

    // ==================== discover() - Invalid URL Tests ====================

    #[Test]
    public function discover_invalidUrl_returnsEmptyArray(): void
    {
        $results = $this->source->discover('not a valid url at all');

        self::assertSame([], $results);
    }

    #[Test]
    public function discover_emptyString_returnsEmptyArray(): void
    {
        $results = $this->source->discover('');

        self::assertSame([], $results);
    }

    #[Test]
    public function discover_whitespaceOnly_returnsEmptyArray(): void
    {
        $results = $this->source->discover('   ');

        self::assertSame([], $results);
    }

    // ==================== discover() - Metadata Tests ====================

    #[Test]
    public function discover_validUrl_containsSourceTypeMetadata(): void
    {
        $results = $this->source->discover('https://example.com');

        self::assertArrayHasKey('source_type', $results[0]->metadata);
        self::assertSame('manual', $results[0]->metadata['source_type']);
    }

    #[Test]
    public function discover_validUrl_containsOriginalInputMetadata(): void
    {
        $results = $this->source->discover('example.com');

        self::assertArrayHasKey('original_input', $results[0]->metadata);
        self::assertSame('example.com', $results[0]->metadata['original_input']);
    }

    #[Test]
    public function discover_normalizedUrl_preservesOriginalInput(): void
    {
        $results = $this->source->discover('EXAMPLE.COM');

        self::assertSame('https://EXAMPLE.COM', $results[0]->url);
        self::assertSame('EXAMPLE.COM', $results[0]->metadata['original_input']);
    }

    // ==================== discover() - Domain Extraction Tests ====================

    #[Test]
    public function discover_validUrl_extractsDomain(): void
    {
        $results = $this->source->discover('https://www.example.com/page');

        self::assertSame('example.com', $results[0]->domain);
    }

    #[Test]
    public function discover_czechDomain_extractsDomain(): void
    {
        $results = $this->source->discover('https://firma.cz');

        self::assertSame('firma.cz', $results[0]->domain);
    }

    // ==================== discover() - Limit Parameter Tests ====================

    #[Test]
    public function discover_limitParameter_ignoredForManualSource(): void
    {
        // Manual source always returns at most 1 result
        $results = $this->source->discover('https://example.com', 100);

        self::assertCount(1, $results);
    }

    #[Test]
    public function discover_limitZero_stillReturnsResult(): void
    {
        // Limit doesn't affect manual source
        $results = $this->source->discover('https://example.com', 0);

        self::assertCount(1, $results);
    }

    // ==================== Real World URL Tests ====================

    #[Test]
    #[DataProvider('realWorldUrlsProvider')]
    public function discover_realWorldUrls_handledCorrectly(string $input, string $expectedUrl, string $expectedDomain): void
    {
        $results = $this->source->discover($input);

        self::assertCount(1, $results);
        self::assertSame($expectedUrl, $results[0]->url);
        self::assertSame($expectedDomain, $results[0]->domain);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function realWorldUrlsProvider(): iterable
    {
        yield 'simple domain' => ['example.com', 'https://example.com', 'example.com'];
        yield 'with www' => ['www.example.com', 'https://www.example.com', 'example.com'];
        yield 'full https url' => ['https://example.com', 'https://example.com', 'example.com'];
        yield 'full http url' => ['http://example.com', 'http://example.com', 'example.com'];
        yield 'czech domain' => ['firma.cz', 'https://firma.cz', 'firma.cz'];
        yield 'subdomain' => ['shop.example.com', 'https://shop.example.com', 'shop.example.com'];
        yield 'with path' => ['example.com/contact', 'https://example.com/contact', 'example.com'];
        yield 'complex url' => ['https://www.example.com/path?query=1#hash', 'https://www.example.com/path?query=1#hash', 'example.com'];
    }
}
