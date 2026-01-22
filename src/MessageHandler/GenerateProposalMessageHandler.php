<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ProposalType;
use App\Message\GenerateProposalMessage;
use App\Repository\AnalysisRepository;
use App\Repository\LeadRepository;
use App\Repository\UserRepository;
use App\Service\Proposal\ProposalService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for generating proposals asynchronously.
 */
#[AsMessageHandler]
final readonly class GenerateProposalMessageHandler
{
    public function __construct(
        private LeadRepository $leadRepository,
        private UserRepository $userRepository,
        private AnalysisRepository $analysisRepository,
        private ProposalService $proposalService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(GenerateProposalMessage $message): void
    {
        $lead = $this->leadRepository->find($message->leadId);
        if ($lead === null) {
            $this->logger->error('Lead not found for proposal generation', [
                'lead_id' => $message->leadId->toRfc4122(),
            ]);

            return;
        }

        $user = $this->userRepository->find($message->userId);
        if ($user === null) {
            $this->logger->error('User not found for proposal generation', [
                'user_id' => $message->userId->toRfc4122(),
            ]);

            return;
        }

        // Get analysis
        $analysis = null;
        if ($message->analysisId !== null) {
            $analysis = $this->analysisRepository->find($message->analysisId);
        }

        // Parse proposal type
        $proposalType = ProposalType::tryFrom($message->proposalType);
        if ($proposalType === null) {
            $this->logger->error('Invalid proposal type', [
                'type' => $message->proposalType,
            ]);

            return;
        }

        $this->logger->info('Starting proposal generation', [
            'lead_id' => $message->leadId->toRfc4122(),
            'user_id' => $message->userId->toRfc4122(),
            'type' => $proposalType->value,
        ]);

        try {
            $proposal = $this->proposalService->createAndGenerate(
                $lead,
                $user,
                $proposalType,
            );

            $this->logger->info('Proposal generated successfully', [
                'lead_id' => $message->leadId->toRfc4122(),
                'proposal_id' => $proposal->getId()?->toRfc4122(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Proposal generation failed', [
                'lead_id' => $message->leadId->toRfc4122(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
