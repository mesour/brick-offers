<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.discovery_source')]
class NajistoDiscoverySource extends AbstractDiscoverySource
{
    private const BASE_URL = 'https://najisto.centrum.cz';
    private const RESULTS_PER_PAGE = 20;
    private const MAX_RETRIES = 3;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 2000;
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::NAJISTO;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::NAJISTO;
    }

    /**
     * Discover businesses from Najisto.cz.
     *
     * Query formats:
     * - "what={search}" - text search (e.g., "what=autoservis")
     * - "what={search}:where={location}" - search with location (e.g., "what=autoservis:where=brno")
     * - "{category}" - browse category (e.g., "sport", "auto-moto")
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        $parsedQuery = $this->parseQuery($query);
        $results = [];
        $page = 1;
        $maxPages = (int) ceil($limit / self::RESULTS_PER_PAGE);
        $queryContext = $parsedQuery['what'] ?? $parsedQuery['category'] ?? 'unknown';

        for ($i = 0; $i < $maxPages && count($results) < $limit; $i++) {
            $pageResults = $this->fetchPage($parsedQuery, $page);

            if (empty($pageResults)) {
                break;
            }

            // Extract data from listing page directly (website URLs are visible)
            foreach ($pageResults as $businessData) {
                if (count($results) >= $limit) {
                    break;
                }

                $result = $this->createResultFromListing($businessData, $queryContext);

                if ($result !== null) {
                    $results[] = $result;
                }
            }

            $page++;

            if ($i < $maxPages - 1 && count($results) < $limit) {
                $this->rateLimit();
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Parse query into structured format.
     *
     * @return array{what?: string, where?: string, category?: string}
     */
    private function parseQuery(string $query): array
    {
        $result = [];

        // Check for search query format: what={search} or what={search}:where={location}
        if (str_starts_with($query, 'what=')) {
            $queryPart = substr($query, 5);
            $parts = explode(':where=', $queryPart, 2);
            $result['what'] = trim($parts[0]);

            if (isset($parts[1])) {
                $result['where'] = trim($parts[1]);
            }

            return $result;
        }

        // Otherwise treat as category
        $result['category'] = trim($query);

        return $result;
    }

    /**
     * Fetch a listing page and extract business data.
     *
     * @param array{what?: string, where?: string, category?: string} $parsedQuery
     *
     * @return array<array{url: string, detailUrl: string, name: string|null, phone: string|null, email: string|null, address: string|null}>
     */
    private function fetchPage(array $parsedQuery, int $page): array
    {
        $url = $this->buildListingUrl($parsedQuery, $page);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($url);

            if ($html !== null) {
                return $this->parseListingPage($html);
            }

            $this->logger->warning('Najisto request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 2);
        }

        $this->logger->error('Najisto: Max retries exceeded for listing page', [
            'query' => $parsedQuery,
            'page' => $page,
        ]);

        return [];
    }

    /**
     * Build listing URL based on query type.
     *
     * @param array{what?: string, where?: string, category?: string} $parsedQuery
     */
    private function buildListingUrl(array $parsedQuery, int $page): string
    {
        $queryParams = [];

        // Search query: https://najisto.centrum.cz/?what={search}&where={location}
        if (isset($parsedQuery['what'])) {
            $queryParams['what'] = $parsedQuery['what'];

            if (isset($parsedQuery['where'])) {
                $queryParams['where'] = $parsedQuery['where'];
            }

            if ($page > 1) {
                $queryParams['p'] = $page;
                $queryParams['pOffset'] = ($page - 1) * self::RESULTS_PER_PAGE;
            }

            return self::BASE_URL . '/?' . http_build_query($queryParams);
        }

        // Category URL: https://najisto.centrum.cz/{category}/
        $category = $parsedQuery['category'] ?? '';
        $url = self::BASE_URL . '/' . urlencode($category) . '/';

        if ($page > 1) {
            $queryParams['p'] = $page;
            $queryParams['pOffset'] = ($page - 1) * self::RESULTS_PER_PAGE;
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
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
            $this->logger->warning('Najisto curl request failed', [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error,
            ]);

            return null;
        }

        return $result;
    }

    /**
     * Parse listing page HTML and extract business data.
     *
     * @return array<array{url: string|null, detailUrl: string, name: string|null, phone: string|null, email: string|null, address: string|null}>
     */
    private function parseListingPage(string $html): array
    {
        $businesses = [];
        $seenIds = [];

        // Find all business result blocks using class="js_result ... companyItem"
        // Split HTML by companyItem blocks
        $parts = preg_split('/<div[^>]+class="js_result[^"]*companyItem[^"]*"/', $html);

        if ($parts === false || count($parts) < 2) {
            return $this->parseListingPageFallback($html);
        }

        // Skip first part (before first result)
        array_shift($parts);

        foreach ($parts as $blockHtml) {
            // Find the end of this block (next major div or end)
            $endPos = strpos($blockHtml, '<div class="resultsMap');

            if ($endPos !== false) {
                $blockHtml = substr($blockHtml, 0, $endPos);
            }

            $business = [
                'url' => null,
                'detailUrl' => '',
                'name' => null,
                'phone' => null,
                'email' => null,
                'address' => null,
            ];

            // Extract detail URL and business ID: href="https://najisto.centrum.cz/{id}/{slug}/"
            if (preg_match('/href="(https:\/\/najisto\.centrum\.cz\/(\d+)\/([^\/]+)\/)"[^>]*data-hit-pos="link_microsite"/', $blockHtml, $match)) {
                $businessId = $match[2];

                if (isset($seenIds[$businessId])) {
                    continue;
                }

                $seenIds[$businessId] = true;
                $business['detailUrl'] = $match[1];
                $business['name'] = ucwords(str_replace('-', ' ', $match[3]));
            } else {
                continue; // Skip if no detail URL found
            }

            // Extract website URL from external link (data-hit-pos="link_external")
            if (preg_match('/<a[^>]+href="(https?:\/\/[^"]+)"[^>]+data-hit-pos="link_external"/', $blockHtml, $match)) {
                $websiteUrl = html_entity_decode($match[1]);

                if (!str_contains($websiteUrl, 'najisto.centrum.cz') && $this->isValidWebsiteUrl($websiteUrl)) {
                    $business['url'] = $websiteUrl;
                }
            }

            // Extract phone from tel: link
            if (preg_match('/href="tel:([^"]+)"/', $blockHtml, $match)) {
                $business['phone'] = trim(html_entity_decode($match[1]));
            }

            // Extract address from addressStreet span
            if (preg_match('/class="[^"]*addressStreet[^"]*"[^>]*>([^<]+)/i', $blockHtml, $match)) {
                $business['address'] = trim(html_entity_decode(str_replace('&nbsp;', ' ', $match[1])));
            }

            $businesses[] = $business;
        }

        return $businesses;
    }

    /**
     * Fallback parsing when block pattern doesn't match.
     *
     * @return array<array{url: string|null, detailUrl: string, name: string|null, phone: string|null, email: string|null, address: string|null}>
     */
    private function parseListingPageFallback(string $html): array
    {
        $businesses = [];
        $seenIds = [];

        // First, extract all external website links with their nearby business IDs
        // Pattern: business link followed by website link within same block
        $externalLinks = [];

        // Find all external links (data-hit-pos="link_external")
        if (preg_match_all('/<a[^>]+href="(https?:\/\/[^"]+)"[^>]+data-hit-pos="link_external"/', $html, $extMatches, PREG_SET_ORDER)) {
            foreach ($extMatches as $match) {
                $url = html_entity_decode($match[1]);

                if (!str_contains($url, 'najisto.centrum.cz') && $this->isValidWebsiteUrl($url)) {
                    $externalLinks[] = $url;
                }
            }
        }

        // Extract business detail URLs
        $urlPattern = '/href="https:\/\/najisto\.centrum\.cz\/(\d+)\/([^\/]+)\/"[^>]*data-hit-pos="link_microsite"/';
        $extIndex = 0;

        if (preg_match_all($urlPattern, $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $businessId = $match[1];

                if (isset($seenIds[$businessId])) {
                    continue;
                }

                $seenIds[$businessId] = true;

                // Try to match external URL (they appear in order)
                $websiteUrl = null;

                if (isset($externalLinks[$extIndex])) {
                    $websiteUrl = $externalLinks[$extIndex];
                    $extIndex++;
                }

                $businesses[] = [
                    'url' => $websiteUrl,
                    'detailUrl' => self::BASE_URL . '/' . $businessId . '/' . $match[2] . '/',
                    'name' => ucwords(str_replace('-', ' ', $match[2])),
                    'phone' => null,
                    'email' => null,
                    'address' => null,
                ];
            }
        }

        return $businesses;
    }

    /**
     * Create a DiscoveryResult from listing data.
     *
     * @param array{url: string|null, detailUrl: string, name: string|null, phone: string|null, email: string|null, address: string|null} $businessData
     */
    private function createResultFromListing(array $businessData, string $queryContext): ?DiscoveryResult
    {
        // If no website URL, we need to fetch the detail page
        if ($businessData['url'] === null) {
            $this->rateLimit();
            $detailData = $this->fetchBusinessDetail($businessData['detailUrl']);

            if ($detailData !== null) {
                $businessData = array_merge($businessData, $detailData);
            }
        }

        $hasOwnWebsite = $businessData['url'] !== null;
        $leadUrl = $hasOwnWebsite ? $businessData['url'] : $businessData['detailUrl'];

        $metadata = [
            'has_own_website' => $hasOwnWebsite,
            'business_name' => $businessData['name'],
            'catalog_profile_url' => $businessData['detailUrl'],
            'phone' => $businessData['phone'],
            'email' => $businessData['email'],
            'address' => $businessData['address'],
            'query' => $queryContext,
            'source_type' => $hasOwnWebsite ? 'najisto_direct' : 'najisto_catalog',
        ];

        return new DiscoveryResult($leadUrl, $metadata);
    }

    /**
     * Fetch business detail page and extract additional data.
     *
     * @return array{url: string|null, name: string|null, phone: string|null, email: string|null, address: string|null}|null
     */
    private function fetchBusinessDetail(string $detailUrl): ?array
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($detailUrl);

            if ($html !== null) {
                return $this->parseDetailPage($html);
            }

            sleep($attempt);
        }

        return null;
    }

    /**
     * Parse business detail page.
     *
     * @return array{url: string|null, name: string|null, phone: string|null, email: string|null, address: string|null}
     */
    private function parseDetailPage(string $html): array
    {
        $data = [
            'url' => null,
            'name' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
        ];

        // Extract name from itemprop="name"
        if (preg_match('/itemprop="name"[^>]*>([^<]+)/i', $html, $match)) {
            $data['name'] = trim(html_entity_decode($match[1]));
        } elseif (preg_match('/<h1[^>]*class="[^"]*companyTitle[^"]*"[^>]*>.*?<span[^>]*class="[^"]*companyName[^"]*"[^>]*>([^<]+)/is', $html, $match)) {
            $data['name'] = trim(html_entity_decode($match[1]));
        }

        // Extract website from contactWeb link
        if (preg_match('/<a[^>]+class="[^"]*contactWeb[^"]*"[^>]+href="([^"]+)"/', $html, $match)) {
            $websiteUrl = html_entity_decode($match[1]);

            if (!str_contains($websiteUrl, 'najisto.centrum.cz') && $this->isValidWebsiteUrl($websiteUrl)) {
                $data['url'] = $websiteUrl;
            }
        }

        // Extract phone from itemprop="telephone"
        if (preg_match('/itemprop="telephone"[^>]*>([^<]+)/i', $html, $match)) {
            $data['phone'] = trim(html_entity_decode($match[1]));
        } elseif (preg_match('/href="tel:([^"]+)"/', $html, $match)) {
            $data['phone'] = trim(html_entity_decode($match[1]));
        }

        // Extract email from contactEmail link
        if (preg_match('/<a[^>]+class="[^"]*contactEmail[^"]*"[^>]+href="mailto:([^"]+)"/', $html, $match)) {
            $data['email'] = trim(html_entity_decode($match[1]));
        } elseif (preg_match('/href="mailto:([^"]+)"/', $html, $match)) {
            $data['email'] = trim(html_entity_decode($match[1]));
        }

        // Extract address from schema.org markup
        $addressParts = [];

        if (preg_match('/itemprop="streetAddress"[^>]*>([^<]+)/i', $html, $match)) {
            $addressParts[] = trim(html_entity_decode($match[1]));
        }

        if (preg_match('/itemprop="addressLocality"[^>]*>([^<]+)/i', $html, $match)) {
            $addressParts[] = trim(html_entity_decode($match[1]));
        }

        if (!empty($addressParts)) {
            $data['address'] = implode(', ', $addressParts);
        }

        return $data;
    }
}
