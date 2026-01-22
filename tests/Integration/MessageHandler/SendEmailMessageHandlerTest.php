<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Offer;
use App\Enum\OfferStatus;
use App\Message\SendEmailMessage;
use App\MessageHandler\SendEmailMessageHandler;
use App\Repository\OfferRepository;
use App\Service\Email\EmailSendResult;
use App\Service\Email\EmailService;
use App\Service\Offer\OfferService;
use App\Service\Offer\RateLimitResult;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for SendEmailMessageHandler.
 */
final class SendEmailMessageHandlerTest extends MessageHandlerTestCase
{
    // ==================== Success Cases ====================

    #[Test]
    public function invoke_approvedOffer_sendsEmailAndMarksAsSent(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user);
        $offer = $this->createOffer($lead, $user, OfferStatus::APPROVED);
        $offerId = $offer->getId();

        $emailService = $this->createMock(EmailService::class);
        $emailService->method('sendOffer')
            ->willReturn(EmailSendResult::success('msg-123'));

        $offerService = $this->createMock(OfferService::class);
        $offerService->method('canSend')
            ->willReturn(RateLimitResult::allowed());

        $handler = new SendEmailMessageHandler(
            self::getContainer()->get(OfferRepository::class),
            $offerService,
            $emailService,
            self::$em,
            new NullLogger(),
        );

        $message = new SendEmailMessage($offerId);
        $handler($message);

        // Refresh and verify
        $updatedOffer = $this->findEntity(Offer::class, $offerId);
        self::assertNotNull($updatedOffer);
        self::assertSame(OfferStatus::SENT, $updatedOffer->getStatus());
        self::assertNotNull($updatedOffer->getSentAt());
    }

    #[Test]
    public function invoke_withValidOffer_callsEmailService(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user);
        $offer = $this->createOffer($lead, $user, OfferStatus::APPROVED);
        $offerId = $offer->getId();

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('sendOffer')
            ->willReturn(EmailSendResult::success('msg-456'));

        $offerService = $this->createMock(OfferService::class);
        $offerService->method('canSend')
            ->willReturn(RateLimitResult::allowed());

        $handler = new SendEmailMessageHandler(
            self::getContainer()->get(OfferRepository::class),
            $offerService,
            $emailService,
            self::$em,
            new NullLogger(),
        );

        $message = new SendEmailMessage($offerId);
        $handler($message);
    }

    // ==================== Not Found Cases ====================

    #[Test]
    public function invoke_nonExistentOffer_returnsEarlyWithoutError(): void
    {
        $nonExistentId = Uuid::v4();

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())
            ->method('sendOffer');

        $offerService = $this->createMock(OfferService::class);

        $handler = new SendEmailMessageHandler(
            self::getContainer()->get(OfferRepository::class),
            $offerService,
            $emailService,
            self::$em,
            new NullLogger(),
        );

        $message = new SendEmailMessage($nonExistentId);

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== Invalid Status Cases ====================

    #[Test]
    public function invoke_draftOffer_doesNotSend(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user);
        $offer = $this->createOffer($lead, $user, OfferStatus::DRAFT);
        $offerId = $offer->getId();

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())
            ->method('sendOffer');

        $offerService = $this->createMock(OfferService::class);

        $handler = new SendEmailMessageHandler(
            self::getContainer()->get(OfferRepository::class),
            $offerService,
            $emailService,
            self::$em,
            new NullLogger(),
        );

        $message = new SendEmailMessage($offerId);
        $handler($message);

        // Verify status unchanged
        $updatedOffer = $this->findEntity(Offer::class, $offerId);
        self::assertSame(OfferStatus::DRAFT, $updatedOffer->getStatus());
    }

    #[Test]
    public function invoke_alreadySentOffer_doesNotSendAgain(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user);
        $offer = $this->createOffer($lead, $user, OfferStatus::SENT);
        $offerId = $offer->getId();

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())
            ->method('sendOffer');

        $offerService = $this->createMock(OfferService::class);

        $handler = new SendEmailMessageHandler(
            self::getContainer()->get(OfferRepository::class),
            $offerService,
            $emailService,
            self::$em,
            new NullLogger(),
        );

        $message = new SendEmailMessage($offerId);
        $handler($message);
    }

    // ==================== Rate Limit Cases ====================

    #[Test]
    public function invoke_rateLimitExceeded_throwsExceptionForRetry(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user);
        $offer = $this->createOffer($lead, $user, OfferStatus::APPROVED);
        $offerId = $offer->getId();

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())
            ->method('sendOffer');

        $offerService = $this->createMock(OfferService::class);
        $offerService->method('canSend')
            ->willReturn(RateLimitResult::denied('Daily limit exceeded', 3600));

        $handler = new SendEmailMessageHandler(
            self::getContainer()->get(OfferRepository::class),
            $offerService,
            $emailService,
            self::$em,
            new NullLogger(),
        );

        $message = new SendEmailMessage($offerId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Rate limit exceeded/');

        $handler($message);
    }

    // ==================== Email Send Failure Cases ====================

    #[Test]
    public function invoke_emailSendFailure_throwsExceptionForRetry(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user);
        $offer = $this->createOffer($lead, $user, OfferStatus::APPROVED);
        $offerId = $offer->getId();

        $emailService = $this->createMock(EmailService::class);
        $emailService->method('sendOffer')
            ->willReturn(EmailSendResult::failure('SMTP connection failed'));

        $offerService = $this->createMock(OfferService::class);
        $offerService->method('canSend')
            ->willReturn(RateLimitResult::allowed());

        $handler = new SendEmailMessageHandler(
            self::getContainer()->get(OfferRepository::class),
            $offerService,
            $emailService,
            self::$em,
            new NullLogger(),
        );

        $message = new SendEmailMessage($offerId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to send email/');

        $handler($message);
    }

    #[Test]
    public function invoke_emailSendFailure_doesNotMarkAsSent(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user);
        $offer = $this->createOffer($lead, $user, OfferStatus::APPROVED);
        $offerId = $offer->getId();

        $emailService = $this->createMock(EmailService::class);
        $emailService->method('sendOffer')
            ->willReturn(EmailSendResult::failure('Connection timeout'));

        $offerService = $this->createMock(OfferService::class);
        $offerService->method('canSend')
            ->willReturn(RateLimitResult::allowed());

        $handler = new SendEmailMessageHandler(
            self::getContainer()->get(OfferRepository::class),
            $offerService,
            $emailService,
            self::$em,
            new NullLogger(),
        );

        $message = new SendEmailMessage($offerId);

        try {
            $handler($message);
        } catch (\RuntimeException) {
            // Expected
        }

        // Verify status unchanged
        self::$em->clear();
        $updatedOffer = $this->findEntity(Offer::class, $offerId);
        self::assertSame(OfferStatus::APPROVED, $updatedOffer->getStatus());
        self::assertNull($updatedOffer->getSentAt());
    }
}
