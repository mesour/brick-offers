<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\BatchDiscoveryMessage;
use App\MessageHandler\BatchDiscoveryMessageHandler;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:discovery:batch',
    description: 'Run batch discovery for users with configured discovery settings',
)]
class DiscoveryBatchCommand extends Command
{
    public function __construct(
        private readonly BatchDiscoveryMessageHandler $handler,
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'Run discovery for specific user code only'
            )
            ->addOption(
                'all-users',
                null,
                InputOption::VALUE_NONE,
                'Run discovery for all users with enabled discovery settings'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be discovered without dispatching jobs'
            )
            ->addOption(
                'async',
                null,
                InputOption::VALUE_NONE,
                'Dispatch to message queue instead of processing synchronously'
            )
            ->addOption(
                'show-config',
                null,
                InputOption::VALUE_NONE,
                'Show discovery configuration for users'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userCode = $input->getOption('user');
        $allUsers = $input->getOption('all-users');
        $dryRun = $input->getOption('dry-run');
        $async = $input->getOption('async');
        $showConfig = $input->getOption('show-config');

        // Validate options
        if ($userCode !== null && $allUsers) {
            $io->error('Cannot use both --user and --all-users options');

            return Command::FAILURE;
        }

        if ($userCode === null && !$allUsers) {
            $io->error('Must specify either --user=CODE or --all-users');

            return Command::FAILURE;
        }

        $io->title('Batch Discovery');

        // Show config mode
        if ($showConfig) {
            return $this->showConfig($io, $userCode);
        }

        $io->table([], [
            ['User', $userCode ?? 'All users'],
            ['Dry run', $dryRun ? 'Yes' : 'No'],
            ['Async', $async ? 'Yes' : 'No'],
        ]);

        if ($async) {
            return $this->executeAsync($io, $userCode, $allUsers, $dryRun);
        }

        // Execute synchronously
        $message = new BatchDiscoveryMessage(
            userCode: $userCode,
            allUsers: $allUsers,
            dryRun: $dryRun
        );
        $result = ($this->handler)($message);

        $io->section('Results');

        $io->definitionList(
            ['Users processed' => $result['users_processed']],
            ['Jobs dispatched' => $result['jobs_dispatched']],
            ['Users skipped' => $result['users_skipped']],
        );

        if ($dryRun) {
            $io->note('DRY RUN - No actual jobs were dispatched');
        }

        if ($result['jobs_dispatched'] === 0) {
            $io->warning('No discovery jobs were dispatched. Check that users have discovery enabled in their settings.');
        } else {
            $io->success(sprintf('Dispatched %d discovery job(s) for %d user(s)', $result['jobs_dispatched'], $result['users_processed']));
        }

        return Command::SUCCESS;
    }

    private function executeAsync(SymfonyStyle $io, ?string $userCode, bool $allUsers, bool $dryRun): int
    {
        $io->text('Dispatching batch discovery job to message queue...');

        if ($dryRun) {
            $io->note('Note: --dry-run flag will be passed to the async handler');
        }

        $this->messageBus->dispatch(new BatchDiscoveryMessage(
            userCode: $userCode,
            allUsers: $allUsers,
            dryRun: $dryRun
        ));

        $io->success('Batch discovery job dispatched to queue');

        return Command::SUCCESS;
    }

    private function showConfig(SymfonyStyle $io, ?string $userCode): int
    {
        $io->section('Discovery Configuration');

        $users = $userCode !== null
            ? (($u = $this->userRepository->findByCode($userCode)) !== null ? [$u] : [])
            : $this->userRepository->findActive();

        if (empty($users)) {
            $io->warning('No users found');

            return Command::SUCCESS;
        }

        foreach ($users as $user) {
            $settings = $user->getSetting('discovery', []);
            $enabled = $settings['enabled'] ?? false;
            $sources = $settings['sources'] ?? [];
            $queries = $settings['queries'] ?? [];
            $limit = $settings['limit'] ?? 50;

            $io->writeln(sprintf(
                '<info>%s</info> (%s) - %s',
                $user->getCode(),
                $user->getName(),
                $enabled ? '<fg=green>Enabled</>' : '<fg=gray>Disabled</>'
            ));

            if ($enabled) {
                $io->table([], [
                    ['Sources', implode(', ', $sources) ?: '<none>'],
                    ['Queries', count($queries) > 0 ? implode(', ', array_slice($queries, 0, 3)) . (count($queries) > 3 ? '...' : '') : '<none>'],
                    ['Limit', $limit],
                ]);
            }

            $io->newLine();
        }

        return Command::SUCCESS;
    }
}
