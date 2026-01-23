<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

/**
 * Analyzes conversion elements - CTAs, contact forms, trust signals.
 */
class ConversionAnalyzer extends AbstractLeadAnalyzer
{
    // Weak/generic CTA texts to flag
    private const WEAK_CTA_TEXTS = [
        'odeslat',
        'send',
        'submit',
        'klikněte zde',
        'click here',
        'více',
        'more',
        'zde',
        'here',
        'ok',
        'potvrdit',
        'confirm',
    ];

    // Strong CTA patterns (these are good)
    private const STRONG_CTA_PATTERNS = [
        '/získ(?:at|ejte)/i',
        '/objedn(?:at|ejte)/i',
        '/koupit/i',
        '/kontaktujte/i',
        '/zavolej/i',
        '/napište/i',
        '/stáhn(?:ěte|out)/i',
        '/vyzkoušet/i',
        '/registr/i',
        '/přihlásit/i',
        '/rezerv/i',
        '/začít/i',
        '/get started/i',
        '/buy now/i',
        '/order/i',
        '/contact us/i',
        '/free trial/i',
        '/sign up/i',
    ];

    public function getCategory(): IssueCategory
    {
        return IssueCategory::CONVERSION;
    }

    public function getPriority(): int
    {
        return 75;
    }

    public function getDescription(): string
    {
        return 'Analyzuje konverzní prvky - CTA, formuláře, signály důvěry.';
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
            'ctaCount' => 0,
            'hasStrongCta' => false,
            'hasWeakCta' => false,
            'hasContactForm' => false,
            'hasPhone' => false,
            'hasEmail' => false,
            'hasTrustSignals' => false,
            'hasSocialProof' => false,
            'conversionScore' => 100,
        ];

        // Check for CTA buttons/links
        $ctaCheck = $this->checkCta($content);
        $rawData['ctaCount'] = $ctaCheck['count'];
        $rawData['hasStrongCta'] = $ctaCheck['hasStrong'];
        $rawData['hasWeakCta'] = $ctaCheck['hasWeak'];
        $rawData['ctaExamples'] = $ctaCheck['examples'];

        if ($ctaCheck['count'] === 0) {
            $rawData['conversionScore'] -= 30;
            $issues[] = $this->createIssue('conversion_no_cta', 'Nebylo nalezeno žádné CTA tlačítko');
        } elseif (!$ctaCheck['hasStrong'] && $ctaCheck['hasWeak']) {
            $rawData['conversionScore'] -= 10;
            $issues[] = $this->createIssue(
                'conversion_weak_cta',
                'CTA texty: ' . implode(', ', array_slice($ctaCheck['examples'], 0, 3))
            );
        }

        // Check for contact form
        $formCheck = $this->checkContactForm($content);
        $rawData['hasContactForm'] = $formCheck['found'];
        $rawData['formType'] = $formCheck['type'];

        if (!$formCheck['found']) {
            $rawData['conversionScore'] -= 25;
            $issues[] = $this->createIssue('conversion_no_contact_form', 'Kontaktní formulář nebyl nalezen');
        }

        // Check for phone number
        $phoneCheck = $this->checkPhone($content);
        $rawData['hasPhone'] = $phoneCheck['found'];
        $rawData['phone'] = $phoneCheck['number'];

        if (!$phoneCheck['found']) {
            $rawData['conversionScore'] -= 10;
            $issues[] = $this->createIssue('conversion_no_phone', 'Telefonní číslo nebylo nalezeno');
        }

        // Check for email
        $emailCheck = $this->checkEmail($content);
        $rawData['hasEmail'] = $emailCheck['found'];
        $rawData['email'] = $emailCheck['email'];

        if (!$emailCheck['found']) {
            $rawData['conversionScore'] -= 10;
            $issues[] = $this->createIssue('conversion_no_email', 'E-mailová adresa nebyla nalezena');
        }

        // Check contact visibility
        if (!$phoneCheck['found'] && !$emailCheck['found'] && !$formCheck['found']) {
            // Already flagged individual issues, but add hidden contact
            $issues[] = $this->createIssue('conversion_hidden_contact', 'Žádný kontaktní údaj nebyl nalezen');
        }

