<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Lead;
use App\Entity\Offer;
use App\Entity\User;
use App\Message\GenerateOfferMessage;
use App\MessageHandler\GenerateOfferMessageHandler;
use App\Repository\LeadRepository;
use App\Repository\ProposalRepository;
use App\Repository\UserRepository;
use App\Service\Offer\OfferService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class GenerateOfferMessageHandlerTest extends TestCase
{
    private LeadRepository&MockObject $leadRepository;
    private UserRepository&MockObject $userRepository;
    private ProposalRepository&MockObject $proposalRepository;
    private OfferService&MockObject $offerService;
    private LoggerInterface&MockObject $logger;
    private GenerateOfferMessageHandler $handler;

    protected function setUp(): void
    {
        $this->leadRepository = $this->createMock(LeadRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->proposalRepository = $this->createMock(ProposalRepository::class);
        $this->offerService = $this->createMock(OfferService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new GenerateOfferMessageHandler(
            $this->leadRepository,
            $this->userRepository,
            $this->proposalRepository,
            $this->offerService,
            $this->logger,
        );
    }

    public function testInvoke_leadNotFound_logsErrorAndReturns(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $message = new GenerateOfferMessage($leadId, $userId);

        $this->leadRepository->expects(self::once())
            ->method('find')
            ->with($leadId)
            ->willReturn(null);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Lead not found for offer generation', self::anything());

        $this->offerService->expects(self::never())
            ->method('createAndGenerate');

        ($this->handler)($message);
    }

    public function testInvoke_userNotFound_logsErrorAndReturns(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $message = new GenerateOfferMessage($leadId, $userId);

        $lead = $this->createMock(Lead::class);

        $this->leadRepository->method('find')->willReturn($lead);

        $this->userRepository->expects(self::once())
            ->method('find')
            ->with($userId)
            ->willReturn(null);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('User not found for offer generation', self::anything());

        $this->offerService->expects(self::never())
            ->method('createAndGenerate');

        ($this->handler)($message);
    }

    public function testInvoke_noRecipientEmail_logsErrorAndReturns(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $message = new GenerateOfferMessage($leadId, $userId);

        $lead = $this->createMock(Lead::class);
        $lead->method('getEmail')->willReturn(null);

        $user = $this->createMock(User::class);

        $this->leadRepository->method('find')->willReturn($lead);
        $this->userRepository->method('find')->willReturn($user);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('No recipient email for offer generation', self::anything());

        $this->offerService->expects(self::never())
            ->method('createAndGenerate');

        ($this->handler)($message);
    }

    public function testInvoke_success_generatesOffer(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $offerId = Uuid::v4();
        $message = new GenerateOfferMessage($leadId, $userId, 'test@example.com');

        $lead = $this->createMock(Lead::class);
        $user = $this->createMock(User::class);

        $offer = $this->createMock(Offer::class);
        $offer->method('getId')->willReturn($offerId);

        $this->leadRepository->method('find')->willReturn($lead);
        $this->userRepository->method('find')->willReturn($user);

        $this->offerService->expects(self::once())
            ->method('createAndGenerate')
            ->with($lead, $user, null, 'test@example.com')
            ->willReturn($offer);

        ($this->handler)($message);
    }

    public function testInvoke_exception_logsErrorAndRethrows(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $message = new GenerateOfferMessage($leadId, $userId, 'test@example.com');

        $lead = $this->createMock(Lead::class);
        $user = $this->createMock(User::class);

        $this->leadRepository->method('find')->willReturn($lead);
        $this->userRepository->method('find')->willReturn($user);

        $exception = new \RuntimeException('Generation failed');

        $this->offerService->expects(self::once())
            ->method('createAndGenerate')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Offer generation failed', self::anything());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Generation failed');

        ($this->handler)($message);
    }
}
