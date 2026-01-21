<?php

declare(strict_types=1);

namespace App\Service\Benchmark;

use App\Entity\Analysis;
use App\Entity\IndustryBenchmark;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use App\Repository\AnalysisRepository;
use App\Repository\IndustryBenchmarkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for calculating industry benchmarks from analysis data.
 * Aggregates scores, percentiles, and common issues across an industry.
 */
class BenchmarkCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnalysisRepository $analysisRepository,
        private readonly IndustryBenchmarkRepository $benchmarkRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Calculate benchmark for a specific industry.
     */
    public function calculateForIndustry(Industry $industry, ?\DateTimeImmutable $periodStart = null): ?IndustryBenchmark
    {
        $periodStart = $periodStart ?? $this->getCurrentPeriodStart();

        // Get all completed analyses for this industry
        $analyses = $this->getAnalysesForIndustry($industry);

        if (empty($analyses)) {
            $this->logger->warning('No analyses found for industry {industry}', [
                'industry' => $industry->value,
            ]);

            return null;
        }

        // Check if benchmark already exists
        $existingBenchmark = $this->benchmarkRepository->findByIndustryAndPeriod($industry, $periodStart);

        if ($existingBenchmark !== null) {
            return $this->updateBenchmark($existingBenchmark, $analyses);
        }

        return $this->createBenchmark($industry, $periodStart, $analyses);
    }

    /**
     * Calculate benchmarks for all industries.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function calculateForAllIndustries(?\DateTimeImmutable $periodStart = null): array
    {
        $periodStart = $periodStart ?? $this->getCurrentPeriodStart();
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach (Industry::cases() as $industry) {
            $analyses = $this->getAnalysesForIndustry($industry);

            if (empty($analyses)) {
                $stats['skipped']++;
                continue;
            }

            $existingBenchmark = $this->benchmarkRepository->findByIndustryAndPeriod($industry, $periodStart);

            if ($existingBenchmark !== null) {
                $this->updateBenchmark($existingBenchmark, $analyses);
                $stats['updated']++;
            } else {
                $this->createBenchmark($industry, $periodStart, $analyses);
                $stats['created']++;
            }
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * Get the current period start (weekly).
     */
    public function getCurrentPeriodStart(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify('monday this week')->setTime(0, 0, 0);
    }

    /**
     * Get analyses for an industry.
     *
     * @return array<Analysis>
     */
    private function getAnalysesForIndustry(Industry $industry): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Analysis::class, 'a')
            ->where('a.industry = :industry')
            ->andWhere('a.status = :status')
            ->setParameter('industry', $industry)
            ->setParameter('status', AnalysisStatus::COMPLETED)
            ->orderBy('a.completedAt', 'DESC')
            ->setMaxResults(1000) // Limit for performance
            ->getQuery()
            ->getResult();
    }

    /**
     * Create a new benchmark entity.
     *
     * @param array<Analysis> $analyses
     */
    private function createBenchmark(
        Industry $industry,
        \DateTimeImmutable $periodStart,
        array $analyses,
    ): IndustryBenchmark {
        $benchmark = new IndustryBenchmark();
        $benchmark->setIndustry($industry);
        $benchmark->setPeriodStart($periodStart);

        $this->populateBenchmarkData($benchmark, $analyses);

        $this->entityManager->persist($benchmark);

        $this->logger->info('Created benchmark for industry {industry}', [
            'industry' => $industry->value,
            'sampleSize' => count($analyses),
            'avgScore' => $benchmark->getAvgScore(),
        ]);

        return $benchmark;
    }

    /**
     * Update an existing benchmark.
     *
     * @param array<Analysis> $analyses
     */
    private function updateBenchmark(IndustryBenchmark $benchmark, array $analyses): IndustryBenchmark
    {
        $this->populateBenchmarkData($benchmark, $analyses);

        $this->logger->info('Updated benchmark for industry {industry}', [
            'industry' => $benchmark->getIndustry()?->value,
            'sampleSize' => count($analyses),
        ]);

        return $benchmark;
    }

    /**
     * Populate benchmark with calculated data.
     *
     * @param array<Analysis> $analyses
     */
    private function populateBenchmarkData(IndustryBenchmark $benchmark, array $analyses): void
    {
        $scores = array_map(fn (Analysis $a) => $a->getTotalScore(), $analyses);
        $issueCounts = array_map(fn (Analysis $a) => $a->getIssueCount(), $analyses);
        $criticalCounts = array_map(fn (Analysis $a) => $a->getCriticalIssueCount(), $analyses);

        // Basic statistics
        $benchmark->setSampleSize(count($analyses));
        $benchmark->setAvgScore($this->calculateAverage($scores));
        $benchmark->setMedianScore($this->calculateMedian($scores));
        $benchmark->setAvgIssueCount($this->calculateAverage($issueCounts));
        $benchmark->setAvgCriticalIssueCount($this->calculateAverage($criticalCounts));

        // Percentiles
        $benchmark->setPercentiles($this->calculatePercentiles($scores));

        // Category scores
        $benchmark->setAvgCategoryScores($this->calculateAvgCategoryScores($analyses));

        // Top issues
        $benchmark->setTopIssues($this->calculateTopIssues($analyses));
    }

    /**
     * Calculate average.
     *
     * @param array<int|float> $values
     */
    private function calculateAverage(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * Calculate median.
     *
     * @param array<int|float> $values
     */
    private function calculateMedian(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return (float) $values[$middle];
    }

    /**
     * Calculate percentiles (p10, p25, p50, p75, p90).
     *
     * @param array<int|float> $values
     * @return array{p10: float, p25: float, p50: float, p75: float, p90: float}
     */
    private function calculatePercentiles(array $values): array
    {
        if (empty($values)) {
            return ['p10' => 0, 'p25' => 0, 'p50' => 0, 'p75' => 0, 'p90' => 0];
        }

        sort($values);

        return [
            'p10' => $this->percentile($values, 10),
            'p25' => $this->percentile($values, 25),
            'p50' => $this->percentile($values, 50),
            'p75' => $this->percentile($values, 75),
            'p90' => $this->percentile($values, 90),
        ];
    }

    /**
     * Calculate a specific percentile.
     *
     * @param array<int|float> $sortedValues
     */
    private function percentile(array $sortedValues, int $percentile): float
    {
        $count = count($sortedValues);
        $index = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper) {
            return (float) $sortedValues[$lower];
        }

        return $sortedValues[$lower] + $fraction * ($sortedValues[$upper] - $sortedValues[$lower]);
    }

    /**
     * Calculate average scores per category.
     *
     * @param array<Analysis> $analyses
     * @return array<string, float>
     */
    private function calculateAvgCategoryScores(array $analyses): array
    {
        $categoryTotals = [];
        $categoryCounts = [];

        foreach ($analyses as $analysis) {
            foreach ($analysis->getScores() as $category => $score) {
                if (!isset($categoryTotals[$category])) {
                    $categoryTotals[$category] = 0;
                    $categoryCounts[$category] = 0;
                }
                $categoryTotals[$category] += $score;
                $categoryCounts[$category]++;
            }
        }

        $avgScores = [];
        foreach ($categoryTotals as $category => $total) {
            $avgScores[$category] = round($total / $categoryCounts[$category], 2);
        }

        return $avgScores;
    }

    /**
     * Calculate top issues across all analyses.
     *
     * @param array<Analysis> $analyses
     * @return array<array{code: string, count: int, percentage: float}>
     */
    private function calculateTopIssues(array $analyses, int $limit = 20): array
    {
        $issueCounts = [];
        $totalAnalyses = count($analyses);

        foreach ($analyses as $analysis) {
            $issueCodes = $analysis->getIssueCodes();
            foreach ($issueCodes as $code) {
                if (!isset($issueCounts[$code])) {
                    $issueCounts[$code] = 0;
                }
                $issueCounts[$code]++;
            }
        }

        // Sort by count descending
        arsort($issueCounts);

        // Take top N
        $topIssues = [];
        $i = 0;
        foreach ($issueCounts as $code => $count) {
            if ($i >= $limit) {
                break;
            }
            $topIssues[] = [
                'code' => $code,
                'count' => $count,
                'percentage' => round(($count / $totalAnalyses) * 100, 1),
            ];
            $i++;
        }

        return $topIssues;
    }

    /**
     * Compare a lead's analysis against industry benchmark.
     *
     * @return array{ranking: string, percentile: ?float, comparison: array<string, mixed>}
     */
    public function compareWithBenchmark(Analysis $analysis): array
    {
        $industry = $analysis->getIndustry();
        if ($industry === null) {
            return [
                'ranking' => 'unknown',
                'percentile' => null,
                'comparison' => [],
            ];
        }

        $benchmark = $this->benchmarkRepository->findLatestByIndustry($industry);
        if ($benchmark === null) {
            return [
                'ranking' => 'no_benchmark',
                'percentile' => null,
                'comparison' => [],
            ];
        }

        $score = $analysis->getTotalScore();

        return [
            'ranking' => $benchmark->getPercentileRanking($score),
            'percentile' => $benchmark->calculatePercentile($score),
            'comparison' => [
                'score' => $score,
                'industryAvg' => $benchmark->getAvgScore(),
                'industryMedian' => $benchmark->getMedianScore(),
                'diffFromAvg' => round($score - $benchmark->getAvgScore(), 1),
                'issueCount' => $analysis->getIssueCount(),
                'industryAvgIssues' => round($benchmark->getAvgIssueCount(), 1),
                'sampleSize' => $benchmark->getSampleSize(),
            ],
        ];
    }
}
