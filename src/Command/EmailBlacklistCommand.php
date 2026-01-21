<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\EmailBounceType;
use App\Repository\UserRepository;
use App\Service\Email\EmailBlacklistService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:email:blacklist',
    description: 'Manage email blacklist',
)]
class EmailBlacklistCommand extends Command
{
    public function __construct(
        private readonly EmailBlacklistService $blacklistService,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action: add, remove, check, list')
            ->addArgument('email', InputArgument::OPTIONAL, 'Email address')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User code (for per-user blacklist)')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Bounce type: hard, soft, complaint, unsubscribe', 'hard')
            ->addOption('reason', 'r', InputOption::VALUE_REQUIRED, 'Reason for blacklisting')
            ->addOption('global', 'g', InputOption::VALUE_NONE, 'Show only global entries (for list action)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit for list action', '100')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        return match ($action) {
            'add' => $this->handleAdd($io, $input),
            'remove' => $this->handleRemove($io, $input),
            'check' => $this->handleCheck($io, $input),
            'list' => $this->handleList($io, $input),
            default => $this->handleUnknown($io, $action),
        };
    }

    private function handleAdd(SymfonyStyle $io, InputInterface $input): int
    {
        $email = $input->getArgument('email');

        if (empty($email)) {
            $io->error('Email address is required for add action');

            return Command::FAILURE;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Invalid email address');

            return Command::FAILURE;
        }

        // Get user if specified
        $user = null;
        if ($userCode = $input->getOption('user')) {
            $user = $this->userRepository->findOneBy(['code' => $userCode]);

            if ($user === null) {
                $io->error(sprintf('User not found: %s', $userCode));

                return Command::FAILURE;
            }
        }

        // Get bounce type
        $typeName = $input->getOption('type');
        try {
            $type = EmailBounceType::from($typeName);
        } catch (\ValueError) {
            $io->error(sprintf('Invalid type: %s. Valid options: hard, soft, complaint, unsubscribe', $typeName));

            return Command::FAILURE;
        }

        $reason = $input->getOption('reason') ?? 'Added via CLI';

        // Add to blacklist
        $entry = $this->blacklistService->add($email, $type, $user, $reason);

        $io->success(sprintf(
            'Added %s to %s blacklist (type: %s)',
            $email,
            $user !== null ? "user '{$user->getCode()}'" : 'global',
            $type->value
        ));

        return Command::SUCCESS;
    }

    private function handleRemove(SymfonyStyle $io, InputInterface $input): int
    {
        $email = $input->getArgument('email');

        if (empty($email)) {
            $io->error('Email address is required for remove action');

            return Command::FAILURE;
        }

        // Get user if specified
        $user = null;
        if ($userCode = $input->getOption('user')) {
            $user = $this->userRepository->findOneBy(['code' => $userCode]);

            if ($user === null) {
                $io->error(sprintf('User not found: %s', $userCode));

                return Command::FAILURE;
            }
        }

        $removed = $this->blacklistService->remove($email, $user);

        if ($removed) {
            $io->success(sprintf(
                'Removed %s from %s blacklist',
                $email,
                $user !== null ? "user '{$user->getCode()}'" : 'global'
            ));
        } else {
            $io->warning(sprintf('Email %s was not in the blacklist', $email));
        }

        return Command::SUCCESS;
    }

    private function handleCheck(SymfonyStyle $io, InputInterface $input): int
    {
        $email = $input->getArgument('email');

        if (empty($email)) {
            $io->error('Email address is required for check action');

            return Command::FAILURE;
        }

        // Get user if specified
        $user = null;
        if ($userCode = $input->getOption('user')) {
            $user = $this->userRepository->findOneBy(['code' => $userCode]);

            if ($user === null) {
                $io->error(sprintf('User not found: %s', $userCode));

                return Command::FAILURE;
            }
        }

        $isBlocked = $this->blacklistService->isBlocked($email, $user);
        $entry = $this->blacklistService->getEntry($email, $user);

        $io->section('Blacklist Check');

        if ($isBlocked && $entry !== null) {
            $io->table([], [
                ['Email', $email],
                ['Status', '<error>BLOCKED</error>'],
                ['Type', $entry->getType()->label()],
                ['Scope', $entry->isGlobal() ? 'Global' : 'User: ' . $entry->getUser()?->getCode()],
                ['Reason', $entry->getReason() ?? 'N/A'],
                ['Added', $entry->getCreatedAt()?->format('Y-m-d H:i:s')],
            ]);
        } else {
            $io->table([], [
                ['Email', $email],
                ['Status', '<info>NOT BLOCKED</info>'],
                ['Context', $user !== null ? "User: {$user->getCode()}" : 'Global check'],
            ]);
        }

        return Command::SUCCESS;
    }

    private function handleList(SymfonyStyle $io, InputInterface $input): int
    {
        $globalOnly = $input->getOption('global');
        $limit = (int) $input->getOption('limit');

        // Get user if specified
        $user = null;
        if (!$globalOnly && ($userCode = $input->getOption('user'))) {
            $user = $this->userRepository->findOneBy(['code' => $userCode]);

            if ($user === null) {
                $io->error(sprintf('User not found: %s', $userCode));

                return Command::FAILURE;
            }
        }

        $io->section('Email Blacklist');

        if ($globalOnly) {
            $entries = $this->blacklistService->getGlobalBounces($limit);
            $io->text(sprintf('Global blacklist entries (limit: %d):', $limit));
        } elseif ($user !== null) {
            $entries = $this->blacklistService->getUserUnsubscribes($user, $limit);
            $io->text(sprintf("Blacklist for user '%s' (limit: %d):", $user->getCode(), $limit));
        } else {
            // Show both global count and note
            $globalCount = $this->blacklistService->countGlobal();
            $entries = $this->blacklistService->getGlobalBounces($limit);
            $io->text(sprintf('Global blacklist entries: %d (showing %d)', $globalCount, min($globalCount, $limit)));
        }

        if (empty($entries)) {
            $io->success('No blacklist entries found');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                $entry->getEmail(),
                $entry->getType()->label(),
                $entry->isGlobal() ? 'Global' : $entry->getUser()?->getCode(),
                $entry->getReason() ?? 'N/A',
                $entry->getCreatedAt()?->format('Y-m-d H:i'),
            ];
        }

        $io->table(['Email', 'Type', 'Scope', 'Reason', 'Added'], $rows);

        return Command::SUCCESS;
    }

    private function handleUnknown(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf(
            'Unknown action: %s. Valid actions: add, remove, check, list',
            $action
        ));

        return Command::FAILURE;
    }
}
