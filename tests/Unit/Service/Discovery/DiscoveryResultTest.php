<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Discovery;

use App\Service\Discovery\DiscoveryResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiscoveryResult::class)]
final class DiscoveryResultTest extends TestCase
{
    // ==================== Constructor Tests ====================

    #[Test]
    public function constructor_setsUrl(): void
    {
        $result = new DiscoveryResult('https://example.com');

        self::assertSame('https://example.com', $result->url);
    }

    #[Test]
    public function constructor_setsEmptyMetadataByDefault(): void
    {
        $result = new DiscoveryResult('https://example.com');

        self::assertSame([], $result->metadata);
    }

    #[Test]
    public function constructor_setsMetadata(): void
    {
        $metadata = ['key' => 'value', 'number' => 42];
        $result = new DiscoveryResult('https://example.com', $metadata);

        self::assertSame($metadata, $result->metadata);
    }

    // ==================== Domain Extraction Tests ====================

    #[Test]
    public function constructor_extractsDomainFromUrl(): void
    {
        $result = new DiscoveryResult('https://example.com/path/to/page');

        self::assertSame('example.com', $result->domain);
    }

    #[Test]
    public function constructor_removesWwwPrefixFromDomain(): void
    {
        $result = new DiscoveryResult('https://www.example.com/page');

        self::assertSame('example.com', $result->domain);
    }

    #[Test]
    public function constructor_lowercasesDomain(): void
    {
        $result = new DiscoveryResult('https://EXAMPLE.COM/page');

        self::assertSame('example.com', $result->domain);
    }

    #[Test]
    public function constructor_preservesSubdomain(): void
    {
        $result = new DiscoveryResult('https://shop.example.com/products');

        self::assertSame('shop.example.com', $result->domain);
    }

    #[Test]
    public function constructor_removesWwwButPreservesOtherSubdomains(): void
    {
        $result = new DiscoveryResult('https://www.shop.example.com/products');

        self::assertSame('shop.example.com', $result->domain);
    }

    #[Test]
    #[DataProvider('urlToDomainProvider')]
    public function constructor_extractsDomainCorrectly(string $url, string $expectedDomain): void
    {
        $result = new DiscoveryResult($url);

        self::assertSame($expectedDomain, $result->domain);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urlToDomainProvider(): iterable
    {
        yield 'simple https' => ['https://example.com', 'example.com'];
        yield 'simple http' => ['http://example.com', 'example.com'];
        yield 'with path' => ['https://example.com/path', 'example.com'];
        yield 'with query' => ['https://example.com?query=1', 'example.com'];
        yield 'with port' => ['https://example.com:8080', 'example.com'];
        yield 'with fragment' => ['https://example.com#section', 'example.com'];
        yield 'with www' => ['https://www.example.com', 'example.com'];
        yield 'subdomain' => ['https://blog.example.com', 'blog.example.com'];
        yield 'deep subdomain' => ['https://api.v2.example.com', 'api.v2.example.com'];
        yield 'uppercase' => ['https://EXAMPLE.COM', 'example.com'];
        yield 'mixed case www' => ['https://WWW.Example.COM', 'www.example.com']; // www. removal is case-sensitive
        yield 'czech domain' => ['https://www.firma.cz', 'firma.cz'];
        yield 'complex path' => ['https://shop.example.com/products/123?ref=google', 'shop.example.com'];
    }

    // ==================== Edge Cases ====================

    #[Test]
    public function constructor_handlesUrlWithoutScheme(): void
    {
        // parse_url returns the URL as path when no scheme is present
        $result = new DiscoveryResult('example.com');

        // Domain extraction falls back to the input when no host is found
        self::assertSame('example.com', $result->domain);
    }

    #[Test]
    public function constructor_handlesInvalidUrl(): void
    {
        // Invalid URLs still get processed
        $result = new DiscoveryResult('not-a-valid-url');

        self::assertSame('not-a-valid-url', $result->domain);
    }

    #[Test]
    public function constructor_handlesEmptyUrl(): void
    {
        $result = new DiscoveryResult('');

        self::assertSame('', $result->url);
        self::assertSame('', $result->domain);
    }

    // ==================== Metadata Tests ====================

    #[Test]
    public function constructor_preservesComplexMetadata(): void
    {
        $metadata = [
            'source' => 'google',
            'position' => 5,
            'nested' => ['a' => 1, 'b' => 2],
            'tags' => ['web', 'design', 'agency'],
        ];

        $result = new DiscoveryResult('https://example.com', $metadata);

        self::assertSame($metadata, $result->metadata);
    }

    #[Test]
    public function constructor_preservesNullValuesInMetadata(): void
    {
        $metadata = ['value' => null, 'other' => 'present'];

        $result = new DiscoveryResult('https://example.com', $metadata);

        self::assertArrayHasKey('value', $result->metadata);
        self::assertNull($result->metadata['value']);
    }

    // ==================== Readonly Tests ====================

    #[Test]
    public function properties_areReadonly(): void
    {
        $result = new DiscoveryResult('https://example.com', ['key' => 'value']);

        $reflection = new \ReflectionClass($result);

        self::assertTrue($reflection->isReadonly());
    }
}
