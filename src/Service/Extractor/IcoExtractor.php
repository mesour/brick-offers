<?php

declare(strict_types=1);

namespace App\Service\Extractor;

class IcoExtractor implements ContactExtractorInterface
{
    // Patterns to find IČO in text
    private const PATTERNS = [
        // IČO: 12345678 or IČ: 12345678
        '/I[ČC]O?\s*[:\s]\s*(\d{8})/iu',
        // IČ 12345678 (without colon)
        '/I[ČC]\s+(\d{8})/iu',
        // Identifikační číslo: 12345678
        '/identifika[čc]n[ií]\s+[čc][ií]slo\s*[:\s]\s*(\d{8})/iu',
        // Company ID: 12345678 (English)
        '/company\s*id\s*[:\s]\s*(\d{8})/iu',
        // Registration number
        '/registra[čc]n[ií]\s+[čc][ií]slo\s*[:\s]\s*(\d{8})/iu',
    ];

    /**
     * Extract IČO from HTML. Returns array with single IČO or empty array.
     *
     * @return array<string>
     */
    public function extract(string $html): array
    {
        // Decode HTML entities
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $ico) {
                    $ico = trim($ico);
                    if ($this->isValidIco($ico)) {
                        return [$ico];  // Return first valid IČO
                    }
                }
            }
        }

        return [];
    }

    /**
     * Extract single IČO from HTML.
     */
    public function extractSingle(string $html): ?string
    {
        $results = $this->extract($html);

        return $results[0] ?? null;
    }

    /**
     * Validate IČO using modulo 11 checksum.
     */
    public function isValidIco(string $ico): bool
    {
        // Must be exactly 8 digits
        if (!preg_match('/^\d{8}$/', $ico)) {
            return false;
        }

        // Modulo 11 checksum validation
        // Weights: 8, 7, 6, 5, 4, 3, 2
        $weights = [8, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $ico[$i] * $weights[$i];
        }

        $remainder = $sum % 11;

        // Calculate expected check digit
        $expectedCheckDigit = match ($remainder) {
            0 => 1,
            1 => 0,
            default => 11 - $remainder,
        };

        $actualCheckDigit = (int) $ico[7];

        return $expectedCheckDigit === $actualCheckDigit;
    }
}
