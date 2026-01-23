<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use App\Enum\Industry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demand signal source for Práce.cz - major Czech job portal.
 *
 * Hiring signals indicate company growth - companies hiring web developers
 * often need external web development help too.
 */
class PraceCzSource extends AbstractDemandSource
{
    private const BASE_URL = 'https://www.prace.cz';
    private const SEARCH_URL = 'https://www.prace.cz/nabidky/';
    private const MAX_PAGES = 5;

    /**
     * Industry to search query mapping.
     *
     * @var array<string, string>
     */
    private const INDUSTRY_QUERIES = [
        'webdesign' => 'webdesigner OR "web developer" OR frontend',
        'eshop' => 'e-commerce OR eshop OR "e-shop"',
        'real_estate' => '"realitní makléř" OR reality',
        'automobile' => 'automobilový OR autoservis OR mechanik',
        'restaurant' => 'gastronomie OR kuchař OR číšník',
        'medical' => 'zdravotnictví OR lékař OR zdravotní sestra',
        'legal' => 'právník OR advokát',
        'finance' => 'účetní OR "finanční poradce"',
        'education' => 'učitel OR lektor',
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
        return $source === DemandSignalSource::PRACE_CZ;
    }

    public function getSource(): DemandSignalSource
    {
        return DemandSignalSource::PRACE_CZ;
    }

    /**
     * @param array<string, mixed> $options Options: query, location, industry (Industry enum)
     * @return DemandSignalResult[]
     */
    public function discover(array $options = [], int $limit = 50): array
    {
        $results = [];
        $query = $options['query'] ?? null;

        // Map industry to search query if not explicitly set
        if ($query === null && isset($options['industry']) && $options['industry'] instanceof Industry) {
            $industryValue = $options['industry']->value;
            $query = self::INDUSTRY_QUERIES[$industryValue] ?? 'webdesigner';
        } elseif ($query === null) {
            $query = 'webdesigner';
        }

        $page = 1;

        while (count($results) < $limit && $page <= self::MAX_PAGES) {
            $url = $this->buildSearchUrl($query, $page);

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => $this->getDefaultHeaders(),
                ]);

                $html = $response->getContent();
                $pageResults = $this->parseListingPage($html, $limit - count($results));

                if (empty($pageResults)) {
                    break;
                }

                $results = array_merge($results, $pageResults);
                $page++;
                $this->rateLimit();

            } catch (\Throwable $e) {
                $this->logger->error('Prace.cz fetch failed', [
                    'url' => $url,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        return array_slice($results, 0, $limit);
    }

    private function buildSearchUrl(string $query, int $page = 1): string
    {
        $params = ['q' => $query];
        if ($page > 1) {
            $params['page'] = $page;
        }
        return self::SEARCH_URL . '?' . http_build_query($params);
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

        // Find job listing links - format: /nabidka/ID/
        $links = $xpath->query("//a[contains(@href, '/nabidka/')]");

        if ($links === false) {
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
            $externalId = $this->extractJobId($href);

            if ($externalId === null || isset($seenIds[$externalId])) {
                continue;
            }

            $title = trim($link->textContent);

            if (empty($title) || mb_strlen($title) < 5) {
                continue;
            }

            $seenIds[$externalId] = true;

            // Make URL absolute and clean query params
            $detailUrl = $href;
            if (!str_starts_with($detailUrl, 'http')) {
                $detailUrl = self::BASE_URL . $detailUrl;
            }
            // Remove tracking params
            $detailUrl = preg_replace('/\?.*$/', '', $detailUrl);

            // Try to find company, location, and salary from parent container
            $container = $this->findParentContainer($link);
            $companyName = null;
            $location = null;
            $value = null;
            $valueMax = null;

            if ($container !== null) {
                $companyNodes = $xpath->query(".//*[contains(@class, 'company')] | .//*[contains(@class, 'employer')]", $container);
                if ($companyNodes !== false && $companyNodes->length > 0) {
                    $companyName = trim($companyNodes->item(0)->textContent);
                }

                $locationNodes = $xpath->query(".//*[contains(@class, 'location')] | .//*[contains(@class, 'place')]", $container);
                if ($locationNodes !== false && $locationNodes->length > 0) {
                    $location = trim($locationNodes->item(0)->textContent);
                }

                // Extract salary
                $salaryNodes = $xpath->query(".//*[contains(@class, 'salary')] | .//*[contains(text(), 'Kč')]", $container);
                if ($salaryNodes !== false && $salaryNodes->length > 0) {
                    $salaryText = trim($salaryNodes->item(0)->textContent);
                    [$value, $valueMax] = $this->parseSalaryRange($salaryText);
                }
            }

            $signalType = $this->detectHiringType($title);
            $industry = $this->detectIndustry($title . ' ' . ($companyName ?? ''));

            $results[] = new DemandSignalResult(
                source: DemandSignalSource::PRACE_CZ,
                type: $signalType,
                externalId: $externalId,
                title: $title,
                companyName: $companyName,
                value: $value,
                valueMax: $valueMax,
                currency: 'CZK',
                industry: $industry,
                location: $location,
                publishedAt: new \DateTimeImmutable(),
                sourceUrl: $detailUrl,
            );
        }

        return $results;
    }

    private function findParentContainer(\DOMElement $element): ?\DOMElement
    {
        $parent = $element->parentNode;
        $maxDepth = 10;

        while ($parent !== null && $maxDepth > 0) {
            if ($parent instanceof \DOMElement) {
                $class = $parent->getAttribute('class');
                if (str_contains($class, 'item') || str_contains($class, 'job') || str_contains($class, 'offer')) {
                    return $parent;
                }
                $tag = strtolower($parent->tagName);
                if ($tag === 'article' || $tag === 'li') {
                    return $parent;
                }
            }
            $parent = $parent->parentNode;
            $maxDepth--;
        }

        return null;
    }

    private function extractJobId(string $url): ?string
    {
        // Format: /nabidka/2000983066/
        if (preg_match('/nabidka\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Parse salary range and return [min, max] values.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function parseSalaryRange(string $salary): array
    {
        // Extract salary range
        if (preg_match('/(\d[\d\s]*)\s*[-–]\s*(\d[\d\s]*)/', $salary, $matches)) {
            $min = $this->parsePrice($matches[1]);
            $max = $this->parsePrice($matches[2]);
            return [$min, $max];
        }

        // Single value
        $value = $this->parsePrice($salary);
        return [$value, null];
    }

    private function detectHiringType(string $title): DemandSignalType
    {
        $title = mb_strtolower($title);

        if (preg_match('/web\s*developer|frontend|backend|fullstack|php|javascript|react|vue/', $title)) {
            return DemandSignalType::HIRING_WEBDEV;
        }

        if (preg_match('/designer|ux|ui|grafik|webdesign/', $title)) {
            return DemandSignalType::HIRING_DESIGNER;
        }

        if (preg_match('/marketing|seo|ppc|content|social media|copywriter/', $title)) {
            return DemandSignalType::HIRING_MARKETING;
        }

        if (preg_match('/it |admin|devops|cloud|security|správce/', $title)) {
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
