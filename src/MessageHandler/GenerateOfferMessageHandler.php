<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateOfferMessage;
use App\Repository\LeadRepository;
use App\Repository\ProposalRepository;
use App\Repository\UserRepository;
use App\Service\Offer\OfferService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for generating offers asynchronously.
 */
#[AsMessageHandler]
final readonly class GenerateOfferMessageHandler
{
    public function __construct(
        private LeadRepository $leadRepository,
        private UserRepository $userRepository,
        private ProposalRepository $proposalRepository,
        private OfferService $offerService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(GenerateOfferMessage $message): void
    {
        $lead = $this->leadRepository->find($message->leadId);
        if ($lead === null) {
            $this->logger->error('Lead not found for offer generation', [
                'lead_id' => $message->leadId->toRfc4122(),
            ]);

            return;
        }

        $user = $this->userRepository->find($message->userId);
        if ($user === null) {
            $this->logger->error('User not found for offer generation', [
                'user_id' => $message->userId->toRfc4122(),
            ]);

            return;
        }

        // Get proposal if specified
        $proposal = null;
        if ($message->proposalId !== null) {
            $proposal = $this->proposalRepository->find($message->proposalId);
        }

        // Determine recipient email
        $recipientEmail = $message->recipientEmail ?? $lead->getEmail();
        if (empty($recipientEmail)) {
            $this->logger->error('No recipient email for offer generation', [
                'lead_id' => $message->leadId->toRfc4122(),
            ]);

            return;
        }

        $this->logger->info('Starting offer generation', [
            'lead_id' => $message->leadId->toRfc4122(),
            'user_id' => $message->userId->toRfc4122(),
            'recipient' => $recipientEmail,
        ]);

        try {
            $offer = $this->offerService->createAndGenerate(
                $lead,
                $user,
                $proposal,
                $recipientEmail,
            );

            $this->logger->info('Offer generated successfully', [
                'lead_id' => $message->leadId->toRfc4122(),
                'offer_id' => $offer->getId()?->toRfc4122(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Offer generation failed', [
                'lead_id' => $message->leadId->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
