<?php

declare(strict_types=1);

namespace App\Service\Ares;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AresClient
{
    private const API_URL = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty';

    private const REQUEST_DELAY_MS = 200;

    private LoggerInterface $logger;
    private ?float $lastRequestTime = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Fetch company data by IČO from ARES API.
     */
    public function fetchByIco(string $ico): ?AresData
    {
        // Validate IČO format
        if (!preg_match('/^\d{8}$/', $ico)) {
            $this->logger->warning('Invalid IČO format', ['ico' => $ico]);

            return null;
        }

        // Rate limiting - ensure at least 200ms between requests
        $this->rateLimit();

        $url = sprintf('%s/%s', self::API_URL, $ico);

        try {
            $this->logger->debug('Fetching ARES data', ['ico' => $ico, 'url' => $url]);

            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                $this->logger->info('IČO not found in ARES', ['ico' => $ico]);

                return null;
            }

            if ($statusCode >= 400) {
                $this->logger->warning('ARES API error', [
                    'ico' => $ico,
                    'status' => $statusCode,
                ]);

                return null;
            }

            $data = $response->toArray();

            $this->logger->info('Successfully fetched ARES data', [
                'ico' => $ico,
                'name' => $data['obchodniJmeno'] ?? $data['nazev'] ?? 'unknown',
            ]);

            return AresData::fromApiResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('Exception while fetching ARES data', [
                'ico' => $ico,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Batch fetch multiple IČOs.
     *
     * @param array<string> $icos
     * @return array<string, AresData|null> Map of IČO => AresData (or null if not found)
     */
    public function fetchBatch(array $icos): array
    {
        $results = [];

        foreach ($icos as $ico) {
            $results[$ico] = $this->fetchByIco($ico);
        }

        return $results;
    }

    /**
     * Ensure rate limiting between requests.
     */
    private function rateLimit(): void
    {
        if ($this->lastRequestTime !== null) {
            $elapsed = (microtime(true) - $this->lastRequestTime) * 1000;
            $remaining = self::REQUEST_DELAY_MS - $elapsed;

            if ($remaining > 0) {
                usleep((int) ($remaining * 1000));
            }
        }

        $this->lastRequestTime = microtime(true);
    }
}
