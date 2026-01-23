<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.discovery_source')]
class GoogleDiscoverySource extends AbstractDiscoverySource
{
    private const API_URL = 'https://www.googleapis.com/customsearch/v1';
    private const MAX_RESULTS_PER_REQUEST = 10;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        private readonly string $apiKey = '',
        private readonly string $searchEngineId = '',
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 100;
    }

    public function supports(LeadSource $source): bool
    {
        return $source === LeadSource::GOOGLE;
    }

    public function getSource(): LeadSource
    {
        return LeadSource::GOOGLE;
    }

    /**
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array
    {
        if (empty($this->apiKey) || empty($this->searchEngineId)) {
            $this->logger->warning('Google Search API credentials not configured');

            return [];
        }

        $results = [];
        $startIndex = 1;
        $maxPages = (int) ceil($limit / self::MAX_RESULTS_PER_REQUEST);

        for ($page = 0; $page < $maxPages && count($results) < $limit; $page++) {
            $pageResults = $this->fetchPage($query, $startIndex);

            if (empty($pageResults)) {
                break;
            }

            $results = array_merge($results, $pageResults);
            $startIndex += self::MAX_RESULTS_PER_REQUEST;

            if ($page < $maxPages - 1) {
                $this->rateLimit();
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * @return array<DiscoveryResult>
     */
    private function fetchPage(string $query, int $startIndex): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => [
                    'key' => $this->apiKey,
                    'cx' => $this->searchEngineId,
                    'q' => $query,
                    'start' => $startIndex,
                    'num' => self::MAX_RESULTS_PER_REQUEST,
                ],
            ]);

            $data = $response->toArray();

            if (!isset($data['items']) || !is_array($data['items'])) {
                $this->logger->debug('Google Search returned no items', [
                    'query' => $query,
                    'total_results' => $data['searchInformation']['totalResults'] ?? 'unknown',
                ]);

                return [];
            }

            $this->logger->debug('Google Search returned items', [
                'query' => $query,
                'items_count' => count($data['items']),
                'total_results' => $data['searchInformation']['totalResults'] ?? 'unknown',
            ]);

            $results = [];
            $skipped = 0;
            foreach ($data['items'] as $item) {
                $url = $item['link'] ?? null;

                if (!$url || !$this->isValidWebsiteUrl($url)) {
                    $skipped++;
                    $this->logger->debug('Skipped invalid URL from Google', ['url' => $url]);
                    continue;
                }

                // Use createResultWithExtraction to optionally extract emails/phones
                $results[] = $this->createResultWithExtraction($url, [
                    'title' => $item['title'] ?? null,
                    'snippet' => $item['snippet'] ?? null,
                    'query' => $query,
                    'source_type' => 'google_search',
                ]);
            }

            $this->logger->debug('Google Search page processed', [
                'query' => $query,
                'valid_results' => count($results),
                'skipped' => $skipped,
            ]);

            return $results;
        } catch (\Throwable $e) {
            $this->logger->error('Google Search API request failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
