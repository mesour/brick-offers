<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Lead;
use App\Entity\Proposal;
use App\Entity\User;
use App\Enum\ProposalType;
use App\Message\GenerateProposalMessage;
use App\MessageHandler\GenerateProposalMessageHandler;
use App\Repository\AnalysisRepository;
use App\Repository\LeadRepository;
use App\Repository\UserRepository;
use App\Service\Proposal\ProposalService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class GenerateProposalMessageHandlerTest extends TestCase
{
    private LeadRepository&MockObject $leadRepository;
    private UserRepository&MockObject $userRepository;
    private AnalysisRepository&MockObject $analysisRepository;
    private ProposalService&MockObject $proposalService;
    private LoggerInterface&MockObject $logger;
    private GenerateProposalMessageHandler $handler;

    protected function setUp(): void
    {
        $this->leadRepository = $this->createMock(LeadRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->analysisRepository = $this->createMock(AnalysisRepository::class);
        $this->proposalService = $this->createMock(ProposalService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new GenerateProposalMessageHandler(
            $this->leadRepository,
            $this->userRepository,
            $this->analysisRepository,
            $this->proposalService,
            $this->logger,
        );
    }

    public function testInvoke_leadNotFound_logsErrorAndReturns(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $message = new GenerateProposalMessage($leadId, $userId, ProposalType::DESIGN_MOCKUP->value);

        $this->leadRepository->expects(self::once())
            ->method('find')
            ->with($leadId)
            ->willReturn(null);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Lead not found for proposal generation', self::anything());

        $this->proposalService->expects(self::never())
            ->method('createAndGenerate');

        ($this->handler)($message);
    }

    public function testInvoke_userNotFound_logsErrorAndReturns(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $message = new GenerateProposalMessage($leadId, $userId, ProposalType::DESIGN_MOCKUP->value);

        $lead = $this->createMock(Lead::class);

        $this->leadRepository->expects(self::once())
            ->method('find')
            ->willReturn($lead);

        $this->userRepository->expects(self::once())
            ->method('find')
            ->with($userId)
            ->willReturn(null);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('User not found for proposal generation', self::anything());

        $this->proposalService->expects(self::never())
            ->method('createAndGenerate');

        ($this->handler)($message);
    }

    public function testInvoke_invalidProposalType_logsErrorAndReturns(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $message = new GenerateProposalMessage($leadId, $userId, 'invalid_type');

        $lead = $this->createMock(Lead::class);
        $user = $this->createMock(User::class);

        $this->leadRepository->method('find')->willReturn($lead);
        $this->userRepository->method('find')->willReturn($user);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Invalid proposal type', self::anything());

        $this->proposalService->expects(self::never())
            ->method('createAndGenerate');

        ($this->handler)($message);
    }

    public function testInvoke_success_generatesProposal(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $proposalId = Uuid::v4();
        $message = new GenerateProposalMessage($leadId, $userId, ProposalType::DESIGN_MOCKUP->value);

        $lead = $this->createMock(Lead::class);
        $user = $this->createMock(User::class);

        $proposal = $this->createMock(Proposal::class);
        $proposal->method('getId')->willReturn($proposalId);

        $this->leadRepository->method('find')->willReturn($lead);
        $this->userRepository->method('find')->willReturn($user);

        $this->proposalService->expects(self::once())
            ->method('createAndGenerate')
            ->with($lead, $user, ProposalType::DESIGN_MOCKUP)
            ->willReturn($proposal);

        ($this->handler)($message);
    }

    public function testInvoke_exception_logsErrorAndRethrows(): void
    {
        $leadId = Uuid::v4();
        $userId = Uuid::v4();
        $message = new GenerateProposalMessage($leadId, $userId, ProposalType::DESIGN_MOCKUP->value);

        $lead = $this->createMock(Lead::class);
        $user = $this->createMock(User::class);

        $this->leadRepository->method('find')->willReturn($lead);
        $this->userRepository->method('find')->willReturn($user);

        $exception = new \RuntimeException('Generation failed');

        $this->proposalService->expects(self::once())
            ->method('createAndGenerate')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Proposal generation failed', self::anything());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Generation failed');

        ($this->handler)($message);
    }
}
