<?php

declare(strict_types=1);

namespace App\Service\Snapshot;

use App\Entity\Analysis;
use App\Entity\AnalysisSnapshot;
use App\Entity\Lead;
use App\Enum\SnapshotPeriod;
use App\Repository\AnalysisSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for creating and managing analysis snapshots.
 * Snapshots are periodic aggregations of analysis data for trending and benchmarking.
 */
class SnapshotService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnalysisSnapshotRepository $snapshotRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create a snapshot from an analysis.
     * Will update existing snapshot for the same period or create a new one.
     */
    public function createSnapshot(Analysis $analysis, ?SnapshotPeriod $periodType = null): ?AnalysisSnapshot
    {
        $lead = $analysis->getLead();
        if ($lead === null) {
            $this->logger->warning('Cannot create snapshot: Analysis has no lead');

            return null;
        }

        // Use provided period type or get from lead's effective setting
        $periodType = $periodType ?? $lead->getEffectiveSnapshotPeriod();
        $periodStart = $this->calculatePeriodStart($periodType);

        // Check if snapshot already exists for this period
        $existingSnapshot = $this->snapshotRepository->findByLeadAndPeriod($lead, $periodType, $periodStart);

        if ($existingSnapshot !== null) {
            // Update existing snapshot with newer analysis data
            return $this->updateSnapshot($existingSnapshot, $analysis);
        }

        // Create new snapshot
        return $this->createNewSnapshot($analysis, $periodType, $periodStart);
    }

    /**
     * Create snapshots for all leads with completed analyses.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function createSnapshotsForAllLeads(SnapshotPeriod $periodType): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        // Get all leads with latest analysis
        $leads = $this->entityManager->getRepository(Lead::class)
            ->createQueryBuilder('l')
            ->where('l.latestAnalysis IS NOT NULL')
            ->getQuery()
            ->getResult();

        foreach ($leads as $lead) {
            $analysis = $lead->getLatestAnalysis();
            if ($analysis === null) {
                $stats['skipped']++;
                continue;
            }

            $periodStart = $this->calculatePeriodStart($periodType);
            $existingSnapshot = $this->snapshotRepository->findByLeadAndPeriod($lead, $periodType, $periodStart);

            if ($existingSnapshot !== null) {
                $this->updateSnapshot($existingSnapshot, $analysis);
                $stats['updated']++;
            } else {
                $this->createNewSnapshot($analysis, $periodType, $periodStart);
                $stats['created']++;
            }
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * Get previous snapshot for delta calculation.
     */
    public function getPreviousSnapshot(Lead $lead, SnapshotPeriod $periodType): ?AnalysisSnapshot
    {
        $currentPeriodStart = $this->calculatePeriodStart($periodType);
        $previousPeriodStart = $this->calculatePreviousPeriodStart($periodType, $currentPeriodStart);

        return $this->snapshotRepository->findByLeadAndPeriod($lead, $periodType, $previousPeriodStart);
    }

    /**
     * Calculate the period start date based on period type.
     */
    public function calculatePeriodStart(SnapshotPeriod $periodType, ?\DateTimeImmutable $date = null): \DateTimeImmutable
    {
        $date = $date ?? new \DateTimeImmutable();

        return match ($periodType) {
            SnapshotPeriod::DAY => $date->setTime(0, 0, 0),
            SnapshotPeriod::WEEK => $date->modify('monday this week')->setTime(0, 0, 0),
            SnapshotPeriod::MONTH => $date->modify('first day of this month')->setTime(0, 0, 0),
        };
    }

    /**
     * Calculate the previous period start date.
     */
    private function calculatePreviousPeriodStart(
        SnapshotPeriod $periodType,
        \DateTimeImmutable $currentPeriodStart,
    ): \DateTimeImmutable {
        return match ($periodType) {
            SnapshotPeriod::DAY => $currentPeriodStart->modify('-1 day'),
            SnapshotPeriod::WEEK => $currentPeriodStart->modify('-1 week'),
            SnapshotPeriod::MONTH => $currentPeriodStart->modify('-1 month'),
        };
    }

    /**
     * Create a new snapshot entity.
     */
    private function createNewSnapshot(
        Analysis $analysis,
        SnapshotPeriod $periodType,
        \DateTimeImmutable $periodStart,
    ): AnalysisSnapshot {
        $lead = $analysis->getLead();

        $snapshot = new AnalysisSnapshot();
        $snapshot->setLead($lead);
        $snapshot->setAnalysis($analysis);
        $snapshot->setPeriodType($periodType);
        $snapshot->setPeriodStart($periodStart);
        $snapshot->setTotalScore($analysis->getTotalScore());
        $snapshot->setCategoryScores($analysis->getScores());
        $snapshot->setIssueCount($analysis->getIssueCount());
        $snapshot->setCriticalIssueCount($analysis->getCriticalIssueCount());
        $snapshot->setIndustry($analysis->getIndustry() ?? $lead->getIndustry());

        // Get top 5 issue codes
        $issueCodes = $analysis->getIssueCodes();
        $snapshot->setTopIssues(array_slice($issueCodes, 0, 5));

        // Calculate score delta from previous snapshot
        $previousSnapshot = $this->getPreviousSnapshot($lead, $periodType);
        if ($previousSnapshot !== null) {
            $scoreDelta = $analysis->getTotalScore() - $previousSnapshot->getTotalScore();
            $snapshot->setScoreDelta($scoreDelta);
        }

        $this->entityManager->persist($snapshot);

        $this->logger->info('Created snapshot for lead {domain}', [
            'domain' => $lead->getDomain(),
            'periodType' => $periodType->value,
            'periodStart' => $periodStart->format('Y-m-d'),
            'totalScore' => $analysis->getTotalScore(),
        ]);

        return $snapshot;
    }

    /**
     * Update an existing snapshot with new analysis data.
     */
    private function updateSnapshot(AnalysisSnapshot $snapshot, Analysis $analysis): AnalysisSnapshot
    {
        $snapshot->setAnalysis($analysis);
        $snapshot->setTotalScore($analysis->getTotalScore());
        $snapshot->setCategoryScores($analysis->getScores());
        $snapshot->setIssueCount($analysis->getIssueCount());
        $snapshot->setCriticalIssueCount($analysis->getCriticalIssueCount());
        $snapshot->setIndustry($analysis->getIndustry() ?? $snapshot->getLead()?->getIndustry());

        // Update top issues
        $issueCodes = $analysis->getIssueCodes();
        $snapshot->setTopIssues(array_slice($issueCodes, 0, 5));

        // Recalculate score delta
        $previousSnapshot = $this->getPreviousSnapshot(
            $snapshot->getLead(),
            $snapshot->getPeriodType()
        );
        if ($previousSnapshot !== null) {
            $scoreDelta = $analysis->getTotalScore() - $previousSnapshot->getTotalScore();
            $snapshot->setScoreDelta($scoreDelta);
        }

        $this->logger->info('Updated snapshot for lead {domain}', [
            'domain' => $snapshot->getLead()?->getDomain(),
            'periodType' => $snapshot->getPeriodType()->value,
        ]);

        return $snapshot;
    }

    /**
     * Clean up old snapshots based on retention policy.
     *
     * @return int Number of deleted snapshots
     */
    public function cleanupOldSnapshots(SnapshotPeriod $periodType, int $retentionPeriods): int
    {
        $cutoffDate = $this->calculateCutoffDate($periodType, $retentionPeriods);

        $qb = $this->entityManager->createQueryBuilder();
        $deleted = $qb->delete(AnalysisSnapshot::class, 's')
            ->where('s.periodType = :periodType')
            ->andWhere('s.periodStart < :cutoffDate')
            ->setParameter('periodType', $periodType)
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();

        $this->logger->info('Cleaned up {count} old snapshots', [
            'count' => $deleted,
            'periodType' => $periodType->value,
            'cutoffDate' => $cutoffDate->format('Y-m-d'),
        ]);

        return (int) $deleted;
    }

    /**
     * Calculate cutoff date for retention.
     */
    private function calculateCutoffDate(SnapshotPeriod $periodType, int $retentionPeriods): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        return match ($periodType) {
            SnapshotPeriod::DAY => $now->modify("-{$retentionPeriods} days"),
            SnapshotPeriod::WEEK => $now->modify("-{$retentionPeriods} weeks"),
            SnapshotPeriod::MONTH => $now->modify("-{$retentionPeriods} months"),
        };
    }
}
