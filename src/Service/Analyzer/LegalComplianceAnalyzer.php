<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

/**
 * Analyzes legal compliance for Czech market - GDPR, cookies, company info.
 */
class LegalComplianceAnalyzer extends AbstractLeadAnalyzer
{
    public function getCategory(): IssueCategory
    {
        return IssueCategory::LEGAL_COMPLIANCE;
    }

    public function getPriority(): int
    {
        return 85; // High priority - legal requirements
    }

    public function getDescription(): string
    {
        return 'Analyzuje právní náležitosti - GDPR, cookies, identifikace provozovatele.';
    }

    public function analyze(Lead $lead): AnalyzerResult
    {
        $url = $lead->getUrl();
        if ($url === null) {
            return AnalyzerResult::failure($this->getCategory(), 'Lead URL is null');
        }

        $result = $this->fetchUrl($url);

        if ($result['error'] !== null || $result['content'] === null) {
            return AnalyzerResult::failure(
                $this->getCategory(),
                $result['error'] ?? 'Failed to fetch content'
            );
        }

        $content = $result['content'];
        $issues = [];

        $rawData = [
            'url' => $url,
            'hasCookieConsent' => false,
            'hasPrivacyPolicy' => false,
            'hasTerms' => false,
            'hasCompanyInfo' => false,
            'hasWithdrawalInfo' => false,
            'cookiesBeforeConsent' => false,
            'complianceScore' => 100,
        ];

        // Check for cookie consent banner
        $cookieCheck = $this->checkCookieConsent($content);
        $rawData['hasCookieConsent'] = $cookieCheck['found'];
        $rawData['cookieConsentType'] = $cookieCheck['type'];

        if (!$cookieCheck['found']) {
            // Check if site uses tracking (GA, FB Pixel, etc.)
            $hasTracking = $this->checkTrackingScripts($content);
            if ($hasTracking) {
                $rawData['complianceScore'] -= 30;
                $issues[] = $this->createIssue(
                    'legal_no_cookie_consent',
                    'Web používá sledovací skripty, ale nemá cookie consent'
                );
            }
        }

        // Check for cookies set before consent
        $earlyCheck = $this->checkCookiesBeforeConsent($content);
        if ($earlyCheck['likely']) {
            $rawData['cookiesBeforeConsent'] = true;
            $rawData['earlyTrackingScripts'] = $earlyCheck['scripts'];
            $rawData['complianceScore'] -= 15;
            $issues[] = $this->createIssue(
                'legal_cookies_before_consent',
                'Skripty: ' . implode(', ', array_slice($earlyCheck['scripts'], 0, 3))
            );
        }

        // Check for privacy policy
        $privacyCheck = $this->checkPrivacyPolicy($content);
        $rawData['hasPrivacyPolicy'] = $privacyCheck['found'];
        $rawData['privacyPolicyUrl'] = $privacyCheck['url'];

        if (!$privacyCheck['found']) {
            $rawData['complianceScore'] -= 25;
            $issues[] = $this->createIssue(
                'legal_no_privacy_policy',
                'Odkaz na zásady ochrany soukromí nebyl nalezen'
            );
        }

        // Check for terms and conditions (important for e-shops)
        $termsCheck = $this->checkTerms($content);
        $rawData['hasTerms'] = $termsCheck['found'];
        $rawData['termsUrl'] = $termsCheck['url'];

        // Only flag if site looks like an e-shop
        if (!$termsCheck['found'] && $this->looksLikeEshop($content)) {
            $rawData['complianceScore'] -= 20;
            $issues[] = $this->createIssue(
                'legal_no_terms',
                'Obchodní podmínky nebyly nalezeny (vypadá jako e-shop)'
            );
        }

        // Check for company information
        $companyCheck = $this->checkCompanyInfo($content);
        $rawData['hasCompanyInfo'] = $companyCheck['found'];
        $rawData['companyInfoDetails'] = $companyCheck['details'];

        if (!$companyCheck['found']) {
            $rawData['complianceScore'] -= 20;
            $issues[] = $this->createIssue(
                'legal_no_company_info',
                'IČO, sídlo ani název firmy nebyly nalezeny'
            );
        }

        // Check for withdrawal information (for e-shops)
        if ($this->looksLikeEshop($content)) {
            $withdrawalCheck = $this->checkWithdrawalInfo($content);
            $rawData['hasWithdrawalInfo'] = $withdrawalCheck['found'];

            if (!$withdrawalCheck['found']) {
                $rawData['complianceScore'] -= 10;
                $issues[] = $this->createIssue(
                    'legal_no_withdrawal_info',
                    'Informace o právu odstoupit od smlouvy nebyly nalezeny'
                );
            }
        }

        $rawData['complianceLevel'] = $this->calculateComplianceLevel($rawData['complianceScore']);

        $this->logger->info('Legal compliance analysis completed', [
            'url' => $url,
            'complianceScore' => $rawData['complianceScore'],
            'issueCount' => count($issues),
        ]);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @return array{found: bool, type: ?string}
     */
    private function checkCookieConsent(string $content): array
    {
        $patterns = [
            '/class\s*=\s*["\'][^"\']*(?:cookie-?(?:consent|banner|notice|bar|popup)|gdpr-?banner|cc-?banner)[^"\']*["\']/i' => 'Cookie consent class',
            '/id\s*=\s*["\'][^"\']*(?:cookie-?(?:consent|banner|notice|bar)|gdpr|cc_div)[^"\']*["\']/i' => 'Cookie consent ID',
            '/(?:cookie(?:bot|consent|yes|info|script)|tarteaucitron|osano|onetrust|cookiepro)/i' => 'Cookie consent service',
            '/data-(?:cookie|gdpr|consent)/i' => 'Cookie data attribute',
            '/(?:souhlas(?:ím)?\s*s\s*cookies|accept\s*cookies|přijmout\s*cookies)/i' => 'Cookie accept text',
            '/(?:nastavení\s*cookies|cookie\s*settings|upravit\s*cookies)/i' => 'Cookie settings text',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $content)) {
                return ['found' => true, 'type' => $type];
            }
        }

