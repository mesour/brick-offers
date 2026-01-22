<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\EmailProvider;
use App\Message\SendEmailMessage;
use App\Repository\OfferRepository;
use App\Service\Email\EmailService;
use App\Service\Offer\OfferService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for sending emails asynchronously.
 */
#[AsMessageHandler]
final readonly class SendEmailMessageHandler
{
    public function __construct(
        private OfferRepository $offerRepository,
        private OfferService $offerService,
        private EmailService $emailService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $defaultProvider = 'smtp',
    ) {}

    public function __invoke(SendEmailMessage $message): void
    {
        $offer = $this->offerRepository->find($message->offerId);

        if ($offer === null) {
            $this->logger->error('Offer not found for email sending', [
                'offer_id' => $message->offerId->toRfc4122(),
            ]);

            return;
        }

        if (!$offer->getStatus()->canSend()) {
            $this->logger->warning('Cannot send offer - invalid status', [
                'offer_id' => $message->offerId->toRfc4122(),
                'status' => $offer->getStatus()->value,
            ]);

            return;
        }

        // Check rate limits
        $rateLimitResult = $this->offerService->canSend($offer);

        if (!$rateLimitResult->allowed) {
            $this->logger->warning('Rate limit exceeded for email', [
                'offer_id' => $message->offerId->toRfc4122(),
                'reason' => $rateLimitResult->reason,
            ]);

            // Re-throw to trigger retry
            throw new \RuntimeException(sprintf(
                'Rate limit exceeded: %s',
                $rateLimitResult->reason,
            ));
        }

        // Determine provider
        $provider = EmailProvider::tryFrom($this->defaultProvider) ?? EmailProvider::SMTP;

        // Send email
        $result = $this->emailService->sendOffer($offer, $provider);

        if (!$result->success) {
            $this->logger->error('Failed to send email', [
                'offer_id' => $message->offerId->toRfc4122(),
                'error' => $result->error,
            ]);

            throw new \RuntimeException(sprintf(
                'Failed to send email: %s',
                $result->error,
            ));
        }

        // Mark as sent
        $offer->markSent();
        $this->em->flush();

        $this->logger->info('Email sent successfully', [
            'offer_id' => $message->offerId->toRfc4122(),
            'message_id' => $result->messageId,
            'recipient' => $offer->getRecipientEmail(),
        ]);
    }
}
