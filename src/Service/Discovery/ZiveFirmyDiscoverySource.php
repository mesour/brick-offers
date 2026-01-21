<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.discovery_source')]
class ZiveFirmyDiscoverySource extends AbstractDiscoverySource
{
    private const BASE_URL = 'https://www.zivefirmy.cz';
    private const RESULTS_PER_PAGE = 30;
    private const MAX_RETRIES = 3;

    /**
     * Category slug to category code mapping.
     * Found at: https://www.zivefirmy.cz/
     * URL format: /{slug}_t{code}/
     */
    private const CATEGORIES = [
        'auto-moto' => 897,
        'doprava-dopravni-technika' => 898,
        'elektro-foto' => 899,
        'kancelar-telekomunikace-potreby' => 900,
        'nabytek-interiery' => 901,
        'potraviny' => 902,
        'stavebnictvi' => 903,
        'vypocetni-technika-internet' => 904,
        'sluzby-obchod-prodej' => 905,
        'urady-sprava' => 906,
        'zdravotnictvi-zdravotni-sluzby-a-technika' => 907,
        'zahrada-zemedelstvi-zvirata' => 908,
        'energetika-topeni' => 909,
        'finance-ekonomika-pravo' => 910,
        'zabava-kultura' => 911,
        'bezpecnost' => 912,
        'textil-obuv-detske-zbozi' => 913,
        'drogerie-barvy-chemie' => 914,
        'tisk-knihy' => 915,
        'vzdelani-jazyky' => 916,
        'klenoty-bizuterie-starozitnosti' => 917,
        'technicke-testy-mereni' => 918,
        'drevo-plasty-sklo' => 919,
        'ekologie-nerosty-obaly' => 920,
        'restaurace-ubytovani' => 921,
        'reality' => 922,
        'voda-vzduchotechnika' => 923,
        'strojirenstvi-kovo' => 1144,
        'bazary' => 1171,
        'reklama-informace' => 1182,
        'cestovni-ruch' => 1315,
        'sport' => 1316,
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 2500; // Conservative rate limiting for bot detection
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::ZIVE_FIRMY;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::ZIVE_FIRMY;
    }

    /**
     * Discover businesses from Živéfirmy.cz.
     *
     * Query formats:
     * - "{category}" - browse category (e.g., "stavebnictvi")
     * - "{category}:loc={id}" - category with location (e.g., "stavebnictvi:loc=10000108")
     * - "q={search}" - text search (e.g., "q=autoservis")
     * - "q={search}:loc={id}" - search with location (e.g., "q=autoservis:loc=10000019")
     *
     * Location IDs (from zivefirmy.cz):
     * - 10000019: Praha, 10000027: Středočeský, 10000035: Jihočeský
     * - 10000043: Plzeňský, 10000051: Karlovarský, 10000060: Ústecký
     * - 10000078: Liberecký, 10000086: Královéhradecký, 10000094: Pardubický
     * - 10000108: Vysočina, 10000116: Jihomoravský, 10000124: Olomoucký
     * - 10000132: Moravskoslezský, 10000141: Zlínský
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        $parsedQuery = $this->parseQuery($query);

        if ($parsedQuery === null) {
            $this->logger->error('ZiveFirmy: Invalid query format', [
                'query' => $query,
                'hint' => 'Use "{category}", "{category}:loc={id}", "q={search}", or "q={search}:loc={id}"',
                'available_categories' => array_keys(self::CATEGORIES),
            ]);

            return [];
        }

        $results = [];
        $page = 1;
        $maxPages = (int) ceil($limit / self::RESULTS_PER_PAGE);
        $queryContext = $parsedQuery['search'] ?? $parsedQuery['category'] ?? 'unknown';

        for ($i = 0; $i < $maxPages && count($results) < $limit; $i++) {
            $pageResults = $this->fetchPage($parsedQuery, $page);

            if (empty($pageResults)) {
                break;
            }

            // Fetch detail pages and extract business info
            foreach ($pageResults as $detailUrl) {
                if (count($results) >= $limit) {
                    break;
                }

                $this->rateLimit();
                $businessResult = $this->fetchBusinessDetail($detailUrl, $queryContext);

                if ($businessResult !== null) {
                    $results[] = $businessResult;
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
     * @return array{category?: string, search?: string, loc?: string}|null
     */
    private function parseQuery(string $query): ?array
    {
        $result = [];

        // Check for search query format: q={search} or q={search}:loc={id}
        if (str_starts_with($query, 'q=')) {
            $queryPart = substr($query, 2);
            $parts = explode(':loc=', $queryPart, 2);
            $result['search'] = trim($parts[0]);

            if (isset($parts[1])) {
                $result['loc'] = trim($parts[1]);
            }

            if (empty($result['search'])) {
                return null;
            }

            return $result;
        }

        // Check for category format: {category} or {category}:loc={id}
        $parts = explode(':loc=', $query, 2);
        $category = trim($parts[0]);

        if (!isset(self::CATEGORIES[$category])) {
            return null;
        }

        $result['category'] = $category;

        if (isset($parts[1])) {
            $result['loc'] = trim($parts[1]);
        }

        return $result;
    }

