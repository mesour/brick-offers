<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demand signal source for NEN (Národní elektronický nástroj) - Czech public procurement.
 *
 * NEN features:
 * - Official public procurement portal
 * - All government and public sector tenders
 * - XML API available
 */
class NenSource extends AbstractDemandSource
{
    private const BASE_URL = 'https://nen.nipez.cz';
    private const SEARCH_URL = 'https://nen.nipez.cz/verejne-zakazky/seznam-verejnych-zakazek';

    // CPV codes for IT/web related tenders
    private const CPV_CODES = [
        '72000000' => 'IT služby',
        '72200000' => 'Programování a poradenství',
        '72212000' => 'Programování aplikačního software',
        '72413000' => 'Služby návrhu webových stránek',
        '72400000' => 'Internetové služby',
        '79340000' => 'Reklamní a marketingové služby',
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 1000;
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
     * @param array<string, mixed> $options Options: cpv, region, minValue
     * @return DemandSignalResult[]
     */
    public function discover(array $options = [], int $limit = 50): array
    {
        $results = [];
        $page = 1;

        $cpv = $options['cpv'] ?? null;
        $minValue = $options['minValue'] ?? null;

        while (count($results) < $limit) {
            $url = $this->buildSearchUrl($cpv, $minValue, $page);

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
                    break;
                }

                $results = array_merge($results, $pageResults);
                $page++;

                $this->rateLimit();

            } catch (\Throwable $e) {
                $this->logger->error('NEN listing fetch failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        return array_slice($results, 0, $limit);
    }

    private function buildSearchUrl(?string $cpv, ?float $minValue, int $page): string
    {
        $params = [
            'stav' => 'zadavani', // Only open tenders
        ];

        if ($page > 1) {
            $params['strana'] = $page;
        }

        if ($cpv !== null) {
            $params['cpv'] = $cpv;
        }

        if ($minValue !== null) {
            $params['predpokladanaHodnota'] = $minValue;
        }

        return self::SEARCH_URL . '?' . http_build_query($params);
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

        // Find tender items
        $items = $xpath->query("//tr[contains(@class, 'zakazka')] | //div[contains(@class, 'tender-item')]");

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
                $this->logger->debug('Failed to parse NEN item', ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    private function parseListItem(\DOMNode $item, \DOMXPath $xpath): ?DemandSignalResult
    {
        // Extract title
        $titleNode = $xpath->query(".//a[contains(@class, 'nazev')] | .//td[contains(@class, 'nazev')]//a", $item)->item(0);
        if ($titleNode === null) {
            $titleNode = $xpath->query(".//a", $item)->item(0);
        }

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

        // Extract tender ID
        $externalId = $this->extractTenderId($detailUrl ?? $title);

        // Extract contracting authority (zadavatel)
        $authorityNode = $xpath->query(".//td[contains(@class, 'zadavatel')] | .//span[contains(@class, 'zadavatel')]", $item)->item(0);
        $companyName = $authorityNode !== null ? trim($authorityNode->textContent) : null;

        // Extract estimated value
        $valueNode = $xpath->query(".//td[contains(@class, 'hodnota')] | .//span[contains(@class, 'hodnota')]", $item)->item(0);
        $value = null;
        if ($valueNode !== null) {
            $value = $this->parsePrice(trim($valueNode->textContent));
        }

        // Extract deadline
        $deadlineNode = $xpath->query(".//td[contains(@class, 'lhuta')] | .//span[contains(@class, 'deadline')]", $item)->item(0);
        $deadline = null;
        if ($deadlineNode !== null) {
            $deadline = $this->parseCzechDate(trim($deadlineNode->textContent));
        }

        // Detect tender type
        $signalType = $this->detectTenderType($title);

        // Detect industry
        $industry = $this->detectIndustry($title);

        return new DemandSignalResult(
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

    private function extractTenderId(?string $urlOrText): ?string
    {
        if ($urlOrText === null) {
            return null;
        }

        // Try to extract ID from URL
        if (preg_match('/zakazka[\/\-](\d+)/', $urlOrText, $matches)) {
            return $matches[1];
        }

        if (preg_match('/N(\d{6}[A-Z]\d{2}[A-Z]\d{5})/', $urlOrText, $matches)) {
            return $matches[1];
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
