<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ProposalStatus;
use App\Message\ExpireProposalsMessage;
use App\Repository\ProposalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for expiring proposals with passed expiresAt dates.
 */
#[AsMessageHandler]
final class ExpireProposalsMessageHandler
{
    public function __construct(
        private readonly ProposalRepository $proposalRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ExpireProposalsMessage $message): int
    {
        $this->logger->info('Starting proposal expiration', [
            'dry_run' => $message->dryRun,
        ]);

        $expiredProposals = $this->proposalRepository->findExpired();
        $count = count($expiredProposals);

        if ($count === 0) {
            $this->logger->info('No expired proposals found');

            return 0;
        }

        $this->logger->info('Found expired proposals', ['count' => $count]);

        if ($message->dryRun) {
            $this->logger->info('Dry run - no changes made');

            return $count;
        }

        foreach ($expiredProposals as $proposal) {
            $proposal->setStatus(ProposalStatus::EXPIRED);

            $this->logger->debug('Marked proposal as expired', [
                'proposal_id' => $proposal->getId()?->toRfc4122(),
                'title' => $proposal->getTitle(),
                'expired_at' => $proposal->getExpiresAt()?->format('Y-m-d H:i:s'),
            ]);
        }

        $this->em->flush();

        $this->logger->info('Proposal expiration completed', ['expired_count' => $count]);

        return $count;
    }
}
