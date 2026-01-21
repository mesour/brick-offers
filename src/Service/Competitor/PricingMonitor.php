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
 * Monitor for tracking changes in competitor pricing.
 * Detects price changes, new packages, and pricing structure changes.
 */
class PricingMonitor extends AbstractCompetitorMonitor
{
    // Common URL patterns for pricing pages
    private const PRICING_PATHS = [
        '/cenik',
        '/ceník',
        '/pricing',
        '/ceny',
        '/prices',
        '/sluzby',
        '/services',
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
        return CompetitorSnapshotType::PRICING;
    }

    protected function extractData(Lead $competitor): array
    {
        $baseUrl = rtrim($competitor->getUrl() ?? 'https://' . $competitor->getDomain(), '/');

        // Try to find pricing page
        $pricingUrl = $this->findPricingPage($baseUrl);
        if ($pricingUrl === null) {
            return [];
        }

        $html = $this->fetchHtml($pricingUrl);
        if ($html === null) {
            return [];
        }

        return $this->parsePricingPage($html, $pricingUrl);
    }

    private function findPricingPage(string $baseUrl): ?string
    {
        foreach (self::PRICING_PATHS as $path) {
            $url = $baseUrl . $path;

            try {
                $response = $this->httpClient->request('HEAD', $url, [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ],
                    'timeout' => 10,
                ]);

                if ($response->getStatusCode() === 200) {
                    return $url;
                }
            } catch (\Throwable $e) {
                // Try next path
            }

            $this->rateLimit();
        }

        // Check homepage for pricing link
        $homepage = $this->fetchHtml($baseUrl);
        if ($homepage !== null) {
            return $this->findPricingLink($homepage, $baseUrl);
        }

