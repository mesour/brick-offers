<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.discovery_source')]
class FirmyCzDiscoverySource extends AbstractDiscoverySource
{
    private const BASE_URL = 'https://www.firmy.cz';
    private const RESULTS_PER_PAGE = 25;
    private const MAX_RETRIES = 3;

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
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        $results = [];
        $page = 1;
        $maxPages = (int) ceil($limit / self::RESULTS_PER_PAGE);

        for ($i = 0; $i < $maxPages && count($results) < $limit; $i++) {
            $pageResults = $this->fetchPage($query, $page);

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
     * Fetch page using native curl to bypass bot detection.
     * Firmy.cz has aggressive bot fingerprinting that affects Symfony's HTTP Client.
     *
     * Note: Firmy.cz is now a React SPA and doesn't support traditional pagination
     * via URL parameters. Only the first page of server-rendered results is available.
     *
     * @return array<DiscoveryResult>
     */
    private function fetchPage(string $query, int $page): array
    {
        // Firmy.cz doesn't support page parameter anymore (React SPA)
        // Only fetch first page which contains ~15 server-rendered results
        if ($page > 1) {
            return [];
        }

        $url = sprintf('%s/?q=%s', self::BASE_URL, urlencode($query));

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($url);

            if ($html !== null) {
                return $this->parseSearchResults($html, $query);
            }

            $this->logger->warning('Firmy.cz request failed, retrying...', [
                'attempt' => $attempt,
                'url' => $url,
            ]);

            sleep($attempt * 3); // Exponential backoff
        }

        $this->logger->error('Firmy.cz: Max retries exceeded', [
            'query' => $query,
        ]);

        return [];
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
                        $results[] = new DiscoveryResult($websiteUrl, [
                            'business_name' => $businessName,
                            'query' => $query,
                            'source_type' => 'firmy_cz_direct',
                            'firmy_cz_detail' => $detailUrl,
                        ]);
                    } elseif ($detailUrl !== null && $businessName !== null) {
                        // Fallback: store detail page for later website extraction
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
        // Match: <a class="titleLinkOverlay" ... href="URL"><h3 class="h3 title" ...>NAME</h3>
        $detailPattern = '/<a[^>]+class="titleLinkOverlay"[^>]+href="(https:\/\/www\.firmy\.cz\/detail\/[^"]+)"[^>]*>.*?<h3[^>]+class="[^"]*title[^"]*"[^>]*>([^<]+)/is';

        if (preg_match_all($detailPattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $detailUrl = html_entity_decode($match[1]);
                $businessName = html_entity_decode(strip_tags($match[2]));

                $results[] = new DiscoveryResult($detailUrl, [
                    'business_name' => $businessName,
                    'query' => $query,
                    'source_type' => 'firmy_cz_catalog',
                    'needs_website_extraction' => true,
                ]);
            }
        }
    }

    /**
     * Extract website URL from a Firmy.cz company detail page.
     */
    public function extractWebsiteFromDetailPage(string $detailUrl): ?string
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $html = $this->fetchWithCurl($detailUrl);

            if ($html !== null) {
                // Look for website link
                if (preg_match('/<a[^>]+href="(https?:\/\/[^"]+)"[^>]*>.*?web.*?<\/a>/is', $html, $match)) {
                    $url = html_entity_decode($match[1]);

                    if ($this->isValidWebsiteUrl($url)) {
                        return $url;
                    }
                }

                return null;
            }

            sleep($attempt * 2);
        }

        $this->logger->error('Failed to extract website from Firmy.cz detail', [
            'detailUrl' => $detailUrl,
        ]);

        return null;
    }
}
