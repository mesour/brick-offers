<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovery source that finds client websites from agency reference/portfolio pages.
 *
 * Workflow:
 * 1. Uses an inner source (Google, Seznam, etc.) to find agencies matching query
 * 2. Crawls agency websites to find reference/portfolio pages
 * 3. Extracts external links (client websites) from those pages
 */
#[AutoconfigureTag('app.discovery_source')]
class ReferenceDiscoverySource extends AbstractDiscoverySource
{
    /** Patterns for reference page URLs (path segments) */
    private const REFERENCE_PATTERNS = [
        'reference',
        'portfolio',
        'nase-prace',
        'our-work',
        'klienti',
        'clients',
        'projects',
        'projekty',
        'case-study',
        'pripadove-studie',
        'realizace',
        'showcases',
        'work',
        'prace',
    ];

    /** Patterns for reference links (anchor text or classes) */
    private const REFERENCE_LINK_PATTERNS = [
        'reference',
        'portfolio',
        'práce',
        'prace',
        'klient',
        'realizac',
        'projekt',
        'case',
        'showcase',
    ];

    /** Domains to skip when extracting client URLs */
    private const SKIP_DOMAINS = [
        // Social networks
        'facebook.com', 'twitter.com', 'x.com', 'instagram.com', 'linkedin.com',
        'youtube.com', 'tiktok.com', 'pinterest.com', 'vimeo.com', 'reddit.com',
        'redditinc.com', 'reddithelp.com',
        // Search engines
        'google.com', 'google.cz', 'seznam.cz', 'bing.com', 'yahoo.com',
        // Stock photos
        'unsplash.com', 'pexels.com', 'shutterstock.com', 'istockphoto.com',
        'pixabay.com', 'gettyimages.com', 'adobe.com',
        // Framework/CMS
        'wordpress.org', 'wordpress.com', 'laravel.com', 'symfony.com',
        'drupal.org', 'joomla.org', 'wix.com', 'squarespace.com', 'shopify.com',
        'webnode.com', 'webnode.cz', 'webnode.page',
        // CDN and services
        'cloudflare.com', 'jsdelivr.net', 'cdnjs.com', 'bootstrapcdn.com',
        'googleapis.com', 'gstatic.com', 'fontawesome.com', 'fonts.google.com',
        // Analytics and tracking
        'google-analytics.com', 'hotjar.com', 'mouseflow.com',
        // Development
        'github.com', 'gitlab.com', 'bitbucket.org', 'stackoverflow.com',
        // Directories (already in parent class, but adding for completeness)
        'firmy.cz', 'zivefirmy.cz', 'najisto.centrum.cz', 'zlatestranky.cz',
        // Wikipedia
        'wikipedia.org',
        // Maps
        'mapy.cz', 'maps.google.com',
        // Reviews and ratings
        'trustpilot.com', 'recenze.cz', 'heureka.cz',
        // Job portals
        'jobs.cz', 'prace.cz', 'profesia.cz',
        // Accessibility widgets
        'accessiway.com', 'userway.org',
    ];

    /** @var array<string, DiscoverySourceInterface> */
    private array $innerSources = [];

    private string $selectedInnerSource = 'google';

    /**
     * @param iterable<DiscoverySourceInterface> $sources
     */
    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        #[TaggedIterator('app.discovery_source')]
        iterable $sources,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 2000; // Conservative rate limiting

