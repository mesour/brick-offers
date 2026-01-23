<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

class SecurityAnalyzer extends AbstractLeadAnalyzer
{
    /**
     * Security headers to check - maps header name to issue code.
     *
     * @var array<string, string>
     */
    private const SECURITY_HEADERS = [
        'content-security-policy' => 'security_missing_content_security_policy',
        'x-frame-options' => 'security_missing_x_frame_options',
        'x-content-type-options' => 'security_missing_x_content_type_options',
        'strict-transport-security' => 'security_missing_strict_transport_security',
        'referrer-policy' => 'security_missing_referrer_policy',
        'permissions-policy' => 'security_missing_permissions_policy',
    ];

    public function getCategory(): IssueCategory
    {
        return IssueCategory::SECURITY;
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function getDescription(): string
    {
        return 'Kontroluje bezpečnostní HTTP hlavičky (CSP, HSTS, X-Frame-Options, atd.).';
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
            'headers' => [],
            'missingHeaders' => [],
            'presentHeaders' => [],
        ];

        $result = $this->fetchUrl($url);

        if ($result['error'] !== null) {
            return AnalyzerResult::failure($this->getCategory(), 'Failed to fetch URL: ' . $result['error']);
        }

        $rawData['headers'] = $result['headers'];

        // Check for security headers
        foreach (self::SECURITY_HEADERS as $headerName => $issueCode) {
            if (!isset($result['headers'][$headerName]) || empty($result['headers'][$headerName])) {
                $rawData['missingHeaders'][] = $headerName;
                $issues[] = $this->createIssue($issueCode, "Hlavička '{$headerName}' nebyla nalezena v odpovědi serveru");
            } else {
                $rawData['presentHeaders'][$headerName] = $result['headers'][$headerName];
            }
        }

        // Additional security checks
        $additionalIssues = $this->performAdditionalSecurityChecks($result['content'] ?? '', $result['headers']);
        $issues = array_merge($issues, $additionalIssues['issues']);
        $rawData['additionalChecks'] = $additionalIssues['data'];

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @param array<string, string> $headers
     * @return array{issues: array<Issue>, data: array<string, mixed>}
     */
    private function performAdditionalSecurityChecks(string $content, array $headers): array
    {
        $issues = [];
        $data = [];

        // Check for server version disclosure
        $serverHeader = $headers['server'] ?? '';
        $data['serverHeader'] = $serverHeader;

        if (preg_match('/\d+\.\d+/', $serverHeader)) {
            $issues[] = $this->createIssue('security_server_version_disclosure', "Server: {$serverHeader}");
        }

        // Check for X-Powered-By header
        $poweredBy = $headers['x-powered-by'] ?? '';
        $data['xPoweredBy'] = $poweredBy;

        if (!empty($poweredBy)) {
            $issues[] = $this->createIssue('security_x_powered_by', "X-Powered-By: {$poweredBy}");
        }

        // Check for inline scripts without nonce/hash (basic check)
        if (preg_match('/<script(?![^>]*\s(?:src|nonce)=)[^>]*>/i', $content)) {
            $data['hasInlineScripts'] = true;
            // Only flag if CSP is present but inline scripts are found
            if (isset($headers['content-security-policy'])) {
                $data['inlineScriptsWithCsp'] = true;
            }
        }

        // Check for forms without HTTPS action
        if (preg_match('/<form[^>]+action=["\']http:\/\/[^"\']+["\'][^>]*>/i', $content)) {
            $data['insecureFormAction'] = true;
            $issues[] = $this->createIssue('security_insecure_form', 'Form action používá http:// místo https://');
        }

        return ['issues' => $issues, 'data' => $data];
    }
}
