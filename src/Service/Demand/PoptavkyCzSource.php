<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use App\Enum\Industry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demand signal source for Poptavky.cz - Czech B2B demand aggregator.
 *
 * Aggregates demands from various sources including public tenders.
 */
class PoptavkyCzSource extends AbstractDemandSource
{
    private const BASE_URL = 'https://www.poptavky.cz';
    private const LISTING_URL = 'https://www.poptavky.cz/vyhledavani';

    /**
     * Industry to poptavky.cz category mapping.
     * Categories are passed as filters[categories][0]=category-slug
     *
     * Note: Category filtering may return 500 errors (server-side issue as of January 2026).
     * Fallback: use null category to fetch all demands without filtering.
     *
     * Known categories: pocitace-software, stavebnictvi, reality, doprava,
     * sluzby, reklama-tisk, auto-moto, nabytek, potravinarstvi
     *
     * @var array<string, string[]>
     */
    private const INDUSTRY_CATEGORIES = [
        'webdesign' => ['pocitace-software'],
        'eshop' => ['pocitace-software'],
        'real_estate' => ['reality', 'stavebnictvi'],
        'automobile' => ['doprava', 'auto-moto'],
        'restaurant' => ['potravinarstvi'],
        'medical' => ['sluzby'],
        'legal' => ['sluzby'],
        'finance' => ['sluzby'],
        'education' => ['sluzby'],
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
        return $source === DemandSignalSource::POPTAVKY_CZ;
    }

    public function getSource(): DemandSignalSource
    {
        return DemandSignalSource::POPTAVKY_CZ;
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
            if (isset(self::INDUSTRY_CATEGORIES[$industryValue])) {
                $category = self::INDUSTRY_CATEGORIES[$industryValue][0]; // Use first matching category
            }
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
                $this->logger->error('Poptavky.cz fetch failed', [
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
        $params = [];

        if ($category !== null) {
            // Category filter uses filters[categories][0]=category-id format
            $params['filters[categories][0]'] = $category;
        }

        if ($page > 1) {
            $params['page'] = $page;
        }

        $url = self::LISTING_URL;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
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

        // Find demand listing items/articles
        $items = $xpath->query("//article | //div[contains(@class, 'poptavka')] | //div[contains(@class, 'item')]");

        if ($items === false || $items->length === 0) {
            // Fallback to link-based parsing
            return $this->parseListingPageFallback($xpath);
        }

        $seenIds = [];

        foreach ($items as $item) {
            if (!$item instanceof \DOMElement) {
                continue;
            }

            // Find the main link within the item
            $linkNode = $xpath->query(".//a[contains(@href, 'poptavka/')]", $item)->item(0);
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

            // Make URL absolute
            $detailUrl = $href;
            if (!str_starts_with($detailUrl, 'http')) {
                $detailUrl = self::BASE_URL . $detailUrl;
            }

            // Extract location
            $location = $this->extractLocationFromItem($xpath, $item);

            // Extract budget/value
            $value = $this->extractValueFromItem($xpath, $item);

            // Extract deadline/date
            $publishedAt = $this->extractDateFromItem($xpath, $item);
            $deadline = $this->extractDeadlineFromItem($xpath, $item);

            $signalType = $this->detectRfpType($title);
            $industry = $this->detectIndustry($title);

            $results[] = new DemandSignalResult(
                source: DemandSignalSource::POPTAVKY_CZ,
                type: $signalType,
                externalId: $externalId,
                title: $title,
                value: $value,
                currency: 'CZK',
                industry: $industry,
                location: $location,
                deadline: $deadline,
                publishedAt: $publishedAt ?? new \DateTimeImmutable(),
                sourceUrl: $detailUrl,
            );
        }

        return $results;
    }

    /**
     * Fallback parsing when structured items are not found.
     *
     * @return DemandSignalResult[]
     */
    private function parseListingPageFallback(\DOMXPath $xpath): array
    {
        $results = [];
        $links = $xpath->query("//a[contains(@href, 'poptavka/')]");

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

            $detailUrl = $href;
            if (!str_starts_with($detailUrl, 'http')) {
                $detailUrl = self::BASE_URL . $detailUrl;
            }

            $signalType = $this->detectRfpType($title);
            $industry = $this->detectIndustry($title);

            $results[] = new DemandSignalResult(
                source: DemandSignalSource::POPTAVKY_CZ,
                type: $signalType,
                externalId: $externalId,
                title: $title,
                industry: $industry,
                publishedAt: new \DateTimeImmutable(),
                sourceUrl: $detailUrl,
            );
        }

        return $results;
    }

    private function extractLocationFromItem(\DOMXPath $xpath, \DOMElement $item): ?string
    {
        // Try various selectors for location
        $selectors = [
            ".//*[contains(@class, 'location')]",
            ".//*[contains(@class, 'place')]",
            ".//*[contains(@class, 'mesto')]",
            ".//*[contains(@class, 'region')]",
            ".//*[contains(@class, 'okres')]",
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

        // Try to extract from item text
        $text = $item->textContent;
        if (preg_match('/(?:lokalita|místo|okres|kraj)[:.\s]*([^,\n]+)/iu', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractValueFromItem(\DOMXPath $xpath, \DOMElement $item): ?float
    {
        // Try various selectors for budget/price
        $selectors = [
            ".//*[contains(@class, 'price')]",
            ".//*[contains(@class, 'budget')]",
            ".//*[contains(@class, 'cena')]",
            ".//*[contains(@class, 'rozpocet')]",
        ];

        foreach ($selectors as $selector) {
            $node = $xpath->query($selector, $item)->item(0);
            if ($node !== null) {
                return $this->parsePrice($node->textContent);
            }
        }

        // Try to extract from item text
        $text = $item->textContent;
        if (preg_match('/(\d[\d\s]*(?:\.\d+)?)\s*(?:Kč|CZK|korun)/iu', $text, $m)) {
            return $this->parsePrice($m[1]);
        }

        return null;
    }

    private function extractDateFromItem(\DOMXPath $xpath, \DOMElement $item): ?\DateTimeImmutable
    {
        // Try time element first
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

        // Try date class
        $dateNode = $xpath->query(".//*[contains(@class, 'date')] | .//*[contains(@class, 'datum')]", $item)->item(0);
        if ($dateNode !== null) {
            return $this->parseCzechDate($dateNode->textContent);
        }

        return null;
    }

    private function extractDeadlineFromItem(\DOMXPath $xpath, \DOMElement $item): ?\DateTimeImmutable
    {
        // Try deadline/termin selectors
        $selectors = [
            ".//*[contains(@class, 'deadline')]",
            ".//*[contains(@class, 'termin')]",
            ".//*[contains(@class, 'do-kdy')]",
        ];

        foreach ($selectors as $selector) {
            $node = $xpath->query($selector, $item)->item(0);
            if ($node !== null) {
                return $this->parseCzechDate($node->textContent);
            }
        }

        // Try to extract from text
        $text = $item->textContent;
        if (preg_match('/(?:do|termín|deadline)[:.\s]*(\d{1,2}\.\s*\d{1,2}\.\s*\d{4})/iu', $text, $m)) {
            return $this->parseCzechDate($m[1]);
        }

        return null;
    }

    private function extractDemandId(string $url): ?string
    {
        if (preg_match('/poptavka\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function detectRfpType(string $title): DemandSignalType
    {
        $title = mb_strtolower($title);

        if (preg_match('/web|www|stránk|portál|eshop|e-shop/', $title)) {
            return DemandSignalType::RFP_WEB;
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
