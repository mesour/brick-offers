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

        return new PageData(
            emails: $emails,
            phones: $phones,
            ico: $icoResults[0] ?? null,
            cms: $techData['cms'],
            technologies: $techData['technologies'],
            socialMedia: $socialMedia,
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
}
