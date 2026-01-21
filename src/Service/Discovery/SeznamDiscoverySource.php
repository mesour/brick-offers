<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.discovery_source')]
class SeznamDiscoverySource extends AbstractDiscoverySource
{
    private const SEARCH_URL = 'https://search.seznam.cz/';
    private const RESULTS_PER_PAGE = 10;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        private readonly string $apiKey = '',
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 1000; // Be more conservative with Seznam
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::SEZNAM;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::SEZNAM;
    }

    /**
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        $results = [];
        $page = 0;
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
     * @return array<DiscoveryResult>
     */
    private function fetchPage(string $query, int $page): array
    {
        try {
            $response = $this->httpClient->request('GET', self::SEARCH_URL, [
                'query' => [
                    'q' => $query,
                    'from' => $page * self::RESULTS_PER_PAGE,
                ],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'cs,en;q=0.9',
                ],
            ]);

            $html = $response->getContent();

            return $this->parseSearchResults($html, $query);
        } catch (\Throwable $e) {
            $this->logger->error('Seznam Search request failed', [
                'query' => $query,
                'page' => $page,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse Seznam search results HTML.
     *
     * @return array<DiscoveryResult>
     */
    private function parseSearchResults(string $html, string $query): array
    {
        $results = [];

        // Extract result links - Seznam uses data-dot-data or specific result classes
        // This pattern targets the main result links
        $pattern = '/<a[^>]+class="[^"]*Result[^"]*"[^>]+href="([^"]+)"[^>]*>.*?<h3[^>]*>([^<]+)<\/h3>/is';

        if (preg_match_all($pattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = html_entity_decode($match[1]);
                $title = html_entity_decode(strip_tags($match[2]));

                if (!$this->isValidWebsiteUrl($url)) {
                    continue;
                }

                $results[] = new DiscoveryResult($url, [
                    'title' => $title,
                    'query' => $query,
                    'source_type' => 'seznam_search',
                ]);
            }
        }

        // Fallback: extract any URLs from result containers
        if (empty($results)) {
            $urls = $this->extractUrlsFromHtml($html);
            foreach ($urls as $url) {
                if ($this->isValidWebsiteUrl($url)) {
                    $results[] = new DiscoveryResult($url, [
                        'query' => $query,
                        'source_type' => 'seznam_search',
                    ]);
                }
            }
        }

        return $results;
    }
}
