<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demand signal source for Jobs.cz - largest Czech job portal.
 *
 * Jobs.cz features:
 * - 4M+ monthly visits
 * - ~17,000 active job listings
 * - Hiring signals indicate company growth/needs
 */
class JobsCzSource extends AbstractDemandSource
{
    private const BASE_URL = 'https://www.jobs.cz';
    private const SEARCH_URL = 'https://www.jobs.cz/prace/';

    // Job categories that indicate need for external services
    private const RELEVANT_CATEGORIES = [
        'it' => 'IT',
        'webdesigner' => 'Web designer',
        'grafik' => 'Grafik',
        'marketing' => 'Marketing',
        'developer' => 'Developer',
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($httpClient, $logger);
        $this->requestDelayMs = 1500; // Be more conservative with job portals
    }

    public function supports(DemandSignalSource $source): bool
    {
        return $source === DemandSignalSource::JOBS_CZ;
    }

    public function getSource(): DemandSignalSource
    {
        return DemandSignalSource::JOBS_CZ;
    }

    /**
     * @param array<string, mixed> $options Options: query, location, category
     * @return DemandSignalResult[]
     */
    public function discover(array $options = [], int $limit = 50): array
    {
        $results = [];
        $page = 1;

        $query = $options['query'] ?? 'web developer';
        $location = $options['location'] ?? null;

        while (count($results) < $limit) {
            $url = $this->buildSearchUrl($query, $location, $page);

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
                $this->logger->error('Jobs.cz listing fetch failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        return array_slice($results, 0, $limit);
    }

    private function buildSearchUrl(string $query, ?string $location, int $page): string
    {
        $params = [];

        if ($page > 1) {
            $params['page'] = $page;
        }

        // Jobs.cz uses URL path for search
        $url = self::SEARCH_URL;

        if ($location !== null) {
            $url .= urlencode($location) . '/';
        }

        $url .= '?' . http_build_query(array_merge($params, ['q' => $query]));

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

        // Find job items - adjust selectors based on actual page structure
        $items = $xpath->query("//article[contains(@class, 'search-list__item')] | //div[contains(@class, 'job-item')]");

        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            try {
                $result = $this->parseJobItem($item, $xpath);
                if ($result !== null) {
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Failed to parse Jobs.cz item', ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    private function parseJobItem(\DOMNode $item, \DOMXPath $xpath): ?DemandSignalResult
    {
        // Extract job title
        $titleNode = $xpath->query(".//h2//a | .//a[contains(@class, 'title')]", $item)->item(0);
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

        // Extract job ID
        $externalId = $this->extractJobId($detailUrl);

        // Extract company name
        $companyNode = $xpath->query(".//span[contains(@class, 'company')] | .//a[contains(@class, 'employer')]", $item)->item(0);
        $companyName = $companyNode !== null ? trim($companyNode->textContent) : null;

        // Extract location
        $locationNode = $xpath->query(".//span[contains(@class, 'location')]", $item)->item(0);
        $location = $locationNode !== null ? trim($locationNode->textContent) : null;

        // Extract salary
        $salaryNode = $xpath->query(".//span[contains(@class, 'salary')]", $item)->item(0);
        $value = null;
        if ($salaryNode !== null) {
            $value = $this->parseSalary(trim($salaryNode->textContent));
        }

        // Determine hiring signal type
        $signalType = $this->detectHiringType($title);

        // Detect industry from company/job context
        $industry = $this->detectIndustry($title . ' ' . ($companyName ?? ''));

        return new DemandSignalResult(
            source: DemandSignalSource::JOBS_CZ,
            type: $signalType,
            externalId: $externalId ?? md5($title . $companyName),
            title: $title,
            companyName: $companyName,
            value: $value,
            currency: 'CZK',
            industry: $industry,
            location: $location,
            publishedAt: new \DateTimeImmutable(),
            sourceUrl: $detailUrl,
        );
    }

    private function extractJobId(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        if (preg_match('/\/(\d+)(?:\/|$|\?)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function parseSalary(string $salary): ?float
    {
        // Extract salary range and take the average
        if (preg_match('/(\d[\d\s]*)\s*[-–]\s*(\d[\d\s]*)/', $salary, $matches)) {
            $min = $this->parsePrice($matches[1]);
            $max = $this->parsePrice($matches[2]);
            if ($min !== null && $max !== null) {
                return ($min + $max) / 2;
            }
        }

        return $this->parsePrice($salary);
    }

    private function detectHiringType(string $title): DemandSignalType
    {
        $title = mb_strtolower($title);

        if (preg_match('/web\s*developer|frontend|backend|fullstack|php|javascript/', $title)) {
            return DemandSignalType::HIRING_WEBDEV;
        }

        if (preg_match('/designer|ux|ui|grafik/', $title)) {
            return DemandSignalType::HIRING_DESIGNER;
        }

        if (preg_match('/marketing|seo|ppc|content|social media/', $title)) {
            return DemandSignalType::HIRING_MARKETING;
        }

        if (preg_match('/it |admin|devops|cloud|security/', $title)) {
            return DemandSignalType::HIRING_IT;
        }

        return DemandSignalType::HIRING_OTHER;
    }
}