        return ['found' => false, 'type' => null];
    }

    private function checkTrackingScripts(string $content): bool
    {
        $trackingPatterns = [
            '/google-?analytics|gtag|ga\.js|analytics\.js/i',
            '/googletagmanager|gtm\.js/i',
            '/facebook\.net.*fbevents|fbq\(/i',
            '/hotjar\.com/i',
            '/linkedin\.com.*insight/i',
            '/connect\.facebook\.net/i',
            '/pixel/i',
        ];

        foreach ($trackingPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{likely: bool, scripts: array<string>}
     */
    private function checkCookiesBeforeConsent(string $content): array
    {
        $scripts = [];

        // Check if tracking scripts are loaded without waiting for consent
        // Look for scripts in <head> that aren't wrapped in consent logic

        // Extract head content
        if (!preg_match('/<head[^>]*>(.*?)<\/head>/is', $content, $headMatch)) {
            return ['likely' => false, 'scripts' => []];
        }

        $headContent = $headMatch[1];

        // Tracking scripts that shouldn't be in head without consent wrapper
        $trackingInHead = [
            '/gtag\s*\(\s*[\'"]config[\'"]/i' => 'Google Analytics',
            '/GoogleAnalyticsObject/i' => 'Google Analytics',
            '/fbq\s*\(\s*[\'"]init[\'"]/i' => 'Facebook Pixel',
            '/hotjar\.com/i' => 'Hotjar',
            '/_linkedin_partner_id/i' => 'LinkedIn Insight',
        ];

        foreach ($trackingInHead as $pattern => $name) {
            if (preg_match($pattern, $headContent)) {
                // Check if it's wrapped in consent logic
                $wrappedPatterns = [
                    '/if\s*\([^)]*(?:consent|cookie|gdpr)/i',
                    '/addEventListener\s*\([^)]*(?:consent|cookie)/i',
                ];

                $isWrapped = false;
                foreach ($wrappedPatterns as $wrapPattern) {
                    if (preg_match($wrapPattern, $headContent)) {
                        $isWrapped = true;
                        break;
                    }
                }

                if (!$isWrapped) {
                    $scripts[] = $name;
                }
            }
        }

        return [
            'likely' => !empty($scripts),
            'scripts' => array_unique($scripts),
        ];
    }

    /**
     * @return array{found: bool, url: ?string}
     */
    private function checkPrivacyPolicy(string $content): array
    {
        $patterns = [
            '/href\s*=\s*["\']([^"\']*(?:privacy|gdpr|soukrom|osobni-?udaje|ochrana-?(?:soukromi|udaju))[^"\']*)["\']/' => 'Privacy link',
            '/<a[^>]*>.*?(?:zásady\s*(?:ochrany\s*)?soukromí|privacy\s*policy|ochrana\s*(?:osobních\s*)?údajů|gdpr).*?<\/a>/is' => 'Privacy anchor text',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $content, $matches)) {
                return ['found' => true, 'url' => $matches[1] ?? null];
            }
        }

        return ['found' => false, 'url' => null];
    }

    /**
     * @return array{found: bool, url: ?string}
     */
    private function checkTerms(string $content): array
    {
        $patterns = [
            '/href\s*=\s*["\']([^"\']*(?:terms|conditions|obchodni-?podminky|vop|vseobecne-?podminky)[^"\']*)["\']/' => 'Terms link',
            '/<a[^>]*>.*?(?:obchodní\s*podmínky|terms|všeobecné\s*podmínky|vop).*?<\/a>/is' => 'Terms anchor text',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $content, $matches)) {
                return ['found' => true, 'url' => $matches[1] ?? null];
            }
        }

        return ['found' => false, 'url' => null];
    }

    /**
     * @return array{found: bool, details: array<string>}
     */
    private function checkCompanyInfo(string $content): array
    {
        $details = [];

        // Check for IČO (Czech company ID)
        if (preg_match('/(?:IČO?|IČ|IC)\s*:?\s*(\d{8})/i', $content, $matches)) {
            $details[] = 'IČO: ' . $matches[1];
        }

        // Check for DIČ (VAT ID)
        if (preg_match('/(?:DIČ|DIC)\s*:?\s*(CZ\d{8,10})/i', $content, $matches)) {
            $details[] = 'DIČ: ' . $matches[1];
        }

        // Check for company name patterns
        if (preg_match('/(?:provozovatel|společnost|firma)\s*:?\s*([^<,\n]{5,50}(?:s\.r\.o\.|a\.s\.|spol\.))/i', $content, $matches)) {
            $details[] = 'Firma: ' . trim($matches[1]);
        }

        // Check for address
        if (preg_match('/(?:sídlo|adresa)\s*:?\s*([^<\n]{10,100})/i', $content, $matches)) {
            $details[] = 'Adresa';
        }

        return [
            'found' => !empty($details),
            'details' => $details,
        ];
    }

    /**
     * @return array{found: bool}
     */
    private function checkWithdrawalInfo(string $content): array
    {
        $patterns = [
            '/(?:odstoupen|withdrawal|vrácen|return)/i',
            '/(?:14\s*(?:dní|dnů|den|days))/i',
            '/(?:právo\s*(?:na\s*)?(?:vrácení|odstoupení))/i',
            '/(?:reklamace|complaint)/i',
        ];

        $foundCount = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $foundCount++;
            }
        }

        return ['found' => $foundCount >= 2];
    }

    private function looksLikeEshop(string $content): bool
    {
        $eshopIndicators = [
            '/(?:košík|cart|nákupní)/i',
            '/(?:produkt|product).*(?:cena|price)/i',
            '/(?:objednat|order|koupit|buy)/i',
            '/(?:doprava|shipping|doručení)/i',
            '/(?:platba|payment)/i',
            '/class\s*=\s*["\'][^"\']*(?:add-to-cart|buy-button|product-price)[^"\']*["\']/i',
        ];

        $matches = 0;
        foreach ($eshopIndicators as $pattern) {
            if (preg_match($pattern, $content)) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    private function calculateComplianceLevel(int $score): string
    {
        if ($score >= 90) {
            return 'compliant';
        }
        if ($score >= 70) {
            return 'mostly_compliant';
        }
        if ($score >= 50) {
            return 'partially_compliant';
        }

        return 'non_compliant';
    }
}
