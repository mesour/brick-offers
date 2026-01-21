<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Lead;
use App\Enum\SnapshotPeriod;
use App\Repository\AnalysisSnapshotRepository;
use App\Service\Snapshot\SnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:analysis:snapshot',
    description: 'Generate analysis snapshots for trending and benchmarking',
)]
class AnalysisSnapshotCommand extends Command
{
    public function __construct(
        private readonly SnapshotService $snapshotService,
        private readonly AnalysisSnapshotRepository $snapshotRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'period',
                'p',
                InputOption::VALUE_REQUIRED,
                'Snapshot period type (day, week, month)',
                'week'
            )
            ->addOption(
                'lead-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Generate snapshot for specific lead by UUID'
            )
            ->addOption(
                'cleanup',
                null,
                InputOption::VALUE_NONE,
                'Clean up old snapshots based on retention policy'
            )
            ->addOption(
                'retention',
                'r',
                InputOption::VALUE_REQUIRED,
                'Number of periods to retain (for cleanup)',
                52
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate without saving to database'
            )
            ->addOption(
                'show-stats',
                null,
                InputOption::VALUE_NONE,
                'Show snapshot statistics'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $periodValue = $input->getOption('period');
        $leadId = $input->getOption('lead-id');
        $cleanup = $input->getOption('cleanup');
        $retention = (int) $input->getOption('retention');
        $dryRun = $input->getOption('dry-run');
        $showStats = $input->getOption('show-stats');

        // Parse period type
        $periodType = SnapshotPeriod::tryFrom($periodValue);
        if ($periodType === null) {
            $io->error(sprintf('Invalid period type "%s". Available: day, week, month', $periodValue));

            return Command::FAILURE;
        }

        $io->title('Analysis Snapshot Generator');
        $io->note(sprintf('Period type: %s', $periodType->getLabel()));

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be saved');
        }

        // Show statistics if requested
        if ($showStats) {
            $this->displayStatistics($io, $periodType);

            return Command::SUCCESS;
        }

        // Clean up old snapshots
        if ($cleanup) {
            return $this->executeCleanup($io, $periodType, $retention, $dryRun);
        }

        // Generate snapshot for specific lead
        if ($leadId !== null) {
            return $this->executeForLead($io, $leadId, $periodType, $dryRun);
        }

