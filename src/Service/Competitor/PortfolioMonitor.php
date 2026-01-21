<?php

declare(strict_types=1);

namespace App\Service\Competitor;

use App\Entity\CompetitorSnapshot;
use App\Entity\Lead;
use App\Enum\ChangeSignificance;
use App\Enum\CompetitorSnapshotType;
use App\Repository\CompetitorSnapshotRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Monitor for tracking changes in competitor portfolios/references.
 * Detects new clients, removed clients, and portfolio structure changes.
 */
class PortfolioMonitor extends AbstractCompetitorMonitor
{
    // Common URL patterns for portfolio pages
    private const PORTFOLIO_PATHS = [
        '/portfolio',
        '/reference',
        '/references',
        '/nase-prace',
        '/projekty',
        '/work',
        '/projects',
        '/case-studies',
        '/realizace',
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        CompetitorSnapshotRepository $snapshotRepository,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $snapshotRepository, $logger);
    }

    public function getType(): CompetitorSnapshotType
    {
        return CompetitorSnapshotType::PORTFOLIO;
    }

    protected function extractData(Lead $competitor): array
    {
        $baseUrl = $this->getSourceUrl($competitor);

        // Try to find portfolio page
        $portfolioUrl = $this->findPortfolioPage($baseUrl);
        if ($portfolioUrl === null) {
            return [];
        }

        $html = $this->fetchHtml($portfolioUrl);
        if ($html === null) {
            return [];
        }

        return $this->parsePortfolioPage($html, $portfolioUrl);
    }

    protected function getSourceUrl(Lead $competitor): string
    {
        $url = $competitor->getUrl() ?? 'https://' . $competitor->getDomain();

        // Remove trailing slash
        return rtrim($url, '/');
    }

    private function findPortfolioPage(string $baseUrl): ?string
    {
        // First check if any of the common paths exist
        foreach (self::PORTFOLIO_PATHS as $path) {
            $url = $baseUrl . $path;

            try {
                $response = $this->httpClient->request('HEAD', $url, [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ],
                    'timeout' => 10,
                    'max_redirects' => 3,
                ]);

                if ($response->getStatusCode() === 200) {
                    return $url;
                }
            } catch (\Throwable $e) {
                // Try next path
            }

            $this->rateLimit();
        }

        // If no dedicated page found, try to find link from homepage
        $homepage = $this->fetchHtml($baseUrl);
        if ($homepage !== null) {
            $portfolioLink = $this->findPortfolioLink($homepage, $baseUrl);
            if ($portfolioLink !== null) {
                return $portfolioLink;
            }
        }

        return null;
    }

    private function findPortfolioLink(string $html, string $baseUrl): ?string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        // Look for links with portfolio-related text
        $patterns = ['portfolio', 'reference', 'práce', 'projekty', 'realizace', 'work', 'projects'];

        foreach ($patterns as $pattern) {
            $links = $xpath->query("//a[contains(translate(text(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '{$pattern}')]");
            if ($links !== false && $links->length > 0) {
                $href = $links->item(0)->getAttribute('href');
                if (!empty($href)) {
                    return $this->makeAbsoluteUrl($href, $baseUrl);
                }
            }
        }

        return null;
    }

    private function parsePortfolioPage(string $html, string $url): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        $data = [
            'url' => $url,
            'items' => [],
            'categories' => [],
            'clients' => [],
            'total_count' => 0,
            'layout_type' => 'unknown',
            'has_filters' => false,
            'has_case_studies' => false,
        ];

        // Detect layout type
        $data['layout_type'] = $this->detectLayoutType($xpath);

        // Detect filters
        $filters = $xpath->query("//*[contains(@class, 'filter')] | //*[contains(@class, 'category')]//a");
        $data['has_filters'] = $filters !== false && $filters->length > 0;

        // Extract portfolio items
        $items = $this->extractPortfolioItems($xpath);
        $data['items'] = $items;
        $data['total_count'] = count($items);

        // Extract unique clients
        $clients = [];
        foreach ($items as $item) {
            if (!empty($item['client'])) {
                $clients[] = $item['client'];
            }
        }
        $data['clients'] = array_unique($clients);

        // Extract categories
        $categories = [];
        foreach ($items as $item) {
            if (!empty($item['category'])) {
                $categories[] = $item['category'];
            }
        }
        $data['categories'] = array_unique($categories);

        // Check for case studies
        $caseStudies = $xpath->query("//*[contains(translate(text(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'case study')] | //*[contains(@class, 'case-study')]");
        $data['has_case_studies'] = $caseStudies !== false && $caseStudies->length > 0;

        return $data;
    }

    private function detectLayoutType(\DOMXPath $xpath): string
    {
        // Check for grid layout
        $grid = $xpath->query("//*[contains(@class, 'grid')] | //*[contains(@class, 'masonry')]");
        if ($grid !== false && $grid->length > 0) {
            return 'grid';
        }

        // Check for list layout
        $list = $xpath->query("//*[contains(@class, 'list')]");
        if ($list !== false && $list->length > 0) {
            return 'list';
        }

        // Check for slider/carousel
        $slider = $xpath->query("//*[contains(@class, 'slider')] | //*[contains(@class, 'carousel')] | //*[contains(@class, 'swiper')]");
        if ($slider !== false && $slider->length > 0) {
            return 'slider';
        }

        return 'unknown';
    }

    /**
     * @return array<array{title: string, client: ?string, url: ?string, category: ?string, image: ?string}>
     */
    private function extractPortfolioItems(\DOMXPath $xpath): array
    {
        $items = [];

        // Common portfolio item selectors
        $selectors = [
            "//article[contains(@class, 'portfolio')]",
            "//div[contains(@class, 'portfolio-item')]",
            "//div[contains(@class, 'project-item')]",
            "//div[contains(@class, 'work-item')]",
            "//div[contains(@class, 'reference-item')]",
            "//li[contains(@class, 'portfolio')]",
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes !== false && $nodes->length > 0) {
                foreach ($nodes as $node) {
                    $item = $this->parsePortfolioItem($node, $xpath);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
                break; // Use first matching selector
            }
        }

        // If no items found with specific selectors, try generic approach
        if (empty($items)) {
            $items = $this->extractGenericItems($xpath);
        }

        return $items;
    }

    private function parsePortfolioItem(\DOMNode $node, \DOMXPath $xpath): ?array
    {
        // Extract title
        $titleNode = $xpath->query(".//h2 | .//h3 | .//h4 | .//a[contains(@class, 'title')]", $node)->item(0);
        $title = $titleNode !== null ? trim($titleNode->textContent) : null;

        if (empty($title)) {
            return null;
        }

        // Extract URL
        $linkNode = $xpath->query(".//a", $node)->item(0);
        $url = $linkNode instanceof \DOMElement ? $linkNode->getAttribute('href') : null;

        // Extract client name
        $clientNode = $xpath->query(".//*[contains(@class, 'client')] | .//*[contains(@class, 'company')]", $node)->item(0);
        $client = $clientNode !== null ? trim($clientNode->textContent) : null;

        // Extract category
        $categoryNode = $xpath->query(".//*[contains(@class, 'category')] | .//*[contains(@class, 'tag')]", $node)->item(0);
        $category = $categoryNode !== null ? trim($categoryNode->textContent) : null;

        // Extract image
        $imageNode = $xpath->query(".//img", $node)->item(0);
        $image = $imageNode instanceof \DOMElement ? $imageNode->getAttribute('src') : null;

        return [
            'title' => $title,
            'client' => $client,
            'url' => $url,
            'category' => $category,
            'image' => $image,
        ];
    }

    /**
     * @return array<array{title: string, client: ?string, url: ?string, category: ?string, image: ?string}>
     */
    private function extractGenericItems(\DOMXPath $xpath): array
    {
        $items = [];

        // Look for any images with links that might be portfolio items
        $imageLinks = $xpath->query("//a[.//img]");
        if ($imageLinks !== false) {
            foreach ($imageLinks as $link) {
                $img = $xpath->query(".//img", $link)->item(0);
                $title = null;

                // Try to get title from alt text
                if ($img instanceof \DOMElement) {
                    $title = $img->getAttribute('alt');
                }

                // Or from title attribute
                if (empty($title) && $link instanceof \DOMElement) {
                    $title = $link->getAttribute('title');
                }

                if (!empty($title)) {
                    $items[] = [
                        'title' => $title,
                        'client' => null,
                        'url' => $link instanceof \DOMElement ? $link->getAttribute('href') : null,
                        'category' => null,
                        'image' => $img instanceof \DOMElement ? $img->getAttribute('src') : null,
                    ];
                }
            }
        }

        return array_slice($items, 0, 50); // Limit to avoid noise
    }

    private function makeAbsoluteUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (str_starts_with($url, '/')) {
            $parsed = parse_url($baseUrl);

            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $url;
        }

        return rtrim($baseUrl, '/') . '/' . $url;
    }

    protected function calculateMetrics(array $rawData): array
    {
        return [
            'total_items' => $rawData['total_count'] ?? 0,
            'unique_clients' => count($rawData['clients'] ?? []),
            'categories_count' => count($rawData['categories'] ?? []),
            'has_filters' => $rawData['has_filters'] ?? false,
            'has_case_studies' => $rawData['has_case_studies'] ?? false,
            'layout_type' => $rawData['layout_type'] ?? 'unknown',
        ];
    }

    public function detectChanges(CompetitorSnapshot $previous, CompetitorSnapshot $current): array
    {
        $changes = [];

        $prevData = $previous->getRawData();
        $currData = $current->getRawData();

        // Compare total count
        $prevCount = $prevData['total_count'] ?? 0;
        $currCount = $currData['total_count'] ?? 0;

        if ($prevCount !== $currCount) {
            $significance = $this->determineSignificance('total_count', $prevCount, $currCount);
            $changes[] = [
                'field' => 'total_count',
                'before' => $prevCount,
                'after' => $currCount,
                'significance' => $significance->value,
            ];
        }

        // Compare clients
        $prevClients = $prevData['clients'] ?? [];
        $currClients = $currData['clients'] ?? [];

        $newClients = array_diff($currClients, $prevClients);
        $removedClients = array_diff($prevClients, $currClients);

        if (!empty($newClients)) {
            $changes[] = [
                'field' => 'clients_added',
                'before' => null,
                'after' => array_values($newClients),
                'significance' => count($newClients) >= 3 ? ChangeSignificance::HIGH->value : ChangeSignificance::MEDIUM->value,
            ];
        }

        if (!empty($removedClients)) {
            $changes[] = [
                'field' => 'clients_removed',
                'before' => array_values($removedClients),
                'after' => null,
                'significance' => count($removedClients) >= 3 ? ChangeSignificance::HIGH->value : ChangeSignificance::MEDIUM->value,
            ];
        }

        // Compare categories
        $prevCategories = $prevData['categories'] ?? [];
        $currCategories = $currData['categories'] ?? [];

        if ($prevCategories !== $currCategories) {
            $changes[] = [
                'field' => 'categories',
                'before' => $prevCategories,
                'after' => $currCategories,
                'significance' => ChangeSignificance::MEDIUM->value,
            ];
        }

        return $changes;
    }
}
