<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use App\Enum\Industry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demand signal source for WebTrh.cz - Czech freelance marketplace.
 *
 * Specialized in web development, design, and marketing services.
 * High-quality leads for web agencies.
 */
class WebTrhSource extends AbstractDemandSource
{
    private const BASE_URL = 'https://webtrh.cz';
    private const LISTING_URL = 'https://webtrh.cz/poptavky';

    /**
     * Industry to WebTrh category slug mapping.
     *
     * Available categories:
     * - poptavky-vyvoje-a-programovani (Development)
     * - poptavky-designu-fotografovani-a-videa (Design)
     * - poptavky-obchodu-a-marketingu (Marketing)
     * - poptavky-jazykovych-sluzeb (Language services)
     * - dalsi-poptavky (Other)
     *
     * @var array<string, string>
     */
    private const INDUSTRY_CATEGORIES = [
        'webdesign' => 'poptavky-vyvoje-a-programovani',
        'eshop' => 'poptavky-vyvoje-a-programovani',
        'real_estate' => 'poptavky-obchodu-a-marketingu',
        'automobile' => 'poptavky-obchodu-a-marketingu',
        'restaurant' => 'poptavky-obchodu-a-marketingu',
        'medical' => 'poptavky-vyvoje-a-programovani',
        'legal' => 'poptavky-obchodu-a-marketingu',
        'finance' => 'poptavky-obchodu-a-marketingu',
        'education' => 'poptavky-jazykovych-sluzeb',
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
        return $source === DemandSignalSource::WEBTRH;
    }

