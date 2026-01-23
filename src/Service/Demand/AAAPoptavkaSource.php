<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use App\Enum\Industry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demand signal source for AAAPoptávka.cz - largest Czech B2B demand portal.
 *
 * Claims 1.4M+ demands processed, 167.6B CZK worth of work.
 */
class AAAPoptavkaSource extends AbstractDemandSource
{
    private const BASE_URL = 'https://www.aaapoptavka.cz';
    private const LISTING_URL = 'https://www.aaapoptavka.cz/poptavky';

    /**
     * Industry to aaapoptavka.cz category slug mapping.
     * Categories are appended to URL: /poptavky/{category}
     *
     * Verified categories (January 2026):
     * pocitace, stavebnictvi, sluzby, auto-moto, reality, reklama, servis,
     * doprava, nabytek, drevo, textil, strojirenstvi, prumysl, stroje,
     * zdravotnictvi, potravinarstvi
     *
     * @var array<string, string>
     */
    private const INDUSTRY_CATEGORIES = [
        'webdesign' => 'pocitace',
        'eshop' => 'pocitace',
        'real_estate' => 'reality',
        'automobile' => 'auto-moto',
        'restaurant' => 'potravinarstvi',
        'medical' => 'zdravotnictvi',
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
        return $source === DemandSignalSource::AAAPOPTAVKA;
    }

    public function getSource(): DemandSignalSource
    {
        return DemandSignalSource::AAAPOPTAVKA;
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
                $this->logger->error('AAAPoptavka.cz fetch failed', [
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
            $url .= '/strana-' . $page;
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

        // Try to find structured listing items first
        $items = $xpath->query("//div[contains(@class, 'poptavka')] | //article | //li[contains(@class, 'item')]");

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

            // Skip navigation links (usually short)
            if (empty($title) || mb_strlen($title) < 15) {
                continue;
            }

            // Clean title - remove "Poptávka: " prefix if present
            $title = preg_replace('/^poptávk[ay]:?\s*/iu', '', $title);
            $title = preg_replace('/^poptávám:?\s*/iu', '', $title);

            $seenIds[$externalId] = true;

            // Make URL absolute
            $detailUrl = $href;
            if (!str_starts_with($detailUrl, 'http')) {
                $detailUrl = self::BASE_URL . $detailUrl;
            }

            // Try to extract location from title or parent
            $location = $this->extractLocation($title);

            $signalType = $this->detectRfpType($title);
            $industry = $this->detectIndustry($title);

            $results[] = new DemandSignalResult(
                source: DemandSignalSource::AAAPOPTAVKA,
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

            // Find the main link
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
            if (empty($title) || mb_strlen($title) < 15) {
                continue;
            }

            $title = preg_replace('/^poptávk[ay]:?\s*/iu', '', $title);
            $title = preg_replace('/^poptávám:?\s*/iu', '', $title);

            $seenIds[$externalId] = true;

            $detailUrl = $href;
            if (!str_starts_with($detailUrl, 'http')) {
                $detailUrl = self::BASE_URL . $detailUrl;
            }

            // Extract location from item
            $location = $this->extractLocationFromItem($xpath, $item);
            if ($location === null) {
                $location = $this->extractLocation($title);
            }

            // Extract region
            $region = $this->extractRegionFromItem($xpath, $item);

            // Extract value/budget
            $value = $this->extractValueFromItem($xpath, $item);

            // Extract date
            $publishedAt = $this->extractDateFromItem($xpath, $item);

            $signalType = $this->detectRfpType($title);
            $industry = $this->detectIndustry($title);

            $results[] = new DemandSignalResult(
                source: DemandSignalSource::AAAPOPTAVKA,
                type: $signalType,
                externalId: $externalId,
                title: $title,
                value: $value,
                currency: 'CZK',
                industry: $industry,
                location: $location,
                region: $region,
                publishedAt: $publishedAt ?? new \DateTimeImmutable(),
                sourceUrl: $detailUrl,
            );
        }

        return $results;
    }

    private function extractLocationFromItem(\DOMXPath $xpath, \DOMElement $item): ?string
    {
        $selectors = [
            ".//*[contains(@class, 'location')]",
            ".//*[contains(@class, 'mesto')]",
            ".//*[contains(@class, 'place')]",
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

    private function extractRegionFromItem(\DOMXPath $xpath, \DOMElement $item): ?string
    {
        $selectors = [
            ".//*[contains(@class, 'region')]",
            ".//*[contains(@class, 'kraj')]",
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

        // Try text extraction
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
        // Format: /poptavka/2769501/poptavka-slug
        if (preg_match('/poptavka\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractLocation(string $title): ?string
    {
        // Common Czech cities at end of titles
        $cities = ['Praha', 'Brno', 'Ostrava', 'Plzeň', 'Liberec', 'Olomouc', 'Pardubice', 'Hradec Králové', 'Zlín', 'České Budějovice'];

        foreach ($cities as $city) {
            if (mb_stripos($title, $city) !== false) {
                return $city;
            }
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
