<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Industry;
use App\Repository\IndustryBenchmarkRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class IndustryBenchmarkController extends AbstractController
{
    public function __construct(
        private readonly IndustryBenchmarkRepository $benchmarkRepository,
    ) {
    }

    /**
     * Get benchmark data for a specific industry.
     *
     * GET /api/industries/{industry}/benchmark
     */
    #[Route('/api/industries/{industry}/benchmark', name: 'api_industry_benchmark', methods: ['GET'])]
    public function getBenchmark(string $industry, Request $request): JsonResponse
    {
        $industryType = Industry::tryFrom($industry);

        if ($industryType === null) {
            $available = array_map(fn (Industry $i) => $i->value, Industry::cases());

            return $this->json([
                'error' => sprintf('Invalid industry "%s"', $industry),
                'available' => $available,
            ], Response::HTTP_BAD_REQUEST);
        }

        $benchmark = $this->benchmarkRepository->findLatestByIndustry($industryType);

        if ($benchmark === null) {
            return $this->json([
                'error' => sprintf('No benchmark data available for industry "%s"', $industry),
                'hint' => 'Run `bin/console app:benchmark:calculate` to generate benchmarks',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'industry' => [
                'code' => $industryType->value,
                'label' => $industryType->getLabel(),
            ],
            'benchmark' => [
                'periodStart' => $benchmark->getPeriodStart()?->format('Y-m-d'),
                'sampleSize' => $benchmark->getSampleSize(),
                'avgScore' => $benchmark->getAvgScore(),
                'medianScore' => $benchmark->getMedianScore(),
                'avgIssueCount' => round($benchmark->getAvgIssueCount(), 1),
                'avgCriticalIssueCount' => round($benchmark->getAvgCriticalIssueCount(), 1),
                'percentiles' => $benchmark->getPercentiles(),
                'avgCategoryScores' => $benchmark->getAvgCategoryScores(),
                'topIssues' => $benchmark->getTopIssues(),
            ],
            'updatedAt' => $benchmark->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get benchmark history for an industry.
     *
     * GET /api/industries/{industry}/benchmark/history?periods=12
     */
    #[Route('/api/industries/{industry}/benchmark/history', name: 'api_industry_benchmark_history', methods: ['GET'])]
    public function getBenchmarkHistory(string $industry, Request $request): JsonResponse
    {
        $industryType = Industry::tryFrom($industry);

        if ($industryType === null) {
            $available = array_map(fn (Industry $i) => $i->value, Industry::cases());

            return $this->json([
                'error' => sprintf('Invalid industry "%s"', $industry),
                'available' => $available,
            ], Response::HTTP_BAD_REQUEST);
        }

        $periods = max(1, min(52, (int) $request->query->get('periods', 12)));

        $history = $this->benchmarkRepository->findHistoryByIndustry($industryType, $periods);

        $data = [];
        foreach ($history as $benchmark) {
            $data[] = [
                'periodStart' => $benchmark->getPeriodStart()?->format('Y-m-d'),
                'sampleSize' => $benchmark->getSampleSize(),
                'avgScore' => $benchmark->getAvgScore(),
                'medianScore' => $benchmark->getMedianScore(),
                'avgIssueCount' => round($benchmark->getAvgIssueCount(), 1),
            ];
        }

        return $this->json([
            'industry' => [
                'code' => $industryType->value,
                'label' => $industryType->getLabel(),
            ],
            'history' => $data,
            'count' => count($data),
        ]);
    }

    /**
     * List all industries with their latest benchmark status.
     *
     * GET /api/industries
     */
    #[Route('/api/industries', name: 'api_industries_list', methods: ['GET'])]
    public function listIndustries(): JsonResponse
    {
        $benchmarks = $this->benchmarkRepository->findLatestForAllIndustries();

        $benchmarkMap = [];
        foreach ($benchmarks as $benchmark) {
            $industry = $benchmark->getIndustry();
            if ($industry !== null) {
                $benchmarkMap[$industry->value] = $benchmark;
            }
        }

        $data = [];
        foreach (Industry::cases() as $industry) {
            $benchmark = $benchmarkMap[$industry->value] ?? null;

            $data[] = [
                'code' => $industry->value,
                'label' => $industry->getLabel(),
                'defaultSnapshotPeriod' => $industry->getDefaultSnapshotPeriod()->value,
                'hasBenchmark' => $benchmark !== null,
                'benchmark' => $benchmark !== null ? [
                    'periodStart' => $benchmark->getPeriodStart()?->format('Y-m-d'),
                    'sampleSize' => $benchmark->getSampleSize(),
                    'avgScore' => $benchmark->getAvgScore(),
                ] : null,
            ];
        }

        return $this->json([
            'industries' => $data,
            'count' => count($data),
        ]);
    }
}