    public function getSource(): DemandSignalSource
    {
        return DemandSignalSource::WEBTRH;
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
                $this->logger->error('WebTrh.cz fetch failed', [
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
            $url .= '/' . $category;
        }

        if ($page > 1) {
            $url .= '/strana/' . $page;
        }

        return $url . '/';
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

        // Find inquiry boxes (demands) and offer boxes (work offers)
        $items = $xpath->query("//div[contains(@class, 'inquiry-box')] | //div[contains(@class, 'offer-box')]");

        if ($items === false || $items->length === 0) {
            return [];
        }

        foreach ($items as $item) {
            if (!$item instanceof \DOMElement) {
                continue;
            }

            $result = $this->parseInquiryBox($xpath, $item);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function parseInquiryBox(\DOMXPath $xpath, \DOMElement $item): ?DemandSignalResult
    {
        // Find the link - can be /poptavka/ (demand) or /prace/ (work offer)
        $linkNode = $xpath->query(".//a[contains(@href, '/poptavka/') or contains(@href, '/prace/')]", $item)->item(0);
        if (!$linkNode instanceof \DOMElement) {
            return null;
        }

        $href = $linkNode->getAttribute('href');
        $externalId = $this->extractDemandId($href);

        if ($externalId === null) {
            return null;
        }

        // Determine if this is a work offer or demand
        $isWorkOffer = str_contains($href, '/prace/');

        // Extract title
        $titleNode = $xpath->query(".//div[contains(@class, 'title')]//span | .//div[contains(@class, 'title')]", $linkNode)->item(0);
        $title = $titleNode !== null ? trim($titleNode->textContent) : null;

        if (empty($title) || mb_strlen($title) < 5) {
            return null;
        }

        // Extract budget from meta
        $value = null;
        $valueMax = null;
        $budgetNode = $xpath->query(".//div[@class='meta']//b", $item)->item(0);
        if ($budgetNode !== null) {
            $budgetText = trim($budgetNode->textContent);
            [$value, $valueMax] = $this->parseBudget($budgetText);
        }

        // Extract category
        $categoryNodes = $xpath->query(".//div[@class='meta']//div[contains(text(), 'Kategorie')]//b", $item);
        $categories = [];
        foreach ($categoryNodes as $catNode) {
            $categories[] = trim($catNode->textContent);
        }

        // Extract posted time
        $createdNode = $xpath->query(".//div[contains(@class, 'created')]", $item)->item(0);
        $publishedAt = new \DateTimeImmutable();
        if ($createdNode !== null) {
            $publishedAt = $this->parseRelativeTime(trim($createdNode->textContent));
        }

        // Build absolute URL
        $detailUrl = $href;
        if (!str_starts_with($detailUrl, 'http')) {
            $detailUrl = self::BASE_URL . $detailUrl;
        }

        // Detect signal type based on whether it's a work offer or demand
        $signalType = $isWorkOffer
            ? $this->detectHiringType($title, $categories)
            : $this->detectRfpType($title, $categories);
        $industry = $this->detectIndustry($title . ' ' . implode(' ', $categories));

        return new DemandSignalResult(
            source: DemandSignalSource::WEBTRH,
            type: $signalType,
            externalId: $externalId,
            title: $title,
            value: $value,
            valueMax: $valueMax,
            currency: 'CZK',
            industry: $industry,
            publishedAt: $publishedAt,
            sourceUrl: $detailUrl,
            rawData: [
                'categories' => $categories,
                'isWorkOffer' => $isWorkOffer,
            ],
        );
    }

    private function extractDemandId(string $url): ?string
    {
        // Format: /poptavka/slug-name/ or /prace/slug-name/
        if (preg_match('/(?:poptavka|prace)\/([^\/]+)/', $url, $matches)) {
            return $matches[1]; // Use slug as ID since WebTrh doesn't have numeric IDs
        }

        return null;
    }

    /**
     * Parse budget string like "do 2 tisíc Kč" or "2 - 10 tisíc Kč".
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function parseBudget(string $budget): array
    {
        $budget = mb_strtolower($budget);

        // "do X tisíc Kč" = up to X thousand
        if (preg_match('/do\s+(\d+)\s+tisíc/', $budget, $matches)) {
            return [null, (float) $matches[1] * 1000];
        }

        // "X - Y tisíc Kč" = X to Y thousand
        if (preg_match('/(\d+)\s*[-–]\s*(\d+)\s+tisíc/', $budget, $matches)) {
            return [(float) $matches[1] * 1000, (float) $matches[2] * 1000];
        }

        // "nad X tisíc Kč" = over X thousand
        if (preg_match('/nad\s+(\d+)\s+tisíc/', $budget, $matches)) {
            return [(float) $matches[1] * 1000, null];
        }

        // "X tisíc Kč" = X thousand
        if (preg_match('/(\d+)\s+tisíc/', $budget, $matches)) {
            return [(float) $matches[1] * 1000, (float) $matches[1] * 1000];
        }

        // "dohodou" = negotiable
        if (str_contains($budget, 'dohodou')) {
            return [null, null];
        }

        return [null, null];
    }

    private function parseRelativeTime(string $timeStr): \DateTimeImmutable
    {
        $timeStr = mb_strtolower(trim($timeStr));
        $now = new \DateTimeImmutable();

        // "před X min"
        if (preg_match('/před\s+(\d+)\s+min/', $timeStr, $m)) {
            return $now->modify("-{$m[1]} minutes");
        }

        // "před X hod"
        if (preg_match('/před\s+(\d+)\s+hod/', $timeStr, $m)) {
            return $now->modify("-{$m[1]} hours");
        }

        // "před X dny" or "před X dnem"
        if (preg_match('/před\s+(\d+)\s+dn/', $timeStr, $m)) {
            return $now->modify("-{$m[1]} days");
        }

        // "včera"
        if (str_contains($timeStr, 'včera')) {
            return $now->modify('-1 day');
        }

        // "dnes"
        if (str_contains($timeStr, 'dnes')) {
            return $now;
        }

        return $now;
    }

    /**
     * @param string[] $categories
     */
    private function detectRfpType(string $title, array $categories): DemandSignalType
    {
        $text = mb_strtolower($title . ' ' . implode(' ', $categories));

        if (preg_match('/wordpress|wp|woocommerce/', $text)) {
            return DemandSignalType::RFP_WEB;
        }

        if (preg_match('/eshop|e-shop|shoptet|prestashop/', $text)) {
            return DemandSignalType::RFP_ESHOP;
        }

        if (preg_match('/web|html|css|javascript|php|react|vue|angular|frontend|backend/', $text)) {
            return DemandSignalType::RFP_WEB;
        }

        if (preg_match('/aplikac|app|mobil|ios|android|flutter/', $text)) {
            return DemandSignalType::RFP_APP;
        }

        if (preg_match('/design|grafik|logo|ui|ux|figma|photoshop/', $text)) {
            return DemandSignalType::RFP_DESIGN;
        }

        if (preg_match('/marketing|seo|ppc|reklam|facebook|instagram|linkedin|copywriting/', $text)) {
            return DemandSignalType::RFP_MARKETING;
        }

        if (preg_match('/server|hosting|cloud|devops|linux|windows/', $text)) {
            return DemandSignalType::RFP_IT;
        }

        return DemandSignalType::RFP_OTHER;
    }

    /**
     * Detect hiring signal type for work offers (/prace/).
     *
     * @param string[] $categories
     */
    private function detectHiringType(string $title, array $categories): DemandSignalType
    {
        $text = mb_strtolower($title . ' ' . implode(' ', $categories));

        if (preg_match('/developer|vývojář|programátor|php|javascript|react|vue|angular|node|\.net|java|python|frontend|backend|full.?stack/', $text)) {
            return DemandSignalType::HIRING_WEBDEV;
        }

        if (preg_match('/designer|grafik|ui|ux|figma/', $text)) {
            return DemandSignalType::HIRING_DESIGNER;
        }

        if (preg_match('/marketing|seo|ppc|copywriter|content/', $text)) {
            return DemandSignalType::HIRING_MARKETING;
        }

        if (preg_match('/devops|admin|server|cloud|linux/', $text)) {
            return DemandSignalType::HIRING_IT;
        }

        return DemandSignalType::HIRING_OTHER;
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
