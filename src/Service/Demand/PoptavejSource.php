<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use App\Enum\Industry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demand signal source for Poptavej.cz - Czech B2B demand portal.
 *
 * Over 200k registered companies.
 */
class PoptavejSource extends AbstractDemandSource
{
    private const BASE_URL = 'https://www.poptavej.cz';
    private const LISTING_URL = 'https://www.poptavej.cz/poptavky';

    /**
     * Industry to poptavej.cz category slug mapping.
     * Categories use URL format: /poptavky/{category}
     *
     * Verified categories (January 2026):
     * informacni-technologie, reality, stavebnictvi, stavebni-material,
     * doprava-a-logistika, potravinarstvi, sluzby, reklama-a-tisk, remesla,
     * elektro, energetika, hobby, nabytek, ostatni
     *
     * @var array<string, string>
     */
    private const INDUSTRY_CATEGORIES = [
        'webdesign' => 'informacni-technologie',
        'eshop' => 'informacni-technologie',
        'real_estate' => 'reality',
        'automobile' => 'doprava-a-logistika',
        'restaurant' => 'potravinarstvi',
        'medical' => 'sluzby',
        'legal' => 'sluzby',
        'finance' => 'sluzby',
        'education' => 'sluzby',
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 1500;
    }

    public function supports(DemandSignalSource $source): bool
    {
        return $source === DemandSignalSource::POPTAVEJ;
    }

    public function getSource(): DemandSignalSource
    {
        return DemandSignalSource::POPTAVEJ;
    }

    /**
     * @param array<string, mixed> $options Options: category, industry (Industry enum)
     * @return DemandSignalResult[]
     */
    public function discover(array $options = [], int $limit = 50): array
    {
        $results = [];
        $page = 1;
        $category = $options['category'] ?? null;

        // Map industry to category if not explicitly set
        if ($category === null && isset($options['industry']) && $options['industry'] instanceof Industry) {
            $industryValue = $options['industry']->value;
            $category = self::INDUSTRY_CATEGORIES[$industryValue] ?? null;
        }

        while (count($results) < $limit && $page <= 5) {
            $url = $this->buildUrl($category, $page);

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => $this->getDefaultHeaders(),
                ]);

                $html = $response->getContent();
                $pageResults = $this->parseListingPage($html);

                if (empty($pageResults)) {
                    break;
                }

