<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ProposalStatus;
use App\Message\ExpireProposalsMessage;
use App\Repository\ProposalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:proposal:expire',
    description: 'Expire proposals with passed expiresAt dates',
)]
class ProposalExpireCommand extends Command
{
    public function __construct(
        private readonly ProposalRepository $proposalRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be expired without making changes'
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
        $dryRun = $input->getOption('dry-run');
        $async = $input->getOption('async');

        $io->title('Proposal Expiration');

        if ($async) {
            return $this->executeAsync($io, $dryRun);
        }

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be made');
        }

        $expiredProposals = $this->proposalRepository->findExpired();
        $count = count($expiredProposals);

        if ($count === 0) {
            $io->success('No expired proposals found');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Found %d expired proposal(s)', $count));

        // Show details
        $rows = [];
        foreach ($expiredProposals as $proposal) {
            $rows[] = [
                $proposal->getId()?->toRfc4122(),
                $proposal->getTitle(),
                $proposal->getStatus()->label(),
                $proposal->getExpiresAt()?->format('Y-m-d H:i'),
                $proposal->getUser()->getCode(),
            ];
        }

        $io->table(['ID', 'Title', 'Current Status', 'Expired At', 'User'], $rows);

        if ($dryRun) {
            $io->success(sprintf('Dry run: Would expire %d proposal(s)', $count));

            return Command::SUCCESS;
        }

        // Update status
        foreach ($expiredProposals as $proposal) {
            $proposal->setStatus(ProposalStatus::EXPIRED);
        }

        $this->em->flush();

        $io->success(sprintf('Expired %d proposal(s)', $count));

        return Command::SUCCESS;
    }

    private function executeAsync(SymfonyStyle $io, bool $dryRun): int
    {
        $io->text('Dispatching expiration job to message queue...');

        if ($dryRun) {
            $io->note('Note: --dry-run flag will be passed to the async handler');
        }

        $this->messageBus->dispatch(new ExpireProposalsMessage(dryRun: $dryRun));

        $io->success('Expiration job dispatched to queue');

        return Command::SUCCESS;
    }
}
