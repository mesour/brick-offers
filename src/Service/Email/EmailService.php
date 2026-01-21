<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\EmailLog;
use App\Entity\Offer;
use App\Entity\User;
use App\Enum\EmailBounceType;
use App\Enum\EmailProvider;
use App\Enum\EmailStatus;
use App\Repository\EmailLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Main email service - orchestrates sending, logging, and webhook processing.
 */
class EmailService
{
    /**
     * @var EmailSenderInterface[]
     */
    private array $senders = [];

    /**
     * @param iterable<EmailSenderInterface> $senders
     */
    public function __construct(
        iterable $senders,
        private readonly EmailBlacklistService $blacklistService,
        private readonly EmailLogRepository $emailLogRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $defaultProvider = 'smtp',
        private readonly string $defaultFromEmail = '',
        private readonly string $defaultFromName = '',
    ) {
        foreach ($senders as $sender) {
            $this->senders[$sender->getProvider()->value] = $sender;
        }
    }

    /**
     * Send an offer email.
     */
    public function sendOffer(Offer $offer, ?EmailProvider $provider = null): EmailSendResult
    {
        $message = EmailMessage::fromOffer(
            $offer,
            $this->defaultFromEmail,
            $this->defaultFromName,
        );

        return $this->send($message, $offer->getUser(), $provider, $offer);
    }

    /**
     * Send an email message.
     */
    public function send(
        EmailMessage $message,
        User $user,
        ?EmailProvider $provider = null,
        ?Offer $offer = null,
    ): EmailSendResult {
        $provider = $provider ?? EmailProvider::from($this->defaultProvider);

        // Check blacklist
        if ($this->blacklistService->isBlocked($message->to, $user)) {
            $this->logger->warning('Email blocked by blacklist', [
                'to' => $message->to,
                'user' => $user->getCode(),
            ]);

            return EmailSendResult::failure('Email is blacklisted', [
                'blacklisted' => true,
            ]);
        }

        // Get sender
        $sender = $this->getSender($provider);
        if ($sender === null) {
            return EmailSendResult::failure("Provider '{$provider->value}' not found or not configured");
        }

        // Create log entry
        $log = $this->createLog($message, $user, $provider, $offer);

        // Send email
        $result = $sender->send($message);

        // Update log
        if ($result->success) {
            $log->markSent($result->messageId ?? '');
            $log->setMetadata(array_merge($log->getMetadata(), $result->metadata));
        } else {
            $log->markFailed($result->error ?? 'Unknown error');
            $log->setMetadata(array_merge($log->getMetadata(), $result->metadata));
        }

        $this->em->flush();

        return $result;
    }

    /**
     * Process a bounce notification (from webhook).
     */
    public function processBounce(
        string $messageId,
        EmailBounceType $bounceType,
        ?string $message = null,
    ): void {
        $log = $this->emailLogRepository->findByMessageId($messageId);

        if ($log === null) {
            $this->logger->warning('Bounce received for unknown message', [
                'message_id' => $messageId,
                'bounce_type' => $bounceType->value,
            ]);

            return;
        }

        // Update log
        $log->markBounced($bounceType, $message);
        $this->em->flush();

        // Add to blacklist if hard bounce or complaint
        if ($bounceType->isPermanent()) {
            $this->blacklistService->addGlobalBounce(
                $log->getToEmail(),
                $bounceType,
                $message,
                $log,
            );
        }

        $this->logger->info('Processed bounce', [
            'message_id' => $messageId,
            'to' => $log->getToEmail(),
            'bounce_type' => $bounceType->value,
        ]);
    }

    /**
     * Process a complaint notification (from webhook).
     */
    public function processComplaint(string $messageId, ?string $message = null): void
    {
        $log = $this->emailLogRepository->findByMessageId($messageId);

        if ($log === null) {
            $this->logger->warning('Complaint received for unknown message', [
                'message_id' => $messageId,
            ]);

            return;
        }

        // Update log
        $log->markComplained($message);
        $this->em->flush();

        // Add to global blacklist
        $this->blacklistService->addGlobalBounce(
            $log->getToEmail(),
            EmailBounceType::COMPLAINT,
            $message ?? 'Spam complaint',
            $log,
        );

        $this->logger->info('Processed complaint', [
            'message_id' => $messageId,
            'to' => $log->getToEmail(),
        ]);
    }

    /**
     * Process a delivery notification (from webhook).
     */
    public function processDelivery(string $messageId): void
    {
        $log = $this->emailLogRepository->findByMessageId($messageId);

        if ($log === null) {
            $this->logger->debug('Delivery received for unknown message', [
                'message_id' => $messageId,
            ]);

            return;
        }

        $log->markDelivered();
        $this->em->flush();

        $this->logger->debug('Processed delivery', [
            'message_id' => $messageId,
        ]);
    }

    /**
     * Process an open notification.
     */
    public function processOpen(string $messageId): void
    {
        $log = $this->emailLogRepository->findByMessageId($messageId);

        if ($log === null) {
            return;
        }

        $log->markOpened();
        $this->em->flush();
    }

    /**
     * Process a click notification.
     */
    public function processClick(string $messageId): void
    {
        $log = $this->emailLogRepository->findByMessageId($messageId);

        if ($log === null) {
            return;
        }

        $log->markClicked();
        $this->em->flush();
    }

    /**
     * Get available providers.
     *
     * @return EmailProvider[]
     */
    public function getAvailableProviders(): array
    {
        $providers = [];

        foreach ($this->senders as $sender) {
            if ($sender->isConfigured()) {
                $providers[] = $sender->getProvider();
            }
        }

        return $providers;
    }

    /**
     * Check if a provider is available.
     */
    public function isProviderAvailable(EmailProvider $provider): bool
    {
        return isset($this->senders[$provider->value])
            && $this->senders[$provider->value]->isConfigured();
    }

    /**
     * Get email statistics for a user.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(
        User $user,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        return $this->emailLogRepository->getStatistics($user, $from, $to);
    }

    /**
     * Get sender for provider.
     */
    private function getSender(EmailProvider $provider): ?EmailSenderInterface
    {
        $sender = $this->senders[$provider->value] ?? null;

        if ($sender === null || !$sender->isConfigured()) {
            return null;
        }

        return $sender;
    }

    /**
     * Create log entry for email.
     */
    private function createLog(
        EmailMessage $message,
        User $user,
        EmailProvider $provider,
        ?Offer $offer = null,
    ): EmailLog {
        $log = new EmailLog();
        $log->setUser($user);
        $log->setOffer($offer);
        $log->setProvider($provider);
        $log->setToEmail(strtolower($message->to));
        $log->setToName($message->toName);
        $log->setFromEmail($message->from ?? $this->defaultFromEmail);
        $log->setSubject($message->subject);
        $log->setStatus(EmailStatus::PENDING);
        $log->setMetadata($message->metadata);

        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }
}
