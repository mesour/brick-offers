<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovery source for seznamskol.eu - comprehensive Czech school directory.
 *
 * URL patterns:
 * - School type listing: /typ/{school-type}/?kraj={region}&p={page}
 * - School detail: /skola/{ID}-{slug}/
 *
 * School types (URL slugs):
 * - materska-skola (kindergartens)
 * - zakladni-skola (primary schools)
 * - stredni-skola (secondary schools)
 * - vysoka-skola (universities)
 * - jazykova-skola (language schools)
 * - umelecka-skola (art schools)
 *
 * Regions (URL params):
 * - praha, stredocesky, jihocesky, plzensky, karlovarsky, ustecky,
 * - liberecky, kralovehradecky, pardubicky, vysocina, jihomoravsky,
 * - olomoucky, zlinsky, moravskoslezsky
 */
#[AutoconfigureTag('app.discovery_source')]
class SeznamSkolEuDiscoverySource extends AbstractDiscoverySource
{
    private const BASE_URL = 'https://www.seznamskol.eu';
    private const RESULTS_PER_PAGE = 20;
    private const MAX_RETRIES = 3;

    /**
     * School type choices with labels for admin forms.
     * Key = URL slug, Value = Czech label.
     */
    public const SCHOOL_TYPES = [
        'materska-skola' => 'Mateřská škola',
        'zakladni-skola' => 'Základní škola',
        'stredni-skola' => 'Střední škola',
        'vysoka-skola' => 'Vysoká škola',
        'jazykova-skola' => 'Jazyková škola',
        'umelecka-skola' => 'Umělecká škola',
    ];

    /**
     * Region choices with labels for admin forms.
     * Key = URL param, Value = Czech label.
     */
    public const REGIONS = [
        'praha' => 'Praha',
        'stredocesky' => 'Středočeský kraj',
        'jihocesky' => 'Jihočeský kraj',
        'plzensky' => 'Plzeňský kraj',
        'karlovarsky' => 'Karlovarský kraj',
        'ustecky' => 'Ústecký kraj',
        'liberecky' => 'Liberecký kraj',
        'kralovehradecky' => 'Královéhradecký kraj',
        'pardubicky' => 'Pardubický kraj',
        'vysocina' => 'Kraj Vysočina',
        'jihomoravsky' => 'Jihomoravský kraj',
        'olomoucky' => 'Olomoucký kraj',
        'zlinsky' => 'Zlínský kraj',
        'moravskoslezsky' => 'Moravskoslezský kraj',
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
        return $source === LeadSource::SEZNAM_SKOL_EU;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::SEZNAM_SKOL_EU;
    }

