<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovery source for atlasskolstvi.cz - Czech school directory.
 *
 * This is a category-based source (not query-based). It browses school types:
 * - zakladni-skoly (primary schools)
 * - stredni-skoly (secondary schools)
 * - vysoke-skoly (universities)
 * - vyssi-odborne-skoly (higher vocational schools)
 * - jazykove-skoly (language schools)
 *
 * URL patterns:
 * - Category: /{school-type}?p={page}
 * - School detail: /ss{id}-{slug} or /zs{id}-{slug} etc.
 */
#[AutoconfigureTag('app.discovery_source')]
class AtlasSkolstviDiscoverySource extends AbstractDiscoverySource
{
    private const BASE_URL = 'https://www.atlasskolstvi.cz';
    private const RESULTS_PER_PAGE = 20;
    private const MAX_RETRIES = 3;

    // School type URL prefixes for detail pages
    private const SCHOOL_TYPE_PREFIXES = [
        'zakladni-skoly' => 'zs',
        'stredni-skoly' => 'ss',
        'vysoke-skoly' => 'vs',
        'vyssi-odborne-skoly' => 'vos',
        'jazykove-skoly' => 'js',
    ];

    /**
     * School type choices with labels for admin forms.
     * Key = URL slug, Value = Czech label.
     */
    public const SCHOOL_TYPES = [
        'zakladni-skoly' => 'Základní školy',
        'stredni-skoly' => 'Střední školy',
        'vysoke-skoly' => 'Vysoké školy',
        'vyssi-odborne-skoly' => 'Vyšší odborné školy',
        'jazykove-skoly' => 'Jazykové školy',
    ];

    /**
     * Region choices with labels for admin forms.
     * Key = URL slug (matching atlasskolstvi.cz URL params), Value = Czech label.
     */
    public const REGIONS = [
        'hlm-praha' => 'Hl.m. Praha',
        'stredocesky-kraj' => 'Středočeský kraj',
        'jihocesky-kraj' => 'Jihočeský kraj',
        'plzensky-kraj' => 'Plzeňský kraj',
        'karlovarsky-kraj' => 'Karlovarský kraj',
        'ustecky-kraj' => 'Ústecký kraj',
        'liberecky-kraj' => 'Liberecký kraj',
        'kralovehradecky-kraj' => 'Královéhradecký kraj',
        'pardubicky-kraj' => 'Pardubický kraj',
        'kraj-vysocina' => 'Kraj Vysočina',
        'jihomoravsky-kraj' => 'Jihomoravský kraj',
        'olomoucky-kraj' => 'Olomoucký kraj',
        'zlinsky-kraj' => 'Zlínský kraj',
        'moravskoslezsky-kraj' => 'Moravskoslezský kraj',
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
        return $source === LeadSource::ATLAS_SKOLSTVI;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::ATLAS_SKOLSTVI;
    }