        // Check for trust signals
        $trustCheck = $this->checkTrustSignals($content);
        $rawData['hasTrustSignals'] = $trustCheck['found'];
        $rawData['trustSignals'] = $trustCheck['signals'];

        if (!$trustCheck['found']) {
            $rawData['conversionScore'] -= 10;
            $issues[] = $this->createIssue('conversion_no_trust_signals', 'Nebyly nalezeny signály důvěry');
        }

        // Check for social proof
        $socialCheck = $this->checkSocialProof($content);
        $rawData['hasSocialProof'] = $socialCheck['found'];
        $rawData['socialProofTypes'] = $socialCheck['types'];

        if (!$socialCheck['found']) {
            $rawData['conversionScore'] -= 5;
            $issues[] = $this->createIssue('conversion_no_social_proof', 'Nebyl nalezen social proof');
        }

        $rawData['conversionLevel'] = $this->calculateConversionLevel($rawData['conversionScore']);

        $this->logger->info('Conversion analysis completed', [
            'url' => $url,
            'conversionScore' => $rawData['conversionScore'],
            'issueCount' => count($issues),
        ]);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @return array{count: int, hasStrong: bool, hasWeak: bool, examples: array<string>}
     */
    private function checkCta(string $content): array
    {
        $examples = [];
        $hasStrong = false;
        $hasWeak = false;

        // Find buttons and prominent links
        $buttonPatterns = [
            '/<button[^>]*>([^<]+)<\/button>/i',
            '/<a[^>]+class\s*=\s*["\'][^"\']*(?:btn|button|cta)[^"\']*["\'][^>]*>([^<]+)<\/a>/i',
            '/<input[^>]+type\s*=\s*["\']submit["\'][^>]+value\s*=\s*["\']([^"\']+)["\']/i',
            '/<a[^>]+href\s*=\s*["\'][^"\']*(?:kontakt|contact|objednat|order)[^"\']*["\'][^>]*>([^<]+)<\/a>/i',
        ];

        foreach ($buttonPatterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            foreach ($matches[1] as $text) {
                $text = trim(strip_tags($text));
                if (empty($text) || strlen($text) > 50) {
                    continue;
                }

                $examples[] = $text;

                // Check if strong CTA
                foreach (self::STRONG_CTA_PATTERNS as $strongPattern) {
                    if (preg_match($strongPattern, $text)) {
                        $hasStrong = true;
                        break;
                    }
                }

                // Check if weak CTA
                $textLower = mb_strtolower($text);
                if (in_array($textLower, self::WEAK_CTA_TEXTS, true)) {
                    $hasWeak = true;
                }
            }
        }

        $examples = array_unique($examples);

        return [
            'count' => count($examples),
            'hasStrong' => $hasStrong,
            'hasWeak' => $hasWeak,
            'examples' => array_slice($examples, 0, 10),
        ];
    }

