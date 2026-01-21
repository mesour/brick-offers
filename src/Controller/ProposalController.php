<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Proposal;
use App\Enum\ProposalType;
use App\Repository\LeadRepository;
use App\Repository\ProposalRepository;
use App\Repository\UserRepository;
use App\Service\Proposal\ProposalService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
class ProposalController extends AbstractController
{
    public function __construct(
        private readonly ProposalService $proposalService,
        private readonly ProposalRepository $proposalRepository,
        private readonly LeadRepository $leadRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Generate a new proposal for a lead.
     *
     * POST /api/proposals/generate
     * Body: {"leadId": "uuid", "userCode": "default", "type": "design_mockup"}
     */
    #[Route('/api/proposals/generate', name: 'api_proposal_generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['leadId'])) {
            return $this->json(['error' => 'leadId is required'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['userCode'])) {
            return $this->json(['error' => 'userCode is required'], Response::HTTP_BAD_REQUEST);
        }

        // Find lead
        $lead = $this->findLead($data['leadId']);
        if ($lead === null) {
            return $this->json(['error' => 'Lead not found'], Response::HTTP_NOT_FOUND);
        }

        // Find user
        $user = $this->userRepository->findOneBy(['code' => $data['userCode']]);
        if ($user === null) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        // Check for analysis
        $analysis = $lead->getLatestAnalysis();
        if ($analysis === null) {
            return $this->json([
                'error' => 'Lead has no analysis',
                'hint' => 'Run analysis first using app:lead:analyze command',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Parse type if provided
        $type = null;
        if (isset($data['type'])) {
            $type = ProposalType::tryFrom($data['type']);
            if ($type === null) {
                return $this->json([
                    'error' => sprintf('Invalid proposal type: %s', $data['type']),
                    'available' => array_map(fn ($t) => $t->value, ProposalType::cases()),
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        // Check if trying to recycle first
        $tryRecycle = $data['recycle'] ?? false;
        if ($tryRecycle) {
            $industry = $lead->getIndustry();
            $recycled = $this->proposalService->findAndRecycle($user, $lead, $industry, $type);

            if ($recycled !== null) {
                return $this->json([
                    'proposal' => $this->serializeProposal($recycled),
                    'recycled' => true,
                    'message' => 'Recycled existing proposal',
                ], Response::HTTP_OK);
            }
        }

        try {
            $proposal = $this->proposalService->createAndGenerate($lead, $user, $type);

            return $this->json([
                'proposal' => $this->serializeProposal($proposal),
                'recycled' => false,
                'message' => 'Proposal generated successfully',
            ], Response::HTTP_CREATED);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Generation failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Approve a proposal.
     *
     * POST /api/proposals/{id}/approve
     */
    #[Route('/api/proposals/{id}/approve', name: 'api_proposal_approve', methods: ['POST'])]
    public function approve(string $id): JsonResponse
    {
        $proposal = $this->findProposal($id);

        if ($proposal === null) {
            return $this->json(['error' => 'Proposal not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->proposalService->approve($proposal);

            return $this->json([
                'proposal' => $this->serializeProposal($proposal),
                'message' => 'Proposal approved',
            ]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Reject a proposal.
     *
     * POST /api/proposals/{id}/reject
     */
    #[Route('/api/proposals/{id}/reject', name: 'api_proposal_reject', methods: ['POST'])]
    public function reject(string $id): JsonResponse
    {
        $proposal = $this->findProposal($id);

        if ($proposal === null) {
            return $this->json(['error' => 'Proposal not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->proposalService->reject($proposal);

            return $this->json([
                'proposal' => $this->serializeProposal($proposal),
                'canBeRecycled' => $proposal->canBeRecycled(),
                'message' => 'Proposal rejected',
            ]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Recycle a proposal to a new user/lead.
     *
     * POST /api/proposals/{id}/recycle
     * Body: {"userCode": "new_user", "leadId": "uuid"} (leadId optional)
     */
    #[Route('/api/proposals/{id}/recycle', name: 'api_proposal_recycle', methods: ['POST'])]
    public function recycle(string $id, Request $request): JsonResponse
    {
        $proposal = $this->findProposal($id);

        if ($proposal === null) {
            return $this->json(['error' => 'Proposal not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$proposal->canBeRecycled()) {
            return $this->json([
                'error' => 'Proposal cannot be recycled',
                'status' => $proposal->getStatus()->value,
                'isAiGenerated' => $proposal->isAiGenerated(),
                'isCustomized' => $proposal->isCustomized(),
                'recyclable' => $proposal->isRecyclable(),
            ], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['userCode'])) {
            return $this->json(['error' => 'userCode is required'], Response::HTTP_BAD_REQUEST);
        }

        // Find new user
        $newUser = $this->userRepository->findOneBy(['code' => $data['userCode']]);
        if ($newUser === null) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        // Find new lead if provided
        $newLead = null;
        if (isset($data['leadId'])) {
            $newLead = $this->findLead($data['leadId']);
            if ($newLead === null) {
                return $this->json(['error' => 'Lead not found'], Response::HTTP_NOT_FOUND);
            }
        }

        try {
            $originalUser = $proposal->getUser()->getCode();
            $this->proposalService->recycle($proposal, $newUser, $newLead);

            return $this->json([
                'proposal' => $this->serializeProposal($proposal),
                'originalUser' => $originalUser,
                'message' => 'Proposal recycled successfully',
            ]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Estimate generation cost for a lead.
     *
     * GET /api/proposals/estimate?leadId={uuid}
     */
    #[Route('/api/proposals/estimate', name: 'api_proposal_estimate', methods: ['GET'])]
    public function estimate(Request $request): JsonResponse
    {
        $leadId = $request->query->get('leadId');

        if (!$leadId) {
            return $this->json(['error' => 'leadId query parameter is required'], Response::HTTP_BAD_REQUEST);
        }

        $lead = $this->findLead($leadId);
        if ($lead === null) {
            return $this->json(['error' => 'Lead not found'], Response::HTTP_NOT_FOUND);
        }

        $analysis = $lead->getLatestAnalysis();
        if ($analysis === null) {
            return $this->json(['error' => 'Lead has no analysis'], Response::HTTP_BAD_REQUEST);
        }

        $estimate = $this->proposalService->estimateCost($analysis);

        if ($estimate === null) {
            return $this->json([
                'error' => 'No generator available for this industry',
                'industry' => $lead->getIndustry()?->value,
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'lead' => [
                'id' => (string) $lead->getId(),
                'domain' => $lead->getDomain(),
                'industry' => $lead->getIndustry()?->value,
            ],
            'estimate' => [
                'inputTokens' => $estimate->estimatedInputTokens,
                'outputTokens' => $estimate->estimatedOutputTokens,
                'totalTokens' => $estimate->getTotalTokens(),
                'costUsd' => $estimate->estimatedCostUsd,
                'timeSeconds' => $estimate->estimatedTimeSeconds,
                'model' => $estimate->model,
            ],
        ]);
    }

    /**
     * Check if recycling is available for given criteria.
     *
     * GET /api/proposals/recyclable?industry={industry}&type={type}
     */
    #[Route('/api/proposals/recyclable', name: 'api_proposal_recyclable', methods: ['GET'])]
    public function checkRecyclable(Request $request): JsonResponse
    {
        $industryValue = $request->query->get('industry');
        $typeValue = $request->query->get('type');

        if (!$industryValue) {
            return $this->json(['error' => 'industry query parameter is required'], Response::HTTP_BAD_REQUEST);
        }

        if (!$typeValue) {
            return $this->json(['error' => 'type query parameter is required'], Response::HTTP_BAD_REQUEST);
        }

        $industry = \App\Enum\Industry::tryFrom($industryValue);
        if ($industry === null) {
            return $this->json(['error' => 'Invalid industry'], Response::HTTP_BAD_REQUEST);
        }

        $type = ProposalType::tryFrom($typeValue);
        if ($type === null) {
            return $this->json(['error' => 'Invalid proposal type'], Response::HTTP_BAD_REQUEST);
        }

        $available = $this->proposalService->canRecycle($industry, $type);

        return $this->json([
            'industry' => $industry->value,
            'type' => $type->value,
            'recyclableAvailable' => $available,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProposal(Proposal $proposal): array
    {
        return [
            'id' => (string) $proposal->getId(),
            'user' => $proposal->getUser()->getCode(),
            'lead' => $proposal->getLead() ? [
                'id' => (string) $proposal->getLead()->getId(),
                'domain' => $proposal->getLead()->getDomain(),
            ] : null,
            'type' => $proposal->getType()->value,
            'status' => $proposal->getStatus()->value,
            'industry' => $proposal->getIndustry()?->value,
            'title' => $proposal->getTitle(),
            'summary' => $proposal->getSummary(),
            'outputs' => $proposal->getOutputs(),
            'isAiGenerated' => $proposal->isAiGenerated(),
            'isCustomized' => $proposal->isCustomized(),
            'recyclable' => $proposal->isRecyclable(),
            'originalUser' => $proposal->getOriginalUser()?->getCode(),
            'expiresAt' => $proposal->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'recycledAt' => $proposal->getRecycledAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $proposal->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $proposal->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function findProposal(string $id): ?Proposal
    {
        try {
            $uuid = Uuid::fromString($id);

            return $this->proposalRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function findLead(string $id): ?\App\Entity\Lead
    {
        try {
            $uuid = Uuid::fromString($id);

            return $this->leadRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
