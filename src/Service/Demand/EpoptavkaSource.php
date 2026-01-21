<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demand signal source for ePoptavka.cz - largest Czech B2B RFP platform.
 *
 * ePoptavka.cz features:
 * - 11,000+ monthly RFPs
 * - 283,000+ registered suppliers
 * - 7+ billion CZK monthly volume
 * - All business sectors covered
 */
class EpoptavkaSource extends AbstractDemandSource
{
    private const BASE_URL = 'https://www.epoptavka.cz';
    private const SEARCH_URL = 'https://www.epoptavka.cz/poptavky';

    // Category IDs for filtering
    private const CATEGORIES = [
        'it' => 'it-sluzby',
        'web' => 'webove-sluzby',
        'marketing' => 'marketing-a-reklama',
        'design' => 'grafika-a-design',
        'stavebnictvi' => 'stavebnictvi',
        'sluzby' => 'sluzby',
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 1000; // Be respectful with rate limiting
    }

    public function supports(DemandSignalSource $source): bool
    {
        return $source === DemandSignalSource::EPOPTAVKA;
    }

    public function getSource(): DemandSignalSource
    {
        return DemandSignalSource::EPOPTAVKA;
    }

    /**
     * @param array<string, mixed> $options Options: category, region, query
     * @return DemandSignalResult[]
     */
    public function discover(array $options = [], int $limit = 50): array
    {
        $results = [];
        $page = 1;

        $category = $options['category'] ?? null;
        $region = $options['region'] ?? null;
        $query = $options['query'] ?? null;

        while (count($results) < $limit) {
            $url = $this->buildSearchUrl($category, $region, $query, $page);

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'cs,en;q=0.5',
                    ],
                ]);

                $html = $response->getContent();
                $pageResults = $this->parseListingPage($html);

                if (empty($pageResults)) {
                    break; // No more results
                }

                $results = array_merge($results, $pageResults);
                $page++;

                $this->rateLimit();

            } catch (\Throwable $e) {
                $this->logger->error('ePoptavka listing fetch failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        // Limit results
        $results = array_slice($results, 0, $limit);

        // Fetch detail pages for more information
        $enrichedResults = [];
        foreach ($results as $result) {
            if ($result->sourceUrl !== null) {
                $enriched = $this->enrichFromDetailPage($result);
                $enrichedResults[] = $enriched;
                $this->rateLimit();
            } else {
                $enrichedResults[] = $result;
            }
        }

        return $enrichedResults;
    }

    private function buildSearchUrl(?string $category, ?string $region, ?string $query, int $page): string
    {
        $url = self::SEARCH_URL;
        $params = [];

        if ($category !== null && isset(self::CATEGORIES[$category])) {
            $url .= '/' . self::CATEGORIES[$category];
        }

        if ($page > 1) {
            $params['strana'] = $page;
        }

        if ($query !== null) {
            $params['q'] = $query;
        }

        if ($region !== null) {
            $params['region'] = $region;
        }

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    /**
     * Parse the listing page HTML.
     *
     * @return DemandSignalResult[]
     */
    private function parseListingPage(string $html): array
    {
        $results = [];

        // Load HTML into DOM
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        // Find RFP items - adjust selector based on actual page structure
        $items = $xpath->query("//div[contains(@class, 'poptavka-item')] | //article[contains(@class, 'demand')]");

        if ($items === false || $items->length === 0) {
            // Try alternative selectors
            $items = $xpath->query("//div[contains(@class, 'list-item')] | //div[contains(@class, 'demand-list-item')]");
        }

        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            try {
                $result = $this->parseListItem($item, $xpath);
                if ($result !== null) {
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to parse ePoptavka item', ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    private function parseListItem(\DOMNode $item, \DOMXPath $xpath): ?DemandSignalResult
    {
        // Extract title
        $titleNode = $xpath->query(".//h2/a | .//h3/a | .//a[contains(@class, 'title')]", $item)->item(0);
        if ($titleNode === null) {
            return null;
        }

        $title = trim($titleNode->textContent);
        $detailUrl = $titleNode instanceof \DOMElement ? $titleNode->getAttribute('href') : null;

        if (empty($title)) {
            return null;
        }

        // Make URL absolute
        if ($detailUrl !== null && !str_starts_with($detailUrl, 'http')) {
            $detailUrl = self::BASE_URL . $detailUrl;
        }

        // Extract external ID from URL
        $externalId = $this->extractExternalId($detailUrl);
        if ($externalId === null) {
            $externalId = md5($title . ($detailUrl ?? ''));
        }

        // Extract description
        $descNode = $xpath->query(".//p[contains(@class, 'description')] | .//div[contains(@class, 'text')]", $item)->item(0);
        $description = $descNode !== null ? trim($descNode->textContent) : null;

        // Extract company name
        $companyNode = $xpath->query(".//span[contains(@class, 'company')] | .//div[contains(@class, 'author')]", $item)->item(0);
        $companyName = $companyNode !== null ? trim($companyNode->textContent) : null;

        // Extract location
        $locationNode = $xpath->query(".//span[contains(@class, 'location')] | .//span[contains(@class, 'region')]", $item)->item(0);
        $location = $locationNode !== null ? trim($locationNode->textContent) : null;

        // Extract date
        $dateNode = $xpath->query(".//span[contains(@class, 'date')] | .//time", $item)->item(0);
        $publishedAt = null;
        if ($dateNode !== null) {
            $publishedAt = $this->parseCzechDate(trim($dateNode->textContent));
        }

        // Extract price/budget
        $priceNode = $xpath->query(".//span[contains(@class, 'price')] | .//span[contains(@class, 'budget')]", $item)->item(0);
        $value = null;
        if ($priceNode !== null) {
            $value = $this->parsePrice(trim($priceNode->textContent));
        }

        // Detect signal type from title and description
        $signalType = $this->detectSignalType($title . ' ' . ($description ?? ''));

        // Detect industry
        $industry = $this->detectIndustry($title . ' ' . ($description ?? ''));

        return new DemandSignalResult(
            source: DemandSignalSource::EPOPTAVKA,
            type: $signalType,
            externalId: $externalId,
            title: $title,
            description: $description,
            companyName: $companyName,
            value: $value,
            industry: $industry,
            location: $location,
            publishedAt: $publishedAt,
            sourceUrl: $detailUrl,
            rawData: [
                'html_snippet' => $item->ownerDocument->saveHTML($item),
            ],
        );
    }

    /**
     * Enrich result with data from detail page.
     */
    private function enrichFromDetailPage(DemandSignalResult $result): DemandSignalResult
    {
        if ($result->sourceUrl === null) {
            return $result;
        }

        try {
            $response = $this->httpClient->request('GET', $result->sourceUrl, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'cs,en;q=0.5',
                ],
            ]);

            $html = $response->getContent();

            return $this->parseDetailPage($html, $result);

        } catch (\Throwable $e) {
            $this->logger->debug('Failed to fetch ePoptavka detail', [
                'url' => $result->sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }
    }

    private function parseDetailPage(string $html, DemandSignalResult $base): DemandSignalResult
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        // Extract full description
        $descNode = $xpath->query("//div[contains(@class, 'description')] | //div[contains(@class, 'content')]")->item(0);
        $description = $descNode !== null ? trim($descNode->textContent) : $base->description;

        // Extract contact info
        $contactEmail = $this->extractEmail($html);
        $contactPhone = $this->extractPhone($html);
        $contactPerson = null;

        $personNode = $xpath->query("//span[contains(@class, 'contact-name')] | //div[contains(@class, 'author')]")->item(0);
        if ($personNode !== null) {
            $contactPerson = trim($personNode->textContent);
        }

        // Extract IČO
        $ico = $this->extractIco($html);

        // Extract deadline
        $deadline = null;
        $deadlineNode = $xpath->query("//*[contains(text(), 'Termín')] | //*[contains(text(), 'deadline')]")->item(0);
        if ($deadlineNode !== null) {
            $deadline = $this->parseCzechDate(trim($deadlineNode->textContent));
        }

        // Extract budget more precisely
        $value = $base->value;
        $valueMax = null;

        $budgetNode = $xpath->query("//*[contains(text(), 'Rozpočet')] | //*[contains(text(), 'Budget')]")->item(0);
        if ($budgetNode !== null) {
            $budgetText = trim($budgetNode->textContent);
            if (preg_match('/(\d[\d\s]*)\s*[-–]\s*(\d[\d\s]*)/', $budgetText, $matches)) {
                $value = $this->parsePrice($matches[1]);
                $valueMax = $this->parsePrice($matches[2]);
            } elseif (preg_match('/(\d[\d\s,.]*)/', $budgetText, $matches)) {
                $value = $this->parsePrice($matches[1]);
            }
        }

        $rawData = $base->rawData;
        $rawData['detail_html'] = substr($html, 0, 10000); // Store first 10KB

        return new DemandSignalResult(
            source: $base->source,
            type: $base->type,
            externalId: $base->externalId,
            title: $base->title,
            description: $description,
            companyName: $base->companyName,
            ico: $ico ?? $base->ico,
            contactEmail: $contactEmail ?? $base->contactEmail,
            contactPhone: $contactPhone ?? $base->contactPhone,
            contactPerson: $contactPerson ?? $base->contactPerson,
            value: $value,
            valueMax: $valueMax,
            currency: $base->currency,
            industry: $base->industry,
            location: $base->location,
            region: $base->region,
            deadline: $deadline ?? $base->deadline,
            publishedAt: $base->publishedAt,
            sourceUrl: $base->sourceUrl,
            rawData: $rawData,
        );
    }

    private function extractExternalId(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        // Try to extract ID from URL like /poptavka/12345
        if (preg_match('/poptavka[\/\-](\d+)/', $url, $matches)) {
            return $matches[1];
        }

        // Try to extract from slug
        if (preg_match('/\/([a-z0-9\-]+)$/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractEmail(string $html): ?string
    {
        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $html, $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function extractPhone(string $html): ?string
    {
        // Czech phone format
        if (preg_match('/(?:\+420\s?)?[0-9]{3}\s?[0-9]{3}\s?[0-9]{3}/', $html, $matches)) {
            return preg_replace('/\s+/', '', $matches[0]);
        }

        return null;
    }
}
