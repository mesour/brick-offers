<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Enum\EmailBounceType;
use App\Message\ProcessSesWebhookMessage;
use App\MessageHandler\ProcessSesWebhookMessageHandler;
use App\Service\Email\EmailService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * Integration tests for ProcessSesWebhookMessageHandler.
 */
final class ProcessSesWebhookMessageHandlerTest extends MessageHandlerTestCase
{
    // ==================== Bounce Handling Tests ====================

    #[Test]
    public function invoke_hardBounce_callsEmailServiceWithCorrectType(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('processBounce')
            ->with(
                'msg-123',
                EmailBounceType::HARD_BOUNCE,
                $this->stringContains('General'),
            );

        $handler = new ProcessSesWebhookMessageHandler(
            $emailService,
            new NullLogger(),
        );

        $message = new ProcessSesWebhookMessage(
            messageId: 'msg-123',
            notificationType: 'Bounce',
            payload: [
                'bounce' => [
                    'bounceType' => 'Permanent',
                    'bounceSubType' => 'General',
                ],
            ],
        );

        $handler($message);
    }

    #[Test]
    public function invoke_softBounce_callsEmailServiceWithSoftBounceType(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('processBounce')
            ->with(
                'msg-456',
                EmailBounceType::SOFT_BOUNCE,
                $this->stringContains('MailboxFull'),
            );

        $handler = new ProcessSesWebhookMessageHandler(
            $emailService,
            new NullLogger(),
        );

        $message = new ProcessSesWebhookMessage(
            messageId: 'msg-456',
            notificationType: 'Bounce',
            payload: [
                'bounce' => [
                    'bounceType' => 'Transient',
                    'bounceSubType' => 'MailboxFull',
                ],
            ],
        );

        $handler($message);
    }

    #[Test]
    public function invoke_bounceWithMissingSubType_usesGeneralAsDefault(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('processBounce')
            ->with(
                'msg-789',
                EmailBounceType::HARD_BOUNCE,
                $this->stringContains('General'),
            );

        $handler = new ProcessSesWebhookMessageHandler(
            $emailService,
            new NullLogger(),
        );

        $message = new ProcessSesWebhookMessage(
            messageId: 'msg-789',
            notificationType: 'Bounce',
            payload: [
                'bounce' => [
                    'bounceType' => 'Permanent',
                ],
            ],
        );

        $handler($message);
    }

    // ==================== Complaint Handling Tests ====================

    #[Test]
    public function invoke_complaint_callsProcessComplaint(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('processComplaint')
            ->with(
                'msg-complaint-123',
                $this->stringContains('abuse'),
            );

        $handler = new ProcessSesWebhookMessageHandler(
            $emailService,
            new NullLogger(),
        );

        $message = new ProcessSesWebhookMessage(
            messageId: 'msg-complaint-123',
            notificationType: 'Complaint',
            payload: [
                'complaint' => [
                    'complaintFeedbackType' => 'abuse',
                ],
            ],
        );

        $handler($message);
    }

    #[Test]
    public function invoke_complaintWithMissingFeedbackType_usesAbuseAsDefault(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('processComplaint')
            ->with(
                'msg-complaint-456',
                $this->stringContains('abuse'),
            );

        $handler = new ProcessSesWebhookMessageHandler(
            $emailService,
            new NullLogger(),
        );

        $message = new ProcessSesWebhookMessage(
            messageId: 'msg-complaint-456',
            notificationType: 'Complaint',
            payload: [
                'complaint' => [],
            ],
        );

        $handler($message);
    }

    // ==================== Delivery Handling Tests ====================

    #[Test]
    public function invoke_delivery_callsProcessDelivery(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('processDelivery')
            ->with('msg-delivery-123');

        $handler = new ProcessSesWebhookMessageHandler(
            $emailService,
            new NullLogger(),
        );

        $message = new ProcessSesWebhookMessage(
            messageId: 'msg-delivery-123',
            notificationType: 'Delivery',
            payload: [],
        );

        $handler($message);
    }

    // ==================== Open Tracking Tests ====================

    #[Test]
    public function invoke_open_callsProcessOpen(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('processOpen')
            ->with('msg-open-123');

        $handler = new ProcessSesWebhookMessageHandler(
            $emailService,
            new NullLogger(),
        );

        $message = new ProcessSesWebhookMessage(
            messageId: 'msg-open-123',
            notificationType: 'Open',
            payload: [],
        );

        $handler($message);
    }

    // ==================== Click Tracking Tests ====================

    #[Test]
    public function invoke_click_callsProcessClick(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('processClick')
            ->with('msg-click-123');

        $handler = new ProcessSesWebhookMessageHandler(
            $emailService,
            new NullLogger(),
        );

        $message = new ProcessSesWebhookMessage(
            messageId: 'msg-click-123',
            notificationType: 'Click',
            payload: [],
        );

        $handler($message);
    }

    // ==================== Unknown Type Tests ====================

    #[Test]
    public function invoke_unknownType_doesNotCallAnyService(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('processBounce');
        $emailService->expects($this->never())->method('processComplaint');
        $emailService->expects($this->never())->method('processDelivery');
        $emailService->expects($this->never())->method('processOpen');
        $emailService->expects($this->never())->method('processClick');

        $handler = new ProcessSesWebhookMessageHandler(
            $emailService,
            new NullLogger(),
        );

        $message = new ProcessSesWebhookMessage(
            messageId: 'msg-unknown-123',
            notificationType: 'UnknownType',
            payload: [],
        );

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }
}
