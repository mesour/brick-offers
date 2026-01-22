<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Proposal;
use App\Enum\ProposalType;
use App\Message\GenerateProposalMessage;
use App\MessageHandler\GenerateProposalMessageHandler;
use App\Repository\AnalysisRepository;
use App\Repository\LeadRepository;
use App\Repository\UserRepository;
use App\Service\Proposal\ProposalService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for GenerateProposalMessageHandler.
 */
final class GenerateProposalMessageHandlerTest extends MessageHandlerTestCase
{
    // ==================== Success Cases ====================

    #[Test]
    public function invoke_validLeadAndUser_createsProposal(): void
    {
        $user = $this->createUser('proposal-user');
        $lead = $this->createLead($user, 'proposal-test.com');
        $leadId = $lead->getId();
        $userId = $user->getId();

        $proposal = new Proposal();
        $proposal->setLead($lead);
        $proposal->setUser($user);
        $proposal->setType(ProposalType::DESIGN_MOCKUP);

        $proposalService = $this->createMock(ProposalService::class);
        $proposalService->expects($this->once())
            ->method('createAndGenerate')
            ->with($lead, $user, ProposalType::DESIGN_MOCKUP)
            ->willReturn($proposal);

        $handler = new GenerateProposalMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            $proposalService,
            new NullLogger(),
        );

        $message = new GenerateProposalMessage(
            leadId: $leadId,
            userId: $userId,
            proposalType: 'design_mockup',
        );

        $handler($message);
    }

    #[Test]
    public function invoke_withAnalysisId_fetchesAnalysis(): void
    {
        $user = $this->createUser('analysis-proposal-user');
        $lead = $this->createLead($user, 'analysis-proposal.com');
        $analysis = $this->createAnalysis($lead);

        $leadId = $lead->getId();
        $userId = $user->getId();
        $analysisId = $analysis->getId();

        $proposal = new Proposal();
        $proposal->setLead($lead);
        $proposal->setUser($user);
        $proposal->setType(ProposalType::MARKETING_AUDIT);

        $proposalService = $this->createMock(ProposalService::class);
        $proposalService->expects($this->once())
            ->method('createAndGenerate')
            ->willReturn($proposal);

        $handler = new GenerateProposalMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            $proposalService,
            new NullLogger(),
        );

        $message = new GenerateProposalMessage(
            leadId: $leadId,
            userId: $userId,
            proposalType: 'marketing_audit',
            analysisId: $analysisId,
        );

        $handler($message);
    }

    // ==================== Not Found Cases ====================

    #[Test]
    public function invoke_leadNotFound_returnsEarlyWithoutError(): void
    {
        $user = $this->createUser('lead-not-found-user');
        $userId = $user->getId();
        $nonExistentLeadId = Uuid::v4();

        $proposalService = $this->createMock(ProposalService::class);
        $proposalService->expects($this->never())
            ->method('createAndGenerate');

        $handler = new GenerateProposalMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            $proposalService,
            new NullLogger(),
        );

        $message = new GenerateProposalMessage(
            leadId: $nonExistentLeadId,
            userId: $userId,
            proposalType: 'design_mockup',
        );

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function invoke_userNotFound_returnsEarlyWithoutError(): void
    {
        $user = $this->createUser('user-not-found-user');
        $lead = $this->createLead($user, 'user-not-found.com');
        $leadId = $lead->getId();
        $nonExistentUserId = Uuid::v4();

        $proposalService = $this->createMock(ProposalService::class);
        $proposalService->expects($this->never())
            ->method('createAndGenerate');

        $handler = new GenerateProposalMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            $proposalService,
            new NullLogger(),
        );

        $message = new GenerateProposalMessage(
            leadId: $leadId,
            userId: $nonExistentUserId,
            proposalType: 'design_mockup',
        );

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== Invalid Type Cases ====================

    #[Test]
    public function invoke_invalidProposalType_returnsEarlyWithoutError(): void
    {
        $user = $this->createUser('invalid-type-user');
        $lead = $this->createLead($user, 'invalid-type.com');
        $leadId = $lead->getId();
        $userId = $user->getId();

        $proposalService = $this->createMock(ProposalService::class);
        $proposalService->expects($this->never())
            ->method('createAndGenerate');

        $handler = new GenerateProposalMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            $proposalService,
            new NullLogger(),
        );

        $message = new GenerateProposalMessage(
            leadId: $leadId,
            userId: $userId,
            proposalType: 'invalid_type',
        );

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== Failure Cases ====================

    #[Test]
    public function invoke_serviceThrows_rethrowsException(): void
    {
        $user = $this->createUser('service-throws-user');
        $lead = $this->createLead($user, 'service-throws.com');
        $leadId = $lead->getId();
        $userId = $user->getId();

        $proposalService = $this->createMock(ProposalService::class);
        $proposalService->method('createAndGenerate')
            ->willThrowException(new \RuntimeException('AI service unavailable'));

        $handler = new GenerateProposalMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            $proposalService,
            new NullLogger(),
        );

        $message = new GenerateProposalMessage(
            leadId: $leadId,
            userId: $userId,
            proposalType: 'design_mockup',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI service unavailable');

        $handler($message);
    }

    // ==================== Proposal Type Cases ====================

    #[Test]
    public function invoke_marketingAudit_callsWithCorrectType(): void
    {
        $user = $this->createUser('marketing-user');
        $lead = $this->createLead($user, 'marketing.com');
        $leadId = $lead->getId();
        $userId = $user->getId();

        $proposal = new Proposal();
        $proposal->setLead($lead);
        $proposal->setUser($user);
        $proposal->setType(ProposalType::MARKETING_AUDIT);

        $proposalService = $this->createMock(ProposalService::class);
        $proposalService->expects($this->once())
            ->method('createAndGenerate')
            ->with($lead, $user, ProposalType::MARKETING_AUDIT)
            ->willReturn($proposal);

        $handler = new GenerateProposalMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            $proposalService,
            new NullLogger(),
        );

        $message = new GenerateProposalMessage(
            leadId: $leadId,
            userId: $userId,
            proposalType: 'marketing_audit',
        );

        $handler($message);
    }

    #[Test]
    public function invoke_conversionReport_callsWithCorrectType(): void
    {
        $user = $this->createUser('conversion-user');
        $lead = $this->createLead($user, 'conversion.com');
        $leadId = $lead->getId();
        $userId = $user->getId();

        $proposal = new Proposal();
        $proposal->setLead($lead);
        $proposal->setUser($user);
        $proposal->setType(ProposalType::CONVERSION_REPORT);

        $proposalService = $this->createMock(ProposalService::class);
        $proposalService->expects($this->once())
            ->method('createAndGenerate')
            ->with($lead, $user, ProposalType::CONVERSION_REPORT)
            ->willReturn($proposal);

        $handler = new GenerateProposalMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            $proposalService,
            new NullLogger(),
        );

        $message = new GenerateProposalMessage(
            leadId: $leadId,
            userId: $userId,
            proposalType: 'conversion_report',
        );

        $handler($message);
    }
}
