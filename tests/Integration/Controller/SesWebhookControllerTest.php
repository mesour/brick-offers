<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\EmailLog;
use App\Enum\EmailProvider;
use App\Enum\EmailStatus;
use App\Service\Email\EmailBlacklistService;
use App\Tests\Integration\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * API integration tests for SesWebhookController.
 */
final class SesWebhookControllerTest extends ApiTestCase
{
    // ==================== Invalid Request Tests ====================

    #[Test]
    public function handleWebhook_invalidJson_returnsBadRequest(): void
    {
        self::$client->request(
            'POST',
            '/api/webhook/ses',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not valid json',
        );

        $response = self::$client->getResponse();

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Invalid JSON', $data['error']);
    }

    #[Test]
    public function handleWebhook_emptyBody_returnsBadRequest(): void
    {
        self::$client->request(
            'POST',
            '/api/webhook/ses',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '',
        );

        $response = self::$client->getResponse();

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
    }

    #[Test]
    public function handleWebhook_unknownType_returnsIgnored(): void
    {
        $response = $this->apiPost('/api/webhook/ses', [
            'Type' => 'UnknownType',
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('ignored', $data['status']);
    }

    // ==================== Subscription Confirmation Tests ====================

    #[Test]
    public function handleWebhook_subscriptionConfirmation_returnsConfirmed(): void
    {
        $response = $this->apiPost('/api/webhook/ses', [
            'Type' => 'SubscriptionConfirmation',
            'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:test-topic',
            // Note: We don't include SubscribeURL as it would make a real HTTP call
        ]);

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('subscription_confirmed', $data['status']);
    }

    // ==================== Bounce Notification Tests ====================

    #[Test]
    public function handleWebhook_bounceNotification_processesSuccessfully(): void
    {
        $log = $this->createEmailLog('test-message-bounce-' . uniqid());
        $messageId = $log->getMessageId();

        $response = $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
            ],
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('processed', $data['status']);
    }

    #[Test]
    public function handleWebhook_hardBounce_updatesLogStatus(): void
    {
        $log = $this->createEmailLog('test-message-hardbounce-' . uniqid());
        $messageId = $log->getMessageId();
        $logId = $log->getId();

        $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
            ],
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        // Refresh entity
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->find($logId);

        self::assertNotNull($updatedLog);
        self::assertSame(EmailStatus::BOUNCED, $updatedLog->getStatus());
    }

    #[Test]
    public function handleWebhook_hardBounce_addsToBlacklist(): void
    {
        $log = $this->createEmailLog('test-message-blacklist-' . uniqid());
        $messageId = $log->getMessageId();
        $email = $log->getToEmail();

        $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
            ],
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        // Check blacklist
        /** @var EmailBlacklistService $blacklistService */
        $blacklistService = $this->getService(EmailBlacklistService::class);

