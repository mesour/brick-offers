<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovery source for skoly.jmk.cz - Jihomoravský kraj school catalog.
 *
 * This is a category-based source. It browses school types and districts:
 * - School types: A (MŠ), B (ZŠ), C (SŠ), E (VOŠ), F (ZUŠ), G (DDM), K (PPP)
 * - Districts: Blansko, Břeclav, Brno-město, Brno-venkov, Hodonín, Vyškov, Znojmo
 *
 * URL patterns:
 * - List: /rodice-a-verejnost/katalog-skol
 * - Pagination: ?page=2
 * - Filtering: ?filter_form[schoolType]=A&filter_form[district]=Brno-město
 * - School detail: /rodice-a-verejnost/katalog-skol/{slug}~sch{ID}
 */
#[AutoconfigureTag('app.discovery_source')]
class JmkKatalogSkolDiscoverySource extends AbstractDiscoverySource
{
    private const BASE_URL = 'https://skoly.jmk.cz';
    private const CATALOG_PATH = '/rodice-a-verejnost/katalog-skol';
    private const RESULTS_PER_PAGE = 10;
    private const MAX_RETRIES = 3;

    /**
     * School type choices with labels for admin forms.
     * Key = form value, Value = Czech label.
     */
    public const SCHOOL_TYPES = [
        'A' => 'Mateřská škola',
        'B' => 'Základní škola',
        'C' => 'Střední škola',
        'E' => 'Vyšší odborná škola',
        'F' => 'Základní umělecká škola',
        'G' => 'Dům dětí a mládeže',
        'K' => 'Psychologická poradna',
    ];

    /**
     * District choices with labels for admin forms.
     * Key = form value, Value = Czech label.
     */
    public const DISTRICTS = [
        'Blansko' => 'Blansko',
        'Břeclav' => 'Břeclav',
        'Brno-město' => 'Brno-město',
        'Brno-venkov' => 'Brno-venkov',
        'Hodonín' => 'Hodonín',
        'Vyškov' => 'Vyškov',
        'Znojmo' => 'Znojmo',
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 1500;
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::JMK_KATALOG_SKOL;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::JMK_KATALOG_SKOL;
    }

