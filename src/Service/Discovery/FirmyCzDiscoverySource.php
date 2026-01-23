<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovery source for Firmy.cz business directory.
 *
 * Firmy.cz is a React SPA with aggressive bot detection. Traditional pagination
 * via URL parameters doesn't work for search results. However, category pages
 * support pagination via ?page=N parameter.
 *
 * Query formats:
 * - "webdesign" - search query (limited to ~15 SSR results, no pagination)
 * - "category:/Remesla-a-sluzby/IT" - category browsing with pagination
 * - "categories:IT,Marketing" - multiple categories (iterates through subcategories)
 */
#[AutoconfigureTag('app.discovery_source')]
class FirmyCzDiscoverySource extends AbstractDiscoverySource
{
    private const BASE_URL = 'https://www.firmy.cz';
    private const RESULTS_PER_PAGE = 15;
    private const MAX_RETRIES = 3;
    private const MAX_PAGES_PER_CATEGORY = 10;

    /**
     * Common category paths for different industries.
     * Use with "categories:" prefix, e.g., "categories:IT" or "categories:stavebnictvi".
     *
     * @var array<string, array<string>>
     */
    private const CATEGORY_GROUPS = [
        'IT' => [
            '/Remesla-a-sluzby/Informacni-technologie/Tvorba-webovych-stranek',
            '/Remesla-a-sluzby/Informacni-technologie/Sprava-IT-systemu',
            '/Remesla-a-sluzby/Informacni-technologie/Programovani-software',
            '/Remesla-a-sluzby/Informacni-technologie/Graficke-studio',
        ],
        'marketing' => [
            '/Remesla-a-sluzby/Reklamni-a-marketingove-sluzby/Reklamni-agentury',
            '/Remesla-a-sluzby/Reklamni-a-marketingove-sluzby/Marketing',
            '/Remesla-a-sluzby/Reklamni-a-marketingove-sluzby/PR-agentury',
        ],
        'stavebnictvi' => [
            '/Remesla-a-sluzby/Stavebnictvi/Stavebni-firmy',
            '/Remesla-a-sluzby/Stavebnictvi/Zednici',
            '/Remesla-a-sluzby/Stavebnictvi/Tesari-pokryvaci',
            '/Remesla-a-sluzby/Stavebnictvi/Instalaterske-prace',
        ],
        'auto' => [
            '/Auto-moto/Autoservisy-pneuservisy/Autoservisy',
            '/Auto-moto/Autoservisy-pneuservisy/Pneuservisy',
            '/Auto-moto/Prodej-aut/Autobazary',
        ],
        'restaurace' => [
            '/Restaurace/Restaurace',
            '/Restaurace/Pizzerie',
            '/Restaurace/Asijska-kuchyne',
            '/Restaurace/Fast-food',
        ],
        'zdravotnictvi' => [
            '/Zdravotnictvi/Lekari/Prakticky-lekar',
            '/Zdravotnictvi/Lekari/Zubni-lekar',
            '/Zdravotnictvi/Lekarny',
        ],
        'reality' => [
            '/Reality/Realitni-kancelare',
            '/Reality/Sprava-nemovitosti',
        ],
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 2000; // Conservative rate limiting for bot detection
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::FIRMY_CZ;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::FIRMY_CZ;
    }

    /**
     * Discover businesses from Firmy.cz.
     *
     * Query formats:
     * - "webdesign" - search query (limited to ~15 results, no pagination)
     * - "category:/Remesla-a-sluzby/IT/Webdesign" - specific category with pagination
     * - "categories:IT" - predefined category group (see CATEGORY_GROUPS)
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        // Category group: "categories:IT" or "categories:stavebnictvi"
        if (str_starts_with($query, 'categories:')) {
            $groupName = strtolower(trim(substr($query, 11)));

            return $this->discoverFromCategoryGroup($groupName, $limit);
        }

        // Single category: "category:/Remesla-a-sluzby/IT"
        if (str_starts_with($query, 'category:')) {
            $categoryPath = trim(substr($query, 9));

            return $this->discoverFromCategory($categoryPath, $limit);
        }

        // Default: search query (limited, no pagination)
        return $this->discoverFromSearch($query, $limit);
    }

    /**
     * Discover from a predefined category group.
     *
     * @return array<DiscoveryResult>
     */
    private function discoverFromCategoryGroup(string $groupName, int $limit): array
    {
        $categories = self::CATEGORY_GROUPS[$groupName] ?? null;

        if ($categories === null) {
            $this->logger->error('Firmy.cz: Unknown category group', [
                'group' => $groupName,
                'available' => array_keys(self::CATEGORY_GROUPS),
            ]);

            return [];
        }

        $results = [];
        $perCategoryLimit = (int) ceil($limit / count($categories));
        $seenDomains = [];

        foreach ($categories as $categoryPath) {
            if (count($results) >= $limit) {
                break;
            }

            $categoryResults = $this->discoverFromCategory($categoryPath, $perCategoryLimit);

            foreach ($categoryResults as $result) {
                if (count($results) >= $limit) {
                    break;
                }

                // Deduplicate by domain
                if (!isset($seenDomains[$result->domain])) {
                    $seenDomains[$result->domain] = true;
                    $results[] = $result;
                }
            }

            $this->rateLimit();
        }

        $this->logger->info('Firmy.cz: Category group discovery completed', [
            'group' => $groupName,
            'categories_count' => count($categories),
            'results' => count($results),
        ]);

        return $results;
    }