        // Generate snapshots for all leads
        return $this->executeForAllLeads($io, $periodType, $dryRun);
    }

    private function executeForLead(
        SymfonyStyle $io,
        string $leadId,
        SnapshotPeriod $periodType,
        bool $dryRun,
    ): int {
        try {
            $uuid = Uuid::fromString($leadId);
            $lead = $this->entityManager->getRepository(Lead::class)->find($uuid);

            if ($lead === null) {
                $io->error(sprintf('Lead with ID "%s" not found', $leadId));

                return Command::FAILURE;
            }

            $analysis = $lead->getLatestAnalysis();
            if ($analysis === null) {
                $io->warning(sprintf('Lead "%s" has no analysis', $lead->getDomain()));

                return Command::SUCCESS;
            }

            $io->section(sprintf('Generating snapshot for %s', $lead->getDomain()));

            if (!$dryRun) {
                $snapshot = $this->snapshotService->createSnapshot($analysis, $periodType);

                if ($snapshot !== null) {
                    $this->entityManager->flush();
                    $io->success(sprintf(
                        'Created snapshot: Score %d, Issues %d, Period %s',
                        $snapshot->getTotalScore(),
                        $snapshot->getIssueCount(),
                        $snapshot->getPeriodStart()->format('Y-m-d')
                    ));
                }
            } else {
                $io->note('Would create snapshot (dry-run)');
            }

            return Command::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $io->error(sprintf('Invalid UUID: %s', $leadId));

            return Command::FAILURE;
        }
    }

    private function executeForAllLeads(
        SymfonyStyle $io,
        SnapshotPeriod $periodType,
        bool $dryRun,
    ): int {
        $io->section('Generating snapshots for all leads');

        if ($dryRun) {
            // Count leads that would be processed
            $count = (int) $this->entityManager->getRepository(Lead::class)
                ->createQueryBuilder('l')
                ->select('COUNT(l.id)')
                ->where('l.latestAnalysis IS NOT NULL')
                ->getQuery()
                ->getSingleScalarResult();

            $io->note(sprintf('Would process %d leads (dry-run)', $count));

            return Command::SUCCESS;
        }

        $stats = $this->snapshotService->createSnapshotsForAllLeads($periodType);

        $io->section('Results');
        $io->definitionList(
            ['Created' => $stats['created']],
            ['Updated' => $stats['updated']],
            ['Skipped' => $stats['skipped']],
        );

        $io->success('Snapshot generation complete');

        return Command::SUCCESS;
    }

    private function executeCleanup(
        SymfonyStyle $io,
        SnapshotPeriod $periodType,
        int $retention,
        bool $dryRun,
    ): int {
        $io->section(sprintf('Cleaning up %s snapshots older than %d periods', $periodType->value, $retention));

        if ($dryRun) {
            // Count snapshots that would be deleted
            $cutoffDate = match ($periodType) {
                SnapshotPeriod::DAY => (new \DateTimeImmutable())->modify("-{$retention} days"),
                SnapshotPeriod::WEEK => (new \DateTimeImmutable())->modify("-{$retention} weeks"),
                SnapshotPeriod::MONTH => (new \DateTimeImmutable())->modify("-{$retention} months"),
            };

            $count = (int) $this->snapshotRepository->createQueryBuilder('s')
                ->select('COUNT(s.id)')
                ->where('s.periodType = :periodType')
                ->andWhere('s.periodStart < :cutoffDate')
                ->setParameter('periodType', $periodType)
                ->setParameter('cutoffDate', $cutoffDate)
                ->getQuery()
                ->getSingleScalarResult();

            $io->note(sprintf('Would delete %d snapshots (dry-run)', $count));

            return Command::SUCCESS;
        }

        $deleted = $this->snapshotService->cleanupOldSnapshots($periodType, $retention);

        $io->success(sprintf('Deleted %d old snapshots', $deleted));

        return Command::SUCCESS;
    }

    private function displayStatistics(SymfonyStyle $io, SnapshotPeriod $periodType): void
    {
        $io->section('Snapshot Statistics');

        // Count by period type
        $counts = $this->snapshotRepository->createQueryBuilder('s')
            ->select('s.periodType, COUNT(s.id) as cnt')
            ->groupBy('s.periodType')
            ->getQuery()
            ->getArrayResult();

        $periodCounts = [];
        foreach ($counts as $row) {
            $periodCounts[$row['periodType']->value] = (int) $row['cnt'];
        }

        $io->table(
            ['Period Type', 'Count'],
            array_map(fn ($k, $v) => [$k, $v], array_keys($periodCounts), $periodCounts)
        );

        // Latest snapshots info
        $latestSnapshots = $this->snapshotRepository->createQueryBuilder('s')
            ->where('s.periodType = :periodType')
            ->setParameter('periodType', $periodType)
            ->orderBy('s.periodStart', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        if (!empty($latestSnapshots)) {
            $io->section('Latest Snapshots');
            $rows = [];
            foreach ($latestSnapshots as $snapshot) {
                $rows[] = [
                    $snapshot->getLead()?->getDomain() ?? 'N/A',
                    $snapshot->getPeriodStart()->format('Y-m-d'),
                    $snapshot->getTotalScore(),
                    $snapshot->getIssueCount(),
                    $snapshot->getScoreDelta() !== null ? sprintf('%+d', $snapshot->getScoreDelta()) : '-',
                ];
            }
            $io->table(['Domain', 'Period Start', 'Score', 'Issues', 'Delta'], $rows);
        }

        // Industry distribution
        $industryStats = $this->snapshotRepository->createQueryBuilder('s')
            ->select('s.industry, COUNT(s.id) as cnt, AVG(s.totalScore) as avgScore')
            ->where('s.industry IS NOT NULL')
            ->groupBy('s.industry')
            ->getQuery()
            ->getArrayResult();

        if (!empty($industryStats)) {
            $io->section('Industry Distribution');
            $rows = [];
            foreach ($industryStats as $row) {
                $rows[] = [
                    $row['industry']->value ?? 'N/A',
                    $row['cnt'],
                    round($row['avgScore'], 1),
                ];
            }
            $io->table(['Industry', 'Snapshots', 'Avg Score'], $rows);
        }
    }
}
