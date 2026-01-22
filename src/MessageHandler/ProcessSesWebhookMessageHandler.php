<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\EmailBounceType;
use App\Message\ProcessSesWebhookMessage;
use App\Service\Email\EmailService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for processing SES webhook notifications asynchronously.
 */
#[AsMessageHandler]
final readonly class ProcessSesWebhookMessageHandler
{
    public function __construct(
        private EmailService $emailService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessSesWebhookMessage $message): void
    {
        $this->logger->debug('Processing SES webhook', [
            'message_id' => $message->messageId,
            'type' => $message->notificationType,
        ]);

        match ($message->notificationType) {
            'Bounce' => $this->handleBounce($message),
            'Complaint' => $this->handleComplaint($message),
            'Delivery' => $this->handleDelivery($message),
            'Open' => $this->handleOpen($message),
            'Click' => $this->handleClick($message),
            default => $this->logger->warning('Unknown SES notification type', [
                'type' => $message->notificationType,
            ]),
        };
    }

    private function handleBounce(ProcessSesWebhookMessage $message): void
    {
        $bounce = $message->payload['bounce'] ?? [];

        $bounceType = ($bounce['bounceType'] ?? '') === 'Permanent'
            ? EmailBounceType::HARD_BOUNCE
            : EmailBounceType::SOFT_BOUNCE;

        $bounceSubType = $bounce['bounceSubType'] ?? 'General';

        $this->emailService->processBounce(
            $message->messageId,
            $bounceType,
            "Bounce: {$bounceSubType}",
        );

        $this->logger->info('Processed SES bounce', [
            'message_id' => $message->messageId,
            'bounce_type' => $bounceType->value,
        ]);
    }

    private function handleComplaint(ProcessSesWebhookMessage $message): void
    {
        $complaint = $message->payload['complaint'] ?? [];
        $complaintFeedbackType = $complaint['complaintFeedbackType'] ?? 'abuse';

        $this->emailService->processComplaint(
            $message->messageId,
            "Complaint: {$complaintFeedbackType}",
        );

        $this->logger->info('Processed SES complaint', [
            'message_id' => $message->messageId,
            'feedback_type' => $complaintFeedbackType,
        ]);
    }

    private function handleDelivery(ProcessSesWebhookMessage $message): void
    {
        $this->emailService->processDelivery($message->messageId);

        $this->logger->debug('Processed SES delivery', [
            'message_id' => $message->messageId,
        ]);
    }

    private function handleOpen(ProcessSesWebhookMessage $message): void
    {
        $this->emailService->processOpen($message->messageId);
    }

    private function handleClick(ProcessSesWebhookMessage $message): void
    {
        $this->emailService->processClick($message->messageId);
    }
}