                $results = array_merge($results, $pageResults);
                $page++;
                $this->rateLimit();

            } catch (\Throwable $e) {
                $this->logger->error('Poptavej.cz fetch failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        return array_slice($results, 0, $limit);
    }

    private function buildUrl(?string $category, int $page): string
    {
        $url = self::LISTING_URL;

        if ($category !== null) {
            $url .= '/' . urlencode($category);
        }

        if ($page > 1) {
            $url .= '?page=' . $page;
        }

        return $url;
    }

    /**
     * @return DemandSignalResult[]
     */
    private function parseListingPage(string $html): array
    {
        $results = [];

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        // Try to find demand rows (div.row.demand structure used by poptavej.cz)
        $items = $xpath->query("//div[contains(@class, 'demand')]");

        if ($items !== false && $items->length > 0) {
            return $this->parseStructuredItems($xpath, $items);
        }

        // Fallback to link-based parsing
        $links = $xpath->query("//a[contains(@href, '/poptavka/')]");

        if ($links === false) {
            return [];
        }

        $seenIds = [];

        foreach ($links as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            $href = $link->getAttribute('href');
            $externalId = $this->extractDemandId($href);

            if ($externalId === null || isset($seenIds[$externalId])) {
                continue;
            }

            $title = trim($link->textContent);

            if (empty($title) || mb_strlen($title) < 10) {
                continue;
            }

            $seenIds[$externalId] = true;

            // Make URL absolute
            $detailUrl = $href;
            if (!str_starts_with($detailUrl, 'http')) {
                $detailUrl = self::BASE_URL . $detailUrl;
            }

            // Try to extract location from URL slug
            $location = $this->extractLocationFromUrl($href);

            $signalType = $this->detectRfpType($title);
            $industry = $this->detectIndustry($title);

            $results[] = new DemandSignalResult(
                source: DemandSignalSource::POPTAVEJ,
                type: $signalType,
                externalId: $externalId,
                title: $title,
                industry: $industry,
                location: $location,
                publishedAt: new \DateTimeImmutable(),
                sourceUrl: $detailUrl,
            );
        }

        return $results;
    }

    /**
     * Parse structured listing items for enhanced data extraction.
     *
     * @return DemandSignalResult[]
     */
    private function parseStructuredItems(\DOMXPath $xpath, \DOMNodeList $items): array
    {
        $results = [];
        $seenIds = [];

        foreach ($items as $item) {
            if (!$item instanceof \DOMElement) {
                continue;
            }

            $linkNode = $xpath->query(".//a[contains(@href, '/poptavka/')]", $item)->item(0);
            if (!$linkNode instanceof \DOMElement) {
                continue;
            }

            $href = $linkNode->getAttribute('href');
            $externalId = $this->extractDemandId($href);

            if ($externalId === null || isset($seenIds[$externalId])) {
                continue;
            }

            $title = trim($linkNode->textContent);
            if (empty($title) || mb_strlen($title) < 10) {
                continue;
            }

            $seenIds[$externalId] = true;

            $detailUrl = $href;
            if (!str_starts_with($detailUrl, 'http')) {
                $detailUrl = self::BASE_URL . $detailUrl;
            }

            // Extract location from item or URL
            $location = $this->extractLocationFromItem($xpath, $item);
            if ($location === null) {
                $location = $this->extractLocationFromUrl($href);
            }

            // Extract value
            $value = $this->extractValueFromItem($xpath, $item);

            // Extract date
            $publishedAt = $this->extractDateFromItem($xpath, $item);

            $signalType = $this->detectRfpType($title);
            $industry = $this->detectIndustry($title);

            $results[] = new DemandSignalResult(
                source: DemandSignalSource::POPTAVEJ,
                type: $signalType,
                externalId: $externalId,
                title: $title,
                value: $value,
                currency: 'CZK',
                industry: $industry,
                location: $location,
                publishedAt: $publishedAt ?? new \DateTimeImmutable(),
                sourceUrl: $detailUrl,
            );
        }

        return $results;
    }

    private function extractLocationFromUrl(string $url): ?string
    {
        // URL format may contain city: /poptavka/12345-nazev-poptavky-praha
        $cities = ['praha', 'brno', 'ostrava', 'plzen', 'liberec', 'olomouc', 'pardubice', 'hradec-kralove', 'zlin', 'ceske-budejovice'];

        $urlLower = mb_strtolower($url);
        foreach ($cities as $city) {
            if (str_contains($urlLower, $city)) {
                return ucfirst(str_replace('-', ' ', $city));
            }
        }

        return null;
    }

    private function extractLocationFromItem(\DOMXPath $xpath, \DOMElement $item): ?string
    {
        // Poptavej.cz uses a.kraj for region
        $selectors = [
            ".//*[contains(@class, 'kraj')]",
            ".//*[contains(@class, 'location')]",
            ".//*[contains(@class, 'mesto')]",
            ".//*[contains(@class, 'place')]",
            ".//*[contains(@class, 'region')]",
        ];

        foreach ($selectors as $selector) {
            $node = $xpath->query($selector, $item)->item(0);
            if ($node !== null) {
                $text = trim($node->textContent);
                if (!empty($text) && mb_strlen($text) < 100) {
                    return $text;
                }
            }
        }

        return null;
    }

    private function extractValueFromItem(\DOMXPath $xpath, \DOMElement $item): ?float
    {
        $selectors = [
            ".//*[contains(@class, 'price')]",
            ".//*[contains(@class, 'cena')]",
            ".//*[contains(@class, 'budget')]",
        ];

        foreach ($selectors as $selector) {
            $node = $xpath->query($selector, $item)->item(0);
            if ($node !== null) {
                return $this->parsePrice($node->textContent);
            }
        }

        $text = $item->textContent;
        if (preg_match('/(\d[\d\s]*)\s*(?:Kč|CZK|korun)/iu', $text, $m)) {
            return $this->parsePrice($m[1]);
        }

        return null;
    }

    private function extractDateFromItem(\DOMXPath $xpath, \DOMElement $item): ?\DateTimeImmutable
    {
        $timeNode = $xpath->query(".//time", $item)->item(0);
        if ($timeNode instanceof \DOMElement) {
            $datetime = $timeNode->getAttribute('datetime');
            if (!empty($datetime)) {
                $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $datetime);
                if ($parsed !== false) {
                    return $parsed;
                }
            }
            return $this->parseCzechDate($timeNode->textContent);
        }

        $dateNode = $xpath->query(".//*[contains(@class, 'date')] | .//*[contains(@class, 'datum')]", $item)->item(0);
        if ($dateNode !== null) {
            return $this->parseCzechDate($dateNode->textContent);
        }

        return null;
    }

    private function extractDemandId(string $url): ?string
    {
        // Format: /poptavka/12345678-slug
        if (preg_match('/poptavka\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function detectRfpType(string $title): DemandSignalType
    {
        $title = mb_strtolower($title);

        if (preg_match('/web|www|stránk|portál/', $title)) {
            return DemandSignalType::RFP_WEB;
        }

        if (preg_match('/eshop|e-shop|internetový obchod/', $title)) {
            return DemandSignalType::RFP_ESHOP;
        }

        if (preg_match('/aplikac|software|systém|program/', $title)) {
            return DemandSignalType::RFP_APP;
        }

        if (preg_match('/marketing|reklam|propagac|seo|ppc/', $title)) {
            return DemandSignalType::RFP_MARKETING;
        }

        if (preg_match('/design|grafik|logo|vizuál/', $title)) {
            return DemandSignalType::RFP_DESIGN;
        }

        if (preg_match('/it |server|síť|cloud|hosting/', $title)) {
            return DemandSignalType::RFP_IT;
        }

        return DemandSignalType::RFP_OTHER;
    }

    /**
     * @return array<string, string>
     */
    private function getDefaultHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'cs,en;q=0.5',
        ];
    }
}
