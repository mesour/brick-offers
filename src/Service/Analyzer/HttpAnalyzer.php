<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

class HttpAnalyzer extends AbstractLeadAnalyzer
{
    private const TTFB_WARNING_THRESHOLD = 1.5; // seconds
    private const SSL_EXPIRY_WARNING_DAYS = 30;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::HTTP;
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function analyze(Lead $lead): AnalyzerResult
    {
        $url = $lead->getUrl();
        if ($url === null) {
            return AnalyzerResult::failure($this->getCategory(), 'Lead URL is null');
        }

        $issues = [];
        $rawData = [
            'url' => $url,
            'checks' => [],
        ];

        // Check SSL certificate
        $sslResult = $this->checkSslCertificate($url);
        $rawData['checks']['ssl'] = $sslResult;
        if ($sslResult['issue'] !== null) {
            $issues[] = $sslResult['issue'];
        }

        // Check HTTP response
        $httpResult = $this->checkHttpResponse($url);
        $rawData['checks']['http'] = $httpResult['data'];
        foreach ($httpResult['issues'] as $issue) {
            $issues[] = $issue;
        }

        // Check for mixed content (HTTP resources on HTTPS page)
        if ($httpResult['data']['content'] !== null) {
            $mixedContentResult = $this->checkMixedContent($url, $httpResult['data']['content']);
            $rawData['checks']['mixedContent'] = $mixedContentResult['data'];
            foreach ($mixedContentResult['issues'] as $issue) {
                $issues[] = $issue;
            }
        }

        // Check 404 handling
        $notFoundResult = $this->check404Handling($url);
        $rawData['checks']['notFound'] = $notFoundResult['data'];
        foreach ($notFoundResult['issues'] as $issue) {
            $issues[] = $issue;
        }

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @return array{valid: bool, expiresDays: ?int, error: ?string, issue: ?Issue}
     */
    private function checkSslCertificate(string $url): array
    {
        if (!$this->isHttps($url)) {
            return [
                'valid' => false,
                'expiresDays' => null,
                'error' => 'Not using HTTPS',
                'issue' => $this->createIssue('ssl_not_https', 'URL používá http:// místo https://'),
            ];
        }

        $domain = $this->extractDomain($url);
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            'ssl://' . $domain . ':443',
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            return [
                'valid' => false,
                'expiresDays' => null,
                'error' => "SSL connection failed: {$errstr}",
                'issue' => $this->createIssue('ssl_connection_failed', $errstr),
            ];
        }

        $params = stream_context_get_params($stream);
        fclose($stream);

        if (!isset($params['options']['ssl']['peer_certificate'])) {
            return [
                'valid' => false,
                'expiresDays' => null,
                'error' => 'Could not retrieve certificate',
                'issue' => null,
            ];
        }

        $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if ($cert === false) {
            return [
                'valid' => false,
                'expiresDays' => null,
                'error' => 'Could not parse certificate',
                'issue' => null,
            ];
        }

        $validTo = $cert['validTo_time_t'] ?? 0;
        $now = time();
        $daysUntilExpiry = (int) (($validTo - $now) / 86400);

        if ($daysUntilExpiry < 0) {
            return [
                'valid' => false,
                'expiresDays' => $daysUntilExpiry,
                'error' => 'Certificate expired',
                'issue' => $this->createIssue('ssl_expired', 'Certifikát vypršel před ' . abs($daysUntilExpiry) . ' dny'),
            ];
        }

        if ($daysUntilExpiry < self::SSL_EXPIRY_WARNING_DAYS) {
            return [
                'valid' => true,
                'expiresDays' => $daysUntilExpiry,
                'error' => null,
                'issue' => $this->createIssue('ssl_expiring_soon', "Certifikát vyprší za {$daysUntilExpiry} dní"),
            ];
        }

        return [
            'valid' => true,
            'expiresDays' => $daysUntilExpiry,
            'error' => null,
            'issue' => null,
        ];
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkHttpResponse(string $url): array
    {
        $issues = [];
        $startTime = microtime(true);

        $result = $this->fetchUrl($url);
        $ttfb = microtime(true) - $startTime;

        $data = [
            'statusCode' => $result['statusCode'],
            'ttfb' => round($ttfb, 3),
            'error' => $result['error'],
            'content' => $result['content'],
        ];

        if ($result['error'] !== null) {
            $issues[] = $this->createIssue('http_connection_failed', $result['error']);

            return ['data' => $data, 'issues' => $issues];
        }

        // Check TTFB
        if ($ttfb > self::TTFB_WARNING_THRESHOLD) {
            $issues[] = $this->createIssue('http_slow_ttfb', 'TTFB: ' . round($ttfb, 2) . 's');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkMixedContent(string $url, string $content): array
    {
        $issues = [];
        $httpResources = [];

        if (!$this->isHttps($url)) {
            return ['data' => ['checked' => false], 'issues' => $issues];
        }

        // Check for HTTP resources in HTTPS page
        $patterns = [
            '/<img[^>]+src=["\']http:\/\/[^"\']+["\'][^>]*>/i',
            '/<script[^>]+src=["\']http:\/\/[^"\']+["\'][^>]*>/i',
            '/<link[^>]+href=["\']http:\/\/[^"\']+["\'][^>]*>/i',
            '/<iframe[^>]+src=["\']http:\/\/[^"\']+["\'][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                $httpResources = array_merge($httpResources, $matches[0]);
            }
        }

        $httpResources = array_unique($httpResources);
        $data = [
            'checked' => true,
            'mixedContentCount' => count($httpResources),
            'examples' => array_slice($httpResources, 0, 3),
        ];

        if (count($httpResources) > 0) {
            $issues[] = $this->createIssue('http_mixed_content', 'Nalezeno ' . count($httpResources) . ' HTTP zdrojů');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function check404Handling(string $url): array
    {
        $issues = [];
        $testUrl = rtrim($url, '/') . '/surely-nonexistent-page-' . time() . '-test';

        $result = $this->fetchUrl($testUrl);

        $data = [
            'testUrl' => $testUrl,
            'statusCode' => $result['statusCode'],
            'error' => $result['error'],
        ];

        if ($result['statusCode'] === 200) {
            $issues[] = $this->createIssue('http_soft_404', 'Testovací URL vrátila status 200');
        }

        return ['data' => $data, 'issues' => $issues];
    }
}
