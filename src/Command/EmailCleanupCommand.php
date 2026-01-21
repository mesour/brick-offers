<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EmailLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:email:cleanup',
    description: 'Clean up old email logs (retention policy)',
)]
class EmailCleanupCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 365;
    private const DEFAULT_BATCH_SIZE = 1000;

    public function __construct(
        private readonly EmailLogRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('retention-days', 'r', InputOption::VALUE_REQUIRED, 'Retention period in days', (string) self::DEFAULT_RETENTION_DAYS)
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Batch size for deletion', (string) self::DEFAULT_BATCH_SIZE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without executing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $retentionDays = (int) $input->getOption('retention-days');
        $batchSize = (int) $input->getOption('batch-size');
        $dryRun = $input->getOption('dry-run');

        if ($retentionDays < 1) {
            $io->error('Retention days must be at least 1');

            return Command::FAILURE;
        }

        $cutoffDate = new \DateTimeImmutable("-{$retentionDays} days");

        $io->section('Email Log Cleanup');
        $io->table([], [
            ['Retention period', sprintf('%d days', $retentionDays)],
            ['Cutoff date', $cutoffDate->format('Y-m-d H:i:s')],
            ['Batch size', $batchSize],
            ['Dry run', $dryRun ? 'Yes' : 'No'],
        ]);

        $totalDeleted = 0;
        $batches = 0;

        while (true) {
            $logs = $this->repository->findOlderThan($cutoffDate, $batchSize);

            if (empty($logs)) {
                break;
            }

            $count = count($logs);
            $batches++;

            if ($dryRun) {
                $io->text(sprintf('Batch %d: Would delete %d logs', $batches, $count));
                $totalDeleted += $count;

                // In dry run, we need to break to avoid infinite loop
                // since we're not actually deleting anything
                $io->note('Dry run - stopping after first batch preview');
                break;
            }

            foreach ($logs as $log) {
                $this->em->remove($log);
            }

            $this->em->flush();
            $this->em->clear();

            $totalDeleted += $count;
            $io->text(sprintf('Batch %d: Deleted %d logs', $batches, $count));

            // Safety check
            if ($batches > 1000) {
                $io->warning('Safety limit reached (1000 batches). Stopping.');
                break;
            }
        }

        $io->newLine();

        if ($dryRun) {
            $io->success(sprintf(
                'Dry run: Would delete approximately %d logs (first batch shown)',
                $totalDeleted
            ));
        } else {
            $io->success(sprintf(
                'Cleanup completed: %d logs deleted in %d batches',
                $totalDeleted,
                $batches
            ));
        }

        return Command::SUCCESS;
    }
}
