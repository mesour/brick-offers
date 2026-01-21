<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Archive\ArchiveService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:analysis:archive',
    description: 'Archive old analysis data according to retention policy',
)]
class AnalysisArchiveCommand extends Command
{
    public function __construct(
        private readonly ArchiveService $archiveService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'compress-after',
                null,
                InputOption::VALUE_REQUIRED,
                'Days after which to compress rawData',
                30
            )
            ->addOption(
                'clear-after',
                null,
                InputOption::VALUE_REQUIRED,
                'Days after which to clear rawData (keep issues)',
                90
            )
            ->addOption(
                'delete-after',
                null,
                InputOption::VALUE_REQUIRED,
                'Days after which to delete AnalysisResult entirely',
                365
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_REQUIRED,
                'Batch size for processing',
                100
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate without making changes'
            )
            ->addOption(
                'show-counts',
                null,
                InputOption::VALUE_NONE,
                'Only show counts, do not archive'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $compressAfter = (int) $input->getOption('compress-after');
        $clearAfter = (int) $input->getOption('clear-after');
        $deleteAfter = (int) $input->getOption('delete-after');
        $batchSize = (int) $input->getOption('batch-size');
        $dryRun = $input->getOption('dry-run');
        $showCounts = $input->getOption('show-counts');

        // Validate options
        if ($compressAfter >= $clearAfter) {
            $io->error('compress-after must be less than clear-after');

            return Command::FAILURE;
        }

        if ($clearAfter >= $deleteAfter) {
            $io->error('clear-after must be less than delete-after');

            return Command::FAILURE;
        }

        $io->title('Analysis Archive');

        $io->definitionList(
            ['Compress after' => sprintf('%d days', $compressAfter)],
            ['Clear after' => sprintf('%d days', $clearAfter)],
            ['Delete after' => sprintf('%d days', $deleteAfter)],
            ['Batch size' => $batchSize],
        );

        // Show counts only
        if ($showCounts) {
            return $this->showCounts($io, $compressAfter, $clearAfter, $deleteAfter);
        }

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be made');
        }

        // Show preview counts
        $counts = $this->archiveService->getArchiveCounts($compressAfter, $clearAfter, $deleteAfter);

        $io->section('Preview');
        $io->table(
            ['Action', 'Count'],
            [
                ['Compress (30-90 days)', $counts['compress']],
                ['Clear rawData (90-365 days)', $counts['clear']],
                ['Delete (365+ days)', $counts['delete']],
                ['Total', $counts['compress'] + $counts['clear'] + $counts['delete']],
            ]
        );

        if ($counts['compress'] + $counts['clear'] + $counts['delete'] === 0) {
            $io->success('Nothing to archive');

            return Command::SUCCESS;
        }

        // Run archive
        $io->section('Archiving...');

        $stats = $this->archiveService->archive(
            $compressAfter,
            $clearAfter,
            $deleteAfter,
            $batchSize,
            $dryRun
        );

        // Results
        $io->section('Results');
        $io->table(
            ['Action', 'Processed'],
            [
                ['Compressed', $stats->compressed],
                ['Cleared', $stats->cleared],
                ['Deleted', $stats->deleted],
                ['Total', $stats->getTotal()],
            ]
        );

        if ($dryRun) {
            $io->note('DRY RUN - No actual changes were made');
        } else {
            $io->success(sprintf('Archive completed: %d records processed', $stats->getTotal()));
        }

        return Command::SUCCESS;
    }

    private function showCounts(
        SymfonyStyle $io,
        int $compressAfter,
        int $clearAfter,
        int $deleteAfter,
    ): int {
        $counts = $this->archiveService->getArchiveCounts($compressAfter, $clearAfter, $deleteAfter);

        $io->section('Archive Counts');
        $io->table(
            ['Action', 'Date Range', 'Count'],
            [
                ['Compress', sprintf('%d-%d days', $compressAfter, $clearAfter), $counts['compress']],
                ['Clear rawData', sprintf('%d-%d days', $clearAfter, $deleteAfter), $counts['clear']],
                ['Delete', sprintf('%d+ days', $deleteAfter), $counts['delete']],
            ]
        );

        $io->text(sprintf('Total: %d records', $counts['compress'] + $counts['clear'] + $counts['delete']));

        return Command::SUCCESS;
    }
}