        return null;
    }

    private function findPricingLink(string $html, string $baseUrl): ?string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        $patterns = ['ceník', 'cenik', 'ceny', 'pricing', 'prices'];

        foreach ($patterns as $pattern) {
            $links = $xpath->query("//a[contains(translate(text(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZČŘÍÁÉÚŮ', 'abcdefghijklmnopqrstuvwxyzčříáéúů'), '{$pattern}')]");
            if ($links !== false && $links->length > 0) {
                $href = $links->item(0)->getAttribute('href');
                if (!empty($href)) {
                    return $this->makeAbsoluteUrl($href, $baseUrl);
                }
            }
        }

        return null;
    }

    private function parsePricingPage(string $html, string $url): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        $data = [
            'url' => $url,
            'packages' => [],
            'pricing_type' => 'unknown',
            'currency' => 'CZK',
            'has_custom_quote' => false,
            'price_range' => [
                'min' => null,
                'max' => null,
            ],
        ];

        // Detect pricing type
        $data['pricing_type'] = $this->detectPricingType($xpath, $html);

        // Check for custom quote option
        $customQuote = $xpath->query("//*[contains(translate(text(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'na míru')] | //*[contains(translate(text(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'individuální')] | //*[contains(translate(text(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'custom')]");
        $data['has_custom_quote'] = $customQuote !== false && $customQuote->length > 0;

        // Extract packages/tiers
        $packages = $this->extractPackages($xpath);
        $data['packages'] = $packages;

        // Calculate price range
        $prices = array_filter(array_column($packages, 'price'));
        if (!empty($prices)) {
            $data['price_range']['min'] = min($prices);
            $data['price_range']['max'] = max($prices);
        }

        // Detect currency
        if (preg_match('/€|EUR/', $html)) {
            $data['currency'] = 'EUR';
        }

        return $data;
    }

    private function detectPricingType(\DOMXPath $xpath, string $html): string
    {
        // Check for pricing tables/cards
        $tables = $xpath->query("//*[contains(@class, 'pricing')]");
        if ($tables !== false && $tables->length > 0) {
            return 'tiered';
        }

        // Check for hourly rates
        if (preg_match('/\bhod\b|\bhodinu?\b|\/h\b|hour/i', $html)) {
            return 'hourly';
        }

        // Check for project-based pricing
        if (preg_match('/od\s+\d|from\s+\d|projekt|project/i', $html)) {
            return 'project';
        }

        return 'fixed';
    }

    /**
     * @return array<array{name: string, price: ?float, period: ?string, features: array}>
     */
    private function extractPackages(\DOMXPath $xpath): array
    {
        $packages = [];

        // Try to find pricing cards/tiers
        $selectors = [
            "//div[contains(@class, 'pricing-card')]",
            "//div[contains(@class, 'price-card')]",
            "//div[contains(@class, 'pricing-table')]//div[contains(@class, 'col')]",
            "//div[contains(@class, 'package')]",
            "//div[contains(@class, 'plan')]",
            "//table[contains(@class, 'pricing')]//tr",
        ];

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes !== false && $nodes->length > 0) {
                foreach ($nodes as $node) {
                    $package = $this->parsePackage($node, $xpath);
                    if ($package !== null) {
                        $packages[] = $package;
                    }
                }

                if (!empty($packages)) {
                    break;
                }
            }
        }

        // If no structured packages found, try to extract prices from text
        if (empty($packages)) {
            $packages = $this->extractPricesFromText($xpath);
        }

        return $packages;
    }

    private function parsePackage(\DOMNode $node, \DOMXPath $xpath): ?array
    {
        // Extract package name
        $nameNode = $xpath->query(".//h2 | .//h3 | .//h4 | .//*[contains(@class, 'title')]", $node)->item(0);
        $name = $nameNode !== null ? trim($nameNode->textContent) : null;

        if (empty($name)) {
            return null;
        }

        // Extract price
        $priceNode = $xpath->query(".//*[contains(@class, 'price')] | .//*[contains(@class, 'amount')]", $node)->item(0);
        $price = null;
        if ($priceNode !== null) {
            $price = $this->parsePrice(trim($priceNode->textContent));
        }

        // If no price element found, try to find price in text
        if ($price === null) {
            $nodeText = $node->textContent;
            if (preg_match('/(\d[\d\s]*)\s*(Kč|CZK|€|EUR)/i', $nodeText, $matches)) {
                $price = $this->parsePrice($matches[1]);
            }
        }

        // Extract period
        $period = null;
        $nodeText = $node->textContent;
        if (preg_match('/měsíc|month/i', $nodeText)) {
            $period = 'month';
        } elseif (preg_match('/rok|year/i', $nodeText)) {
            $period = 'year';
        } elseif (preg_match('/projekt|project/i', $nodeText)) {
            $period = 'project';
        }

        // Extract features
        $features = [];
        $featureNodes = $xpath->query(".//li | .//*[contains(@class, 'feature')]", $node);
        if ($featureNodes !== false) {
            foreach ($featureNodes as $featureNode) {
                $feature = trim($featureNode->textContent);
                if (!empty($feature) && strlen($feature) < 200) {
                    $features[] = $feature;
                }
            }
        }

        return [
            'name' => $name,
            'price' => $price,
            'period' => $period,
            'features' => array_slice($features, 0, 20), // Limit features
        ];
    }

    /**
     * @return array<array{name: string, price: ?float, period: ?string, features: array}>
     */
    private function extractPricesFromText(\DOMXPath $xpath): array
    {
        $packages = [];

        // Find elements containing prices
        $pricePattern = '/(\d[\d\s]*)\s*(Kč|CZK|€|EUR)/i';

        $elements = $xpath->query("//*[contains(text(), 'Kč') or contains(text(), 'CZK') or contains(text(), '€')]");
        if ($elements !== false) {
            foreach ($elements as $element) {
                $text = trim($element->textContent);
                if (preg_match($pricePattern, $text, $matches)) {
                    $price = $this->parsePrice($matches[1]);
                    if ($price !== null && $price > 0) {
                        // Try to get a name from parent or sibling
                        $parent = $element->parentNode;
                        $name = null;

                        if ($parent !== null) {
                            $heading = $xpath->query(".//h2 | .//h3 | .//h4", $parent)->item(0);
                            if ($heading !== null) {
                                $name = trim($heading->textContent);
                            }
                        }

                        if ($name === null) {
                            $name = substr($text, 0, 50);
                        }

                        $packages[] = [
                            'name' => $name,
                            'price' => $price,
                            'period' => null,
                            'features' => [],
                        ];
                    }
                }
            }
        }

        return array_slice($packages, 0, 10);
    }

    private function parsePrice(string $priceText): ?float
    {
        $price = preg_replace('/[^0-9,.]/', '', $priceText);
        $price = str_replace(',', '.', $price);

        return !empty($price) ? (float) $price : null;
    }

    private function makeAbsoluteUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        $parsed = parse_url($baseUrl);
        $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        return $base . '/' . $url;
    }

    protected function calculateMetrics(array $rawData): array
    {
        return [
            'packages_count' => count($rawData['packages'] ?? []),
            'pricing_type' => $rawData['pricing_type'] ?? 'unknown',
            'price_min' => $rawData['price_range']['min'] ?? null,
            'price_max' => $rawData['price_range']['max'] ?? null,
            'currency' => $rawData['currency'] ?? 'CZK',
            'has_custom_quote' => $rawData['has_custom_quote'] ?? false,
        ];
    }

    public function detectChanges(CompetitorSnapshot $previous, CompetitorSnapshot $current): array
    {
        $changes = [];

        $prevData = $previous->getRawData();
        $currData = $current->getRawData();

        // Compare pricing type
        $prevType = $prevData['pricing_type'] ?? 'unknown';
        $currType = $currData['pricing_type'] ?? 'unknown';

        if ($prevType !== $currType) {
            $changes[] = [
                'field' => 'pricing_type',
                'before' => $prevType,
                'after' => $currType,
                'significance' => ChangeSignificance::HIGH->value,
            ];
        }

        // Compare price ranges
        $prevMin = $prevData['price_range']['min'] ?? null;
        $currMin = $currData['price_range']['min'] ?? null;

        if ($prevMin !== $currMin && $prevMin !== null && $currMin !== null) {
            $percentChange = abs($currMin - $prevMin) / $prevMin * 100;
            $significance = $percentChange >= 20 ? ChangeSignificance::HIGH : ($percentChange >= 10 ? ChangeSignificance::MEDIUM : ChangeSignificance::LOW);

            $changes[] = [
                'field' => 'price_min',
                'before' => $prevMin,
                'after' => $currMin,
                'significance' => $significance->value,
            ];
        }

        // Compare packages count
        $prevPackages = count($prevData['packages'] ?? []);
        $currPackages = count($currData['packages'] ?? []);

        if ($prevPackages !== $currPackages) {
            $changes[] = [
                'field' => 'packages_count',
                'before' => $prevPackages,
                'after' => $currPackages,
                'significance' => abs($currPackages - $prevPackages) >= 2 ? ChangeSignificance::HIGH->value : ChangeSignificance::MEDIUM->value,
            ];
        }

        // Compare individual package prices
        $prevPackagesByName = [];
        foreach ($prevData['packages'] ?? [] as $pkg) {
            $prevPackagesByName[$pkg['name']] = $pkg;
        }

        foreach ($currData['packages'] ?? [] as $pkg) {
            $name = $pkg['name'];
            if (isset($prevPackagesByName[$name])) {
                $prevPrice = $prevPackagesByName[$name]['price'] ?? null;
                $currPrice = $pkg['price'] ?? null;

                if ($prevPrice !== $currPrice && $prevPrice !== null && $currPrice !== null) {
                    $percentChange = abs($currPrice - $prevPrice) / $prevPrice * 100;
                    $significance = $percentChange >= 20 ? ChangeSignificance::HIGH : ($percentChange >= 10 ? ChangeSignificance::MEDIUM : ChangeSignificance::LOW);

                    $changes[] = [
                        'field' => "package_price_{$name}",
                        'before' => $prevPrice,
                        'after' => $currPrice,
                        'significance' => $significance->value,
                    ];
                }
            }
        }

        return $changes;
    }
}
