<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\BatchDiscoveryMessage;
use App\MessageHandler\BatchDiscoveryMessageHandler;
use App\Repository\DiscoveryProfileRepository;
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
    description: 'Run batch discovery using Discovery Profiles',
)]
class DiscoveryBatchCommand extends Command
{
    public function __construct(
        private readonly BatchDiscoveryMessageHandler $handler,
        private readonly UserRepository $userRepository,
        private readonly DiscoveryProfileRepository $profileRepository,
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
                'profile-name',
                'p',
                InputOption::VALUE_REQUIRED,
                'Run discovery for specific profile name (requires --user)'
            )
            ->addOption(
                'all-users',
                null,
                InputOption::VALUE_NONE,
                'Run discovery for all users with enabled discovery profiles'
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
                'Show discovery profiles configuration'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userCode = $input->getOption('user');
        $profileName = $input->getOption('profile-name');
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

        if ($profileName !== null && $userCode === null) {
            $io->error('--profile-name requires --user option');

            return Command::FAILURE;
        }

        $io->title('Batch Discovery');

        // Show config mode
        if ($showConfig) {
            return $this->showConfig($io, $userCode);
        }

        $io->table([], [
            ['User', $userCode ?? 'All users'],
            ['Profile', $profileName ?? 'All active profiles'],
            ['Dry run', $dryRun ? 'Yes' : 'No'],
            ['Async', $async ? 'Yes' : 'No'],
        ]);

        if ($async) {
            return $this->executeAsync($io, $userCode, $profileName, $allUsers, $dryRun);
        }

        // Execute synchronously
        $message = new BatchDiscoveryMessage(
            userCode: $userCode,
            profileName: $profileName,
            allUsers: $allUsers,
            dryRun: $dryRun,
        );
        $result = ($this->handler)($message);

        $io->section('Results');

        $io->definitionList(
            ['Users processed' => $result['users_processed']],
            ['Profiles processed' => $result['profiles_processed']],
            ['Jobs dispatched' => $result['jobs_dispatched']],
            ['Profiles skipped' => $result['profiles_skipped']],
        );

        if ($dryRun) {
            $io->note('DRY RUN - No actual jobs were dispatched');
        }

        if ($result['jobs_dispatched'] === 0) {
            $io->warning('No discovery jobs were dispatched. Check that users have discovery profiles enabled.');
        } else {
            $io->success(sprintf(
                'Dispatched %d discovery job(s) for %d profile(s) from %d user(s)',
                $result['jobs_dispatched'],
                $result['profiles_processed'],
                $result['users_processed']
            ));
        }

        return Command::SUCCESS;
    }

    private function executeAsync(SymfonyStyle $io, ?string $userCode, ?string $profileName, bool $allUsers, bool $dryRun): int
    {
        $io->text('Dispatching batch discovery job to message queue...');

        if ($dryRun) {
            $io->note('Note: --dry-run flag will be passed to the async handler');
        }

        $this->messageBus->dispatch(new BatchDiscoveryMessage(
            userCode: $userCode,
            profileName: $profileName,
            allUsers: $allUsers,
            dryRun: $dryRun,
        ));

        $io->success('Batch discovery job dispatched to queue');

        return Command::SUCCESS;
    }

    private function showConfig(SymfonyStyle $io, ?string $userCode): int
    {
        $io->section('Discovery Profiles');

        $users = $userCode !== null
            ? (($u = $this->userRepository->findByCode($userCode)) !== null ? [$u] : [])
            : $this->userRepository->findActive();

        if (empty($users)) {
            $io->warning('No users found');

            return Command::SUCCESS;
        }

        foreach ($users as $user) {
            $io->writeln(sprintf(
                '<info>%s</info> (%s)',
                $user->getCode(),
                $user->getName()
            ));

            $profiles = $this->profileRepository->findByUser($user);

            if (empty($profiles)) {
                $io->text('  No discovery profiles');
            } else {
                foreach ($profiles as $profile) {
                    $io->writeln(sprintf(
                        '  <comment>%s</comment> %s - %s',
                        $profile->getName(),
                        $profile->isDefault() ? '(default)' : '',
                        $profile->isDiscoveryEnabled() ? '<fg=green>Enabled</>' : '<fg=gray>Disabled</>'
                    ));

                    if ($profile->isDiscoveryEnabled()) {
                        $io->table([], [
                            ['Sources', implode(', ', $profile->getDiscoverySources()) ?: '<none>'],
                            ['Queries', count($profile->getDiscoveryQueries()) > 0 ? implode(', ', array_slice($profile->getDiscoveryQueries(), 0, 3)) . (count($profile->getDiscoveryQueries()) > 3 ? '...' : '') : '<none>'],
                            ['Limit', $profile->getDiscoveryLimit()],
                            ['Industry', $profile->getIndustry()?->value ?? '<none>'],
                            ['Auto-analyze', $profile->isAutoAnalyze() ? 'Yes' : 'No'],
                        ]);
                    }
                }
            }

            $io->newLine();
        }

        return Command::SUCCESS;
    }
}
