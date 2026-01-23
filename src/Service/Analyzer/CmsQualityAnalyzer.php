<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

/**
 * Analyzes CMS/platform quality and identifies amateur/free platforms.
 */
class CmsQualityAnalyzer extends AbstractLeadAnalyzer
{
    // Free/amateur platforms that indicate need for redesign
    private const FREE_PLATFORMS = [
        'webzdarma' => 'Webzdarma.cz',
        'estranky' => 'eStránky.cz',
        'webgarden' => 'Webgarden',
        'webnode' => 'Webnode',
        'wix' => 'Wix',
        'weebly' => 'Weebly',
    ];

    // Platforms with subdomain patterns indicating free tier
    private const SUBDOMAIN_PATTERNS = [
        '/\.webnode\.(cz|com|sk)/i' => 'Webnode subdomain',
        '/\.wix\.com/i' => 'Wix subdomain',
        '/\.wixsite\.com/i' => 'Wix subdomain',
        '/\.squarespace\.com/i' => 'Squarespace subdomain',
        '/\.weebly\.com/i' => 'Weebly subdomain',
        '/\.webzdarma\.cz/i' => 'Webzdarma subdomain',
        '/\.estranky\.(cz|sk)/i' => 'eStránky subdomain',
        '/\.webgarden\.cz/i' => 'Webgarden subdomain',
        '/\.wordpress\.com/i' => 'WordPress.com subdomain',
        '/\.blogspot\.com/i' => 'Blogspot subdomain',
        '/\.blogger\.com/i' => 'Blogger subdomain',
        '/\.jimdo\.com/i' => 'Jimdo subdomain',
        '/\.site123\.me/i' => 'Site123 subdomain',
        '/\.mozello\.com/i' => 'Mozello subdomain',
        '/\.ucoz\.(cz|com|ru)/i' => 'uCoz subdomain',
    ];

    // Builder platforms with limitations
    private const BUILDER_PLATFORMS = ['wix', 'squarespace', 'webnode', 'weebly', 'jimdo'];

    // WordPress default theme identifiers
    private const WP_DEFAULT_THEMES = [
        'twentytwentyfour',
        'twentytwentythree',
        'twentytwentytwo',
        'twentytwentyone',
        'twentytwenty',
        'twentynineteen',
        'twentyseventeen',
        'twentysixteen',
        'twentyfifteen',
    ];

    // Platform ad indicators
    private const PLATFORM_AD_PATTERNS = [
        '/vytvořeno.*webnode/i',
        '/powered\s+by\s+webnode/i',
        '/powered\s+by\s+wix/i',
        '/wix\.com.*create/i',
        '/made\s+with\s+squarespace/i',
        '/powered\s+by\s+squarespace/i',
        '/webzdarma\.cz.*reklama/i',
        '/estranky\.cz.*banner/i',
        '/create.*free.*website/i',
        '/vytvořte.*web.*zdarma/i',
    ];

    public function getCategory(): IssueCategory
    {
        return IssueCategory::CMS_QUALITY;
    }

    public function getPriority(): int
    {
        return 80; // High priority - strong redesign signal
    }

