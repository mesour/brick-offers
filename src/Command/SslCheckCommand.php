<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\CheckSslCertificatesMessage;
use App\MessageHandler\CheckSslCertificatesMessageHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:ssl:check',
    description: 'Check for SSL certificates that are expiring soon',
)]
class SslCheckCommand extends Command
{
    public function __construct(
        private readonly CheckSslCertificatesMessageHandler $handler,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'threshold',
                't',
                InputOption::VALUE_REQUIRED,
                'Days until expiration threshold',
                '30'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'Check only for specific user code'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Only show report without taking any action (same as normal run currently)'
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
        $threshold = (int) $input->getOption('threshold');
        $userCode = $input->getOption('user');
        $dryRun = $input->getOption('dry-run');
        $async = $input->getOption('async');

        if ($threshold < 1) {
            $io->error('Threshold must be at least 1 day');

            return Command::FAILURE;
        }

        $io->title('SSL Certificate Check');

        $io->table([], [
            ['Threshold', sprintf('%d days', $threshold)],
            ['User filter', $userCode ?? 'All users'],
            ['Dry run', $dryRun ? 'Yes' : 'No'],
            ['Async', $async ? 'Yes' : 'No'],
        ]);

        if ($async) {
            return $this->executeAsync($io, $threshold, $userCode, $dryRun);
        }

        // Execute synchronously
        $message = new CheckSslCertificatesMessage(
            thresholdDays: $threshold,
            userCode: $userCode,
            dryRun: $dryRun
        );
        $result = ($this->handler)($message);

        if ($result['total'] === 0) {
            $io->success('No SSL certificates expiring within the threshold');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Found %d certificate(s) expiring soon', $result['total']));

        foreach ($result['by_user'] as $user => $domains) {
            $io->writeln(sprintf('<info>User: %s</info> (%d certificates)', $user, count($domains)));

            $rows = [];
            foreach ($domains as $domain) {
                $daysColor = match (true) {
                    $domain['expires_days'] <= 7 => 'red',
                    $domain['expires_days'] <= 14 => 'yellow',
                    default => 'green',
                };
                $rows[] = [
                    $domain['domain'],
                    sprintf('<%s>%d days</>', $daysColor, $domain['expires_days']),
                ];
            }

            $io->table(['Domain', 'Expires In'], $rows);
        }

        $io->warning(sprintf(
            'Total: %d certificate(s) expiring within %d days across %d user(s)',
            $result['total'],
            $threshold,
            count($result['by_user'])
        ));

        return Command::SUCCESS;
    }

    private function executeAsync(SymfonyStyle $io, int $threshold, ?string $userCode, bool $dryRun): int
    {
        $io->text('Dispatching SSL check job to message queue...');

        $this->messageBus->dispatch(new CheckSslCertificatesMessage(
            thresholdDays: $threshold,
            userCode: $userCode,
            dryRun: $dryRun
        ));

        $io->success('SSL check job dispatched to queue');

        return Command::SUCCESS;
    }
}