    /**
     * Discover from a specific category with pagination.
     *
     * @return array<DiscoveryResult>
     */
    private function discoverFromCategory(string $categoryPath, int $limit): array
    {
        $results = [];
        $page = 1;
        $maxPages = min(self::MAX_PAGES_PER_CATEGORY, (int) ceil($limit / self::RESULTS_PER_PAGE));
        $seenDomains = [];

        // Normalize category path
        if (!str_starts_with($categoryPath, '/')) {
            $categoryPath = '/' . $categoryPath;
        }

        for ($i = 0; $i < $maxPages && count($results) < $limit; $i++) {
            $url = self::BASE_URL . $categoryPath;
            if ($page > 1) {
                $url .= '?page=' . $page;
            }

            $html = $this->fetchWithRetry($url);

            if ($html === null) {
                break;
            }

            $pageResults = $this->parseSearchResults($html, $categoryPath);

            if (empty($pageResults)) {
                $this->logger->debug('Firmy.cz: No more results in category', [
                    'category' => $categoryPath,
                    'page' => $page,
                ]);

                break;
            }

            foreach ($pageResults as $result) {
                if (count($results) >= $limit) {
                    break;
                }

                // Deduplicate by domain
                if (!isset($seenDomains[$result->domain])) {
                    $seenDomains[$result->domain] = true;
                    $results[] = $result;
                }
            }

            $page++;

            if ($i < $maxPages - 1 && count($results) < $limit) {
                $this->rateLimit();
            }
        }

        $this->logger->debug('Firmy.cz: Category discovery completed', [
            'category' => $categoryPath,
            'pages_fetched' => $page - 1,
            'results' => count($results),
        ]);

        return array_slice($results, 0, $limit);
    }

    /**
     * Discover from search query (limited to first page, ~15 results).
     *
     * @return array<DiscoveryResult>
     */
    private function discoverFromSearch(string $query, int $limit): array
    {
        $this->logger->info('Firmy.cz: Using search query (limited to ~15 results)', [
            'query' => $query,
            'hint' => 'For more results, use category: or categories: prefix',
        ]);

        $url = sprintf('%s/?q=%s', self::BASE_URL, urlencode($query));
        $html = $this->fetchWithRetry($url);

        if ($html === null) {
            return [];
        }

        $results = $this->parseSearchResults($html, $query);

        return array_slice($results, 0, $limit);
    }

    /**
     * Fetch URL with retry logic.
     */
    private function fetchWithRetry(string $url): ?string
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($url);

            if ($html !== null) {
                return $html;
            }

            $this->logger->warning('Firmy.cz request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 3); // Exponential backoff
        }

        $this->logger->error('Firmy.cz: Max retries exceeded', [
            'url' => $url,
        ]);

