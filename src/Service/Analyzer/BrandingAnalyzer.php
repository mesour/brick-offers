<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

/**
 * Analyzes branding elements - favicon, logo, color consistency.
 */
class BrandingAnalyzer extends AbstractLeadAnalyzer
{
    // Default favicons from popular platforms
    private const DEFAULT_FAVICONS = [
        'wordpress' => '/wp-includes/images/w-logo-blue',
        'wix' => 'wix.com/favicon',
        'squarespace' => 'squarespace.com/favicon',
        'webnode' => 'webnode.com/favicon',
        'joomla' => 'joomla',
        'drupal' => 'drupal',
    ];

    // Maximum colors before flagging inconsistency
    private const MAX_BRAND_COLORS = 8;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::BRANDING;
    }

    public function getPriority(): int
    {
        return 60;
    }

    public function getDescription(): string
    {
        return 'Analyzuje branding - favicon, logo, konzistenci barev.';
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
            'hasFavicon' => false,
            'faviconUrl' => null,
            'isDefaultFavicon' => false,
            'hasLogo' => false,
            'logoUrl' => null,
            'colorCount' => 0,
            'brandingScore' => 100,
        ];

        // Check favicon
        $faviconCheck = $this->checkFavicon($content, $url);
        $rawData['hasFavicon'] = $faviconCheck['found'];
        $rawData['faviconUrl'] = $faviconCheck['url'];
        $rawData['isDefaultFavicon'] = $faviconCheck['isDefault'];

        if (!$faviconCheck['found']) {
            $rawData['brandingScore'] -= 20;
            $issues[] = $this->createIssue('branding_no_favicon', 'Favicon nebyl nalezen v <link> tagu');
        } elseif ($faviconCheck['isDefault']) {
            $rawData['brandingScore'] -= 15;
            $issues[] = $this->createIssue('branding_default_favicon', $faviconCheck['platform'] ?? 'Výchozí favicon platformy');
        }

        // Check logo
        $logoCheck = $this->checkLogo($content);
        $rawData['hasLogo'] = $logoCheck['found'];
        $rawData['logoUrl'] = $logoCheck['url'];
        $rawData['logoQuality'] = $logoCheck['quality'];

        if (!$logoCheck['found']) {
            $rawData['brandingScore'] -= 15;
            $issues[] = $this->createIssue('branding_no_logo', 'Logo nebylo nalezeno');
        } elseif ($logoCheck['quality'] === 'low') {
            $rawData['brandingScore'] -= 10;
            $issues[] = $this->createIssue('branding_low_quality_logo', $logoCheck['evidence'] ?? 'Malé rozměry');
        }

        // Check color consistency (from inline styles)
        $colorCheck = $this->checkColorConsistency($content);
        $rawData['colorCount'] = $colorCheck['uniqueColors'];
        $rawData['primaryColors'] = $colorCheck['primaryColors'];

        if ($colorCheck['uniqueColors'] > self::MAX_BRAND_COLORS) {
            $rawData['brandingScore'] -= 10;
            $issues[] = $this->createIssue(
                'branding_inconsistent_colors',
                sprintf('Nalezeno %d různých barev v inline stylech', $colorCheck['uniqueColors'])
            );
        }

        // Check for brand identity elements
        $identityCheck = $this->checkBrandIdentity($content);
        $rawData['hasOpenGraph'] = $identityCheck['hasOpenGraph'];
        $rawData['hasAppleTouchIcon'] = $identityCheck['hasAppleTouchIcon'];
        $rawData['hasThemeColor'] = $identityCheck['hasThemeColor'];

        if (!$identityCheck['hasOpenGraph'] && !$identityCheck['hasAppleTouchIcon'] && !$identityCheck['hasThemeColor']) {
            $rawData['brandingScore'] -= 10;
            $issues[] = $this->createIssue('branding_no_brand_identity', 'Chybí OG tagy, Apple touch icon i theme-color');
        }

        $rawData['brandingLevel'] = $this->calculateBrandingLevel($rawData['brandingScore']);

        $this->logger->info('Branding analysis completed', [
            'url' => $url,
            'brandingScore' => $rawData['brandingScore'],
            'issueCount' => count($issues),
        ]);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @return array{found: bool, url: ?string, isDefault: bool, platform: ?string}
     */
    private function checkFavicon(string $content, string $baseUrl): array
    {
        $faviconUrl = null;
        $isDefault = false;
        $platform = null;

        // Look for favicon link tags
        $patterns = [
            '/<link[^>]+rel\s*=\s*["\'](?:icon|shortcut icon)["\'][^>]+href\s*=\s*["\']([^"\']+)["\']/i',
            '/<link[^>]+href\s*=\s*["\']([^"\']+)["\'][^>]+rel\s*=\s*["\'](?:icon|shortcut icon)["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $faviconUrl = $matches[1];
                break;
            }
        }

        if ($faviconUrl !== null) {
            // Check if it's a default platform favicon
            foreach (self::DEFAULT_FAVICONS as $platformName => $defaultPattern) {
                if (stripos($faviconUrl, $defaultPattern) !== false) {
                    $isDefault = true;
                    $platform = ucfirst($platformName);
                    break;
                }
            }

            return [
                'found' => true,
                'url' => $faviconUrl,
                'isDefault' => $isDefault,
                'platform' => $platform,
            ];
        }

        return [
            'found' => false,
            'url' => null,
            'isDefault' => false,
            'platform' => null,
        ];
    }

    /**
     * @return array{found: bool, url: ?string, quality: string, evidence: ?string}
     */
    private function checkLogo(string $content): array
    {
        $logoUrl = null;
        $quality = 'unknown';
        $evidence = null;

        // Common logo patterns
        $logoPatterns = [
            '/<img[^>]+(?:class|id)\s*=\s*["\'][^"\']*logo[^"\']*["\'][^>]+src\s*=\s*["\']([^"\']+)["\']/i',
            '/<img[^>]+src\s*=\s*["\']([^"\']+)["\'][^>]+(?:class|id)\s*=\s*["\'][^"\']*logo[^"\']*["\']/i',
            '/<img[^>]+src\s*=\s*["\']([^"\']*logo[^"\']+)["\']/i',
            '/<img[^>]+alt\s*=\s*["\'][^"\']*logo[^"\']*["\'][^>]+src\s*=\s*["\']([^"\']+)["\']/i',
            '/<a[^>]+class\s*=\s*["\'][^"\']*logo[^"\']*["\'][^>]*>.*?<img[^>]+src\s*=\s*["\']([^"\']+)["\']/is',
        ];

        foreach ($logoPatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $logoUrl = $matches[1];
                break;
            }
        }

        if ($logoUrl !== null) {
            // Extract dimensions if available
            $fullMatch = '';
            if (preg_match('/<img[^>]+src\s*=\s*["\']' . preg_quote($logoUrl, '/') . '["\'][^>]*>/i', $content, $m)) {
                $fullMatch = $m[0];
            }

            $width = null;
            $height = null;

            if (preg_match('/width\s*=\s*["\']?(\d+)/i', $fullMatch, $m)) {
                $width = (int) $m[1];
            }
            if (preg_match('/height\s*=\s*["\']?(\d+)/i', $fullMatch, $m)) {
                $height = (int) $m[1];
            }

            // Check quality based on dimensions
            if ($width !== null && $width < 100) {
                $quality = 'low';
                $evidence = sprintf('Šířka: %dpx', $width);
            } elseif ($height !== null && $height < 30) {
                $quality = 'low';
                $evidence = sprintf('Výška: %dpx', $height);
            } elseif ($width !== null || $height !== null) {
                $quality = 'good';
            }

            return [
                'found' => true,
                'url' => $logoUrl,
                'quality' => $quality,
                'evidence' => $evidence,
            ];
        }

        return [
            'found' => false,
            'url' => null,
            'quality' => 'none',
            'evidence' => null,
        ];
    }

    /**
     * @return array{uniqueColors: int, primaryColors: array<string>}
     */
    private function checkColorConsistency(string $content): array
    {
        $colors = [];

        // Extract colors from inline styles
        $colorPatterns = [
            '/(?:color|background-color|border-color)\s*:\s*(#[0-9a-fA-F]{3,6})/i',
            '/(?:color|background-color|border-color)\s*:\s*(rgb\([^)]+\))/i',
        ];

        foreach ($colorPatterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            foreach ($matches[1] as $color) {
                $normalized = $this->normalizeColor($color);
                if ($normalized !== null) {
                    $colors[] = $normalized;
                }
            }
        }

        $uniqueColors = array_unique($colors);
        $colorCounts = array_count_values($colors);
        arsort($colorCounts);

        // Get top 5 most used colors
        $primaryColors = array_slice(array_keys($colorCounts), 0, 5);

        return [
            'uniqueColors' => count($uniqueColors),
            'primaryColors' => $primaryColors,
        ];
    }

    /**
     * @return array{hasOpenGraph: bool, hasAppleTouchIcon: bool, hasThemeColor: bool}
     */
    private function checkBrandIdentity(string $content): array
    {
        return [
            'hasOpenGraph' => (bool) preg_match('/<meta[^>]+property\s*=\s*["\']og:image["\']/i', $content),
            'hasAppleTouchIcon' => (bool) preg_match('/<link[^>]+rel\s*=\s*["\']apple-touch-icon["\']/i', $content),
            'hasThemeColor' => (bool) preg_match('/<meta[^>]+name\s*=\s*["\']theme-color["\']/i', $content),
        ];
    }

    private function normalizeColor(string $color): ?string
    {
        // Convert to lowercase
        $color = strtolower(trim($color));

        // Expand short hex colors
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $color, $m)) {
            $color = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }

        // Convert rgb to hex
        if (preg_match('/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i', $color, $m)) {
            $color = sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // Skip common colors (black, white, transparent)
        $skipColors = ['#000000', '#ffffff', '#000', '#fff'];
        if (in_array($color, $skipColors, true)) {
            return null;
        }

        return $color;
    }

    private function calculateBrandingLevel(int $score): string
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