    /**
     * Discover schools from atlasskolstvi.cz.
     *
     * For this source, $query is ignored. Use discoverWithSettings() instead.
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        // Default to all school types if no specific settings
        return $this->discoverWithSettings([
            'schoolTypes' => array_keys(self::SCHOOL_TYPE_PREFIXES),
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
        $schoolTypes = $settings['schoolTypes'] ?? array_keys(self::SCHOOL_TYPE_PREFIXES);
        $regions = $settings['regions'] ?? [];

        if (empty($schoolTypes)) {
            $this->logger->warning('AtlasSkolstvi: No school types specified');

            return [];
        }

        $results = [];
        $limitPerType = (int) ceil($limit / count($schoolTypes));

        foreach ($schoolTypes as $schoolType) {
            if (!isset(self::SCHOOL_TYPE_PREFIXES[$schoolType])) {
                $this->logger->warning('AtlasSkolstvi: Unknown school type', ['type' => $schoolType]);

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
        $results = [];
        $page = 1;
        $maxPages = (int) ceil($limit / self::RESULTS_PER_PAGE);

        for ($i = 0; $i < $maxPages && count($results) < $limit; $i++) {
            $pageResults = $this->fetchPage($schoolType, $regions, $page);

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
     * @param array<string> $regions
     * @return array<DiscoveryResult>
     */
    private function fetchPage(string $schoolType, array $regions, int $page): array
    {
        $url = sprintf('%s/%s', self::BASE_URL, $schoolType);

        $queryParams = [];
        if ($page > 1) {
            $queryParams['p'] = $page;
        }
        if (!empty($regions)) {
            // Region filtering - may need adjustment based on actual URL params
            $queryParams['region'] = implode(',', $regions);
        }

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($url);

            if ($html !== null) {
                return $this->parseListingPage($html, $schoolType);
            }

            $this->logger->warning('AtlasSkolstvi request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 2);
        }

        $this->logger->error('AtlasSkolstvi: Max retries exceeded', [
            'schoolType' => $schoolType,
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
            $this->logger->warning('AtlasSkolstvi curl request failed', [
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
        $prefix = self::SCHOOL_TYPE_PREFIXES[$schoolType] ?? 'ss';

        // Pattern to match school detail links: /ss123-nazev-skoly
        $detailPattern = sprintf(
            '/<a[^>]+href="(\/%s(\d+)-([^"]+))"[^>]*>([^<]*)<\/a>/i',
            preg_quote($prefix, '/')
        );

        if (preg_match_all($detailPattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $detailPath = $match[1];
                $schoolId = $match[2];
                $schoolSlug = $match[3];
                $schoolName = trim(html_entity_decode(strip_tags($match[4])));

                // Skip empty names
                if (empty($schoolName)) {
                    continue;
                }

                $detailUrl = self::BASE_URL . $detailPath;

                $results[] = new DiscoveryResult($detailUrl, [
                    'business_name' => $schoolName,
                    'school_id' => $schoolId,
                    'school_type' => $schoolType,
                    'source_type' => 'atlas_skolstvi_catalog',
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
                // Look for external website links
                // Schools typically have their website listed with icons or in contact section
                $patterns = [
                    // Direct website link with www
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?atlasskolstvi\.cz)[^"]+)"[^>]*>[^<]*(?:www\.|web|stránky)[^<]*<\/a>/i',
                    // Link with globe/world icon
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?atlasskolstvi\.cz)[^"]+)"[^>]*class="[^"]*(?:web|link|external)[^"]*"[^>]*>/i',
                    // Any external link that looks like a school website
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?atlasskolstvi\.cz|maps\.google|facebook\.com|instagram\.com)[^"]+)"[^>]*target="_blank"[^>]*>/i',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $html, $match)) {
                        $url = html_entity_decode($match[1]);

                        if ($this->isValidWebsiteUrl($url)) {
                            return $url;
                        }
                    }
                }

                // Fallback: look for any .cz or .sk domain that's not social media
                if (preg_match_all('/<a[^>]+href="(https?:\/\/[^"]+\.(?:cz|sk)[^"]*)"[^>]*>/i', $html, $allMatches)) {
                    foreach ($allMatches[1] as $url) {
                        $url = html_entity_decode($url);
                        if ($this->isValidWebsiteUrl($url) && !str_contains($url, 'atlasskolstvi.cz')) {
                            return $url;
                        }
                    }
                }

                return null;
            }

            sleep($attempt * 2);
        }

        $this->logger->error('Failed to extract website from AtlasSkolstvi detail', [
            'detailUrl' => $detailUrl,
        ]);

        return null;
    }

    /**
     * Override to add atlasskolstvi.cz to skip domains.
     */
    protected function isValidWebsiteUrl(string $url): bool
    {
        if (!parent::isValidWebsiteUrl($url)) {
            return false;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Skip atlasskolstvi.cz URLs
        if (str_ends_with($host, 'atlasskolstvi.cz')) {
            return false;
        }

        return true;
    }
}
