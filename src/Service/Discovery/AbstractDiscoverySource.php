<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Service\Extractor\PageData;
use App\Service\Extractor\PageDataExtractor;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractDiscoverySource implements DiscoverySourceInterface
{
    protected int $requestDelayMs = 500;
    protected ?PageDataExtractor $pageDataExtractor = null;
    protected bool $extractionEnabled = false;
    protected bool $contactPageCrawlingEnabled = true;

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Set the page data extractor for contact/technology extraction.
     */
    public function setPageDataExtractor(?PageDataExtractor $extractor): void
    {
        $this->pageDataExtractor = $extractor;
    }

    /**
     * Enable or disable extraction for discovery.
     */
    public function setExtractionEnabled(bool $enabled): void
    {
        $this->extractionEnabled = $enabled;
    }

    /**
     * Enable or disable contact page crawling during extraction.
     *
     * When enabled, the extractor will look for contact/about pages
     * and extract emails from them if not found on the main page.
     */
    public function setContactPageCrawlingEnabled(bool $enabled): void
    {
        $this->contactPageCrawlingEnabled = $enabled;
    }

    /**
     * Extract page data from a URL if extractor is configured and enabled.
     *
     * When contact page crawling is enabled, the extractor will look for
     * contact/about pages and extract emails from them if not found on the main page.
     */
    protected function extractPageData(string $url): ?PageData
    {
        if (!$this->extractionEnabled || $this->pageDataExtractor === null) {
            return null;
        }

        if ($this->contactPageCrawlingEnabled) {
            // Use contact page crawling to find emails on contact pages
            return $this->pageDataExtractor->extractWithContactPages($url);
        }

        // Simple extraction from main page only
        return $this->pageDataExtractor->extractFromUrl($url);
    }

    /**
     * Create a DiscoveryResult with optional page data extraction.
     *
     * @param array<string, mixed> $metadata
     */
    protected function createResultWithExtraction(
        string $url,
        array $metadata = [],
        bool $extractData = true,
    ): DiscoveryResult {
        if ($extractData && $this->extractionEnabled) {
            $pageData = $this->extractPageData($url);
            if ($pageData !== null) {
                $metadata = array_merge($metadata, $pageData->toMetadata());
            }
        }

        return new DiscoveryResult($url, $metadata);
    }

    /**
     * Apply rate limiting between requests.
     */
    protected function rateLimit(): void
    {
        if ($this->requestDelayMs > 0) {
            usleep($this->requestDelayMs * 1000);
        }
    }

    /**
     * Normalize a URL to ensure it has a scheme.
     */
    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    /**
     * Extract URLs from HTML content.
     *
     * @return array<string>
     */
    protected function extractUrlsFromHtml(string $html): array
    {
        $urls = [];

        // Extract hrefs from anchor tags
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $href = trim($href);

                // Skip empty, anchors, javascript, and mailto links
                if (
                    empty($href)
                    || str_starts_with($href, '#')
                    || str_starts_with($href, 'javascript:')
                    || str_starts_with($href, 'mailto:')
                    || str_starts_with($href, 'tel:')
                ) {
                    continue;
                }

                // Only include URLs that look like websites
                if (preg_match('~^https?://[a-z0-9]~i', $href)) {
                    $urls[] = $href;
                }
            }
        }

        return array_unique($urls);
    }

    /**
     * Check if a URL looks like a valid website (not social media, search engines, etc.).
     */
    protected function isValidWebsiteUrl(string $url): bool
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        if (empty($host)) {
            return false;
        }

        // Skip common non-target domains
        $skipDomains = [
            'google.com', 'google.cz',
            'facebook.com', 'twitter.com', 'instagram.com', 'linkedin.com',
            'youtube.com', 'tiktok.com',
            'wikipedia.org',
            'seznam.cz', 'firmy.cz', 'ekatalog.cz', 'atlasskolstvi.cz', 'seznamskol.cz', 'zivefirmy.cz', 'najisto.centrum.cz', 'zlatestranky.cz',
            'skoly-online.cz', 'stredniskoly.cz',
            'bing.com', 'yahoo.com',
            'github.com', 'stackoverflow.com',
        ];

        foreach ($skipDomains as $skipDomain) {
            if (str_ends_with($host, $skipDomain) || str_ends_with($host, '.' . $skipDomain)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Set the delay between requests in milliseconds.
     */
    public function setRequestDelay(int $delayMs): void
    {
        $this->requestDelayMs = max(0, $delayMs);
    }
}
