<?php

declare(strict_types=1);

namespace App\Service\Extractor;

class CompanyNameExtractor implements ContactExtractorInterface
{
    // Legal form suffixes to detect company names
    private const LEGAL_FORMS = [
        's\.?\s*r\.?\s*o\.?',
        'spol\.\s*s\s*r\.?\s*o\.?',
        'a\.?\s*s\.?',
        'v\.?\s*o\.?\s*s\.?',
        'k\.?\s*s\.?',
        'z\.?\s*s\.?',
        's\.?\s*p\.?',
        'o\.?\s*p\.?\s*s\.?',
        'SE',
        'z\.?\s*ú\.?',
        'n\.?\s*o\.?',
    ];

    /**
     * Extract company names from HTML.
     *
     * @return array<string> Array of company names sorted by priority
     */
    public function extract(string $html): array
    {
        // Decode HTML entities
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $candidates = [];

        // 1. Schema.org Organization name (highest priority)
        $schemaNames = $this->extractSchemaOrgNames($html);
        foreach ($schemaNames as $name) {
            $candidates[] = ['name' => $name, 'priority' => 100];
        }

        // 2. Open Graph site_name meta tag
        if (preg_match('/<meta[^>]+(?:property|name)=["\']og:site_name["\'][^>]+content=["\']([^"\']+)["\']|<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']og:site_name["\']/i', $html, $matches)) {
            $name = trim($matches[1] ?: $matches[2]);
            if ($this->isValidCompanyName($name)) {
                $candidates[] = ['name' => $name, 'priority' => 90];
            }
        }

        // 3. Company name with legal form in footer or content
        $legalFormPattern = '(' . implode('|', self::LEGAL_FORMS) . ')';
        if (preg_match_all('/([A-ZÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ][A-Za-záčďéěíňóřšťúůýžÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ0-9\s\-&.,]+)\s*,?\s*' . $legalFormPattern . '/iu', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = trim($match[1]) . ', ' . trim($match[2]);
                $name = $this->cleanCompanyName($name);
                if ($this->isValidCompanyName($name)) {
                    $candidates[] = ['name' => $name, 'priority' => 80];
                }
            }
        }

        // 4. Copyright notice (© 2024 Company Name)
        if (preg_match_all('/[©&copy;]\s*(?:\d{4}\s*[-–]\s*)?\d{4}\s+([^<\n\r|]+?)(?:\s*[|,.\-<]|$)/iu', $html, $matches)) {
            foreach ($matches[1] as $name) {
                $name = $this->cleanCompanyName($name);
                if ($this->isValidCompanyName($name)) {
                    $candidates[] = ['name' => $name, 'priority' => 70];
                }
            }
        }

        // 5. Title tag (lowest priority, often contains page title)
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $matches)) {
            $title = trim($matches[1]);
            // Extract company name from title (often "Page Name | Company Name" or "Company Name - Something")
            $titleParts = preg_split('/\s*[|\-–—]\s*/', $title);
            if ($titleParts !== false) {
                foreach ($titleParts as $part) {
                    $part = trim($part);
                    if ($this->isValidCompanyName($part) && $this->looksLikeCompanyName($part)) {
                        $candidates[] = ['name' => $part, 'priority' => 50];
                    }
                }
            }
        }

        // Sort by priority and deduplicate
        usort($candidates, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        $seen = [];
        $result = [];

        foreach ($candidates as $candidate) {
            $normalized = mb_strtolower(trim($candidate['name']));
            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $result[] = $candidate['name'];
            }
        }

        return $result;
    }

    /**
     * Extract single company name (the highest priority one).
     */
    public function extractSingle(string $html): ?string
    {
        $names = $this->extract($html);

        return $names[0] ?? null;
    }

    /**
     * Extract company names from Schema.org JSON-LD.
     *
     * @return array<string>
     */
    private function extractSchemaOrgNames(string $html): array
    {
        $names = [];

        // Find JSON-LD scripts
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $jsonContent) {
                try {
                    $data = json_decode(trim($jsonContent), true, 512, JSON_THROW_ON_ERROR);

                    // Handle both single object and array of objects
                    $items = isset($data['@graph']) ? $data['@graph'] : [$data];

                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $type = $item['@type'] ?? '';
                        $types = is_array($type) ? $type : [$type];

                        // Check for Organization types
                        $orgTypes = ['Organization', 'LocalBusiness', 'Corporation', 'Company', 'Store', 'Restaurant', 'Hotel'];
                        $isOrg = !empty(array_intersect($types, $orgTypes));

                        if ($isOrg && isset($item['name'])) {
                            $name = is_array($item['name']) ? ($item['name'][0] ?? '') : $item['name'];
                            if ($this->isValidCompanyName($name)) {
                                $names[] = $name;
                            }
                        }
                    }
                } catch (\JsonException) {
                    // Invalid JSON, skip
                }
            }
        }

        // Also check microdata
        if (preg_match('/<[^>]+itemtype=["\'][^"\']*(?:Organization|LocalBusiness)[^"\']*["\'][^>]*>.*?<[^>]+itemprop=["\']name["\'][^>]*>([^<]+)</is', $html, $matches)) {
            $name = trim($matches[1]);
            if ($this->isValidCompanyName($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Clean and normalize company name.
     */
    private function cleanCompanyName(string $name): string
    {
        // Remove extra whitespace
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        // Remove leading/trailing punctuation except periods (for s.r.o.)
        $name = trim($name, " \t\n\r\0\x0B,;:-–—|");

        // Normalize legal form format
        $name = preg_replace('/,\s*,/', ',', $name) ?? $name;

        return trim($name);
    }

    /**
     * Check if the string is a valid company name.
     */
    private function isValidCompanyName(string $name): bool
    {
        // Must have at least 2 characters
        if (mb_strlen($name) < 2) {
            return false;
        }

        // Must not be too long
        if (mb_strlen($name) > 200) {
            return false;
        }

        // Must contain at least one letter
        if (!preg_match('/\p{L}/u', $name)) {
            return false;
        }

        // Reject common non-company strings
        $rejectPatterns = [
            '/^(home|úvod|kontakt|o nás|about|contact|services|služby)$/iu',
            '/^(hlavní strana|hlavní stránka|domů)$/iu',
            '/^(menu|navigation|footer|header)$/iu',
            '/^\d+$/', // Just numbers
            '/^https?:\/\//i', // URLs
        ];

        foreach ($rejectPatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the string looks like a company name (not a page title).
     */
    private function looksLikeCompanyName(string $name): bool
    {
        // Contains legal form suffix
        $legalFormPattern = '/(' . implode('|', self::LEGAL_FORMS) . ')/iu';
        if (preg_match($legalFormPattern, $name)) {
            return true;
        }

        // Starts with capital letter and is short enough
        if (preg_match('/^[A-ZÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ]/u', $name) && mb_strlen($name) <= 50) {
            return true;
        }

        return false;
    }
}
