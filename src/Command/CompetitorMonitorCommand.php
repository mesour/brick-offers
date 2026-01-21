<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CompetitorSnapshot;
use App\Entity\Lead;
use App\Enum\ChangeSignificance;
use App\Enum\CompetitorSnapshotType;
use App\Enum\Industry;
use App\Repository\CompetitorSnapshotRepository;
use App\Repository\LeadRepository;
use App\Service\Competitor\CompetitorMonitorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:competitor:monitor',
    description: 'Monitor competitors for portfolio, pricing, and service changes',
)]
class CompetitorMonitorCommand extends Command
{
    /** @var iterable<CompetitorMonitorInterface> */
    private iterable $monitors;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeadRepository $leadRepository,
        private readonly CompetitorSnapshotRepository $snapshotRepository,
        iterable $monitors,
    ) {
        parent::__construct();
        $this->monitors = $monitors;
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Monitor type: portfolio, pricing, services, all', 'all')
            ->addOption('competitor', 'c', InputOption::VALUE_REQUIRED, 'Specific competitor URL or domain to monitor')
            ->addOption('industry', 'i', InputOption::VALUE_REQUIRED, 'Filter competitors by industry')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum competitors to monitor', 50)
            ->addOption('only-changes', null, InputOption::VALUE_NONE, 'Only output competitors with detected changes')
            ->addOption('min-significance', null, InputOption::VALUE_REQUIRED, 'Minimum significance to report: critical, high, medium, low', 'low')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not persist snapshots to database')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Clean up old snapshots (keep last 10 per competitor/type)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $typeOption = $input->getOption('type');
        $competitorOption = $input->getOption('competitor');
        $industryOption = $input->getOption('industry');
        $limit = (int) $input->getOption('limit');
        $onlyChanges = $input->getOption('only-changes');
        $minSignificanceOption = $input->getOption('min-significance');
        $dryRun = $input->getOption('dry-run');
        $cleanup = $input->getOption('cleanup');

        // Cleanup old snapshots if requested
        if ($cleanup) {
            $deleted = $this->snapshotRepository->cleanupOldSnapshots(10);
            $io->note("Cleaned up {$deleted} old snapshots");
        }

        // Determine which types to monitor
        $types = $this->getTypesToMonitor($typeOption);
        if (empty($types)) {
            $io->error('No valid monitor type specified');

            return Command::FAILURE;
        }

        // Determine minimum significance
        $minSignificance = ChangeSignificance::tryFrom($minSignificanceOption) ?? ChangeSignificance::LOW;

        // Get competitors to monitor
        $competitors = $this->getCompetitors($competitorOption, $industryOption, $limit);
        if (empty($competitors)) {
            $io->warning('No competitors found to monitor');

            return Command::SUCCESS;
        }

        $io->title('Competitor Monitor');
        $io->text("Types: " . implode(', ', array_map(fn ($t) => $t->value, $types)));
        $io->text("Competitors: " . count($competitors));
        if ($dryRun) {
            $io->note('DRY RUN - snapshots will not be persisted');
        }

        $totalSnapshots = 0;
        $totalChanges = 0;
        $significantChanges = [];

        foreach ($competitors as $competitor) {
            $competitorLabel = $competitor->getDomain();
            $io->section("Monitoring: {$competitorLabel}");

            foreach ($types as $type) {
                $monitor = $this->getMonitor($type);
                if ($monitor === null) {
                    $io->warning("  No monitor implementation for type: {$type->value}");
                    continue;
                }

                $io->text("  Checking {$type->getLabel()}...");

                try {
                    $snapshot = $monitor->createSnapshot($competitor);

                    if ($snapshot === null) {
                        if ($output->isVerbose()) {
                            $io->text("    No data extracted");
                        }
                        continue;
                    }

                    $totalSnapshots++;

                    // Report changes
                    if ($snapshot->hasChanges()) {
                        $totalChanges++;
                        $changes = $snapshot->getChanges();

                        foreach ($changes as $change) {
                            $significance = ChangeSignificance::tryFrom($change['significance']) ?? ChangeSignificance::LOW;

                            if ($significance->getWeight() >= $minSignificance->getWeight()) {
                                $io->text("    [" . strtoupper($change['significance']) . "] {$change['field']}");

                                if ($significance->getWeight() >= ChangeSignificance::HIGH->getWeight()) {
                                    $significantChanges[] = [
                                        'competitor' => $competitorLabel,
                                        'type' => $type->value,
                                        'change' => $change,
                                    ];
                                }
                            }
                        }
                    } elseif (!$onlyChanges && $output->isVerbose()) {
                        $io->text("    No changes detected");
                    }

                    // Persist snapshot
                    if (!$dryRun) {
                        $this->entityManager->persist($snapshot);
                    }

                } catch (\Throwable $e) {
                    $io->error("    Failed: " . $e->getMessage());
                }
            }

            // Flush after each competitor to avoid memory issues
            if (!$dryRun) {
                $this->entityManager->flush();
                $this->entityManager->clear(CompetitorSnapshot::class);
            }
        }

        // Final summary
        $io->newLine();
        $io->success([
            "Monitoring complete!",
            "Snapshots created: {$totalSnapshots}",
            "Competitors with changes: {$totalChanges}",
            "Significant changes: " . count($significantChanges),
        ]);

        // List significant changes
        if (!empty($significantChanges)) {
            $io->section('Significant Changes (HIGH/CRITICAL)');

            $tableRows = [];
            foreach ($significantChanges as $sc) {
                $tableRows[] = [
                    $sc['competitor'],
                    $sc['type'],
                    $sc['change']['field'],
                    strtoupper($sc['change']['significance']),
                ];
            }

            $io->table(['Competitor', 'Type', 'Field', 'Significance'], $tableRows);
        }

        return Command::SUCCESS;
    }

    /**
     * @return CompetitorSnapshotType[]
     */
    private function getTypesToMonitor(string $option): array
    {
        if ($option === 'all') {
            return [
                CompetitorSnapshotType::PORTFOLIO,
                CompetitorSnapshotType::PRICING,
                CompetitorSnapshotType::SERVICES,
            ];
        }

        $type = CompetitorSnapshotType::tryFrom($option);

        return $type !== null ? [$type] : [];
    }

    /**
     * @return Lead[]
     */
    private function getCompetitors(?string $competitorOption, ?string $industryOption, int $limit): array
    {
        // If specific competitor provided
        if ($competitorOption !== null) {
            // Try to find by domain
            $competitor = $this->leadRepository->findOneBy(['domain' => $competitorOption]);

            if ($competitor === null) {
                // Try by URL
                $competitor = $this->leadRepository->findOneBy(['url' => $competitorOption]);
            }

            return $competitor !== null ? [$competitor] : [];
        }

        // Filter by industry if specified
        $criteria = ['hasWebsite' => true];
        if ($industryOption !== null) {
            $industry = Industry::tryFrom($industryOption);
            if ($industry !== null) {
                $criteria['industry'] = $industry;
            }
        }

        return $this->leadRepository->findBy($criteria, ['createdAt' => 'DESC'], $limit);
    }

    private function getMonitor(CompetitorSnapshotType $type): ?CompetitorMonitorInterface
    {
        foreach ($this->monitors as $monitor) {
            if ($monitor->supports($type)) {
                return $monitor;
            }
        }

        return null;
    }
}
