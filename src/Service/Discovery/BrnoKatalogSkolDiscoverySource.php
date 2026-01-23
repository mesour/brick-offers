<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovery source for Brno city school catalog.
 *
 * URL patterns:
 * - Kindergartens: https://zapisdoms.brno.cz/
 * - Primary schools: https://zapisdozs.brno.cz/
 *
 * Note: These sites have SSL certificate issues, so we need to disable verification.
 *
 * School types:
 * - ms: Mateřské školy (kindergartens) - zapisdoms.brno.cz
 * - zs: Základní školy (primary schools) - zapisdozs.brno.cz
 */
#[AutoconfigureTag('app.discovery_source')]
class BrnoKatalogSkolDiscoverySource extends AbstractDiscoverySource
{
    private const URLS = [
        'ms' => 'https://zapisdoms.brno.cz/materske-skoly',
        'zs' => 'https://zapisdozs.brno.cz/zakladni-skoly',
    ];

    private const BASE_URLS = [
        'ms' => 'https://zapisdoms.brno.cz',
        'zs' => 'https://zapisdozs.brno.cz',
    ];

    /**
     * School type choices with labels for admin forms.
     * Key = type code, Value = Czech label.
     */
    public const SCHOOL_TYPES = [
        'ms' => 'Mateřské školy',
        'zs' => 'Základní školy',
    ];

    private const MAX_RETRIES = 3;
    private const RESULTS_PER_PAGE = 20;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 1500;
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::BRNO_KATALOG_SKOL;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::BRNO_KATALOG_SKOL;
    }

    /**
     * Discover schools from Brno city catalog.
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        return $this->discoverWithSettings([
            'schoolTypes' => ['zs'],
        ], $limit);
    }

    /**
     * Discover schools with specific settings.
     *
     * @param array{schoolTypes?: array<string>} $settings
     * @return array<DiscoveryResult>
     */
    public function discoverWithSettings(array $settings, int $limit = 50): array
    {
        $this->logger->info('BrnoKatalogSkol: discoverWithSettings called', [
            'settings' => $settings,
            'limit' => $limit,
        ]);

        $schoolTypes = $settings['schoolTypes'] ?? ['zs'];

        if (empty($schoolTypes)) {
            $this->logger->warning('BrnoKatalogSkol: No school types specified');

            return [];
        }

        $this->logger->info('BrnoKatalogSkol: Processing school types', [
            'schoolTypes' => $schoolTypes,
        ]);

        $results = [];
        $limitPerType = (int) ceil($limit / count($schoolTypes));

        foreach ($schoolTypes as $schoolType) {
            if (!isset(self::URLS[$schoolType])) {
                $this->logger->warning('BrnoKatalogSkol: Unknown school type', ['type' => $schoolType]);

                continue;
            }

            $typeResults = $this->discoverSchoolType($schoolType, $limitPerType);
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
     * @return array<DiscoveryResult>
     */
    private function discoverSchoolType(string $schoolType, int $limit): array
    {
        $baseUrl = self::URLS[$schoolType];
        $results = [];
        $maxPages = (int) ceil($limit / self::RESULTS_PER_PAGE);

        for ($page = 1; $page <= $maxPages && count($results) < $limit; $page++) {
            $url = $baseUrl;
            if ($page > 1) {
                $url .= '?page=' . $page;
            }

            $pageResults = $this->fetchPage($url, $schoolType);

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
    private function fetchPage(string $url, string $schoolType): array
    {
        $this->logger->debug('BrnoKatalogSkol: Fetching page', ['url' => $url]);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurlInsecure($url);

            if ($html !== null) {
                return $this->parseListingPage($html, $schoolType);
            }

            $this->logger->warning('BrnoKatalogSkol request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 2);
        }

        return [];
    }

    /**
     * Use native curl with SSL verification disabled (required for brno.cz catalog sites).
     */
    private function fetchWithCurlInsecure(string $url): ?string
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
            // SSL verification disabled due to invalid certificates on brno.cz catalog sites
            \CURLOPT_SSL_VERIFYPEER => false,
            \CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!\is_string($result) || $httpCode !== 200) {
            $this->logger->warning('BrnoKatalogSkol curl request failed', [
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

        // Pattern: Match only /skola/ detail page links (not /materske-skoly etc.)
        $pattern = '/<a[^>]+href="(\/skola\/[^"]+)"[^>]*>([^<]+)<\/a>/i';

        if (preg_match_all($pattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $href = $match[1];
                $schoolName = trim(html_entity_decode(strip_tags($match[2])));

                // Skip empty names
                if (empty($schoolName) || strlen($schoolName) < 5) {
                    continue;
                }

                // Build full detail URL
                $baseUrl = self::BASE_URLS[$schoolType];
                $detailUrl = rtrim($baseUrl, '/') . $href;

                // Return catalog detail URL - website will be extracted later in handler
                $results[] = new DiscoveryResult($detailUrl, [
                    'business_name' => $schoolName,
                    'school_type' => $schoolType,
                    'school_type_label' => self::SCHOOL_TYPES[$schoolType] ?? $schoolType,
                    'source_type' => 'brno_katalog_skol',
                    'needs_website_extraction' => true,
                ]);
            }
        }

        // Deduplicate by URL
        $seen = [];
        $results = array_filter($results, function (DiscoveryResult $result) use (&$seen) {
            $url = $result->url;
            if (isset($seen[$url])) {
                return false;
            }
            $seen[$url] = true;

            return true;
        });

        $this->logger->debug('BrnoKatalogSkol: Parsed page', [
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
            $html = $this->fetchWithCurlInsecure($detailUrl);

            if ($html !== null) {
                // Look for website link in contact section
                $patterns = [
                    // Pattern 1: Link inside field-school-www div (most reliable for Brno catalog)
                    '/field-school-www.*?href="(https?:[^"]+)"/s',
                    // Pattern 2: Link with http(s):// visible text (school website shown as text)
                    '/<a[^>]+href="(https?:\/\/(?!(?:zapisdoms|zapisdozs|zapisdoskol)[^"]*)[^"]+)"[^>]*>\s*https?:\/\/[^<]+<\/a>/i',
                    // Pattern 3: Any external .cz link not to catalog
                    '/<a[^>]+href="(https?:\/\/(?!(?:zapisdoms|zapisdozs|zapisdoskol|brno\.cz)[^"]*)[^"]+\.cz[^"]*)"[^>]*>/i',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $html, $match)) {
                        $url = html_entity_decode($match[1]);

                        if ($this->isValidSchoolWebsite($url)) {
                            $this->logger->debug('BrnoKatalogSkol: Extracted website', [
                                'detailUrl' => $detailUrl,
                                'website' => $url,
                            ]);

                            return $url;
                        }
                    }
                }

                $this->logger->debug('BrnoKatalogSkol: No website found on detail page', [
                    'detailUrl' => $detailUrl,
                ]);

                return null;
            }

            sleep($attempt * 2);
        }

        return null;
    }

    /**
     * Check if URL is a valid school website (not the catalog itself).
     */
    private function isValidSchoolWebsite(string $url): bool
    {
        if (!parent::isValidWebsiteUrl($url)) {
            return false;
        }

        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');

        // Skip catalog and related URLs
        $skipDomains = [
            'zapisdoms.brno.cz',
            'zapisdozs.brno.cz',
            'brno.cz',
            'zapisdoskol.cz',
            'www.zapisdoskol.cz',
        ];

        foreach ($skipDomains as $skipDomain) {
            if ($host === $skipDomain || str_ends_with($host, '.' . $skipDomain)) {
                return false;
            }
        }

        return true;
    }
}