        // Hard bounces are global (user=null)
        self::assertTrue($blacklistService->isBlocked($email, null));
    }

    #[Test]
    public function handleWebhook_softBounce_doesNotBlacklist(): void
    {
        $log = $this->createEmailLog('test-message-softbounce-' . uniqid());
        $messageId = $log->getMessageId();
        $email = $log->getToEmail();

        $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Transient',
                'bounceSubType' => 'MailboxFull',
            ],
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        // Check blacklist
        /** @var EmailBlacklistService $blacklistService */
        $blacklistService = $this->getService(EmailBlacklistService::class);

        // Soft bounces should not be blacklisted globally
        self::assertFalse($blacklistService->isBlocked($email, null));
    }

    #[Test]
    public function handleWebhook_bounceUnknownMessage_returnsProcessed(): void
    {
        $response = $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
            ],
            'mail' => [
                'messageId' => 'unknown-message-id-' . uniqid(),
            ],
        ]));

        // Should still return processed (graceful handling)
        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('processed', $data['status']);
    }

    // ==================== Complaint Notification Tests ====================

    #[Test]
    public function handleWebhook_complaint_processesSuccessfully(): void
    {
        $log = $this->createEmailLog('test-message-complaint-' . uniqid());
        $messageId = $log->getMessageId();

        $response = $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Complaint',
            'complaint' => [
                'complaintFeedbackType' => 'abuse',
            ],
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('processed', $data['status']);
    }

    #[Test]
    public function handleWebhook_complaint_updatesLogStatus(): void
    {
        $log = $this->createEmailLog('test-message-complaintlog-' . uniqid());
        $messageId = $log->getMessageId();
        $logId = $log->getId();

        $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Complaint',
            'complaint' => [
                'complaintFeedbackType' => 'abuse',
            ],
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        // Refresh entity
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->find($logId);

        self::assertNotNull($updatedLog);
        self::assertSame(EmailStatus::COMPLAINED, $updatedLog->getStatus());
    }

    #[Test]
    public function handleWebhook_complaint_addsToGlobalBlacklist(): void
    {
        $log = $this->createEmailLog('test-message-complaintbl-' . uniqid());
        $messageId = $log->getMessageId();
        $email = $log->getToEmail();

        $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Complaint',
            'complaint' => [
                'complaintFeedbackType' => 'abuse',
            ],
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        // Check blacklist
        /** @var EmailBlacklistService $blacklistService */
        $blacklistService = $this->getService(EmailBlacklistService::class);

        // Complaints are global
        self::assertTrue($blacklistService->isBlocked($email, null));
    }

    // ==================== Delivery Notification Tests ====================

    #[Test]
    public function handleWebhook_delivery_processesSuccessfully(): void
    {
        $log = $this->createEmailLog('test-message-delivery-' . uniqid());
        $messageId = $log->getMessageId();

        $response = $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Delivery',
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('processed', $data['status']);
    }

    #[Test]
    public function handleWebhook_delivery_updatesLogStatus(): void
    {
        $log = $this->createEmailLog('test-message-deliverylog-' . uniqid());
        $messageId = $log->getMessageId();
        $logId = $log->getId();

        $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Delivery',
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        // Refresh entity
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->find($logId);

        self::assertNotNull($updatedLog);
        self::assertSame(EmailStatus::DELIVERED, $updatedLog->getStatus());
    }

    // ==================== Open Notification Tests ====================

    #[Test]
    public function handleWebhook_open_processesSuccessfully(): void
    {
        $log = $this->createEmailLog('test-message-open-' . uniqid());
        $messageId = $log->getMessageId();

        $response = $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Open',
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('processed', $data['status']);
    }

    #[Test]
    public function handleWebhook_open_setsOpenedAt(): void
    {
        $log = $this->createEmailLog('test-message-openlog-' . uniqid());
        $messageId = $log->getMessageId();
        $logId = $log->getId();

        $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Open',
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        // Refresh entity
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->find($logId);

        self::assertNotNull($updatedLog);
        self::assertNotNull($updatedLog->getOpenedAt());
    }

    // ==================== Click Notification Tests ====================

    #[Test]
    public function handleWebhook_click_processesSuccessfully(): void
    {
        $log = $this->createEmailLog('test-message-click-' . uniqid());
        $messageId = $log->getMessageId();

        $response = $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Click',
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('processed', $data['status']);
    }

    #[Test]
    public function handleWebhook_click_setsClickedAt(): void
    {
        $log = $this->createEmailLog('test-message-clicklog-' . uniqid());
        $messageId = $log->getMessageId();
        $logId = $log->getId();

        $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Click',
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        // Refresh entity
        self::$em->clear();
        $updatedLog = self::$em->getRepository(EmailLog::class)->find($logId);

        self::assertNotNull($updatedLog);
        self::assertNotNull($updatedLog->getClickedAt());
    }

    // ==================== eventType Format Tests (SES alternative format) ====================

    #[Test]
    public function handleWebhook_eventTypeFormat_processesSuccessfully(): void
    {
        $log = $this->createEmailLog('test-message-eventtype-' . uniqid());
        $messageId = $log->getMessageId();

        // SES sometimes uses eventType instead of notificationType
        $response = $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'eventType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
            ],
            'mail' => [
                'messageId' => $messageId,
            ],
        ]));

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('processed', $data['status']);
    }

    // ==================== Invalid Notification Tests ====================

    #[Test]
    public function handleWebhook_invalidMessageInNotification_returnsBadRequest(): void
    {
        $response = $this->apiPost('/api/webhook/ses', [
            'Type' => 'Notification',
            'Message' => 'not valid json',
        ]);

        $this->assertResponseStatusCode(Response::HTTP_BAD_REQUEST, $response);
        $data = $this->getJsonResponse($response);
        self::assertSame('Invalid message', $data['error']);
    }

    #[Test]
    public function handleWebhook_missingMessageId_returnsProcessed(): void
    {
        // When messageId is missing, the webhook just doesn't do anything but still returns success
        $response = $this->apiPost('/api/webhook/ses', $this->createNotificationPayload([
            'notificationType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Permanent',
            ],
            'mail' => [
                // No messageId
            ],
        ]));

        $this->assertApiResponseIsSuccessful($response);
        $data = $this->getJsonResponse($response);
        self::assertSame('processed', $data['status']);
    }

    // ==================== Helper Methods ====================

    private function createEmailLog(string $messageId): EmailLog
    {
        $user = $this->createUser('webhook-user-' . uniqid());

        $log = new EmailLog();
        $log->setUser($user);
        $log->setProvider(EmailProvider::SES);
        $log->setToEmail('recipient-' . uniqid() . '@example.com');
        $log->setFromEmail('sender@example.com');
        $log->setSubject('Test Subject');
        $log->setStatus(EmailStatus::SENT);
        $log->setMessageId($messageId);

        self::$em->persist($log);
        self::$em->flush();

        return $log;
    }

    /**
     * Create SNS notification payload.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function createNotificationPayload(array $message): array
    {
        return [
            'Type' => 'Notification',
            'MessageId' => 'sns-message-' . uniqid(),
            'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:ses-notifications',
            'Message' => json_encode($message),
            'Timestamp' => date('c'),
        ];
    }
}
