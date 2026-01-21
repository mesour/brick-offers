<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\EmailBounceType;
use App\Service\Email\EmailService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller for AWS SES webhook notifications.
 */
#[AsController]
class SesWebhookController extends AbstractController
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle SNS notifications from SES.
     *
     * POST /api/webhook/ses
     */
    #[Route('/api/webhook/ses', name: 'api_webhook_ses', methods: ['POST'])]
    public function handleWebhook(Request $request): Response
    {
        $content = $request->getContent();
        $data = json_decode($content, true);

        if ($data === null) {
            $this->logger->warning('Invalid JSON in SES webhook');

            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Handle SNS subscription confirmation
        if (isset($data['Type']) && $data['Type'] === 'SubscriptionConfirmation') {
            return $this->handleSubscriptionConfirmation($data);
        }

        // Handle notification
        if (isset($data['Type']) && $data['Type'] === 'Notification') {
            return $this->handleNotification($data);
        }

        $this->logger->warning('Unknown SES webhook type', ['type' => $data['Type'] ?? 'unknown']);

        return new JsonResponse(['status' => 'ignored']);
    }

    /**
     * Handle SNS subscription confirmation.
     *
     * @param array<string, mixed> $data
     */
    private function handleSubscriptionConfirmation(array $data): Response
    {
        $subscribeUrl = $data['SubscribeURL'] ?? null;

        if ($subscribeUrl !== null) {
            $this->logger->info('SES SNS subscription confirmation requested', [
                'topic_arn' => $data['TopicArn'] ?? 'unknown',
                'subscribe_url' => $subscribeUrl,
            ]);

            // Auto-confirm by fetching the URL
            try {
                file_get_contents($subscribeUrl);
                $this->logger->info('SES SNS subscription confirmed');
            } catch (\Throwable $e) {
                $this->logger->error('Failed to confirm SES SNS subscription', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new JsonResponse(['status' => 'subscription_confirmed']);
    }

    /**
     * Handle SNS notification.
     *
     * @param array<string, mixed> $data
     */
    private function handleNotification(array $data): Response
    {
        $message = json_decode($data['Message'] ?? '{}', true);

        if ($message === null) {
            $this->logger->warning('Invalid message in SES notification');

            return new JsonResponse(['error' => 'Invalid message'], Response::HTTP_BAD_REQUEST);
        }

        $notificationType = $message['notificationType'] ?? $message['eventType'] ?? null;

        switch ($notificationType) {
            case 'Bounce':
                $this->handleBounce($message);
                break;

            case 'Complaint':
                $this->handleComplaint($message);
                break;

            case 'Delivery':
                $this->handleDelivery($message);
                break;

            case 'Open':
                $this->handleOpen($message);
                break;

            case 'Click':
                $this->handleClick($message);
                break;

            default:
                $this->logger->debug('Unknown SES notification type', [
                    'type' => $notificationType,
                ]);
        }

        return new JsonResponse(['status' => 'processed']);
    }

    /**
     * Handle bounce notification.
     *
     * @param array<string, mixed> $message
     */
    private function handleBounce(array $message): void
    {
        $bounce = $message['bounce'] ?? [];
        $mail = $message['mail'] ?? [];

        $messageId = $mail['messageId'] ?? null;
        if ($messageId === null) {
            return;
        }

        $bounceType = ($bounce['bounceType'] ?? '') === 'Permanent'
            ? EmailBounceType::HARD_BOUNCE
            : EmailBounceType::SOFT_BOUNCE;

        $bounceSubType = $bounce['bounceSubType'] ?? 'General';

        $this->logger->info('SES bounce received', [
            'message_id' => $messageId,
            'bounce_type' => $bounceType->value,
            'sub_type' => $bounceSubType,
        ]);

        $this->emailService->processBounce(
            $messageId,
            $bounceType,
            "Bounce: {$bounceSubType}",
        );
    }

    /**
     * Handle complaint notification.
     *
     * @param array<string, mixed> $message
     */
    private function handleComplaint(array $message): void
    {
        $complaint = $message['complaint'] ?? [];
        $mail = $message['mail'] ?? [];

        $messageId = $mail['messageId'] ?? null;
        if ($messageId === null) {
            return;
        }

        $complaintFeedbackType = $complaint['complaintFeedbackType'] ?? 'abuse';

        $this->logger->info('SES complaint received', [
            'message_id' => $messageId,
            'feedback_type' => $complaintFeedbackType,
        ]);

        $this->emailService->processComplaint(
            $messageId,
            "Complaint: {$complaintFeedbackType}",
        );
    }

    /**
     * Handle delivery notification.
     *
     * @param array<string, mixed> $message
     */
    private function handleDelivery(array $message): void
    {
        $mail = $message['mail'] ?? [];

        $messageId = $mail['messageId'] ?? null;
        if ($messageId === null) {
            return;
        }

        $this->logger->debug('SES delivery received', [
            'message_id' => $messageId,
        ]);

        $this->emailService->processDelivery($messageId);
    }

    /**
     * Handle open notification.
     *
     * @param array<string, mixed> $message
     */
    private function handleOpen(array $message): void
    {
        $mail = $message['mail'] ?? [];

        $messageId = $mail['messageId'] ?? null;
        if ($messageId === null) {
            return;
        }

        $this->emailService->processOpen($messageId);
    }

    /**
     * Handle click notification.
     *
     * @param array<string, mixed> $message
     */
    private function handleClick(array $message): void
    {
        $mail = $message['mail'] ?? [];

        $messageId = $mail['messageId'] ?? null;
        if ($messageId === null) {
            return;
        }

        $this->emailService->processClick($messageId);
    }
}
