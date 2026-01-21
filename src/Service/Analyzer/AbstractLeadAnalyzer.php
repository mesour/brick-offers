<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\IssueCategory;
use App\Enum\IssueSeverity;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

abstract class AbstractLeadAnalyzer implements LeadAnalyzerInterface
{
    protected int $timeout = 10;
    protected int $maxRedirects = 5;

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
    ) {}

    public function supports(IssueCategory $category): bool
    {
        return $category === $this->getCategory();
    }

    /**
     * Get the industries this analyzer supports.
     * Default implementation: empty array = universal analyzer (runs for all industries).
     *
     * @return array<Industry>
     */
    public function getSupportedIndustries(): array
    {
        return [];
    }

    /**
     * Check if this analyzer is universal (runs for all industries).
     * Default implementation: true (universal).
     */
    public function isUniversal(): bool
    {
        return empty($this->getSupportedIndustries());
    }

    /**
     * Check if this analyzer should run for a specific industry.
     * Universal analyzers run for all industries including null.
     * Industry-specific analyzers only run for their supported industries.
     */
    public function supportsIndustry(?Industry $industry): bool
    {
        // Universal analyzers run for all industries
        if ($this->isUniversal()) {
            return true;
        }

        // Industry-specific analyzers require a matching industry
        if ($industry === null) {
            return false;
        }

        return in_array($industry, $this->getSupportedIndustries(), true);
    }

    /**
     * Fetch URL content with standard error handling.
     *
     * @return array{response: ?ResponseInterface, content: ?string, error: ?string, statusCode: ?int, headers: array<string, string>}
     */
    protected function fetchUrl(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => $this->timeout,
                'max_redirects' => $this->maxRedirects,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; WebAnalyzerBot/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'cs,en;q=0.9',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            $headers = $this->normalizeHeaders($response->getHeaders(false));

            return [
                'response' => $response,
                'content' => $content,
                'error' => null,
                'statusCode' => $statusCode,
                'headers' => $headers,
            ];
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Failed to fetch URL: {url}, error: {error}', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'response' => null,
                'content' => null,
                'error' => $e->getMessage(),
                'statusCode' => null,
                'headers' => [],
            ];
        }
    }

    /**
     * Normalize response headers (flatten arrays to single values).
     *
     * @param array<string, array<string>> $headers
     * @return array<string, string>
     */
    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower($name)] = $values[0] ?? '';
        }

        return $normalized;
    }

    /**
     * Create an issue from registry definition.
     * Only code and evidence are needed, metadata comes from IssueRegistry.
     */
    protected function createIssue(string $code, ?string $evidence = null): Issue
    {
        $def = IssueRegistry::get($code);

        if ($def === null) {
            throw new \InvalidArgumentException(sprintf('Unknown issue code "%s". Add it to IssueRegistry.', $code));
        }

        return new Issue(
            category: $def['category'],
            severity: $def['severity'],
            code: $code,
            title: $def['title'],
            description: $def['description'],
            evidence: $evidence,
            impact: $def['impact'],
        );
    }

    /**
     * Create an issue with custom metadata (for dynamic issues like accessibility violations).
     * Use this only when the issue is not in the registry.
     */
    protected function createCustomIssue(
        IssueSeverity $severity,
        string $code,
        string $title,
        string $description,
        ?string $evidence = null,
        ?string $impact = null,
    ): Issue {
        return new Issue(
            category: $this->getCategory(),
            severity: $severity,
            code: $code,
            title: $title,
            description: $description,
            evidence: $evidence,
            impact: $impact,
        );
    }

    /**
     * Check if URL uses HTTPS.
     */
    protected function isHttps(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://');
    }

    /**
     * Extract domain from URL.
     */
    protected function extractDomain(string $url): string
    {
        $parsed = parse_url($url);

        return $parsed['host'] ?? '';
    }

    /**
     * Build the base URL from lead URL.
     */
    protected function getBaseUrl(Lead $lead): string
    {
        $parsed = parse_url($lead->getUrl() ?? '');
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
