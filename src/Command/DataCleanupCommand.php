<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\CleanupOldDataMessage;
use App\MessageHandler\CleanupOldDataMessageHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:data:cleanup',
    description: 'Clean up old data from various entities (email logs, analysis results, competitor snapshots, demand signals)',
)]
class DataCleanupCommand extends Command
{
    public function __construct(
        private readonly CleanupOldDataMessageHandler $handler,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'target',
                't',
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Target to clean up: %s',
                    implode(', ', CleanupOldDataMessage::VALID_TARGETS)
                ),
                CleanupOldDataMessage::TARGET_ALL
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be cleaned up without making changes'
            )
            ->addOption(
                'async',
                null,
                InputOption::VALUE_NONE,
                'Dispatch to message queue instead of processing synchronously'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = $input->getOption('target');
        $dryRun = $input->getOption('dry-run');
        $async = $input->getOption('async');

        // Validate target
        if (!in_array($target, CleanupOldDataMessage::VALID_TARGETS, true)) {
            $io->error(sprintf(
                'Invalid target "%s". Valid targets: %s',
                $target,
                implode(', ', CleanupOldDataMessage::VALID_TARGETS)
            ));

            return Command::FAILURE;
        }

        $io->title('Data Cleanup');

        $io->table([], [
            ['Target', $target],
            ['Dry run', $dryRun ? 'Yes' : 'No'],
            ['Async', $async ? 'Yes' : 'No'],
        ]);

        if ($async) {
            return $this->executeAsync($io, $target, $dryRun);
        }

        // Execute synchronously
        $message = new CleanupOldDataMessage(target: $target, dryRun: $dryRun);
        $results = ($this->handler)($message);

        $io->section('Results');

        $rows = [];
        foreach ($results as $type => $count) {
            $rows[] = [$type, $count];
        }
        $rows[] = ['<info>Total</info>', '<info>' . array_sum($results) . '</info>'];

        $io->table(['Type', 'Records Processed'], $rows);

        if ($dryRun) {
            $io->note('DRY RUN - No actual changes were made');
        }

        $io->success('Cleanup completed');

        return Command::SUCCESS;
    }

    private function executeAsync(SymfonyStyle $io, string $target, bool $dryRun): int
    {
        $io->text('Dispatching cleanup job to message queue...');

        if ($dryRun) {
            $io->note('Note: --dry-run flag will be passed to the async handler');
        }

        $this->messageBus->dispatch(new CleanupOldDataMessage(target: $target, dryRun: $dryRun));

        $io->success('Cleanup job dispatched to queue');

        return Command::SUCCESS;
    }
}