    /**
     * Discover schools from seznamskol.eu.
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        return $this->discoverWithSettings([
            'schoolTypes' => ['zakladni-skola'],
            'regions' => [],
        ], $limit);
    }

    /**
     * Discover schools with specific settings.
     *
     * @param array{schoolTypes?: array<string>, regions?: array<string>} $settings
     * @return array<DiscoveryResult>
     */
    public function discoverWithSettings(array $settings, int $limit = 50): array
    {
        $schoolTypes = $settings['schoolTypes'] ?? ['zakladni-skola'];
        $regions = $settings['regions'] ?? [];

        if (empty($schoolTypes)) {
            $this->logger->warning('SeznamSkolEu: No school types specified');

            return [];
        }

        $results = [];
        $limitPerType = (int) ceil($limit / count($schoolTypes));

        foreach ($schoolTypes as $schoolType) {
            if (!isset(self::SCHOOL_TYPES[$schoolType])) {
                $this->logger->warning('SeznamSkolEu: Unknown school type', ['type' => $schoolType]);

                continue;
            }

            $typeResults = $this->discoverSchoolType($schoolType, $regions, $limitPerType);
            $results = array_merge($results, $typeResults);

            if (count($results) >= $limit) {
                break;
            }

            $this->rateLimit();
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Discover schools of a specific type.
     *
     * @param array<string> $regions
     * @return array<DiscoveryResult>
     */
    private function discoverSchoolType(string $schoolType, array $regions, int $limit): array
    {
        // If no regions specified, fetch without region filter
        if (empty($regions)) {
            return $this->fetchSchoolsForRegion($schoolType, null, $limit);
        }

        $results = [];
        $limitPerRegion = (int) ceil($limit / count($regions));

        foreach ($regions as $region) {
            if (!isset(self::REGIONS[$region])) {
                continue;
            }

            $regionResults = $this->fetchSchoolsForRegion($schoolType, $region, $limitPerRegion);
            $results = array_merge($results, $regionResults);

            if (count($results) >= $limit) {
                break;
            }

            $this->rateLimit();
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Fetch schools for a specific type and region.
     *
     * @return array<DiscoveryResult>
     */
    private function fetchSchoolsForRegion(string $schoolType, ?string $region, int $limit): array
    {
        $results = [];
        $maxPages = (int) ceil($limit / self::RESULTS_PER_PAGE);

        for ($page = 1; $page <= $maxPages && count($results) < $limit; $page++) {
            $pageResults = $this->fetchPage($schoolType, $region, $page);

            if (empty($pageResults)) {
                break;
            }

            $results = array_merge($results, $pageResults);

            if ($page < $maxPages) {
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
    private function fetchPage(string $schoolType, ?string $region, int $page): array
    {
        $url = sprintf('%s/typ/%s/', self::BASE_URL, $schoolType);

        $queryParams = [];
        if ($region !== null) {
            $queryParams['kraj'] = $region;
        }
        if ($page > 1) {
            $queryParams['p'] = $page;
        }

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $this->logger->debug('SeznamSkolEu: Fetching page', ['url' => $url, 'page' => $page]);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($url);

            if ($html !== null) {
                return $this->parseListingPage($html, $schoolType);
            }

            $this->logger->warning('SeznamSkolEu request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 2);
        }

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
            \CURLOPT_ENCODING => '',
            \CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: cs,sk;q=0.8,en-US;q=0.5,en;q=0.3',
                'Accept-Encoding: gzip, deflate, br',
                'DNT: 1',
                'Connection: keep-alive',
            ],
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!\is_string($result) || $httpCode !== 200) {
            $this->logger->warning('SeznamSkolEu curl request failed', [
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
    private function parseListingPage(string $html, string $schoolType): array
    {
        $results = [];

        // Pattern to match school links: /skola/{ID}-{slug}/
        // School listings have links like /skola/12345-nazev-skoly/
        $pattern = '/<a[^>]+href="(\/skola\/(\d+)-([^"\/]+)\/?)"[^>]*>([^<]*(?:<[^>]+>[^<]*)*)<\/a>/i';

        if (preg_match_all($pattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $detailPath = $match[1];
                $schoolId = $match[2];
                $schoolSlug = $match[3];
                $schoolNameRaw = $match[4];

                // Extract text from potentially nested HTML
                $schoolName = trim(html_entity_decode(strip_tags($schoolNameRaw)));

                // Skip empty names or navigation links
                if (empty($schoolName) || strlen($schoolName) < 3) {
                    continue;
                }

                // Skip if slug contains query parameters
                if (str_contains($schoolSlug, '?')) {
                    continue;
                }

                $detailUrl = self::BASE_URL . $detailPath;

                $results[] = new DiscoveryResult($detailUrl, [
                    'business_name' => $schoolName,
                    'school_id' => $schoolId,
                    'school_slug' => $schoolSlug,
                    'school_type' => $schoolType,
                    'school_type_label' => self::SCHOOL_TYPES[$schoolType] ?? $schoolType,
                    'source_type' => 'seznam_skol_eu_catalog',
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

        $this->logger->debug('SeznamSkolEu: Parsed page', [
            'schoolType' => $schoolType,
            'resultsFound' => count($results),
        ]);

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
                // Look for website link in contact section
                $patterns = [
                    // Pattern 1: "Web:" label followed by link (most reliable)
                    '/Web:\s*<\/?\w*[^>]*>\s*<a[^>]+href="(https?:\/\/[^"]+)"[^>]*>/i',
                    // Pattern 2: Link with explicit "web" or "www" text
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?seznamskol\.eu)[^"]+)"[^>]*>'
                    . '\s*(?:www\.|web\s|http)[^<]*<\/a>/i',
                    // Pattern 3: Link containing school-like domain (.cz/.sk) with target="_blank"
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?(?:seznamskol\.eu|facebook\.com'
                    . '|instagram\.com|twitter\.com|linkedin\.com|youtube\.com))[^"]+\.(?:cz|sk)[^"]*)"'
                    . '[^>]*target="_blank"[^>]*>/i',
                    // Pattern 4: Any .cz external link (fallback)
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?seznamskol)[^"]+\.cz[^"]*)"[^>]*>/i',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $html, $match)) {
                        $url = html_entity_decode($match[1]);

                        if ($this->isValidWebsiteUrl($url)) {
                            $this->logger->debug('SeznamSkolEu: Extracted website', [
                                'detailUrl' => $detailUrl,
                                'website' => $url,
                            ]);

                            return $url;
                        }
                    }
                }

                $this->logger->debug('SeznamSkolEu: No website found on detail page', [
                    'detailUrl' => $detailUrl,
                ]);

                return null;
            }

            sleep($attempt * 2);
        }

        return null;
    }

    /**
     * Override to add seznamskol.eu to skip domains.
     */
    protected function isValidWebsiteUrl(string $url): bool
    {
        if (!parent::isValidWebsiteUrl($url)) {
            return false;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Skip seznamskol.eu URLs
        if (str_ends_with($host, 'seznamskol.eu') || str_ends_with($host, 'seznamskol.cz')) {
            return false;
        }

        return true;
    }
}