    /**
     * Discover schools from skoly.jmk.cz.
     *
     * For this source, $query is ignored. Use discoverWithSettings() instead.
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        return $this->discoverWithSettings([
            'schoolTypes' => ['A', 'B', 'C'], // Default: MŠ, ZŠ, SŠ
        ], $limit);
    }

    /**
     * Discover schools with specific settings.
     *
     * @param array{schoolTypes?: array<string>, districts?: array<string>} $settings
     * @return array<DiscoveryResult>
     */
    public function discoverWithSettings(array $settings, int $limit = 50): array
    {
        $schoolTypes = $settings['schoolTypes'] ?? ['A', 'B', 'C'];
        $districts = $settings['districts'] ?? [];

        if (empty($schoolTypes)) {
            $this->logger->warning('JmkKatalogSkol: No school types specified');

            return [];
        }

        $results = [];

        // If districts specified, iterate over type+district combinations
        if (!empty($districts)) {
            $combinations = [];
            foreach ($schoolTypes as $type) {
                foreach ($districts as $district) {
                    $combinations[] = ['type' => $type, 'district' => $district];
                }
            }
            $limitPerCombo = (int) ceil($limit / count($combinations));

            foreach ($combinations as $combo) {
                $comboResults = $this->discoverCategory($combo['type'], $combo['district'], $limitPerCombo);
                $results = array_merge($results, $comboResults);

                if (count($results) >= $limit) {
                    break;
                }

                $this->rateLimit();
            }
        } else {
            // No districts - just iterate over types
            $limitPerType = (int) ceil($limit / count($schoolTypes));

            foreach ($schoolTypes as $schoolType) {
                $typeResults = $this->discoverCategory($schoolType, null, $limitPerType);
                $results = array_merge($results, $typeResults);

                if (count($results) >= $limit) {
                    break;
                }

                $this->rateLimit();
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Discover schools in a specific category (and optionally district).
     *
     * @return array<DiscoveryResult>
     */
    private function discoverCategory(string $schoolType, ?string $district, int $limit): array
    {
        $results = [];
        $page = 1;
        $maxPages = (int) ceil($limit / self::RESULTS_PER_PAGE);

        for ($i = 0; $i < $maxPages && count($results) < $limit; $i++) {
            $pageResults = $this->fetchPage($schoolType, $district, $page);

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
     * Fetch a page of school listings.
     *
     * @return array<DiscoveryResult>
     */
    private function fetchPage(string $schoolType, ?string $district, int $page): array
    {
        $queryParams = [];

        if ($page > 1) {
            $queryParams['page'] = $page;
        }

        // Add filter parameters
        $queryParams['filter_form[schoolType]'] = $schoolType;

        if ($district !== null) {
            $queryParams['filter_form[district]'] = $district;
        }

        $url = self::BASE_URL . self::CATALOG_PATH;
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($url);

            if ($html !== null) {
                return $this->parseListingPage($html, $schoolType, $district);
            }

            $this->logger->warning('JmkKatalogSkol request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 2);
        }

        $this->logger->error('JmkKatalogSkol: Max retries exceeded', [
            'schoolType' => $schoolType,
            'district' => $district,
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
            ],
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            $this->logger->warning('JmkKatalogSkol curl request failed', [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error,
            ]);

            return null;
        }

        return $result;
    }

    /**
     * Parse school listings from HTML page.
     *
     * @return array<DiscoveryResult>
     */
    private function parseListingPage(string $html, string $schoolType, ?string $district): array
    {
        $results = [];

        // Pattern to match school detail links: /rodice-a-verejnost/katalog-skol/{slug}~sch{ID}
        $detailPattern = '/<a[^>]+href="(\/rodice-a-verejnost\/katalog-skol\/([^"~]+)~sch(\d+))"[^>]*>([^<]+)<\/a>/i';

        if (preg_match_all($detailPattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $detailPath = $match[1];
                $schoolSlug = $match[2];
                $schoolId = $match[3];
                $schoolName = trim(html_entity_decode(strip_tags($match[4])));

                // Skip empty names
                if (empty($schoolName)) {
                    continue;
                }

                $detailUrl = self::BASE_URL . $detailPath;

                $results[] = new DiscoveryResult($detailUrl, [
                    'business_name' => $schoolName,
                    'school_id' => $schoolId,
                    'school_slug' => $schoolSlug,
                    'school_type' => $schoolType,
                    'district' => $district,
                    'source_type' => 'jmk_katalog_skol_catalog',
                    'needs_website_extraction' => true,
                ]);
            }
        }

        // Deduplicate by school ID
        $seen = [];
        $results = array_filter($results, function (DiscoveryResult $result) use (&$seen) {
            $id = $result->metadata['school_id'] ?? $result->url;
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;

            return true;
        });

        return array_values($results);
    }

    /**
     * Extract website URL from school detail page.
     */
    public function extractWebsiteFromDetailPage(string $detailUrl): ?string
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($detailUrl);

            if ($html !== null) {
                // Look for external website links - typically marked with icon-ext or target="_blank"
                $patterns = [
                    // Links with external icon
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?(?:skoly\.jmk\.cz|jmk\.cz|facebook\.com|instagram\.com|maps\.google))[^"]+)"[^>]*class="[^"]*"[^>]*>[^<]*<span[^>]*class="[^"]*icon-ext[^"]*"[^>]*>/i',
                    // Links with target="_blank" that are not social media
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?(?:skoly\.jmk\.cz|jmk\.cz|facebook\.com|instagram\.com|maps\.google|youtube\.com))[^"]+)"[^>]*target="_blank"[^>]*>/i',
                    // Any .cz domain link that's not the catalog itself
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?(?:skoly\.jmk\.cz|jmk\.cz))[^"]+\.cz[^"]*)"[^>]*>/i',
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

        $this->logger->error('Failed to extract website from JmkKatalogSkol detail', [
            'detailUrl' => $detailUrl,
        ]);

        return null;
    }

    /**
     * Override to add skoly.jmk.cz to skip domains.
     */
    protected function isValidWebsiteUrl(string $url): bool
    {
        if (!parent::isValidWebsiteUrl($url)) {
            return false;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Skip JMK catalog URLs
        if (str_ends_with($host, 'jmk.cz')) {
            return false;
        }

        return true;
    }
}
