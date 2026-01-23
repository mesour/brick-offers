<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalType;
use App\Enum\Industry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demand signal source for StartupJobs.cz - Czech startup job portal.
 *
 * Uses JSON API at /api/offers for efficient data retrieval.
 * Hiring signals from startups indicate growth and potential need for web services.
 */
class StartupJobsSource extends AbstractDemandSource
{
    private const BASE_URL = 'https://www.startupjobs.cz';
    private const API_URL = 'https://www.startupjobs.cz/api/offers';

    /**
     * Industry to StartupJobs area slugs mapping.
     *
     * Available areas: vyvoj, back-end-vyvojar, front-end-vyvojar, full-stack-vyvojar,
     * mobilni-vyvojar, product-designer, marketing, obchod, data-scientist, etc.
     *
     * @var array<string, string[]>
     */
    private const INDUSTRY_AREAS = [
        'webdesign' => ['front-end-vyvojar', 'full-stack-vyvojar', 'vyvoj', 'product-designer'],
        'eshop' => ['front-end-vyvojar', 'full-stack-vyvojar', 'vyvoj'],
        'real_estate' => ['obchod', 'business-developer'],
        'automobile' => ['obchod'],
        'restaurant' => ['marketing', 'obchod'],
        'medical' => ['vyvoj', 'product'],
        'legal' => ['obchod'],
        'finance' => ['data-scientist', 'vyvoj'],
        'education' => ['marketing', 'product'],
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
        return $source === DemandSignalSource::STARTUP_JOBS;
    }

    public function getSource(): DemandSignalSource
    {
        return DemandSignalSource::STARTUP_JOBS;
    }

    /**
     * @param array<string, mixed> $options Options: areas (array), industry (Industry enum)
     * @return DemandSignalResult[]
     */
    public function discover(array $options = [], int $limit = 50): array
    {
        $areas = $options['areas'] ?? null;

        // Map industry to areas if not explicitly set
        if ($areas === null && isset($options['industry']) && $options['industry'] instanceof Industry) {
            $industryValue = $options['industry']->value;
            $areas = self::INDUSTRY_AREAS[$industryValue] ?? null;
        }

        try {
            $url = $this->buildApiUrl($areas, $limit);

            $response = $this->httpClient->request('GET', $url, [
                'headers' => $this->getDefaultHeaders(),
            ]);

            $data = $response->toArray();

            return $this->parseApiResponse($data, $limit);

        } catch (\Throwable $e) {
            $this->logger->error('StartupJobs.cz fetch failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @param string[]|null $areas
     */
    private function buildApiUrl(?array $areas, int $limit): string
    {
        $params = [
            'limit' => min($limit, 100), // API max is usually 100
        ];

        // Note: StartupJobs API may not support area filtering via query params
        // If needed, we filter results after fetching

        return self::API_URL . '?' . http_build_query($params);
    }

    /**
     * @param array<string, mixed> $data
     * @return DemandSignalResult[]
     */
    private function parseApiResponse(array $data, int $limit): array
    {
        $results = [];
        $resultSet = $data['resultSet'] ?? [];

        foreach ($resultSet as $job) {
            if (count($results) >= $limit) {
                break;
            }

            $id = $job['id'] ?? null;
            $name = $job['name'] ?? null;

            if ($id === null || empty($name)) {
                continue;
            }

            // Build detail URL
            $detailUrl = self::BASE_URL . ($job['url'] ?? '/nabidka/' . $id);

            // Parse salary
            $value = null;
            $valueMax = null;
            $currency = 'CZK';

            if (isset($job['salary']) && is_array($job['salary'])) {
                $value = isset($job['salary']['min']) ? (float) $job['salary']['min'] : null;
                $valueMax = isset($job['salary']['max']) ? (float) $job['salary']['max'] : null;
                $currency = $job['salary']['currency'] ?? 'CZK';
            }

            // Detect signal type based on areas
            $signalType = $this->detectHiringType($job);

            // Detect industry
            $industry = $this->detectIndustry($name . ' ' . ($job['company'] ?? ''));

            $results[] = new DemandSignalResult(
                source: DemandSignalSource::STARTUP_JOBS,
                type: $signalType,
                externalId: (string) $id,
                title: $name,
                description: $this->stripHtml($job['description'] ?? null),
                companyName: $job['company'] ?? null,
                value: $value,
                valueMax: $valueMax,
                currency: $currency,
                industry: $industry,
                location: $job['locations'] ?? null,
                publishedAt: new \DateTimeImmutable(),
                sourceUrl: $detailUrl,
                rawData: [
                    'companyType' => $job['companyType'] ?? null,
                    'shifts' => $job['shifts'] ?? null,
                    'seniorities' => $job['seniorities'] ?? [],
                    'areaSlugs' => $job['areaSlugs'] ?? [],
                    'areaNames' => $job['areaNames'] ?? [],
                    'isHot' => $job['isHot'] ?? false,
                    'isRemote' => $job['isRemote'] ?? false,
                ],
            );
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function detectHiringType(array $job): DemandSignalType
    {
        $areaSlugs = $job['areaSlugs'] ?? [];
        $name = mb_strtolower($job['name'] ?? '');

        // Check area slugs first
        $devAreas = ['vyvoj', 'back-end-vyvojar', 'front-end-vyvojar', 'full-stack-vyvojar', 'mobilni-vyvojar'];
        $designAreas = ['product-designer', 'ux-designer', 'grafik'];
        $marketingAreas = ['marketing', 'marketing-manager', 'marketingovy-analytik'];

        foreach ($areaSlugs as $area) {
            if (in_array($area, $devAreas, true)) {
                return DemandSignalType::HIRING_WEBDEV;
            }
            if (in_array($area, $designAreas, true)) {
                return DemandSignalType::HIRING_DESIGNER;
            }
            if (in_array($area, $marketingAreas, true)) {
                return DemandSignalType::HIRING_MARKETING;
            }
        }

        // Fallback to title matching
        if (preg_match('/developer|vývojář|programátor|php|javascript|react|vue|angular|node/', $name)) {
            return DemandSignalType::HIRING_WEBDEV;
        }

        if (preg_match('/designer|ux|ui|grafik/', $name)) {
            return DemandSignalType::HIRING_DESIGNER;
        }

        if (preg_match('/marketing|seo|ppc|content|copywriter/', $name)) {
            return DemandSignalType::HIRING_MARKETING;
        }

        if (preg_match('/it |admin|devops|cloud|security/', $name)) {
            return DemandSignalType::HIRING_IT;
        }

        return DemandSignalType::HIRING_OTHER;
    }

    private function stripHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * @return array<string, string>
     */
    private function getDefaultHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'application/json',
            'Accept-Language' => 'cs,en;q=0.5',
        ];
    }
}
