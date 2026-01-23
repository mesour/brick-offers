<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

/**
 * Analyzes navigation and UX elements.
 */
class NavigationUxAnalyzer extends AbstractLeadAnalyzer
{
    private const MAX_MENU_ITEMS = 7;
    private const MAX_NAV_DEPTH = 3;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::NAVIGATION_UX;
    }

    public function getPriority(): int
    {
        return 55;
    }

    public function getDescription(): string
    {
        return 'Analyzuje navigaci a UX prvky webu.';
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
            'mainMenuItems' => 0,
            'maxNavDepth' => 0,
            'hasSearch' => false,
            'hasBreadcrumbs' => false,
            'hasMobileMenu' => false,
            'brokenLinksIndicators' => 0,
            'navScore' => 100,
        ];

        // Check main navigation menu items
        $menuCheck = $this->checkMenuItems($content);
        $rawData['mainMenuItems'] = $menuCheck['count'];
        $rawData['menuItemLabels'] = $menuCheck['labels'];

        if ($menuCheck['count'] > self::MAX_MENU_ITEMS) {
            $rawData['navScore'] -= 15;
            $issues[] = $this->createIssue(
                'nav_too_many_items',
                sprintf('Nalezeno %d položek v hlavním menu (doporučeno max %d)', $menuCheck['count'], self::MAX_MENU_ITEMS)
            );
        }

        // Check navigation depth
        $depthCheck = $this->checkNavDepth($content);
        $rawData['maxNavDepth'] = $depthCheck['depth'];

        if ($depthCheck['depth'] > self::MAX_NAV_DEPTH) {
            $rawData['navScore'] -= 10;
            $issues[] = $this->createIssue(
                'nav_deep_hierarchy',
                sprintf('Nalezeno %d úrovní zanořených menu', $depthCheck['depth'])
            );
        }

        // Check for search functionality
        $searchCheck = $this->checkSearch($content);
        $rawData['hasSearch'] = $searchCheck['found'];
        $rawData['searchType'] = $searchCheck['type'];

        if (!$searchCheck['found']) {
            $rawData['navScore'] -= 5;
            $issues[] = $this->createIssue('nav_no_search', 'Vyhledávací pole nebylo nalezeno');
        }

        // Check for breadcrumbs
        $breadcrumbCheck = $this->checkBreadcrumbs($content);
        $rawData['hasBreadcrumbs'] = $breadcrumbCheck['found'];

        if (!$breadcrumbCheck['found']) {
            $rawData['navScore'] -= 5;
            $issues[] = $this->createIssue('nav_no_breadcrumbs', 'Drobečková navigace nebyla nalezena');
        }

        // Check for mobile menu
        $mobileCheck = $this->checkMobileMenu($content);
        $rawData['hasMobileMenu'] = $mobileCheck['found'];
        $rawData['mobileMenuType'] = $mobileCheck['type'];

        if (!$mobileCheck['found']) {
            $rawData['navScore'] -= 20;
            $issues[] = $this->createIssue('nav_no_mobile_menu', 'Hamburger menu nebo mobilní navigace nebyla nalezena');
        }

        // Check for potentially broken links (href="#" or javascript:void(0))
        $brokenCheck = $this->checkBrokenLinkPatterns($content);
        $rawData['brokenLinksIndicators'] = $brokenCheck['count'];

        if ($brokenCheck['count'] > 5) {
            $rawData['navScore'] -= 10;
            $issues[] = $this->createIssue(
                'nav_broken_links',
                sprintf('Nalezeno %d podezřelých odkazů (href="#", javascript:void(0))', $brokenCheck['count'])
            );
        }

        // Check for 404 page customization indicators
        $custom404Check = $this->check404Indicators($content);
        $rawData['hasCustom404Indicators'] = $custom404Check['found'];

        $rawData['navLevel'] = $this->calculateNavLevel($rawData['navScore']);

        $this->logger->info('Navigation UX analysis completed', [
            'url' => $url,
            'navScore' => $rawData['navScore'],
            'issueCount' => count($issues),
        ]);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * @return array{count: int, labels: array<string>}
     */
    private function checkMenuItems(string $content): array
    {
        $labels = [];

        // Look for main navigation patterns
        $navPatterns = [
            '/<nav[^>]*(?:id|class)\s*=\s*["\'][^"\']*(?:main|primary|header)[^"\']*["\'][^>]*>(.*?)<\/nav>/is',
            '/<(?:ul|div)[^>]*(?:id|class)\s*=\s*["\'][^"\']*(?:main-menu|primary-menu|nav-menu|navbar)[^"\']*["\'][^>]*>(.*?)<\/(?:ul|div)>/is',
            '/<header[^>]*>(.*?)<\/header>/is',
        ];

        $navContent = '';
        foreach ($navPatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $navContent = $matches[1];
                break;
            }
        }

        if (empty($navContent)) {
            // Fallback: look for first nav or menu element
            if (preg_match('/<nav[^>]*>(.*?)<\/nav>/is', $content, $matches)) {
                $navContent = $matches[1];
            }
        }

        if (!empty($navContent)) {
            // Count top-level menu items (direct li children or a tags)
            // This is a simplified approach - count anchor texts in nav
            preg_match_all('/<a[^>]*>([^<]+)<\/a>/i', $navContent, $matches);

            foreach ($matches[1] as $label) {
                $label = trim(strip_tags($label));
                if (!empty($label) && strlen($label) < 50) {
                    $labels[] = $label;
                }
            }
        }

        // Remove duplicates and limit
        $labels = array_unique(array_slice($labels, 0, 15));

        return [
            'count' => count($labels),
            'labels' => $labels,
        ];
    }

    /**
     * @return array{depth: int}
     */
    private function checkNavDepth(string $content): array
    {
        $maxDepth = 1;

        // Look for nested ul elements (submenu indicators)
        $patterns = [
            '/<ul[^>]*class\s*=\s*["\'][^"\']*sub-?menu[^"\']*["\']/i',
            '/<ul[^>]*class\s*=\s*["\'][^"\']*dropdown[^"\']*["\']/i',
            '/<li[^>]*class\s*=\s*["\'][^"\']*has-?(?:sub|children|dropdown)[^"\']*["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                // Estimate depth based on nested patterns
                $count = count($matches[0]);
                if ($count > 20) {
                    $maxDepth = 4;
                } elseif ($count > 10) {
                    $maxDepth = 3;
                } elseif ($count > 0) {
                    $maxDepth = 2;
                }
            }
        }

        return ['depth' => $maxDepth];
    }

    /**
     * @return array{found: bool, type: ?string}
     */
    private function checkSearch(string $content): array
    {
        $patterns = [
            '/<input[^>]+type\s*=\s*["\']search["\']/i' => 'HTML5 search input',
            '/<form[^>]+(?:action|class|id)\s*=\s*["\'][^"\']*search[^"\']*["\']/i' => 'Search form',
            '/<input[^>]+(?:name|id|class|placeholder)\s*=\s*["\'][^"\']*search[^"\']*["\']/i' => 'Search input',
            '/class\s*=\s*["\'][^"\']*search-?(?:box|form|field|input)[^"\']*["\']/i' => 'Search class',
            '/<button[^>]*>.*?(?:hled|search|vyhled).*?<\/button>/is' => 'Search button',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $content)) {
                return ['found' => true, 'type' => $type];
            }
        }

        return ['found' => false, 'type' => null];
    }

    /**
     * @return array{found: bool}
     */
    private function checkBreadcrumbs(string $content): array
    {
        $patterns = [
            '/class\s*=\s*["\'][^"\']*breadcrumb[^"\']*["\']/i',
            '/<nav[^>]+aria-label\s*=\s*["\']breadcrumb["\']/i',
            '/itemtype\s*=\s*["\'][^"\']*BreadcrumbList["\']/i',
            '/<ol[^>]*class\s*=\s*["\'][^"\']*(?:breadcrumb|crumbs)[^"\']*["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return ['found' => true];
            }
        }

        return ['found' => false];
    }

    /**
     * @return array{found: bool, type: ?string}
     */
    private function checkMobileMenu(string $content): array
    {
        $patterns = [
            '/class\s*=\s*["\'][^"\']*(?:hamburger|mobile-menu|burger|nav-toggle|menu-toggle)[^"\']*["\']/i' => 'Hamburger menu',
            '/class\s*=\s*["\'][^"\']*(?:navbar-toggler|menu-btn|mobile-nav)[^"\']*["\']/i' => 'Mobile nav toggle',
            '/<button[^>]*aria-label\s*=\s*["\'][^"\']*(?:menu|navigation|toggle)[^"\']*["\']/i' => 'Aria-labeled toggle',
            '/data-toggle\s*=\s*["\'](?:collapse|nav|menu)["\']/i' => 'Data toggle',
            '/class\s*=\s*["\'][^"\']*(?:offcanvas|sidebar-menu|slide-menu)[^"\']*["\']/i' => 'Offcanvas menu',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $content)) {
                return ['found' => true, 'type' => $type];
            }
        }

        return ['found' => false, 'type' => null];
    }

    /**
     * @return array{count: int}
     */
    private function checkBrokenLinkPatterns(string $content): array
    {
        $count = 0;

        $patterns = [
            '/href\s*=\s*["\']#["\']/i', // href="#"
            '/href\s*=\s*["\']javascript:\s*(?:void\s*\(\s*0\s*\)|;)["\']/i', // javascript:void(0)
            '/href\s*=\s*["\']javascript:["\']/i', // javascript:
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            $count += count($matches[0]);
        }

        return ['count' => $count];
    }

    /**
     * @return array{found: bool}
     */
    private function check404Indicators(string $content): array
    {
        // Check for 404 error page link or custom error handling
        $patterns = [
            '/(?:class|id)\s*=\s*["\'][^"\']*(?:error-?page|page-?404|not-?found)[^"\']*["\']/i',
            '/<title[^>]*>.*(?:404|not found|nenalezeno|stránka neexistuje).*<\/title>/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return ['found' => true];
            }
        }

        return ['found' => false];
    }

    private function calculateNavLevel(int $score): string
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
