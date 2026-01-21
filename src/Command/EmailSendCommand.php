<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\EmailProvider;
use App\Enum\OfferStatus;
use App\Repository\OfferRepository;
use App\Repository\UserRepository;
use App\Service\Email\EmailService;
use App\Service\Offer\OfferService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:email:send',
    description: 'Send approved offers via email',
)]
class EmailSendCommand extends Command
{
    public function __construct(
        private readonly OfferService $offerService,
        private readonly OfferRepository $offerRepository,
        private readonly UserRepository $userRepository,
        private readonly EmailService $emailService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only for specific user (code)')
            ->addOption('offer', 'o', InputOption::VALUE_REQUIRED, 'Send specific offer (ID)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum to send', '50')
            ->addOption('provider', 'p', InputOption::VALUE_REQUIRED, 'Email provider (smtp, ses, null)', 'smtp')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be sent without executing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        // Validate provider
        $providerName = $input->getOption('provider');
        try {
            $provider = EmailProvider::from($providerName);
        } catch (\ValueError) {
            $io->error(sprintf('Invalid provider: %s. Valid options: smtp, ses, null', $providerName));

            return Command::FAILURE;
        }

        // Check if provider is available
        if (!$this->emailService->isProviderAvailable($provider)) {
            $io->warning(sprintf('Provider %s is not configured', $provider->value));

            if (!$dryRun) {
                return Command::FAILURE;
            }
        }

        // Send specific offer
        if ($offerId = $input->getOption('offer')) {
            return $this->sendSingleOffer($io, $offerId, $provider, $dryRun);
        }

        // Send batch
        return $this->sendBatch($io, $input, $provider, $dryRun);
    }

    private function sendSingleOffer(
        SymfonyStyle $io,
        string $offerId,
        EmailProvider $provider,
        bool $dryRun,
    ): int {
        $offer = $this->offerRepository->find($offerId);

        if ($offer === null) {
            $io->error(sprintf('Offer not found: %s', $offerId));

            return Command::FAILURE;
        }

        if (!$offer->getStatus()->canSend()) {
            $io->error(sprintf(
                'Cannot send offer in status %s (must be approved)',
                $offer->getStatus()->value
            ));

            return Command::FAILURE;
        }

        $io->section('Sending Offer');
        $io->table([], [
            ['Offer ID', $offer->getId()?->toRfc4122()],
            ['Recipient', $offer->getRecipientEmail()],
            ['Subject', $offer->getSubject()],
            ['Provider', $provider->value],
        ]);

        if ($dryRun) {
            $io->success('Dry run - email not sent');

            return Command::SUCCESS;
        }

        try {
            // Check rate limits
            $rateLimitResult = $this->offerService->canSend($offer);

            if (!$rateLimitResult->allowed) {
                $io->error(sprintf('Rate limit exceeded: %s', $rateLimitResult->reason));

                return Command::FAILURE;
            }

            // Send
            $result = $this->emailService->sendOffer($offer, $provider);

            if (!$result->success) {
                $io->error(sprintf('Send failed: %s', $result->error));

                return Command::FAILURE;
            }

            // Mark as sent
            $offer->markSent();
            $this->offerRepository->getEntityManager()->flush();

            $io->success(sprintf('Email sent! Message ID: %s', $result->messageId));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Send failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    private function sendBatch(
        SymfonyStyle $io,
        InputInterface $input,
        EmailProvider $provider,
        bool $dryRun,
    ): int {
        $limit = (int) $input->getOption('limit');

        // Get user filter
        $user = null;
        if ($userCode = $input->getOption('user')) {
            $user = $this->userRepository->findOneBy(['code' => $userCode]);

            if ($user === null) {
                $io->error(sprintf('User not found: %s', $userCode));

                return Command::FAILURE;
            }
        }

        // Find approved offers
        $qb = $this->offerRepository->createQueryBuilder('o')
            ->where('o.status = :status')
            ->setParameter('status', OfferStatus::APPROVED)
            ->orderBy('o.createdAt', 'ASC')
            ->setMaxResults($limit);

        if ($user !== null) {
            $qb->andWhere('o.user = :user')
                ->setParameter('user', $user);
        }

        $offers = $qb->getQuery()->getResult();

        if (empty($offers)) {
            $io->success('No approved offers to send');

            return Command::SUCCESS;
        }

        $io->section('Email Send Queue');
        $io->text(sprintf('Found %d approved offers', count($offers)));
        $io->text(sprintf('Provider: %s', $provider->value));
        $io->newLine();

        if ($dryRun) {
            $io->table(
                ['Offer ID', 'Recipient', 'Subject'],
                array_map(fn($o) => [
                    $o->getId()?->toRfc4122(),
                    $o->getRecipientEmail(),
                    substr($o->getSubject(), 0, 50) . (strlen($o->getSubject()) > 50 ? '...' : ''),
                ], $offers)
            );
            $io->success('Dry run - no emails sent');

            return Command::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($offers as $offer) {
            $io->text(sprintf(
                'Sending to: %s',
                $offer->getRecipientEmail()
            ));

            try {
                // Check rate limits
                $rateLimitResult = $this->offerService->canSend($offer);

                if (!$rateLimitResult->allowed) {
                    $io->warning(sprintf('Rate limited: %s', $rateLimitResult->reason));
                    $skipped++;
                    continue;
                }

                // Send
                $result = $this->emailService->sendOffer($offer, $provider);

                if (!$result->success) {
                    $io->warning(sprintf('Failed: %s', $result->error));
                    $failed++;
                    continue;
                }

                // Mark as sent
                $offer->markSent();
                $this->offerRepository->getEntityManager()->flush();

                $sent++;
            } catch (\Throwable $e) {
                $io->warning(sprintf('Error: %s', $e->getMessage()));
                $failed++;
            }
        }

        $io->newLine();
        $io->table([], [
            ['Sent', $sent],
            ['Failed', $failed],
            ['Skipped (rate limited)', $skipped],
        ]);

        if ($sent > 0) {
            $io->success(sprintf('Sent %d emails', $sent));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
