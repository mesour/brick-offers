<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Offer;
use App\Enum\OfferStatus;
use App\Message\SendEmailMessage;
use App\MessageHandler\SendEmailMessageHandler;
use App\Repository\OfferRepository;
use App\Service\Email\EmailSendResult;
use App\Service\Email\EmailService;
use App\Service\Offer\OfferService;
use App\Service\Offer\RateLimitResult;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class SendEmailMessageHandlerTest extends TestCase
{
    private OfferRepository&MockObject $offerRepository;
    private OfferService&MockObject $offerService;
    private EmailService&MockObject $emailService;
    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    private SendEmailMessageHandler $handler;

    protected function setUp(): void
    {
        $this->offerRepository = $this->createMock(OfferRepository::class);
        $this->offerService = $this->createMock(OfferService::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new SendEmailMessageHandler(
            $this->offerRepository,
            $this->offerService,
            $this->emailService,
            $this->em,
            $this->logger,
        );
    }

    public function testInvoke_offerNotFound_logsErrorAndReturns(): void
    {
        $offerId = Uuid::v4();
        $message = new SendEmailMessage($offerId);

        $this->offerRepository->expects(self::once())
            ->method('find')
            ->with($offerId)
            ->willReturn(null);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Offer not found for email sending', self::anything());

        $this->emailService->expects(self::never())
            ->method('sendOffer');

        ($this->handler)($message);
    }

    public function testInvoke_offerCannotSend_logsWarningAndReturns(): void
    {
        $offerId = Uuid::v4();
        $message = new SendEmailMessage($offerId);

        // Use actual enum value - SENT status cannot be sent again
        $offer = $this->createMock(Offer::class);
        $offer->method('getStatus')->willReturn(OfferStatus::SENT);

        $this->offerRepository->expects(self::once())
            ->method('find')
            ->with($offerId)
            ->willReturn($offer);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('Cannot send offer - invalid status', self::anything());

        $this->emailService->expects(self::never())
            ->method('sendOffer');

        ($this->handler)($message);
    }

    public function testInvoke_rateLimitExceeded_throwsException(): void
    {
        $offerId = Uuid::v4();
        $message = new SendEmailMessage($offerId);

        // Use APPROVED status which can be sent
        $offer = $this->createMock(Offer::class);
        $offer->method('getStatus')->willReturn(OfferStatus::APPROVED);

        $this->offerRepository->method('find')->willReturn($offer);

        $rateLimitResult = new RateLimitResult(false, 'Daily limit exceeded');
        $this->offerService->expects(self::once())
            ->method('canSend')
            ->with($offer)
            ->willReturn($rateLimitResult);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('Rate limit exceeded for email', self::anything());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded: Daily limit exceeded');

        ($this->handler)($message);
    }

    public function testInvoke_emailSendFails_throwsException(): void
    {
        $offerId = Uuid::v4();
        $message = new SendEmailMessage($offerId);

        $offer = $this->createMock(Offer::class);
        $offer->method('getStatus')->willReturn(OfferStatus::APPROVED);

        $this->offerRepository->method('find')->willReturn($offer);

        $rateLimitResult = new RateLimitResult(true);
        $this->offerService->method('canSend')->willReturn($rateLimitResult);

        $emailResult = EmailSendResult::failure('SMTP connection failed');
        $this->emailService->expects(self::once())
            ->method('sendOffer')
            ->willReturn($emailResult);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Failed to send email', self::anything());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to send email: SMTP connection failed');

        ($this->handler)($message);
    }

    public function testInvoke_success_sendsEmailAndMarksAsSent(): void
    {
        $offerId = Uuid::v4();
        $message = new SendEmailMessage($offerId);

        $offer = $this->createMock(Offer::class);
        $offer->method('getStatus')->willReturn(OfferStatus::APPROVED);
        $offer->method('getRecipientEmail')->willReturn('test@example.com');
        $offer->expects(self::once())->method('markSent');

        $this->offerRepository->method('find')->willReturn($offer);

        $rateLimitResult = new RateLimitResult(true);
        $this->offerService->method('canSend')->willReturn($rateLimitResult);

        $emailResult = EmailSendResult::success('msg-123');
        $this->emailService->expects(self::once())
            ->method('sendOffer')
            ->willReturn($emailResult);

        $this->em->expects(self::once())->method('flush');

        $this->logger->expects(self::once())
            ->method('info')
            ->with('Email sent successfully', self::anything());

        ($this->handler)($message);
    }
}
