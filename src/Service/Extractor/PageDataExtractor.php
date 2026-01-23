<?php

declare(strict_types=1);

namespace App\Service\Extractor;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PageDataExtractor
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly EmailExtractor $emailExtractor,
        private readonly PhoneExtractor $phoneExtractor,
        private readonly IcoExtractor $icoExtractor,
        private readonly TechnologyDetector $technologyDetector,
        private readonly SocialMediaExtractor $socialMediaExtractor,
        private readonly CompanyNameExtractor $companyNameExtractor,
        private readonly ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Extract all data from HTML content.
     *
     * @param array<string, array<string>> $headers HTTP response headers
     */
    public function extract(string $html, array $headers = []): PageData
    {
        $emails = $this->emailExtractor->extract($html);
        $phones = $this->phoneExtractor->extract($html);
        $icoResults = $this->icoExtractor->extract($html);
        $techData = $this->technologyDetector->detect($html, $headers);
        $socialMedia = $this->socialMediaExtractor->extract($html);
        $companyName = $this->companyNameExtractor->extractSingle($html);

        return new PageData(
            emails: $emails,
            phones: $phones,
            ico: $icoResults[0] ?? null,
            cms: $techData['cms'],
            technologies: $techData['technologies'],
            socialMedia: $socialMedia,
            companyName: $companyName,
        );
    }

    /**
     * Fetch a URL and extract all data from it.
     */
    public function extractFromUrl(string $url): ?PageData
    {
        if ($this->httpClient === null) {
            $this->logger->warning('HTTP client not configured, cannot fetch URL', ['url' => $url]);

            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'cs,en;q=0.9',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $this->logger->warning('Failed to fetch URL', [
                    'url' => $url,
                    'status' => $statusCode,
                ]);

                return null;
            }

            $html = $response->getContent();
            $headers = $response->getHeaders();

            return $this->extract($html, $headers);
        } catch (\Throwable $e) {
            $this->logger->warning('Exception while fetching URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fetch URL and return PageData with additional error handling.
     *
     * @return array{data: PageData|null, error: string|null}
     */
    public function extractFromUrlWithError(string $url): array
    {
        if ($this->httpClient === null) {
            return [
                'data' => null,
                'error' => 'HTTP client not configured',
            ];
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'cs,en;q=0.9',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                return [
                    'data' => null,
                    'error' => sprintf('HTTP %d', $statusCode),
                ];
            }

            $html = $response->getContent();
            $headers = $response->getHeaders();

            return [
                'data' => $this->extract($html, $headers),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract data from URL including contact pages.
     *
     * Fetches the main URL, then looks for contact page links and extracts
     * additional data from them. Merges all found data together.
     */
    public function extractWithContactPages(string $url): ?PageData
    {
        if ($this->httpClient === null) {
            $this->logger->warning('HTTP client not configured, cannot fetch URL', ['url' => $url]);
            return null;
        }

        try {
            // Fetch main page
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'headers' => $this->getDefaultHeaders(),
            ]);

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $html = $response->getContent();
            $headers = $response->getHeaders();
            $baseUrl = $this->getBaseUrl($url);

            // Extract from main page
            $mainData = $this->extract($html, $headers);

            // If we already have email, no need to crawl contact pages
            // (company name is nice-to-have, email is the priority)
            if (!empty($mainData->emails)) {
                return $mainData;
            }

            // Find contact page links
            $contactUrls = $this->findContactPageUrls($html, $baseUrl);

            $this->logger->debug('Found contact page URLs', [
                'url' => $url,
                'contact_urls' => $contactUrls,
            ]);

            // Extract from contact pages
            $allEmails = $mainData->emails;
            $allPhones = $mainData->phones;

            foreach ($contactUrls as $contactUrl) {
                try {
                    usleep(200000); // 200ms delay between requests

                    $contactResponse = $this->httpClient->request('GET', $contactUrl, [
                        'timeout' => 10,
                        'headers' => $this->getDefaultHeaders(),
                    ]);

                    if ($contactResponse->getStatusCode() < 400) {
                        $contactHtml = $contactResponse->getContent();
                        $contactEmails = $this->emailExtractor->extract($contactHtml);
                        $contactPhones = $this->phoneExtractor->extract($contactHtml);

                        $allEmails = array_unique(array_merge($allEmails, $contactEmails));
                        $allPhones = array_unique(array_merge($allPhones, $contactPhones));

                        $this->logger->debug('Extracted from contact page', [
                            'contact_url' => $contactUrl,
                            'emails_found' => count($contactEmails),
                            'phones_found' => count($contactPhones),
                        ]);

                        // If we found email, stop crawling
                        if (!empty($contactEmails)) {
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('Failed to fetch contact page', [
                        'contact_url' => $contactUrl,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Return merged data
            return new PageData(
                emails: $allEmails,
                phones: $allPhones,
                ico: $mainData->ico,
                cms: $mainData->cms,
                technologies: $mainData->technologies,
                socialMedia: $mainData->socialMedia,
                companyName: $mainData->companyName,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Exception while extracting with contact pages', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Find URLs that likely point to contact pages.
     *
     * @return array<string>
     */
    private function findContactPageUrls(string $html, string $baseUrl): array
    {
        $contactUrls = [];

        // Patterns for contact page links (Czech and English)
        $patterns = [
            // Czech
            'kontakt', 'kontakty', 'napiste-nam', 'napiste_nam', 'o-nas', 'o_nas',
            // English
            'contact', 'contacts', 'contact-us', 'contact_us', 'about', 'about-us', 'about_us',
            // German
            'kontakt', 'impressum',
        ];

        // Extract all href attributes
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $href = trim($href);

                // Skip empty, anchors, external protocols
                if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')
                    || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                    continue;
                }

                $hrefLower = strtolower($href);

                // Check if URL matches contact patterns
                foreach ($patterns as $pattern) {
                    if (str_contains($hrefLower, $pattern)) {
                        $fullUrl = $this->resolveUrl($href, $baseUrl);
                        if ($fullUrl !== null && !in_array($fullUrl, $contactUrls, true)) {
                            $contactUrls[] = $fullUrl;
                        }
                        break;
                    }
                }
            }
        }

        // Limit to 3 contact pages max
        return array_slice($contactUrls, 0, 3);
    }

    /**
     * Resolve a relative URL to an absolute URL.
     */
    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        // Already absolute
        if (preg_match('~^https?://~i', $href)) {
            // Only return if same domain
            $hrefHost = parse_url($href, PHP_URL_HOST);
            $baseHost = parse_url($baseUrl, PHP_URL_HOST);
            return ($hrefHost === $baseHost) ? $href : null;
        }

        // Protocol-relative
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }

        // Absolute path
        if (str_starts_with($href, '/')) {
            return rtrim($baseUrl, '/') . $href;
        }

        // Relative path
        return rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
    }

    /**
     * Get base URL (scheme + host) from a full URL.
     */
    private function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        return $scheme . '://' . $host;
    }

    /**
     * @return array<string, string>
     */
    private function getDefaultHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'cs,en;q=0.9',
        ];
    }
}