    /**
     * @return array{found: bool, type: ?string}
     */
    private function checkContactForm(string $content): array
    {
        $patterns = [
            '/<form[^>]*(?:id|class|action)\s*=\s*["\'][^"\']*(?:contact|kontakt|poptav|inquiry)[^"\']*["\']/i' => 'Contact form',
            '/<form[^>]*>.*?(?:email|e-mail|telefon|phone|zpráva|message).*?<\/form>/is' => 'Form with contact fields',
            '/class\s*=\s*["\'][^"\']*(?:contact-form|inquiry-form|poptavka)[^"\']*["\']/i' => 'Contact form class',
            '/<textarea[^>]*(?:name|id)\s*=\s*["\'][^"\']*(?:message|zprava|dotaz)[^"\']*["\']/i' => 'Message textarea',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $content)) {
                return ['found' => true, 'type' => $type];
            }
        }

        // Check for generic form with email input
        if (preg_match('/<form[^>]*>.*?<input[^>]+type\s*=\s*["\']email["\'].*?<\/form>/is', $content)) {
            return ['found' => true, 'type' => 'Form with email input'];
        }

        return ['found' => false, 'type' => null];
    }

    /**
     * @return array{found: bool, number: ?string}
     */
    private function checkPhone(string $content): array
    {
        // Czech phone number patterns
        $patterns = [
            '/(?:tel|phone|telefon)[:\s]*([+]?420[\s-]?\d{3}[\s-]?\d{3}[\s-]?\d{3})/i',
            '/href\s*=\s*["\']tel:([+]?\d[\d\s-]{8,})["\']/',
            '/([+]420[\s-]?\d{3}[\s-]?\d{3}[\s-]?\d{3})/',
            '/(\d{3}[\s-]\d{3}[\s-]\d{3})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $number = preg_replace('/[\s-]/', '', $matches[1]);
                if (strlen($number) >= 9) {
                    return ['found' => true, 'number' => $matches[1]];
                }
            }
        }

        return ['found' => false, 'number' => null];
    }

    /**
     * @return array{found: bool, email: ?string}
     */
    private function checkEmail(string $content): array
    {
        // Look for email patterns
        $patterns = [
            '/href\s*=\s*["\']mailto:([^"\'?]+)["\']/',
            '/[\w.-]+@[\w.-]+\.\w{2,}/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $email = $matches[1] ?? $matches[0];
                // Filter out common generic/fake emails
                if (!preg_match('/example\.com|test\.com|email\.com|your-?email/i', $email)) {
                    return ['found' => true, 'email' => $email];
                }
            }
        }

        return ['found' => false, 'email' => null];
    }

    /**
     * @return array{found: bool, signals: array<string>}
     */
    private function checkTrustSignals(string $content): array
    {
        $signals = [];

        $patterns = [
            '/(?:ssl|secure|zabezpečen|šifrovan)/i' => 'Security mention',
            '/(?:certifik|certificate|iso\s*\d)/i' => 'Certification',
            '/(?:garanci|guarantee|záruk)/i' => 'Guarantee',
            '/(?:ocenění|award|vítěz|winner)/i' => 'Award',
            '/(?:partner|member|člen)/i' => 'Partnership',
            '/heureka/i' => 'Heureka',
            '/zbozi\.cz|zboží\.cz/i' => 'Zboží.cz',
            '/shoptet/i' => 'Shoptet badge',
            '/google\s*(?:partner|reviews)/i' => 'Google badge',
            '/facebook\s*(?:reviews|rating)/i' => 'Facebook reviews',
            '/trustpilot/i' => 'Trustpilot',
        ];

        foreach ($patterns as $pattern => $signal) {
            if (preg_match($pattern, $content)) {
                $signals[] = $signal;
            }
        }

        return [
            'found' => !empty($signals),
            'signals' => array_unique($signals),
        ];
    }

    /**
     * @return array{found: bool, types: array<string>}
     */
    private function checkSocialProof(string $content): array
    {
        $types = [];

        $patterns = [
            '/(?:recenz|review|hodnocen)/i' => 'Reviews',
            '/(?:testimoni|reference|doporuč)/i' => 'Testimonials',
            '/(?:spokojených|satisfied)\s*(?:zákazník|klient|customer)/i' => 'Customer count',
            '/(?:\d+\s*[+]?\s*(?:klient|zákazník|projekt|realizac))/i' => 'Stats',
            '/(?:case\s*stud|případov)/i' => 'Case studies',
            '/class\s*=\s*["\'][^"\']*(?:client-logo|partner-logo|trust-logo)[^"\']*["\']/i' => 'Client logos',
            '/(?:spolupracujeme|our clients|naši klienti)/i' => 'Client section',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $content)) {
                $types[] = $type;
            }
        }

        return [
            'found' => !empty($types),
            'types' => array_unique($types),
        ];
    }

    private function calculateConversionLevel(int $score): string
    {
        if ($score >= 90) {
            return 'excellent';
        }
        if ($score >= 70) {
            return 'good';
        }
        if ($score >= 50) {
            return 'fair';
        }

        return 'poor';
    }
}
