<?php

declare(strict_types=1);

namespace App\Service\Extractor;

class EmailExtractor implements ContactExtractorInterface
{
    private const EMAIL_PATTERN = '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/i';
    private const MAILTO_PATTERN = '/mailto:([^"\'>\s?]+)/i';

    // Pattern for obfuscated emails using data attributes (e.g., brno.cz)
    // <a href="mailto:" data-mail="posta" data-domain="brno.cz">
    private const OBFUSCATED_EMAIL_PATTERN = '/data-mail=["\']([^"\']+)["\'][^>]*data-domain=["\']([^"\']+)["\']/i';
    private const OBFUSCATED_EMAIL_PATTERN_ALT = '/data-domain=["\']([^"\']+)["\'][^>]*data-mail=["\']([^"\']+)["\']/i';

    // Domains to ignore (fake/placeholder emails)
    private const IGNORED_DOMAINS = [
        'example.com',
        'example.org',
        'example.net',
        'domain.tld',
        'domain.com',
        'yourdomain.com',
        'email.com',
        'wixpress.com',
        'sentry.io',
        'sentry-next.wixpress.com',
        'placeholder.com',
        'test.com',
        'localhost',
    ];

    // File extensions that look like TLDs but are actually image/asset files
    // These often appear in src attributes like "image@2x.webp"
    private const IGNORED_EXTENSIONS = [
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'svg',
        'ico',
        'bmp',
        'tiff',
        'avif',
        'css',
        'js',
        'map',
        'woff',
        'woff2',
        'ttf',
        'eot',
        'otf',
    ];

    // Priority prefixes (higher index = lower priority)
    private const PRIORITY_PREFIXES = [
        // Highest priority - business/general contact
        ['info', 'kontakt', 'contact', 'objednavky', 'obchod', 'office', 'podpora', 'support', 'recepce'],
        // Medium priority - specific departments
        ['sales', 'marketing', 'fakturace', 'uctarna', 'hr', 'jobs', 'career', 'servis', 'service'],
        // Lower priority - personal emails (but still valid)
    ];

    /**
     * @return array<string>
     */
    public function extract(string $html): array
    {
        $emails = [];

        // Extract from mailto: links first (most reliable)
        if (preg_match_all(self::MAILTO_PATTERN, $html, $mailtoMatches)) {
            foreach ($mailtoMatches[1] as $email) {
                $email = $this->cleanEmail($email);
                if ($this->isValidEmail($email)) {
                    $emails[] = $email;
                }
            }
        }

        // Extract obfuscated emails (data-mail + data-domain pattern)
        // Used by sites like brno.cz to prevent scraping
        $obfuscatedEmails = $this->extractObfuscatedEmails($html);
        foreach ($obfuscatedEmails as $email) {
            if ($this->isValidEmail($email)) {
                $emails[] = $email;
            }
        }

        // Strip non-content elements that may contain asset filenames with @ symbols
        // (e.g., retina images like "logo@2x.webp")
        $cleanedHtml = $this->stripNonContentElements($html);

        // Extract from cleaned content
        if (preg_match_all(self::EMAIL_PATTERN, $cleanedHtml, $contentMatches)) {
            foreach ($contentMatches[0] as $email) {
                $email = $this->cleanEmail($email);
                if ($this->isValidEmail($email)) {
                    $emails[] = $email;
                }
            }
        }

        // Remove duplicates and sort by priority
        $emails = array_unique(array_map('strtolower', $emails));
        $emails = $this->sortByPriority($emails);

        return array_values($emails);
    }