    /**
     * Fetch a listing page and extract business detail URLs.
     *
     * @param array{category?: string, search?: string, loc?: string} $parsedQuery
     *
     * @return array<string>
     */
    private function fetchPage(array $parsedQuery, int $page): array
    {
        $url = $this->buildListingUrl($parsedQuery, $page);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($url);

            if ($html !== null) {
                return $this->parseListingPage($html);
            }

            $this->logger->warning('ZiveFirmy request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 3); // Exponential backoff
        }

        $this->logger->error('ZiveFirmy: Max retries exceeded for listing page', [
            'query' => $parsedQuery,
            'page' => $page,
        ]);

        return [];
    }

    /**
     * Build listing URL based on query type.
     *
     * @param array{category?: string, search?: string, loc?: string} $parsedQuery
     */
    private function buildListingUrl(array $parsedQuery, int $page): string
    {
        $queryParams = [];

        // Default location: 1 = Česká republika (entire country)
        $queryParams['loc'] = $parsedQuery['loc'] ?? '1';

        // Search query: https://www.zivefirmy.cz/?q={search}&loc={id}&page={n}
        if (isset($parsedQuery['search'])) {
            $queryParams['q'] = $parsedQuery['search'];

            if ($page > 1) {
                $queryParams['page'] = $page;
            }

            return self::BASE_URL . '/?' . http_build_query($queryParams);
        }

        // Category URL: https://www.zivefirmy.cz/{category}_t{code}?loc={id}&page={n}
        $category = $parsedQuery['category'];
        $categoryCode = self::CATEGORIES[$category];
        $url = sprintf('%s/%s_t%d', self::BASE_URL, $category, $categoryCode);

        if ($page > 1) {
            $queryParams['page'] = $page;
        }

        $url .= '?' . http_build_query($queryParams);

        return $url;
    }

