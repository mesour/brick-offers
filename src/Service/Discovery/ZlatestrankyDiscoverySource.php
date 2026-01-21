<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.discovery_source')]
class ZlatestrankyDiscoverySource extends AbstractDiscoverySource
{
    private const BASE_URL = 'https://www.zlatestranky.cz';
    private const RESULTS_PER_PAGE = 30;
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
        return $source === LeadSource::ZLATESTRANKY;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::ZLATESTRANKY;
    }

    /**
     * Discover businesses from ZlatéStránky.cz.
     *
     * Query formats:
     * - "{search}" - text search (e.g., "autoservis")
     * - "{search}:{location}" - search with location (e.g., "autoservis:praha")
     * - "rubrika:{category}" - browse category (e.g., "rubrika:Autoservis, opravy silničních vozidel")
     * - "kraj:{region}" - browse by region (e.g., "kraj:Jihočeský kraj")
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        $parsedQuery = $this->parseQuery($query);
        $results = [];
        $page = 1;
        $maxPages = (int) ceil($limit / self::RESULTS_PER_PAGE);
        $queryContext = $parsedQuery['search'] ?? $parsedQuery['category'] ?? $parsedQuery['region'] ?? 'unknown';

        for ($i = 0; $i < $maxPages && count($results) < $limit; $i++) {
            $pageResults = $this->fetchPage($parsedQuery, $page);

            if (empty($pageResults)) {
                break;
            }

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
     * @return array{search?: string, location?: string, category?: string, region?: string}
     */
    private function parseQuery(string $query): array
    {
        $result = [];

        // Check for category format: rubrika:{category}
        if (str_starts_with($query, 'rubrika:')) {
            $result['category'] = trim(substr($query, 8));

            return $result;
        }

        // Check for region format: kraj:{region}
        if (str_starts_with($query, 'kraj:')) {
            $result['region'] = trim(substr($query, 5));

            return $result;
        }

        // Otherwise treat as search query, optionally with location
        // Format: {search} or {search}:{location}
        $parts = explode(':', $query, 2);
        $result['search'] = trim($parts[0]);

        if (isset($parts[1]) && !empty($parts[1])) {
            $result['location'] = trim($parts[1]);
        }

        return $result;
    }

    /**
     * Fetch a listing page and extract business data.
     *
     * @param array{search?: string, location?: string, category?: string, region?: string} $parsedQuery
     *
     * @return array<array{url: string|null, detailUrl: string, name: string|null, phone: string|null, address: string|null, rating: string|null}>
     */
    private function fetchPage(array $parsedQuery, int $page): array
    {
        $url = $this->buildListingUrl($parsedQuery, $page);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($url);

            if ($html !== null) {
                return $this->parseListingPage($html);
            }

            $this->logger->warning('Zlatestranky request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 2);
        }

        $this->logger->error('Zlatestranky: Max retries exceeded for listing page', [
            'query' => $parsedQuery,
            'page' => $page,
        ]);

        return [];
    }

    /**
     * Build listing URL based on query type.
     *
     * @param array{search?: string, location?: string, category?: string, region?: string} $parsedQuery
     */
    private function buildListingUrl(array $parsedQuery, int $page): string
    {
        $queryParams = [];

        // Search query: /firmy/hledani/{query}
        if (isset($parsedQuery['search'])) {
            $search = $parsedQuery['search'];

            // If location is provided, append it to search
            if (isset($parsedQuery['location'])) {
                $search .= ' ' . $parsedQuery['location'];
            }

            $url = self::BASE_URL . '/firmy/hledani/' . urlencode($search);

            if ($page > 1) {
                $queryParams['page'] = $page;
                $url .= '?' . http_build_query($queryParams);
            }

            return $url;
        }

        // Category URL: /firmy/rubrika/{category}
        if (isset($parsedQuery['category'])) {
            $url = self::BASE_URL . '/firmy/rubrika/' . urlencode($parsedQuery['category']);

            if ($page > 1) {
                $queryParams['page'] = $page;
                $url .= '?' . http_build_query($queryParams);
            }

            return $url;
        }

        // Region URL: /firmy/kraj/{region}
        if (isset($parsedQuery['region'])) {
            $url = self::BASE_URL . '/firmy/kraj/' . urlencode($parsedQuery['region']);

            if ($page > 1) {
                $queryParams['page'] = $page;
                $url .= '?' . http_build_query($queryParams);
            }

            return $url;
        }

        return self::BASE_URL . '/firmy';
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
            $this->logger->warning('Zlatestranky curl request failed', [
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
     * @return array<array{url: string|null, detailUrl: string, name: string|null, phone: string|null, address: string|null, rating: string|null}>
     */
    private function parseListingPage(string $html): array
    {
        $businesses = [];
        $seenIds = [];

        // Split HTML by list-listing items
        // Pattern: <li class="list-listing" id="listing-{id}">
        $parts = preg_split('/<li[^>]+class="list-listing"[^>]+id="listing-([^"]+)"[^>]*>/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false || count($parts) < 3) {
            $this->logger->debug('Zlatestranky: No listings found in HTML');

            return [];
        }

        // Process pairs: [before, id, content, id, content, ...]
        for ($i = 1; $i < count($parts) - 1; $i += 2) {
            $businessId = $parts[$i];
            $blockHtml = $parts[$i + 1] ?? '';

            if (empty($businessId) || isset($seenIds[$businessId])) {
                continue;
            }

            $seenIds[$businessId] = true;

            // Find the end of this listing block
            $endPos = strpos($blockHtml, '</li>');

            if ($endPos !== false) {
                $blockHtml = substr($blockHtml, 0, $endPos);
            }

            $business = $this->parseBusinessBlock($businessId, $blockHtml);

            if ($business !== null) {
                $businesses[] = $business;
            }
        }

        return $businesses;
    }

    /**
     * Parse a single business listing block.
     *
     * @return array{url: string|null, detailUrl: string, name: string|null, phone: string|null, address: string|null, rating: string|null}|null
     */
    private function parseBusinessBlock(string $businessId, string $blockHtml): ?array
    {
        $business = [
            'url' => null,
            'detailUrl' => self::BASE_URL . '/profil/' . $businessId,
            'name' => null,
            'phone' => null,
            'address' => null,
            'rating' => null,
        ];

        // Extract business name from <h3><a href="/profil/...">Name</a></h3>
        if (preg_match('/<h3[^>]*>\s*<a[^>]+href="\/profil\/[^"]+">([^<]+)<\/a>/i', $blockHtml, $match)) {
            $business['name'] = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Extract website URL from Web button: data-ta="LinkClick" ... <i class="fa fa-globe"></i>
        // Pattern: <a class="btn btn-primary btn-outline t-c" href="{url}" data-ta="LinkClick" ... rel="nofollow"><i class="fa fa-globe"></i> Web</a>
        if (preg_match('/<a[^>]+href="(https?:\/\/[^"]+)"[^>]+data-ta="LinkClick"[^>]*>.*?<i[^>]+class="[^"]*fa-globe[^"]*"[^>]*>/is', $blockHtml, $match)) {
            $websiteUrl = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (!str_contains($websiteUrl, 'zlatestranky.cz') && $this->isValidWebsiteUrl($websiteUrl)) {
                $business['url'] = $websiteUrl;
            }
        }

        // Extract phone from button with fa-phone icon
        // Pattern: <button ...><i class="fa fa-phone"></i> +420 ...</button>
        if (preg_match('/<button[^>]*>.*?<i[^>]+class="[^"]*fa-phone[^"]*"[^>]*><\/i>\s*([^<]+)/is', $blockHtml, $match)) {
            $phone = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (!empty($phone)) {
                $business['phone'] = $phone;
            }
        }

        // Extract address from icon-list with fa-map-marker
        // Pattern: <li><i class="fa fa-map-marker"></i> Address ... </li>
        if (preg_match('/<li[^>]*>\s*<i[^>]+class="[^"]*fa-map-marker[^"]*"[^>]*><\/i>\s*([^<]+)/i', $blockHtml, $match)) {
            $address = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (!empty($address)) {
                $business['address'] = $address;
            }
        }

        // Extract rating from oblibometr
        if (preg_match('/<span[^>]+class="[^"]*value[^"]*"[^>]*>(\d+)<\/span>/i', $blockHtml, $match)) {
            $business['rating'] = $match[1];
        }

        // Only return if we have at least the name
        if ($business['name'] === null) {
            return null;
        }

        return $business;
    }

    /**
     * Create a DiscoveryResult from listing data.
     *
     * @param array{url: string|null, detailUrl: string, name: string|null, phone: string|null, address: string|null, rating: string|null} $businessData
     */
    private function createResultFromListing(array $businessData, string $queryContext): ?DiscoveryResult
    {
        // If no website URL, we need to fetch the detail page
        if ($businessData['url'] === null) {
            $this->rateLimit();
            $detailData = $this->fetchBusinessDetail($businessData['detailUrl']);

            if ($detailData !== null && $detailData['url'] !== null) {
                $businessData['url'] = $detailData['url'];

                // Merge other data if not already present
                if ($businessData['phone'] === null && $detailData['phone'] !== null) {
                    $businessData['phone'] = $detailData['phone'];
                }

                if ($businessData['address'] === null && $detailData['address'] !== null) {
                    $businessData['address'] = $detailData['address'];
                }
            }
        }

        $hasOwnWebsite = $businessData['url'] !== null;
        $leadUrl = $hasOwnWebsite ? $businessData['url'] : $businessData['detailUrl'];

        $metadata = [
            'has_own_website' => $hasOwnWebsite,
            'business_name' => $businessData['name'],
            'catalog_profile_url' => $businessData['detailUrl'],
            'phone' => $businessData['phone'],
            'address' => $businessData['address'],
            'rating' => $businessData['rating'],
            'query' => $queryContext,
            'source_type' => $hasOwnWebsite ? 'zlatestranky_direct' : 'zlatestranky_catalog',
        ];

        return new DiscoveryResult($leadUrl, $metadata);
    }

    /**
     * Fetch business detail page and extract additional data.
     *
     * @return array{url: string|null, name: string|null, phone: string|null, address: string|null}|null
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
     * @return array{url: string|null, name: string|null, phone: string|null, address: string|null}
     */
    private function parseDetailPage(string $html): array
    {
        $data = [
            'url' => null,
            'name' => null,
            'phone' => null,
            'address' => null,
        ];

        // Extract name from itemprop="name"
        if (preg_match('/itemprop="name"[^>]*>([^<]+)/i', $html, $match)) {
            $data['name'] = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Extract website from itemprop="url" link
        // Pattern: <a href="{url}" ... itemprop="url" rel="nofollow">
        if (preg_match('/<a[^>]+href="(https?:\/\/[^"]+)"[^>]+itemprop="url"/i', $html, $match)) {
            $websiteUrl = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (!str_contains($websiteUrl, 'zlatestranky.cz') && $this->isValidWebsiteUrl($websiteUrl)) {
                $data['url'] = $websiteUrl;
            }
        } elseif (preg_match('/itemprop="url"[^>]+href="(https?:\/\/[^"]+)"/i', $html, $match)) {
            $websiteUrl = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (!str_contains($websiteUrl, 'zlatestranky.cz') && $this->isValidWebsiteUrl($websiteUrl)) {
                $data['url'] = $websiteUrl;
            }
        }

        // Extract phone from itemprop="telephone"
        if (preg_match('/itemprop="telephone"[^>]*>([^<]+)/i', $html, $match)) {
            $data['phone'] = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Extract address from icon-list with fa-map-marker
        if (preg_match('/<li[^>]*>\s*<i[^>]+class="[^"]*fa-map-marker[^"]*"[^>]*><\/i>\s*([^<]+)/i', $html, $match)) {
            $data['address'] = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $data;
    }
}