        return null;
    }

    /**
     * Use native curl with browser-like TLS fingerprint to bypass bot detection.
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
            \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_2_0, // Use HTTP/2 like browsers
            \CURLOPT_ENCODING => '', // Accept any encoding
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
            // TLS fingerprint settings to appear more like a browser
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, \CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            $this->logger->warning('Curl request failed', [
                'url' => $url,
                'final_url' => $finalUrl,
                'http_code' => $httpCode,
                'error' => $error,
            ]);

            return null;
        }

        return $result;
    }

    /**
     * Parse Firmy.cz search results HTML.
     * Uses JSON-LD structured data which contains direct website URLs.
     *
     * @return array<DiscoveryResult>
     */
    private function parseSearchResults(string $html, string $query): array
    {
        $results = [];

        // Extract JSON-LD structured data - most reliable source for business info
        $jsonLdPattern = '/<script type="application\/ld\+json">(\{[^<]+\})<\/script>/i';

        if (preg_match_all($jsonLdPattern, $html, $matches)) {
            foreach ($matches[1] as $jsonString) {
                try {
                    $data = json_decode($jsonString, true, 512, \JSON_THROW_ON_ERROR);

                    if (!isset($data['@type']) || $data['@type'] !== 'LocalBusiness') {
                        continue;
                    }

                    $businessName = $data['name'] ?? null;
                    $detailUrl = $data['url'] ?? null;

                    // Extract actual website URL from sameAs array (second element is usually the website)
                    $websiteUrl = null;
                    if (isset($data['sameAs']) && \is_array($data['sameAs'])) {
                        foreach ($data['sameAs'] as $url) {
                            // Skip firmy.cz URLs, we want the actual business website
                            if (!\str_contains($url, 'firmy.cz') && $this->isValidWebsiteUrl($url)) {
                                $websiteUrl = $url;

                                break;
                            }
                        }
                    }

                    // Prefer actual website URL over firmy.cz detail page
                    if ($websiteUrl !== null) {
                        $results[] = $this->createResultWithExtraction($websiteUrl, [
                            'business_name' => $businessName,
                            'query' => $query,
                            'source_type' => 'firmy_cz_direct',
                            'firmy_cz_detail' => $detailUrl,
                        ]);
                    } elseif ($detailUrl !== null && $businessName !== null) {
                        // Fallback: store detail page for later website extraction
                        // Note: don't extract from firmy.cz pages, only from actual websites
                        $results[] = new DiscoveryResult($detailUrl, [
                            'business_name' => $businessName,
                            'query' => $query,
                            'source_type' => 'firmy_cz_catalog',
                            'needs_website_extraction' => true,
                        ]);
                    }
                } catch (\JsonException $e) {
                    $this->logger->debug('Failed to parse JSON-LD', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Fallback: extract from HTML if JSON-LD parsing failed
        if (empty($results)) {
            $this->parseFromHtml($html, $query, $results);
        }

        return $results;
    }

    /**
     * Fallback HTML parsing for Firmy.cz search results.
     *
     * @param array<DiscoveryResult> $results
     */
    private function parseFromHtml(string $html, string $query, array &$results): void
    {
        // Match detail page URLs and business names
        $detailPattern = '/<a[^>]+href="(https:\/\/www\.firmy\.cz\/detail\/[^"]+)"[^>]*>.*?<h[23][^>]*[^>]*>([^<]+)/is';

        if (preg_match_all($detailPattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $detailUrl = html_entity_decode($match[1]);
                $businessName = html_entity_decode(strip_tags(trim($match[2])));

                if (empty($businessName)) {
                    continue;
                }

                $results[] = new DiscoveryResult($detailUrl, [
                    'business_name' => $businessName,
                    'query' => $query,
                    'source_type' => 'firmy_cz_catalog',
                    'needs_website_extraction' => true,
                ]);
            }
        }

        // Also try to find direct website links
        $websitePattern = '/<a[^>]+href="(https?:\/\/(?!www\.firmy\.cz)[^"]+)"[^>]*class="[^"]*web[^"]*"[^>]*>/i';

        if (preg_match_all($websitePattern, $html, $matches)) {
            foreach ($matches[1] as $url) {
                $url = html_entity_decode($url);
                if ($this->isValidWebsiteUrl($url)) {
                    $results[] = $this->createResultWithExtraction($url, [
                        'query' => $query,
                        'source_type' => 'firmy_cz_direct',
                    ]);
                }
            }
        }
    }

    /**
     * Extract website URL from a Firmy.cz company detail page.
     */
    public function extractWebsiteFromDetailPage(string $detailUrl): ?string
    {
        $html = $this->fetchWithRetry($detailUrl);

        if ($html === null) {
            return null;
        }

        // Look for website link in JSON-LD first
        if (preg_match('/<script type="application\/ld\+json">(\{[^<]+\})<\/script>/i', $html, $match)) {
            try {
                $data = json_decode($match[1], true, 512, \JSON_THROW_ON_ERROR);
                if (isset($data['sameAs']) && is_array($data['sameAs'])) {
                    foreach ($data['sameAs'] as $url) {
                        if (!str_contains($url, 'firmy.cz') && $this->isValidWebsiteUrl($url)) {
                            return $url;
                        }
                    }
                }
            } catch (\JsonException) {
                // Fall through to HTML parsing
            }
        }

        // Fallback: look for website link in HTML
        if (preg_match('/<a[^>]+href="(https?:\/\/[^"]+)"[^>]*>.*?web.*?<\/a>/is', $html, $match)) {
            $url = html_entity_decode($match[1]);

            if ($this->isValidWebsiteUrl($url) && !str_contains($url, 'firmy.cz')) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Get available category groups.
     *
     * @return array<string, array<string>>
     */
    public static function getCategoryGroups(): array
    {
        return self::CATEGORY_GROUPS;
    }
}
