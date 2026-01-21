<?php

declare(strict_types=1);

namespace App\Service\Extractor;

class PhoneExtractor implements ContactExtractorInterface
{
    // Czech phone patterns
    private const PATTERNS = [
        // International format: +420 123 456 789
        '/\+420[\s.\-]?\d{3}[\s.\-]?\d{3}[\s.\-]?\d{3}/u',
        // With country code without +: 00420 123 456 789
        '/00420[\s.\-]?\d{3}[\s.\-]?\d{3}[\s.\-]?\d{3}/u',
        // Local format: 123 456 789 (9 digits)
        '/(?<![0-9+])(\d{3})[\s.\-]?(\d{3})[\s.\-]?(\d{3})(?![0-9])/u',
        // Compact format: 123456789
        '/(?<![0-9+])(\d{9})(?![0-9])/u',
        // tel: links
        '/tel:[\s]*(\+?[0-9\s.\-]+)/i',
    ];

    // Patterns to exclude (not phone numbers)
    private const EXCLUDE_PATTERNS = [
        '/^\d{8}$/',  // IČO (8 digits)
        '/^[012]\d{8}$/',  // Invalid Czech phone (doesn't start with valid prefix)
    ];

    // Valid Czech mobile prefixes
    private const VALID_PREFIXES = [
        '6', '7',  // Mobile
        '2',       // Prague landline
        '3', '4', '5',  // Regional landlines
    ];

    /**
     * @return array<string>
     */
    public function extract(string $html): array
    {
        $phones = [];

        // Decode HTML entities
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[0] as $match) {
                    $normalized = $this->normalizePhone($match);
                    if ($normalized !== null && $this->isValidPhone($normalized)) {
                        $phones[] = $normalized;
                    }
                }
            }
        }

        // Remove duplicates
        $phones = array_unique($phones);

        return array_values($phones);
    }

    private function normalizePhone(string $phone): ?string
    {
        // Remove tel: prefix
        $phone = preg_replace('/^tel:\s*/i', '', $phone);

        // Remove all non-digit characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // Handle 00420 prefix
        if (str_starts_with($phone, '00420')) {
            $phone = '+420' . substr($phone, 5);
        }

        // Add +420 prefix if missing and looks like Czech number
        if (!str_starts_with($phone, '+')) {
            if (strlen($phone) === 9) {
                $phone = '+420' . $phone;
            } else {
                return null;
            }
        }

        // Validate length (+420 + 9 digits = 13 chars)
        if (strlen($phone) !== 13 || !str_starts_with($phone, '+420')) {
            return null;
        }

        return $phone;
    }

    private function isValidPhone(string $phone): bool
    {
        // Must be +420 format with 9 digits
        if (!preg_match('/^\+420\d{9}$/', $phone)) {
            return false;
        }

        // Extract the 9-digit local number
        $localNumber = substr($phone, 4);

        // Check exclusion patterns
        foreach (self::EXCLUDE_PATTERNS as $pattern) {
            if (preg_match($pattern, $localNumber)) {
                return false;
            }
        }

        // First digit must be valid prefix
        $firstDigit = $localNumber[0];
        if (!in_array($firstDigit, self::VALID_PREFIXES, true)) {
            return false;
        }

        // Avoid obviously fake numbers
        if (preg_match('/^(.)\1{8}$/', $localNumber)) {
            return false;  // All same digits like 111111111
        }

        if ($localNumber === '123456789') {
            return false;
        }

        return true;
    }
}
