<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Offer;
use App\Repository\LeadRepository;
use App\Repository\OfferRepository;
use App\Repository\ProposalRepository;
use App\Repository\UserRepository;
use App\Service\Offer\OfferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
class OfferController extends AbstractController
{
    public function __construct(
        private readonly OfferService $offerService,
        private readonly OfferRepository $offerRepository,
        private readonly LeadRepository $leadRepository,
        private readonly UserRepository $userRepository,
        private readonly ProposalRepository $proposalRepository,
    ) {
    }

    /**
     * Generate a new offer for a lead.
     *
     * POST /api/offers/generate
     * Body: {"leadId": "uuid", "userCode": "default", "proposalId": "uuid", "recipientEmail": "..."}
     */
    #[Route('/api/offers/generate', name: 'api_offer_generate', methods: ['POST'])]
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

        // Find proposal if provided
        $proposal = null;
        if (isset($data['proposalId'])) {
            $proposal = $this->findProposal($data['proposalId']);
            if ($proposal === null) {
                return $this->json(['error' => 'Proposal not found'], Response::HTTP_NOT_FOUND);
            }
        }

        // Recipient email
        $recipientEmail = $data['recipientEmail'] ?? $lead->getEmail();
        if (empty($recipientEmail)) {
            return $this->json(['error' => 'No recipient email available'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $offer = $this->offerService->createAndGenerate(
                $lead,
                $user,
                $proposal,
                $recipientEmail,
                $data['options'] ?? [],
            );

            return $this->json([
                'offer' => $this->serializeOffer($offer),
                'message' => 'Offer generated successfully',
            ], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Generation failed: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Submit offer for approval.
     *
     * POST /api/offers/{id}/submit
     */
    #[Route('/api/offers/{id}/submit', name: 'api_offer_submit', methods: ['POST'])]
    public function submit(string $id): JsonResponse
    {
        $offer = $this->findOffer($id);

        if ($offer === null) {
            return $this->json(['error' => 'Offer not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->offerService->submitForApproval($offer);

            return $this->json([
                'offer' => $this->serializeOffer($offer),
                'message' => 'Offer submitted for approval',
            ]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Approve an offer.
     *
     * POST /api/offers/{id}/approve
     * Body: {"userCode": "approver"}
     */
    #[Route('/api/offers/{id}/approve', name: 'api_offer_approve', methods: ['POST'])]
    public function approve(string $id, Request $request): JsonResponse
    {
        $offer = $this->findOffer($id);

        if ($offer === null) {
            return $this->json(['error' => 'Offer not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $approverCode = $data['userCode'] ?? null;

        // Use offer owner as approver if not specified
        $approver = $approverCode
            ? $this->userRepository->findOneBy(['code' => $approverCode])
            : $offer->getUser();

        if ($approver === null) {
            return $this->json(['error' => 'Approver not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->offerService->approve($offer, $approver);

            return $this->json([
                'offer' => $this->serializeOffer($offer),
                'message' => 'Offer approved',
            ]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Reject an offer.
     *
     * POST /api/offers/{id}/reject
     * Body: {"reason": "..."}
     */
    #[Route('/api/offers/{id}/reject', name: 'api_offer_reject', methods: ['POST'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        $offer = $this->findOffer($id);

        if ($offer === null) {
            return $this->json(['error' => 'Offer not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? '';

        try {
            $this->offerService->reject($offer, $reason);

            return $this->json([
                'offer' => $this->serializeOffer($offer),
                'message' => 'Offer rejected',
            ]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Send an offer.
     *
     * POST /api/offers/{id}/send
     */
    #[Route('/api/offers/{id}/send', name: 'api_offer_send', methods: ['POST'])]
    public function send(string $id): JsonResponse
    {
        $offer = $this->findOffer($id);

        if ($offer === null) {
            return $this->json(['error' => 'Offer not found'], Response::HTTP_NOT_FOUND);
        }

        // Check rate limits first
        $rateLimitResult = $this->offerService->canSend($offer);

        if (!$rateLimitResult->allowed) {
            return $this->json([
                'error' => 'Rate limit exceeded',
                'reason' => $rateLimitResult->reason,
                'retryAfterSeconds' => $rateLimitResult->retryAfterSeconds,
                'currentUsage' => $rateLimitResult->currentUsage,
                'limits' => $rateLimitResult->limits,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $this->offerService->send($offer);

            return $this->json([
                'offer' => $this->serializeOffer($offer),
                'message' => 'Offer sent successfully',
            ]);
        } catch (\LogicException|\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Preview an offer without sending.
     *
     * GET /api/offers/{id}/preview
     */
    #[Route('/api/offers/{id}/preview', name: 'api_offer_preview', methods: ['GET'])]
    public function preview(string $id): JsonResponse
    {
        $offer = $this->findOffer($id);

        if ($offer === null) {
            return $this->json(['error' => 'Offer not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'subject' => $offer->getSubject(),
            'body' => $offer->getBody(),
            'plainTextBody' => $offer->getPlainTextBody(),
            'recipient' => [
                'email' => $offer->getRecipientEmail(),
                'name' => $offer->getRecipientName(),
            ],
            'trackingToken' => $offer->getTrackingToken(),
        ]);
    }

    /**
     * Get rate limits for current user.
     *
     * GET /api/offers/rate-limits?userCode=default&domain=example.com
     */
    #[Route('/api/offers/rate-limits', name: 'api_offer_rate_limits', methods: ['GET'])]
    public function rateLimits(Request $request): JsonResponse
    {
        $userCode = $request->query->get('userCode');

        if (!$userCode) {
            return $this->json(['error' => 'userCode query parameter is required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->findOneBy(['code' => $userCode]);

        if ($user === null) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $domain = $request->query->get('domain');
        $status = $this->offerService->getRateLimitStatus($user, $domain);

        return $this->json([
            'user' => $user->getCode(),
            'domain' => $domain,
            ...$status,
        ]);
    }

    /**
     * Mark offer as responded.
     *
     * POST /api/offers/{id}/responded
     */
    #[Route('/api/offers/{id}/responded', name: 'api_offer_responded', methods: ['POST'])]
    public function markResponded(string $id): JsonResponse
    {
        $offer = $this->findOffer($id);

        if ($offer === null) {
            return $this->json(['error' => 'Offer not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->offerService->markResponded($offer);

            return $this->json([
                'offer' => $this->serializeOffer($offer),
                'message' => 'Offer marked as responded',
            ]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /**
     * Mark offer as converted.
     *
     * POST /api/offers/{id}/converted
     */
    #[Route('/api/offers/{id}/converted', name: 'api_offer_converted', methods: ['POST'])]
    public function markConverted(string $id): JsonResponse
    {
        $offer = $this->findOffer($id);

        if ($offer === null) {
            return $this->json(['error' => 'Offer not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->offerService->markConverted($offer);

            return $this->json([
                'offer' => $this->serializeOffer($offer),
                'message' => 'Offer marked as converted',
            ]);
        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOffer(Offer $offer): array
    {
        return [
            'id' => (string) $offer->getId(),
            'user' => $offer->getUser()->getCode(),
            'lead' => [
                'id' => (string) $offer->getLead()->getId(),
                'domain' => $offer->getLead()->getDomain(),
            ],
            'proposal' => $offer->getProposal() ? [
                'id' => (string) $offer->getProposal()->getId(),
                'type' => $offer->getProposal()->getType()->value,
            ] : null,
            'status' => $offer->getStatus()->value,
            'subject' => $offer->getSubject(),
            'recipientEmail' => $offer->getRecipientEmail(),
            'recipientName' => $offer->getRecipientName(),
            'approvedBy' => $offer->getApprovedBy()?->getCode(),
            'approvedAt' => $offer->getApprovedAt()?->format(\DateTimeInterface::ATOM),
            'sentAt' => $offer->getSentAt()?->format(\DateTimeInterface::ATOM),
            'openedAt' => $offer->getOpenedAt()?->format(\DateTimeInterface::ATOM),
            'clickedAt' => $offer->getClickedAt()?->format(\DateTimeInterface::ATOM),
            'respondedAt' => $offer->getRespondedAt()?->format(\DateTimeInterface::ATOM),
            'convertedAt' => $offer->getConvertedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $offer->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $offer->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function findOffer(string $id): ?Offer
    {
        try {
            $uuid = Uuid::fromString($id);

            return $this->offerRepository->find($uuid);
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

    private function findProposal(string $id): ?\App\Entity\Proposal
    {
        try {
            $uuid = Uuid::fromString($id);

            return $this->proposalRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