    /**
     * Use native curl with browser-like headers to bypass bot detection.
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
        $finalUrl = curl_getinfo($ch, \CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            $this->logger->warning('ZiveFirmy curl request failed', [
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
     * Parse listing page HTML and extract business detail URLs.
     *
     * @return array<string>
     */
    private function parseListingPage(string $html): array
    {
        $detailUrls = [];

        // Extract business detail links from listing
        // Pattern: /{slug}_f{id} with optional ?query or #fragment
        // Examples: /agrikomp-bohemia_f1111550, /a-sport-produkt_f237251?q=sport
        $pattern = '/href="(\/[a-z0-9-]+_f\d+)(?:\?[^"#]*)?(?:#[^"]*)?"/i';

        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[1] as $path) {
                $fullUrl = self::BASE_URL . html_entity_decode($path);

                // Deduplicate
                if (!in_array($fullUrl, $detailUrls, true)) {
                    $detailUrls[] = $fullUrl;
                }
            }
        }

        return $detailUrls;
    }

    /**
     * Fetch business detail page and extract data.
     */
    private function fetchBusinessDetail(string $detailUrl, string $category): ?DiscoveryResult
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($detailUrl);

            if ($html !== null) {
                // Try JSON-LD first (most reliable)
                $result = $this->extractJsonLd($html, $detailUrl, $category);

                if ($result !== null) {
                    return $result;
                }

                // Fallback to HTML parsing
                return $this->extractFromHtml($html, $detailUrl, $category);
            }

            $this->logger->warning('ZiveFirmy detail request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $detailUrl,
            ]);

            sleep($attempt * 2);
        }

        $this->logger->error('ZiveFirmy: Max retries exceeded for detail page', [
            'detailUrl' => $detailUrl,
        ]);

        return null;
    }

    /**
     * Extract business data from JSON-LD structured data.
     */
    private function extractJsonLd(string $html, string $detailUrl, string $category): ?DiscoveryResult
    {
        $pattern = '/<script type="application\/ld\+json">\s*(\{[^<]+\})\s*<\/script>/i';

        if (!preg_match_all($pattern, $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $jsonString) {
            try {
                $data = json_decode($jsonString, true, 512, \JSON_THROW_ON_ERROR);

                // Look for LocalBusiness or Organization type
                $type = $data['@type'] ?? null;

                if (!in_array($type, ['LocalBusiness', 'Organization', 'Store', 'Restaurant'], true)) {
                    continue;
                }

                $businessName = $data['name'] ?? null;

                if ($businessName === null) {
                    continue;
                }

                // Extract website URL
                $websiteUrl = $data['url'] ?? null;

                // Check sameAs array for website
                if (($websiteUrl === null || str_contains($websiteUrl, 'zivefirmy.cz')) && isset($data['sameAs'])) {
                    $sameAs = is_array($data['sameAs']) ? $data['sameAs'] : [$data['sameAs']];

                    foreach ($sameAs as $url) {
                        if (!str_contains($url, 'zivefirmy.cz') && $this->isValidWebsiteUrl($url)) {
                            $websiteUrl = $url;

                            break;
                        }
                    }
                }

                // Check if website is zivefirmy.cz itself or empty
                $hasOwnWebsite = $websiteUrl !== null
                    && !str_contains($websiteUrl, 'zivefirmy.cz')
                    && $this->isValidWebsiteUrl($websiteUrl);

                // Build metadata
                $metadata = $this->buildMetadata($data, $detailUrl, $category, $hasOwnWebsite);

                // Use website URL if available, otherwise use catalog profile URL
                $leadUrl = $hasOwnWebsite ? $websiteUrl : $detailUrl;

                return new DiscoveryResult($leadUrl, $metadata);
            } catch (\JsonException $e) {
                $this->logger->debug('ZiveFirmy: Failed to parse JSON-LD', [
                    'error' => $e->getMessage(),
                    'url' => $detailUrl,
                ]);
            }
        }

        return null;
    }

    /**
     * Fallback: Extract business data from HTML.
     */
    private function extractFromHtml(string $html, string $detailUrl, string $category): ?DiscoveryResult
    {
        $businessName = null;
        $websiteUrl = null;
        $phone = null;
        $email = null;
        $address = null;
        $ico = null;

        // Extract business name from h1
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $match)) {
            $businessName = trim(html_entity_decode($match[1]));
        }

        if ($businessName === null) {
            return null;
        }

        // Extract website URL
        // Pattern: <a href="http..." class="...web..." or data-action="web"
        if (preg_match('/<a[^>]+href="(https?:\/\/[^"]+)"[^>]*(?:class="[^"]*web[^"]*"|data-action="web"|>.*?web)/is', $html, $match)) {
            $url = html_entity_decode($match[1]);

            if (!str_contains($url, 'zivefirmy.cz') && $this->isValidWebsiteUrl($url)) {
                $websiteUrl = $url;
            }
        }

        // Extract phone
        if (preg_match('/(?:tel:|href="tel:)([+0-9\s\-]+)/i', $html, $match)) {
            $phone = trim($match[1]);
        }

        // Extract email
        if (preg_match('/(?:mailto:|href="mailto:)([^"<>\s]+@[^"<>\s]+)/i', $html, $match)) {
            $email = trim($match[1]);
        }

        // Extract ICO
        if (preg_match('/I[ČC]O[:\s]*(\d{8})/i', $html, $match)) {
            $ico = $match[1];
        }

        // Extract address - look for address in structured format
        if (preg_match('/<address[^>]*>(.*?)<\/address>/is', $html, $match)) {
            $address = trim(strip_tags($match[1]));
        }

        $hasOwnWebsite = $websiteUrl !== null;

        $metadata = [
            'has_own_website' => $hasOwnWebsite,
            'business_name' => $businessName,
            'catalog_profile_url' => $detailUrl,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'ico' => $ico,
            'category' => $category,
            'source_type' => $hasOwnWebsite ? 'zive_firmy_direct' : 'zive_firmy_catalog',
        ];

        $leadUrl = $hasOwnWebsite ? $websiteUrl : $detailUrl;

        return new DiscoveryResult($leadUrl, $metadata);
    }

    /**
     * Build metadata array from JSON-LD data.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function buildMetadata(array $data, string $detailUrl, string $category, bool $hasOwnWebsite): array
    {
        $address = null;

        if (isset($data['address'])) {
            $addr = $data['address'];

            if (is_string($addr)) {
                $address = $addr;
            } elseif (is_array($addr)) {
                $parts = array_filter([
                    $addr['streetAddress'] ?? null,
                    $addr['addressLocality'] ?? null,
                    $addr['postalCode'] ?? null,
                ]);
                $address = implode(', ', $parts);
            }
        }

        $phone = $data['telephone'] ?? null;

        if (is_array($phone)) {
            $phone = $phone[0] ?? null;
        }

        return [
            'has_own_website' => $hasOwnWebsite,
            'business_name' => $data['name'] ?? null,
            'catalog_profile_url' => $detailUrl,
            'phone' => $phone,
            'email' => $data['email'] ?? null,
            'address' => $address,
            'ico' => $data['taxID'] ?? $data['vatID'] ?? null,
            'category' => $category,
            'source_type' => $hasOwnWebsite ? 'zive_firmy_direct' : 'zive_firmy_catalog',
        ];
    }
}
