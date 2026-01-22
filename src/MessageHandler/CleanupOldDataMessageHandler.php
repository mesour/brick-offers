<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CleanupOldDataMessage;
use App\Repository\CompetitorSnapshotRepository;
use App\Repository\DemandSignalRepository;
use App\Repository\EmailLogRepository;
use App\Service\Archive\ArchiveService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for cleaning up old data from various entities.
 */
#[AsMessageHandler]
final class CleanupOldDataMessageHandler
{
    private const EMAIL_RETENTION_DAYS = 365;
    private const COMPETITOR_KEEP_LATEST = 10;
    private const DEMAND_EXPIRED_DAYS = 90;

    public function __construct(
        private readonly EmailLogRepository $emailLogRepository,
        private readonly ArchiveService $archiveService,
        private readonly CompetitorSnapshotRepository $competitorSnapshotRepository,
        private readonly DemandSignalRepository $demandSignalRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, int>
     */
    public function __invoke(CleanupOldDataMessage $message): array
    {
        $this->logger->info('Starting data cleanup', [
            'target' => $message->target,
            'dry_run' => $message->dryRun,
        ]);

        $results = [];

        $targets = $message->target === CleanupOldDataMessage::TARGET_ALL
            ? [
                CleanupOldDataMessage::TARGET_EMAIL,
                CleanupOldDataMessage::TARGET_ANALYSIS,
                CleanupOldDataMessage::TARGET_COMPETITOR,
                CleanupOldDataMessage::TARGET_DEMAND,
            ]
            : [$message->target];

        foreach ($targets as $target) {
            $results[$target] = match ($target) {
                CleanupOldDataMessage::TARGET_EMAIL => $this->cleanupEmailLogs($message->dryRun),
                CleanupOldDataMessage::TARGET_ANALYSIS => $this->cleanupAnalysisResults($message->dryRun),
                CleanupOldDataMessage::TARGET_COMPETITOR => $this->cleanupCompetitorSnapshots($message->dryRun),
                CleanupOldDataMessage::TARGET_DEMAND => $this->cleanupDemandSignals($message->dryRun),
                default => 0,
            };
        }

        $total = array_sum($results);

        $this->logger->info('Data cleanup completed', [
            'target' => $message->target,
            'results' => $results,
            'total' => $total,
        ]);

        return $results;
    }

    private function cleanupEmailLogs(bool $dryRun): int
    {
        $cutoffDate = new \DateTimeImmutable(sprintf('-%d days', self::EMAIL_RETENTION_DAYS));

        if ($dryRun) {
            $logs = $this->emailLogRepository->findOlderThan($cutoffDate, 1000);

            return count($logs);
        }

        $totalDeleted = 0;
        $batchSize = 1000;

        while (true) {
            $logs = $this->emailLogRepository->findOlderThan($cutoffDate, $batchSize);

            if (empty($logs)) {
                break;
            }

            foreach ($logs as $log) {
                $this->em->remove($log);
            }

            $this->em->flush();
            $this->em->clear();

            $totalDeleted += count($logs);

            $this->logger->debug('Deleted email logs batch', ['count' => count($logs), 'total' => $totalDeleted]);

            // Safety check
            if ($totalDeleted > 1000000) {
                $this->logger->warning('Safety limit reached for email log deletion');
                break;
            }
        }

        return $totalDeleted;
    }

    private function cleanupAnalysisResults(bool $dryRun): int
    {
        // Use existing ArchiveService which handles tiered retention:
        // - 30-90 days: compress
        // - 90-365 days: clear rawData
        // - 365+ days: delete
        $stats = $this->archiveService->archive(
            compressAfterDays: 30,
            clearAfterDays: 90,
            deleteAfterDays: 365,
            batchSize: 100,
            dryRun: $dryRun,
        );

        return $stats->getTotal();
    }

    private function cleanupCompetitorSnapshots(bool $dryRun): int
    {
        // Keep only latest N snapshots per domain/type
        if ($dryRun) {
            // For dry run, we'd need to estimate - for now return 0
            // The actual cleanup uses a complex SQL query
            return 0;
        }

        return $this->competitorSnapshotRepository->cleanupOldSnapshots(self::COMPETITOR_KEEP_LATEST);
    }

    private function cleanupDemandSignals(bool $dryRun): int
    {
        // First, expire any signals with passed deadlines
        if (!$dryRun) {
            $this->demandSignalRepository->expireOldSignals();
        }

        // Then delete expired signals older than 90 days
        $cutoffDate = new \DateTimeImmutable(sprintf('-%d days', self::DEMAND_EXPIRED_DAYS));

        $qb = $this->em->createQueryBuilder();

        if ($dryRun) {
            return (int) $qb
                ->select('COUNT(d.id)')
                ->from('App\Entity\DemandSignal', 'd')
                ->where('d.status = :status')
                ->andWhere('d.deadline < :cutoff')
                ->setParameter('status', \App\Enum\DemandSignalStatus::EXPIRED)
                ->setParameter('cutoff', $cutoffDate)
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $qb
            ->delete('App\Entity\DemandSignal', 'd')
            ->where('d.status = :status')
            ->andWhere('d.deadline < :cutoff')
            ->setParameter('status', \App\Enum\DemandSignalStatus::EXPIRED)
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->execute();
    }
}
