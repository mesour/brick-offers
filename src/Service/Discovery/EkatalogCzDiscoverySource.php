<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovery source for ekatalog.cz - Czech business directory.
 *
 * Ekatalog.cz uses category-based browsing with URL pattern:
 * - Category pages: /o/{category}/
 * - Pagination: ?page={number}
 * - Company details: /firma/{id}-{slug}/
 *
 * Each listing contains direct website URLs when available.
 */
#[AutoconfigureTag('app.discovery_source')]
class EkatalogCzDiscoverySource extends AbstractDiscoverySource
{
    private const BASE_URL = 'https://www.ekatalog.cz';
    private const RESULTS_PER_PAGE = 10;
    private const MAX_RETRIES = 3;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 1500; // Conservative rate limiting
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::EKATALOG;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::EKATALOG;
    }

    /**
     * Discover companies from ekatalog.cz.
     *
     * Query can be either:
     * - A category slug (e.g., "restaurace", "webdesign", "autoservisy")
     * - A search term that will be used as category
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        $results = [];
        $page = 1;
        $maxPages = (int) ceil($limit / self::RESULTS_PER_PAGE);

        // Normalize query to URL-safe category slug
        $categorySlug = $this->normalizeToSlug($query);

        for ($i = 0; $i < $maxPages && count($results) < $limit; $i++) {
            $pageResults = $this->fetchPage($categorySlug, $page);

            if (empty($pageResults)) {
                break;
            }

            $results = array_merge($results, $pageResults);
            $page++;

            if ($i < $maxPages - 1) {
                $this->rateLimit();
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Fetch a page of results from category listing.
     *
     * @return array<DiscoveryResult>
     */
    private function fetchPage(string $categorySlug, int $page): array
    {
        $url = sprintf('%s/o/%s/', self::BASE_URL, $categorySlug);

        if ($page > 1) {
            $url .= '?page=' . $page;
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($url);

            if ($html !== null) {
                return $this->parseListingPage($html, $categorySlug);
            }

            $this->logger->warning('Ekatalog.cz request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 2);
        }

        $this->logger->error('Ekatalog.cz: Max retries exceeded', [
            'category' => $categorySlug,
            'page' => $page,
        ]);

        return [];
    }

    /**
     * Use native curl with browser-like headers.
     */
    private function fetchWithCurl(string $url): ?string
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 5,
            \CURLOPT_TIMEOUT => 30,
            \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_2_0,
            \CURLOPT_ENCODING => '',
            \CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: cs,sk;q=0.8,en-US;q=0.5,en;q=0.3',
                'Accept-Encoding: gzip, deflate, br',
                'DNT: 1',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
            ],
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            $this->logger->warning('Ekatalog.cz curl request failed', [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error,
            ]);

            return null;
        }

        return $result;
    }

    /**
     * Parse company listings from HTML page.
     *
     * @return array<DiscoveryResult>
     */
    private function parseListingPage(string $html, string $category): array
    {
        $results = [];

        // Strategy 1: Extract direct website URLs from listings
        // Pattern: website URLs are typically displayed as links with domain text
        $websitePattern = '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?ekatalog\.cz)[^"]+)"[^>]*>([^<]*\.[a-z]{2,}[^<]*)<\/a>/i';

        if (preg_match_all($websitePattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = html_entity_decode($match[1]);
                $linkText = trim(html_entity_decode($match[2]));

                if (!$this->isValidWebsiteUrl($url)) {
                    continue;
                }

                // Skip ekatalog.cz internal links
                if (str_contains($url, 'ekatalog.cz')) {
                    continue;
                }

                $results[] = $this->createResultWithExtraction($url, [
                    'link_text' => $linkText,
                    'category' => $category,
                    'source_type' => 'ekatalog_direct',
                ]);
            }
        }

        // Strategy 2: Extract company detail pages for later processing
        // Pattern: /firma/{id}-{slug}/
        $detailPattern = '/<a[^>]+href="(\/firma\/\d+-[^"]+)"[^>]*>.*?<h\d[^>]*>([^<]+)<\/h\d>/is';

        if (preg_match_all($detailPattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $detailPath = html_entity_decode($match[1]);
                $companyName = trim(html_entity_decode(strip_tags($match[2])));

                // Check if we already have a direct URL for this company
                $hasDirectUrl = false;
                foreach ($results as $result) {
                    if (str_contains(strtolower($result->metadata['link_text'] ?? ''), strtolower($companyName))) {
                        $hasDirectUrl = true;

                        break;
                    }
                }

                if (!$hasDirectUrl) {
                    $detailUrl = self::BASE_URL . $detailPath;
                    $results[] = new DiscoveryResult($detailUrl, [
                        'business_name' => $companyName,
                        'category' => $category,
                        'source_type' => 'ekatalog_catalog',
                        'needs_website_extraction' => true,
                    ]);
                }
            }
        }

        // Strategy 3: Fallback - extract any external URLs
        if (empty($results)) {
            $this->extractFallbackUrls($html, $category, $results);
        }

        return $results;
    }

    /**
     * Fallback URL extraction from HTML.
     *
     * @param array<DiscoveryResult> $results
     */
    private function extractFallbackUrls(string $html, string $category, array &$results): void
    {
        $urls = $this->extractUrlsFromHtml($html);

        foreach ($urls as $url) {
            if ($this->isValidWebsiteUrl($url) && !str_contains($url, 'ekatalog.cz')) {
                $results[] = $this->createResultWithExtraction($url, [
                    'category' => $category,
                    'source_type' => 'ekatalog_fallback',
                ]);
            }
        }
    }

    /**
     * Extract website URL from company detail page.
     */
    public function extractWebsiteFromDetailPage(string $detailUrl): ?string
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($detailUrl);

            if ($html !== null) {
                // Look for website link - usually marked with globe icon or "web" text
                $patterns = [
                    // Direct website link with external URL
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?ekatalog\.cz)[^"]+)"[^>]*class="[^"]*web[^"]*"[^>]*>/i',
                    // Link followed by domain-like text
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?ekatalog\.cz)[^"]+)"[^>]*>[^<]*www\.[^<]+<\/a>/i',
                    // Generic external link that looks like a website
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?ekatalog\.cz|maps\.google|facebook\.com|instagram\.com)[^"]+)"[^>]*target="_blank"[^>]*>/i',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $html, $match)) {
                        $url = html_entity_decode($match[1]);

                        if ($this->isValidWebsiteUrl($url)) {
                            return $url;
                        }
                    }
                }

                return null;
            }

            sleep($attempt * 2);
        }

        $this->logger->error('Failed to extract website from Ekatalog.cz detail', [
            'detailUrl' => $detailUrl,
        ]);

        return null;
    }

    /**
     * Normalize query string to URL-safe category slug.
     */
    private function normalizeToSlug(string $query): string
    {
        // Convert to lowercase
        $slug = mb_strtolower($query);

        // Replace Czech characters
        $slug = strtr($slug, [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
            'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's',
            'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        ]);

        // Replace spaces with hyphens
        $slug = preg_replace('/\s+/', '-', $slug);

        // Remove any non-alphanumeric characters except hyphens
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug ?? '');

        // Remove multiple consecutive hyphens
        $slug = preg_replace('/-+/', '-', $slug ?? '');

        // Trim hyphens from start and end
        return trim($slug ?? '', '-');
    }

    /**
     * Override to add ekatalog.cz to skip domains.
     */
    protected function isValidWebsiteUrl(string $url): bool
    {
        if (!parent::isValidWebsiteUrl($url)) {
            return false;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Skip ekatalog.cz URLs
        if (str_ends_with($host, 'ekatalog.cz')) {
            return false;
        }

        return true;
    }
}