        // Index all sources by name (excluding self)
        foreach ($sources as $source) {
            $name = $source->getSource()->value;
            if ($name !== LeadSource::REFERENCE_CRAWLER->value) {
                $this->innerSources[$name] = $source;
            }
        }
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::REFERENCE_CRAWLER;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::REFERENCE_CRAWLER;
    }

    /**
     * Set the inner source to use for finding agencies.
     */
    public function setInnerSource(string $sourceName): void
    {
        if (!isset($this->innerSources[$sourceName])) {
            $available = implode(', ', array_keys($this->innerSources));
            throw new \InvalidArgumentException(
                sprintf('Unknown inner source "%s". Available: %s', $sourceName, $available)
            );
        }
        $this->selectedInnerSource = $sourceName;
    }

    /**
     * Get available inner source names.
     *
     * @return array<string>
     */
    public function getAvailableInnerSources(): array
    {
        return array_keys($this->innerSources);
    }

    /**
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        $innerSource = $this->innerSources[$this->selectedInnerSource] ?? null;

        if ($innerSource === null) {
            $this->logger->error('Inner source not available', ['source' => $this->selectedInnerSource]);
            return [];
        }

        $this->logger->info('Starting reference crawler discovery', [
            'query' => $query,
            'inner_source' => $this->selectedInnerSource,
            'limit' => $limit,
        ]);

        // Find agencies using inner source (limit to reasonable number)
        $agencyLimit = min($limit, 20);
        $agencies = $innerSource->discover($query, $agencyLimit);

        $this->logger->info('Found agencies from inner source', [
            'count' => count($agencies),
        ]);

        // Extract client references from each agency
        $results = [];
        $seenDomains = [];

        foreach ($agencies as $agency) {
            $this->logger->debug('Processing agency', ['url' => $agency->url, 'domain' => $agency->domain]);

            $clientUrls = $this->extractClientReferences($agency->url, $agency->domain);

            foreach ($clientUrls as $clientData) {
                $clientDomain = $this->extractDomain($clientData['url']);

                // Skip if we've seen this domain or if it's the agency itself
                if (isset($seenDomains[$clientDomain]) || $clientDomain === $agency->domain) {
                    continue;
                }

                $seenDomains[$clientDomain] = true;

                $truncatedUrl = $this->truncateUrl($clientData['url']);

                $results[] = new DiscoveryResult($truncatedUrl, [
                    'source_type' => 'reference_crawler',
                    'inner_source' => $this->selectedInnerSource,
                    'agency_url' => $agency->url,
                    'agency_domain' => $agency->domain,
                    'found_on_page' => $clientData['found_on'] ?? $agency->url,
                    'query' => $query,
                ]);

                if (count($results) >= $limit) {
                    break 2;
                }
            }

            $this->rateLimit();
        }

        $this->logger->info('Reference crawler discovery completed', [
            'total_results' => count($results),
        ]);

        return $results;
    }

    /**
     * Extract client website URLs from an agency's reference pages.
     *
     * @return array<array{url: string, found_on: string}>
     */
    private function extractClientReferences(string $agencyUrl, string $agencyDomain): array
    {
        // First, get the agency's homepage to find reference pages
        $homepageHtml = $this->fetchPage($agencyUrl);

        if ($homepageHtml === null) {
            return [];
        }

        // Find reference page links
        $referencePageUrls = $this->findReferencePageLinks($homepageHtml, $agencyUrl, $agencyDomain);

        // Always include homepage in case references are listed there
        $pagesToScan = array_merge([$agencyUrl], $referencePageUrls);
        $pagesToScan = array_unique($pagesToScan);

        // Limit pages to scan per agency
        $pagesToScan = array_slice($pagesToScan, 0, 5);

        $clientUrls = [];
        $seenUrls = [];

        foreach ($pagesToScan as $pageUrl) {
            $this->logger->debug('Scanning page for client references', ['url' => $pageUrl]);

            $html = $pageUrl === $agencyUrl ? $homepageHtml : $this->fetchPage($pageUrl);

            if ($html === null) {
                continue;
            }

            $externalUrls = $this->extractExternalLinks($html, $agencyDomain);

            foreach ($externalUrls as $url) {
                if (!isset($seenUrls[$url])) {
                    $seenUrls[$url] = true;
                    $clientUrls[] = [
                        'url' => $url,
                        'found_on' => $pageUrl,
                    ];
                }
            }

            // Rate limit between page fetches
            if ($pageUrl !== $agencyUrl) {
                $this->rateLimit();
            }
        }

        return $clientUrls;
    }

    /**
     * Find links to reference/portfolio pages on the agency website.
     *
     * @return array<string>
     */
    private function findReferencePageLinks(string $html, string $baseUrl, string $agencyDomain): array
    {
        $links = [];

        // Extract all internal links
        if (!preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $baseParsed = parse_url($baseUrl);
        $baseScheme = $baseParsed['scheme'] ?? 'https';

        foreach ($matches as $match) {
            $href = trim($match[1]);
            $anchorText = strip_tags($match[2]);
            $fullMatch = $match[0];

            // Skip empty, anchors, javascript, and external links
            if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            // Resolve relative URLs
            $absoluteUrl = $this->resolveUrl($href, $baseUrl);

            if ($absoluteUrl === null) {
                continue;
            }

            // Must be same domain
            $urlDomain = $this->extractDomain($absoluteUrl);
            if ($urlDomain !== $agencyDomain && $urlDomain !== 'www.' . $agencyDomain) {
                continue;
            }

            // Check if URL path matches reference patterns
            $urlPath = strtolower(parse_url($absoluteUrl, PHP_URL_PATH) ?? '');
            foreach (self::REFERENCE_PATTERNS as $pattern) {
                if (str_contains($urlPath, $pattern)) {
                    $links[] = $absoluteUrl;
                    continue 2;
                }
            }

            // Check if anchor text or class suggests reference page
            $searchText = strtolower($anchorText . ' ' . $fullMatch);
            foreach (self::REFERENCE_LINK_PATTERNS as $pattern) {
                if (str_contains($searchText, $pattern)) {
                    $links[] = $absoluteUrl;
                    break;
                }
            }
        }

        return array_unique($links);
    }

    /**
     * Extract external links from HTML that could be client websites.
     *
     * @return array<string>
     */
    private function extractExternalLinks(string $html, string $excludeDomain): array
    {
        $urls = [];

        // Extract hrefs from anchor tags
        if (!preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            return [];
        }

        foreach ($matches[1] as $href) {
            $href = trim($href);

            // Must be absolute URL with http(s)
            if (!preg_match('~^https?://~i', $href)) {
                continue;
            }

            $domain = $this->extractDomain($href);

            // Skip the agency's own domain
            if ($domain === $excludeDomain) {
                continue;
            }

            // Skip known non-client domains
            if ($this->isSkippedDomain($domain)) {
                continue;
            }

            // Basic URL validation
            if (!$this->isValidClientUrl($href)) {
                continue;
            }

            $urls[] = $href;
        }

        return array_unique($urls);
    }

    /**
     * Check if domain should be skipped.
     */
    private function isSkippedDomain(string $domain): bool
    {
        foreach (self::SKIP_DOMAINS as $skipDomain) {
            if ($domain === $skipDomain || str_ends_with($domain, '.' . $skipDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if URL looks like a valid client website.
     */
    private function isValidClientUrl(string $url): bool
    {
        $parsed = parse_url($url);

        // Must have host
        if (empty($parsed['host'])) {
            return false;
        }

        // Skip URLs with file extensions that aren't web pages
        $path = $parsed['path'] ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'pdf', 'zip', 'exe', 'dmg'], true)) {
            return false;
        }

        // Skip localhost and IP addresses
        $host = $parsed['host'];
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return true;
    }

    /**
     * Fetch a page and return its HTML content.
     */
    private function fetchPage(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'max_redirects' => 3,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; WebAnalyzer/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'cs,en;q=0.9',
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                $this->logger->debug('Failed to fetch page', ['url' => $url, 'status' => $statusCode]);
                return null;
            }

            return $response->getContent();
        } catch (\Throwable $e) {
            $this->logger->debug('Error fetching page', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Resolve a relative URL against a base URL.
     */
    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        // Already absolute
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }

        $baseParsed = parse_url($baseUrl);

        if (empty($baseParsed['host'])) {
            return null;
        }

        $scheme = $baseParsed['scheme'] ?? 'https';
        $host = $baseParsed['host'];
        $port = isset($baseParsed['port']) ? ':' . $baseParsed['port'] : '';

        // Protocol-relative
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        // Absolute path
        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $port . $href;
        }

        // Relative path - append to base path
        $basePath = $baseParsed['path'] ?? '/';
        $baseDir = rtrim(dirname($basePath), '/');

        return $scheme . '://' . $host . $port . $baseDir . '/' . $href;
    }

    /**
     * Extract domain from URL.
     */
    private function extractDomain(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Remove www. prefix
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return strtolower($host);
    }

    /**
     * Truncate URL to fit database column (500 chars max).
     * Removes query string and fragment if needed.
     */
    private function truncateUrl(string $url, int $maxLength = 500): string
    {
        if (strlen($url) <= $maxLength) {
            return $url;
        }

        // First try: remove fragment
        $parsed = parse_url($url);
        $truncated = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        if (isset($parsed['port'])) {
            $truncated .= ':' . $parsed['port'];
        }

        if (isset($parsed['path'])) {
            $truncated .= $parsed['path'];
        }

        if (isset($parsed['query'])) {
            $truncated .= '?' . $parsed['query'];
        }

        if (strlen($truncated) <= $maxLength) {
            return $truncated;
        }

        // Second try: remove query string too
        $truncated = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        if (isset($parsed['port'])) {
            $truncated .= ':' . $parsed['port'];
        }

        if (isset($parsed['path'])) {
            $truncated .= $parsed['path'];
        }

        if (strlen($truncated) <= $maxLength) {
            return $truncated;
        }

        // Last resort: just base URL
        $truncated = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        if (isset($parsed['port'])) {
            $truncated .= ':' . $parsed['port'];
        }

        return substr($truncated, 0, $maxLength);
    }
}
