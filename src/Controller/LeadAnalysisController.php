<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Lead;
use App\Enum\SnapshotPeriod;
use App\Repository\AnalysisRepository;
use App\Repository\AnalysisSnapshotRepository;
use App\Repository\LeadRepository;
use App\Service\Benchmark\BenchmarkCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
class LeadAnalysisController extends AbstractController
{
    public function __construct(
        private readonly LeadRepository $leadRepository,
        private readonly AnalysisRepository $analysisRepository,
        private readonly AnalysisSnapshotRepository $snapshotRepository,
        private readonly BenchmarkCalculator $benchmarkCalculator,
    ) {
    }

    /**
     * Get analysis history for a lead.
     *
     * GET /api/leads/{id}/analyses?limit=10&offset=0
     */
    #[Route('/api/leads/{id}/analyses', name: 'api_lead_analyses', methods: ['GET'])]
    public function getAnalyses(string $id, Request $request): JsonResponse
    {
        $lead = $this->findLeadOrFail($id);

        if ($lead === null) {
            return $this->json(['error' => 'Lead not found'], Response::HTTP_NOT_FOUND);
        }

        $limit = max(1, min(100, (int) $request->query->get('limit', 10)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        $analyses = $this->analysisRepository->createQueryBuilder('a')
            ->where('a.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('a.sequenceNumber', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $total = (int) $this->analysisRepository->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.lead = :lead')
            ->setParameter('lead', $lead)
            ->getQuery()
            ->getSingleScalarResult();

        $data = [];
        foreach ($analyses as $analysis) {
            $data[] = [
                'id' => (string) $analysis->getId(),
                'sequenceNumber' => $analysis->getSequenceNumber(),
                'status' => $analysis->getStatus()->value,
                'industry' => $analysis->getIndustry()?->value,
                'totalScore' => $analysis->getTotalScore(),
                'scoreDelta' => $analysis->getScoreDelta(),
                'isImproved' => $analysis->isImproved(),
                'issueCount' => $analysis->getIssueCount(),
                'criticalIssueCount' => $analysis->getCriticalIssueCount(),
                'issueDelta' => $analysis->getIssueDelta(),
                'scores' => $analysis->getScores(),
                'createdAt' => $analysis->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'completedAt' => $analysis->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json([
            'lead' => [
                'id' => (string) $lead->getId(),
                'domain' => $lead->getDomain(),
                'analysisCount' => $lead->getAnalysisCount(),
            ],
            'analyses' => $data,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    /**
     * Get trending data (snapshots) for a lead.
     *
     * GET /api/leads/{id}/trend?period=week&limit=52
     */
    #[Route('/api/leads/{id}/trend', name: 'api_lead_trend', methods: ['GET'])]
    public function getTrend(string $id, Request $request): JsonResponse
    {
        $lead = $this->findLeadOrFail($id);

        if ($lead === null) {
            return $this->json(['error' => 'Lead not found'], Response::HTTP_NOT_FOUND);
        }

        $periodValue = $request->query->get('period', 'week');
        $periodType = SnapshotPeriod::tryFrom($periodValue);

        if ($periodType === null) {
            return $this->json([
                'error' => sprintf('Invalid period type "%s". Available: day, week, month', $periodValue),
            ], Response::HTTP_BAD_REQUEST);
        }

        $limit = max(1, min(365, (int) $request->query->get('limit', 52)));

        $trendData = $this->snapshotRepository->getTrendingData($lead, $periodType, $limit);

        // Get latest snapshot for current values
        $latestSnapshot = $this->snapshotRepository->findLatestByLead($lead, $periodType);

        return $this->json([
            'lead' => [
                'id' => (string) $lead->getId(),
                'domain' => $lead->getDomain(),
                'industry' => $lead->getIndustry()?->value,
            ],
            'period' => $periodType->value,
            'current' => $latestSnapshot !== null ? [
                'periodStart' => $latestSnapshot->getPeriodStart()?->format('Y-m-d'),
                'totalScore' => $latestSnapshot->getTotalScore(),
                'scoreDelta' => $latestSnapshot->getScoreDelta(),
                'issueCount' => $latestSnapshot->getIssueCount(),
                'criticalIssueCount' => $latestSnapshot->getCriticalIssueCount(),
                'categoryScores' => $latestSnapshot->getCategoryScores(),
                'topIssues' => $latestSnapshot->getTopIssues(),
            ] : null,
            'trend' => $trendData,
            'count' => count($trendData),
        ]);
    }

    /**
     * Compare lead's analysis with industry benchmark.
     *
     * GET /api/leads/{id}/benchmark
     */
    #[Route('/api/leads/{id}/benchmark', name: 'api_lead_benchmark', methods: ['GET'])]
    public function getBenchmark(string $id): JsonResponse
    {
        $lead = $this->findLeadOrFail($id);

        if ($lead === null) {
            return $this->json(['error' => 'Lead not found'], Response::HTTP_NOT_FOUND);
        }

        $analysis = $lead->getLatestAnalysis();

        if ($analysis === null) {
            return $this->json([
                'error' => 'Lead has no analysis',
            ], Response::HTTP_NOT_FOUND);
        }

        $industry = $analysis->getIndustry() ?? $lead->getIndustry();

        if ($industry === null) {
            return $this->json([
                'error' => 'Lead has no industry set',
                'hint' => 'Set industry on lead or run analysis with --industry parameter',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get benchmark comparison
        $comparison = $this->benchmarkCalculator->compareWithBenchmark($analysis);

        return $this->json([
            'lead' => [
                'id' => (string) $lead->getId(),
                'domain' => $lead->getDomain(),
                'industry' => $industry->value,
            ],
            'analysis' => [
                'id' => (string) $analysis->getId(),
                'totalScore' => $analysis->getTotalScore(),
                'issueCount' => $analysis->getIssueCount(),
                'completedAt' => $analysis->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            ],
            'benchmark' => $comparison,
        ]);
    }

    private function findLeadOrFail(string $id): ?Lead
    {
        try {
            $uuid = Uuid::fromString($id);

            return $this->leadRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