    public function getDescription(): string
    {
        return 'Analyzuje kvalitu CMS/platformy a detekuje amatérské nebo bezplatné řešení.';
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
        $detectedCms = $lead->getDetectedCms();

        $rawData = [
            'url' => $url,
            'detectedCms' => $detectedCms,
            'isFreePlatform' => false,
            'isSubdomain' => false,
            'isBuilder' => false,
            'hasDefaultTheme' => false,
            'hasPlatformAds' => false,
            'qualityScore' => 100,
        ];

        // Check if domain is a subdomain of a platform
        $subdomainCheck = $this->checkSubdomain($url);
        if ($subdomainCheck['isSubdomain']) {
            $rawData['isSubdomain'] = true;
            $rawData['subdomainPlatform'] = $subdomainCheck['platform'];
            $rawData['qualityScore'] -= 40;
            $issues[] = $this->createIssue('cms_subdomain_hosting', $subdomainCheck['platform']);
        }

        // Check if using free/amateur platform
        if ($detectedCms !== null && isset(self::FREE_PLATFORMS[strtolower($detectedCms)])) {
            $rawData['isFreePlatform'] = true;
            $rawData['qualityScore'] -= 30;
            $issues[] = $this->createIssue('cms_free_platform', self::FREE_PLATFORMS[strtolower($detectedCms)]);
        }

        // Check if using builder with limitations
        if ($detectedCms !== null && in_array(strtolower($detectedCms), self::BUILDER_PLATFORMS, true)) {
            $rawData['isBuilder'] = true;
            if (!$rawData['isFreePlatform']) { // Don't duplicate if already flagged as free
                $rawData['qualityScore'] -= 15;
                $issues[] = $this->createIssue('cms_builder_limitations', ucfirst($detectedCms));
            }
        }

        // Check for WordPress default theme
        if ($detectedCms === 'wordpress') {
            $themeCheck = $this->checkWordPressDefaultTheme($content);
            if ($themeCheck['isDefault']) {
                $rawData['hasDefaultTheme'] = true;
                $rawData['defaultTheme'] = $themeCheck['theme'];
                $rawData['qualityScore'] -= 20;
                $issues[] = $this->createIssue('cms_wordpress_default_theme', 'Téma: ' . $themeCheck['theme']);
            }
        }

        // Check for platform advertisements/branding
        $adsCheck = $this->checkPlatformAds($content);
        if ($adsCheck['hasAds']) {
            $rawData['hasPlatformAds'] = true;
            $rawData['adEvidence'] = $adsCheck['evidence'];
            $rawData['qualityScore'] -= 25;
            $issues[] = $this->createIssue('cms_platform_ads', $adsCheck['evidence']);
        }

        // Calculate final quality level
        $rawData['qualityLevel'] = $this->calculateQualityLevel($rawData['qualityScore']);
        $rawData['needsRedesign'] = $rawData['qualityScore'] < 70;

        $this->logger->info('CMS quality analysis completed', [
            'url' => $url,
            'cms' => $detectedCms,
            'qualityScore' => $rawData['qualityScore'],
            'issueCount' => count($issues),
        ]);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @return array{isSubdomain: bool, platform: ?string}
     */
    private function checkSubdomain(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return ['isSubdomain' => false, 'platform' => null];
        }

        foreach (self::SUBDOMAIN_PATTERNS as $pattern => $platform) {
            if (preg_match($pattern, $host)) {
                return ['isSubdomain' => true, 'platform' => $platform];
            }
        }

        return ['isSubdomain' => false, 'platform' => null];
    }

    /**
     * @return array{isDefault: bool, theme: ?string}
     */
    private function checkWordPressDefaultTheme(string $content): array
    {
        foreach (self::WP_DEFAULT_THEMES as $theme) {
            // Check in stylesheet URL
            if (preg_match('/themes\/' . $theme . '/i', $content)) {
                return ['isDefault' => true, 'theme' => $theme];
            }
            // Check body class
            if (preg_match('/class="[^"]*theme-' . $theme . '/i', $content)) {
                return ['isDefault' => true, 'theme' => $theme];
            }
        }

        return ['isDefault' => false, 'theme' => null];
    }

    /**
     * @return array{hasAds: bool, evidence: ?string}
     */
    private function checkPlatformAds(string $content): array
    {
        foreach (self::PLATFORM_AD_PATTERNS as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                return ['hasAds' => true, 'evidence' => trim($matches[0])];
            }
        }

        // Check for common ad container IDs/classes
        $adSelectors = [
            'wix-ads',
            'wix-ad',
            'squarespace-branding',
            'webnode-banner',
            'webnode-footer-branding',
        ];

        foreach ($adSelectors as $selector) {
            if (stripos($content, $selector) !== false) {
                return ['hasAds' => true, 'evidence' => 'Detekován element: ' . $selector];
            }
        }

        return ['hasAds' => false, 'evidence' => null];
    }

    private function calculateQualityLevel(int $score): string
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
