<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovery source for seznamskol.cz - Czech school directory.
 *
 * This is a category-based source. It browses school types:
 * - materske-skoly (preschools)
 * - zakladni-skoly (primary schools)
 * - zakladni-umelecke-skoly (arts schools)
 *
 * URL patterns:
 * - Category: /{school-type}/
 * - Category with region: /{school-type}/{region}/
 * - Pagination: /{school-type}/{page}/ or /{school-type}/{region}/{page}/
 * - School detail: /skola/{id}.html
 *
 * Direct website URLs are available in listings with UTM parameters.
 */
#[AutoconfigureTag('app.discovery_source')]
class SeznamSkolDiscoverySource extends AbstractDiscoverySource
{
    private const BASE_URL = 'https://www.seznamskol.cz';
    private const RESULTS_PER_PAGE = 20;
    private const MAX_RETRIES = 3;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 1500;
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::SEZNAM_SKOL;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::SEZNAM_SKOL;
    }

    /**
     * Discover schools from seznamskol.cz.
     *
     * For this source, $query is ignored. Use discoverWithSettings() instead.
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        return $this->discoverWithSettings([
            'schoolTypes' => ['materske-skoly', 'zakladni-skoly', 'zakladni-umelecke-skoly'],
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
        $schoolTypes = $settings['schoolTypes'] ?? ['materske-skoly', 'zakladni-skoly', 'zakladni-umelecke-skoly'];
        $regions = $settings['regions'] ?? [];

        if (empty($schoolTypes)) {
            $this->logger->warning('SeznamSkol: No school types specified');

            return [];
        }

        $results = [];

        // If regions specified, iterate over type+region combinations
        if (!empty($regions)) {
            $combinations = [];
            foreach ($schoolTypes as $type) {
                foreach ($regions as $region) {
                    $combinations[] = ['type' => $type, 'region' => $region];
                }
            }
            $limitPerCombo = (int) ceil($limit / count($combinations));

            foreach ($combinations as $combo) {
                $comboResults = $this->discoverCategory($combo['type'], $combo['region'], $limitPerCombo);
                $results = array_merge($results, $comboResults);

                if (count($results) >= $limit) {
                    break;
                }

                $this->rateLimit();
            }
        } else {
            // No regions - just iterate over types
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
     * Discover schools in a specific category (and optionally region).
     *
     * @return array<DiscoveryResult>
     */
    private function discoverCategory(string $schoolType, ?string $region, int $limit): array
    {
        $results = [];
        $page = 1;
        $maxPages = (int) ceil($limit / self::RESULTS_PER_PAGE);

        for ($i = 0; $i < $maxPages && count($results) < $limit; $i++) {
            $pageResults = $this->fetchPage($schoolType, $region, $page);

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
    private function fetchPage(string $schoolType, ?string $region, int $page): array
    {
        // Build URL: /{type}/ or /{type}/{region}/ or /{type}/{page}/ or /{type}/{region}/{page}/
        if ($region !== null) {
            $url = $page === 1
                ? sprintf('%s/%s/%s/', self::BASE_URL, $schoolType, $region)
                : sprintf('%s/%s/%s/%d/', self::BASE_URL, $schoolType, $region, $page);
        } else {
            $url = $page === 1
                ? sprintf('%s/%s/', self::BASE_URL, $schoolType)
                : sprintf('%s/%s/%d/', self::BASE_URL, $schoolType, $page);
        }

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($url);

            if ($html !== null) {
                return $this->parseListingPage($html, $schoolType, $region);
            }

            $this->logger->warning('SeznamSkol request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 2);
        }

        $this->logger->error('SeznamSkol: Max retries exceeded', [
            'schoolType' => $schoolType,
            'region' => $region,
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
            $this->logger->warning('SeznamSkol curl request failed', [
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
    private function parseListingPage(string $html, string $schoolType, ?string $region): array
    {
        $results = [];

        // Strategy 1: Extract direct website URLs (they have UTM parameters)
        // Pattern: href="http(s)://...?utm_source=seznamskol.cz..."
        $websitePattern = '/<a[^>]+href="(https?:\/\/[^"]+\?utm_source=seznamskol\.cz[^"]*)"[^>]*>/i';

        if (preg_match_all($websitePattern, $html, $matches)) {
            foreach ($matches[1] as $urlWithUtm) {
                // Remove UTM parameters to get clean URL
                $url = $this->removeUtmParams($urlWithUtm);

                if (!$this->isValidWebsiteUrl($url)) {
                    continue;
                }

                $results[] = $this->createResultWithExtraction($url, [
                    'school_type' => $schoolType,
                    'region' => $region,
                    'source_type' => 'seznam_skol_direct',
                ]);
            }
        }

        // Strategy 2: Extract school detail page links for schools without direct website
        // Pattern: /skola/{id}.html
        $detailPattern = '/<a[^>]+href="(\/skola\/(\d+)\.html)"[^>]*>([^<]+)<\/a>/i';

        if (preg_match_all($detailPattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $detailPath = $match[1];
                $schoolId = $match[2];
                $schoolName = trim(html_entity_decode(strip_tags($match[3])));

                // Skip if empty name or if we already have direct URL
                if (empty($schoolName)) {
                    continue;
                }

                $detailUrl = self::BASE_URL . $detailPath;

                $results[] = new DiscoveryResult($detailUrl, [
                    'business_name' => $schoolName,
                    'school_id' => $schoolId,
                    'school_type' => $schoolType,
                    'region' => $region,
                    'source_type' => 'seznam_skol_catalog',
                    'needs_website_extraction' => true,
                ]);
            }
        }

        // Deduplicate by URL/domain
        $seen = [];
        $results = array_filter($results, function (DiscoveryResult $result) use (&$seen) {
            $key = $result->domain ?? $result->url;
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        });

        return array_values($results);
    }

    /**
     * Remove UTM parameters from URL.
     */
    private function removeUtmParams(string $url): string
    {
        $parsed = parse_url($url);
        if (!isset($parsed['query'])) {
            return $url;
        }

        parse_str($parsed['query'], $params);

        // Remove UTM parameters
        $utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        foreach ($utmKeys as $key) {
            unset($params[$key]);
        }

        $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $baseUrl .= ':' . $parsed['port'];
        }
        if (isset($parsed['path'])) {
            $baseUrl .= $parsed['path'];
        }

        if (!empty($params)) {
            $baseUrl .= '?' . http_build_query($params);
        }

        return $baseUrl;
    }

    /**
     * Extract website URL from school detail page.
     */
    public function extractWebsiteFromDetailPage(string $detailUrl): ?string
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($detailUrl);

            if ($html !== null) {
                // Look for external website link with UTM params
                if (preg_match('/<a[^>]+href="(https?:\/\/[^"]+\?utm_source=seznamskol\.cz[^"]*)"[^>]*>/i', $html, $match)) {
                    $url = $this->removeUtmParams($match[1]);
                    if ($this->isValidWebsiteUrl($url)) {
                        return $url;
                    }
                }

                // Fallback: look for any external link that's not social media
                $patterns = [
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?seznamskol\.cz)[^"]+)"[^>]*class="[^"]*web[^"]*"[^>]*>/i',
                    '/<a[^>]+href="(https?:\/\/(?!(?:www\.)?seznamskol\.cz|maps\.google|facebook\.com|instagram\.com)[^"]+)"[^>]*target="_blank"[^>]*>/i',
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

        $this->logger->error('Failed to extract website from SeznamSkol detail', [
            'detailUrl' => $detailUrl,
        ]);

        return null;
    }

    /**
     * Override to add seznamskol.cz to skip domains.
     */
    protected function isValidWebsiteUrl(string $url): bool
    {
        if (!parent::isValidWebsiteUrl($url)) {
            return false;
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Skip seznamskol.cz URLs
        if (str_ends_with($host, 'seznamskol.cz')) {
            return false;
        }

        return true;
    }
}
