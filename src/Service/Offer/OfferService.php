<?php

declare(strict_types=1);

namespace App\Service\Offer;

use App\Entity\Lead;
use App\Entity\Offer;
use App\Entity\Proposal;
use App\Entity\User;
use App\Enum\EmailProvider;
use App\Enum\OfferStatus;
use App\Repository\OfferRepository;
use App\Service\Email\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing offers.
 */
class OfferService
{
    public function __construct(
        private readonly OfferRepository $repository,
        private readonly OfferGenerator $generator,
        private readonly RateLimitChecker $rateLimiter,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ?EmailService $emailService = null,
    ) {
    }

    /**
     * Create a new offer for a lead.
     */
    public function createOffer(
        Lead $lead,
        User $user,
        ?Proposal $proposal = null,
        ?string $recipientEmail = null,
    ): Offer {
        $offer = new Offer();
        $offer->setUser($user);
        $offer->setLead($lead);
        $offer->setProposal($proposal);
        $offer->setAnalysis($lead->getLatestAnalysis());
        $offer->setRecipientEmail($recipientEmail ?? $lead->getEmail() ?? '');
        $offer->setRecipientName($lead->getCompanyName());
        $offer->setStatus(OfferStatus::DRAFT);

        $this->em->persist($offer);
        $this->em->flush();

        $this->logger->info('Created offer', [
            'offer_id' => $offer->getId()?->toRfc4122(),
            'lead_id' => $lead->getId()?->toRfc4122(),
            'user' => $user->getCode(),
        ]);

        return $offer;
    }

    /**
     * Generate content for an offer using templates and AI.
     *
     * @param array<string, mixed> $options
     */
    public function generateContent(Offer $offer, array $options = []): void
    {
        $this->logger->info('Generating offer content', [
            'offer_id' => $offer->getId()?->toRfc4122(),
        ]);

        $result = $this->generator->generate($offer, $options);

        if (!$result->success) {
            $this->logger->error('Offer content generation failed', [
                'offer_id' => $offer->getId()?->toRfc4122(),
                'error' => $result->error,
            ]);

            throw new \RuntimeException('Failed to generate offer content: ' . $result->error);
        }

        $offer->setSubject($result->subject);
        $offer->setBody($result->body);
        $offer->setPlainTextBody($result->plainTextBody);
        $offer->setAiMetadata($result->aiMetadata);

        $this->em->flush();

        $this->logger->info('Offer content generated', [
            'offer_id' => $offer->getId()?->toRfc4122(),
            'ai_personalized' => !empty($result->aiMetadata),
        ]);
    }

    /**
     * Create and generate offer content in one step.
     *
     * @param array<string, mixed> $options
     */
    public function createAndGenerate(
        Lead $lead,
        User $user,
        ?Proposal $proposal = null,
        ?string $recipientEmail = null,
        array $options = [],
    ): Offer {
        $offer = $this->createOffer($lead, $user, $proposal, $recipientEmail);
        $this->generateContent($offer, $options);

        return $offer;
    }

    /**
     * Submit offer for approval.
     */
    public function submitForApproval(Offer $offer): void
    {
        $offer->submitForApproval();
        $this->em->flush();

        $this->logger->info('Offer submitted for approval', [
            'offer_id' => $offer->getId()?->toRfc4122(),
        ]);
    }

    /**
     * Approve an offer.
     */
    public function approve(Offer $offer, User $approver): void
    {
        $offer->approve($approver);
        $this->em->flush();

        $this->logger->info('Offer approved', [
            'offer_id' => $offer->getId()?->toRfc4122(),
            'approver' => $approver->getCode(),
        ]);
    }

    /**
     * Reject an offer.
     */
    public function reject(Offer $offer, string $reason = ''): void
    {
        $offer->reject($reason);
        $this->em->flush();

        $this->logger->info('Offer rejected', [
            'offer_id' => $offer->getId()?->toRfc4122(),
            'reason' => $reason,
        ]);
    }

    /**
     * Check if sending is allowed (rate limits).
     */
    public function canSend(Offer $offer): RateLimitResult
    {
        return $this->rateLimiter->canSend(
            $offer->getUser(),
            $offer->getRecipientDomain(),
        );
    }

    /**
     * Send an offer via email.
     */
    public function send(Offer $offer, ?EmailProvider $provider = null): void
    {
        // Check rate limits
        $rateLimitResult = $this->canSend($offer);

        if (!$rateLimitResult->allowed) {
            throw new \RuntimeException('Rate limit exceeded: ' . $rateLimitResult->reason);
        }

        // Check status
        if (!$offer->getStatus()->canSend()) {
            throw new \LogicException(sprintf(
                'Cannot send offer in status %s',
                $offer->getStatus()->value
            ));
        }

        // Send via EmailService if available
        if ($this->emailService !== null) {
            $result = $this->emailService->sendOffer($offer, $provider);

            if (!$result->success) {
                throw new \RuntimeException('Email sending failed: ' . $result->error);
            }
        }

        $offer->markSent();
        $this->em->flush();

        $this->logger->info('Offer sent', [
            'offer_id' => $offer->getId()?->toRfc4122(),
            'recipient' => $offer->getRecipientEmail(),
        ]);
    }

    /**
     * Track email open.
     */
    public function trackOpen(string $token): ?Offer
    {
        $offer = $this->repository->findByTrackingToken($token);

        if ($offer === null) {
            $this->logger->warning('Open tracking: offer not found', ['token' => $token]);

            return null;
        }

        $offer->trackOpen();
        $this->em->flush();

        $this->logger->info('Tracked email open', [
            'offer_id' => $offer->getId()?->toRfc4122(),
        ]);

        return $offer;
    }

    /**
     * Track link click.
     */
    public function trackClick(string $token, string $url): ?Offer
    {
        $offer = $this->repository->findByTrackingToken($token);

        if ($offer === null) {
            $this->logger->warning('Click tracking: offer not found', ['token' => $token]);

            return null;
        }

        $offer->trackClick();
        $this->em->flush();

        $this->logger->info('Tracked link click', [
            'offer_id' => $offer->getId()?->toRfc4122(),
            'url' => $url,
        ]);

        return $offer;
    }

    /**
     * Mark offer as responded.
     */
    public function markResponded(Offer $offer): void
    {
        $offer->markResponded();
        $this->em->flush();

        $this->logger->info('Offer marked as responded', [
            'offer_id' => $offer->getId()?->toRfc4122(),
        ]);
    }

    /**
     * Mark offer as converted.
     */
    public function markConverted(Offer $offer): void
    {
        $offer->markConverted();
        $this->em->flush();

        $this->logger->info('Offer marked as converted', [
            'offer_id' => $offer->getId()?->toRfc4122(),
        ]);
    }

    /**
     * Get rate limit status for user.
     *
     * @return array<string, mixed>
     */
    public function getRateLimitStatus(User $user, ?string $domain = null): array
    {
        $limits = $this->rateLimiter->getLimits($user);
        $usage = $this->rateLimiter->getCurrentUsage($user, $domain);

        return [
            'limits' => $limits,
            'usage' => $usage,
            'remaining' => [
                'per_hour' => max(0, $limits['emails_per_hour'] - $usage['per_hour']),
                'per_day' => max(0, $limits['emails_per_day'] - $usage['per_day']),
                'per_domain_day' => $domain !== null
                    ? max(0, $limits['emails_per_domain_day'] - $usage['per_domain_day'])
                    : null,
            ],
        ];
    }
}
