<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use App\Service\Browser\BrowserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demand signal source for NEN (Národní elektronický nástroj) - Czech public procurement.
 *
 * Uses headless browser because NEN is a JavaScript SPA.
 */
class NenSource extends AbstractDemandSource
{
    private const BASE_URL = 'https://nen.nipez.cz';
    private const SEARCH_URL = 'https://nen.nipez.cz/verejne-zakazky';

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        private readonly ?BrowserInterface $browser = null,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 2000;
    }

    public function supports(DemandSignalSource $source): bool
    {
        return $source === DemandSignalSource::NEN;
    }

    public function getSource(): DemandSignalSource
    {
        return DemandSignalSource::NEN;
    }

    /**
     * @param array<string, mixed> $options
     * @return DemandSignalResult[]
     */
    public function discover(array $options = [], int $limit = 50): array
    {
        if ($this->browser === null || !$this->browser->isAvailable()) {
            $this->logger->warning('NEN source requires Browserless but it is not available');
            return [];
        }

        $url = $this->buildSearchUrl($options);

        try {
            $html = $this->browser->getPageSource($url, 3000);
            return $this->parseListingPage($html, $limit);
        } catch (\Throwable $e) {
            $this->logger->error('NEN fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function buildSearchUrl(array $options): string
    {
        // The main listing page shows recent tenders, no extra params needed
        return self::SEARCH_URL;
    }

    /**
     * @return DemandSignalResult[]
     */
    private function parseListingPage(string $html, int $limit): array
    {
        $results = [];

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new \DOMXPath($dom);

        // NEN uses gov-* classes, find tender rows
        // Look for links that point to tender details (URL pattern: /verejne-zakazky/detail-zakazky/ID)
        $links = $xpath->query("//a[contains(@href, '/detail-zakazky/')]");

        if ($links === false || $links->length === 0) {
            $this->logger->debug('No tender links found in NEN page');
            return [];
        }

        $seenIds = [];

        foreach ($links as $link) {
            if (count($results) >= $limit) {
                break;
            }

            if (!$link instanceof \DOMElement) {
                continue;
            }

            $href = $link->getAttribute('href');
            $externalId = $this->extractTenderId($href);

            // Skip duplicates (same tender may have multiple links)
            if ($externalId !== null && isset($seenIds[$externalId])) {
                continue;
            }

            // Title is in aria-label (format: "Show detail TENDER_NAME")
            $ariaLabel = $link->getAttribute('aria-label');
            $title = '';
            if (!empty($ariaLabel)) {
                // Remove "Show detail " or "Detail " prefix
                $title = preg_replace('/^(Show detail|Detail)\s*/i', '', $ariaLabel);
            }

            // Fallback to text content
            if (empty($title)) {
                $title = trim($link->textContent);
            }

            if (empty($title) || mb_strlen($title) < 10) {
                continue;
            }

            if ($externalId !== null) {
                $seenIds[$externalId] = true;
            }

            // Make URL absolute
            $detailUrl = $href;
            if (!str_starts_with($detailUrl, 'http')) {
                $detailUrl = self::BASE_URL . $detailUrl;
            }

            // Try to find parent row for more data
            $row = $this->findParentRow($link, $xpath);
            $companyName = null;
            $value = null;
            $deadline = null;

            if ($row !== null) {
                // Try to extract additional data from the row
                $texts = $xpath->query(".//span | .//div | .//td", $row);
                foreach ($texts as $text) {
                    $content = trim($text->textContent);

                    // Look for price patterns
                    if ($value === null && preg_match('/(\d[\d\s,.]*)\s*(Kč|CZK)/i', $content, $m)) {
                        $value = $this->parsePrice($m[1]);
                    }

                    // Look for date patterns
                    if ($deadline === null && preg_match('/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/', $content, $m)) {
                        try {
                            $deadline = new \DateTimeImmutable("{$m[3]}-{$m[2]}-{$m[1]}");
                        } catch (\Exception) {
                        }
                    }
                }
            }

            $signalType = $this->detectTenderType($title);
            $industry = $this->detectIndustry($title);

            $results[] = new DemandSignalResult(
                source: DemandSignalSource::NEN,
                type: $signalType,
                externalId: $externalId ?? md5($title),
                title: $title,
                companyName: $companyName,
                value: $value,
                currency: 'CZK',
                industry: $industry,
                deadline: $deadline,
                sourceUrl: $detailUrl,
            );
        }

        return $results;
    }

    private function findParentRow(\DOMElement $element, \DOMXPath $xpath): ?\DOMElement
    {
        $parent = $element->parentNode;
        $maxDepth = 10;

        while ($parent !== null && $maxDepth > 0) {
            if ($parent instanceof \DOMElement) {
                $tag = strtolower($parent->tagName);
                if ($tag === 'tr' || $tag === 'article' || $tag === 'li') {
                    return $parent;
                }
                $class = $parent->getAttribute('class');
                if (str_contains($class, 'item') || str_contains($class, 'row') || str_contains($class, 'card')) {
                    return $parent;
                }
            }
            $parent = $parent->parentNode;
            $maxDepth--;
        }

        return null;
    }

    private function extractTenderId(?string $urlOrText): ?string
    {
        if ($urlOrText === null) {
            return null;
        }

        // Extract ID from URL like /verejne-zakazky/detail-zakazky/N006-25-V00040026
        if (preg_match('/detail-zakazky\/([A-Z0-9\-]+)/', $urlOrText, $matches)) {
            return $matches[1];
        }

        if (preg_match('/N\d{3}-\d{2}-[A-Z]\d+/', $urlOrText, $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function detectTenderType(string $title): DemandSignalType
    {
        $title = mb_strtolower($title);

        if (preg_match('/web|www|internet|portál|stránk/', $title)) {
            return DemandSignalType::TENDER_WEB;
        }

        if (preg_match('/informační systém|software|aplikac/', $title)) {
            return DemandSignalType::TENDER_IT;
        }

        if (preg_match('/marketing|reklam|propagac/', $title)) {
            return DemandSignalType::TENDER_MARKETING;
        }

        if (preg_match('/design|grafik|vizuál/', $title)) {
            return DemandSignalType::TENDER_DESIGN;
        }

        return DemandSignalType::TENDER_OTHER;
    }
}