    /**
     * Extract emails obfuscated using data-mail and data-domain attributes.
     *
     * Pattern: <a href="mailto:" data-mail="info" data-domain="example.cz">
     * Result: info@example.cz
     *
     * @return array<string>
     */
    private function extractObfuscatedEmails(string $html): array
    {
        $emails = [];

        // Pattern 1: data-mail before data-domain
        if (preg_match_all(self::OBFUSCATED_EMAIL_PATTERN, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $localPart = trim($match[1]);
                $domain = trim($match[2]);
                if (!empty($localPart) && !empty($domain)) {
                    $emails[] = strtolower($localPart . '@' . $domain);
                }
            }
        }

        // Pattern 2: data-domain before data-mail (reversed order)
        if (preg_match_all(self::OBFUSCATED_EMAIL_PATTERN_ALT, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $domain = trim($match[1]);
                $localPart = trim($match[2]);
                if (!empty($localPart) && !empty($domain)) {
                    $emails[] = strtolower($localPart . '@' . $domain);
                }
            }
        }

        return $emails;
    }

    /**
     * Remove HTML elements that typically contain asset URLs, not email addresses.
     */
    private function stripNonContentElements(string $html): string
    {
        // Remove <script> tags and content
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;

        // Remove <style> tags and content
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        // Remove <noscript> tags and content
        $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html) ?? $html;

        // Remove src attributes from img tags (retina images often have @2x in filename)
        $html = preg_replace('/<img\b[^>]*>/is', '', $html) ?? $html;

        // Remove srcset attributes (also contain image filenames)
        $html = preg_replace('/\bsrcset\s*=\s*["\'][^"\']*["\']/is', '', $html) ?? $html;

        // Remove background-image CSS (may contain image URLs)
        $html = preg_replace('/background(?:-image)?\s*:\s*url\([^)]+\)/is', '', $html) ?? $html;

        // Remove data-* attributes that often contain asset URLs
        $html = preg_replace('/\bdata-(?:src|background|image|srcset)\s*=\s*["\'][^"\']*["\']/is', '', $html) ?? $html;

        return $html;
    }

    private function cleanEmail(string $email): string
    {
        // Decode HTML entities (e.g., &#64; -> @)
        $email = html_entity_decode($email, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove URL encoding (e.g., %40 -> @)
        $email = urldecode($email);

        // Remove trailing punctuation
        $email = rtrim($email, '.,;:!?)');

        // Remove leading/trailing whitespace
        $email = trim($email);

        return strtolower($email);
    }

    private function isValidEmail(string $email): bool
    {
        // Basic format validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Extract domain
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }

        $domain = strtolower($parts[1]);

        // Check against ignored domains
        foreach (self::IGNORED_DOMAINS as $ignoredDomain) {
            if ($domain === $ignoredDomain || str_ends_with($domain, '.' . $ignoredDomain)) {
                return false;
            }
        }

        // Check if "domain" is actually a file extension (e.g., image@2x.webp)
        // Extract the TLD from the domain
        $domainParts = explode('.', $domain);
        $tld = end($domainParts);
        if (in_array($tld, self::IGNORED_EXTENSIONS, true)) {
            return false;
        }

        // Skip domains that look like filenames (contain mostly digits before extension)
        // e.g., "2x.57766654.webp" or "123456.png"
        if (preg_match('/^\d+\.\w+$/', $domain) || preg_match('/^[\dx]+\.\d+\.\w+$/', $domain)) {
            return false;
        }

        // Skip obvious fake patterns
        $localPart = $parts[0];
        $fakePatterns = ['your', 'email', 'name', 'user', 'xxx', 'test', 'sample', 'demo'];
        foreach ($fakePatterns as $pattern) {
            if ($localPart === $pattern || $localPart === $pattern . '@') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string> $emails
     * @return array<string>
     */
    private function sortByPriority(array $emails): array
    {
        usort($emails, function (string $a, string $b): int {
            $priorityA = $this->getEmailPriority($a);
            $priorityB = $this->getEmailPriority($b);

            return $priorityA - $priorityB;
        });

        return $emails;
    }

    private function getEmailPriority(string $email): int
    {
        $localPart = explode('@', $email)[0];

        foreach (self::PRIORITY_PREFIXES as $priorityLevel => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($localPart, $prefix)) {
                    return $priorityLevel;
                }
            }
        }

        // Default priority for personal/other emails
        return count(self::PRIORITY_PREFIXES);
    }
}
