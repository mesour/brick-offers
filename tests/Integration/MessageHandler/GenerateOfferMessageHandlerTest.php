<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Offer;
use App\Enum\OfferStatus;
use App\Message\GenerateOfferMessage;
use App\MessageHandler\GenerateOfferMessageHandler;
use App\Repository\LeadRepository;
use App\Repository\ProposalRepository;
use App\Repository\UserRepository;
use App\Service\Offer\OfferService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for GenerateOfferMessageHandler.
 */
final class GenerateOfferMessageHandlerTest extends MessageHandlerTestCase
{
    // ==================== Success Cases ====================

    #[Test]
    public function invoke_validLeadAndUser_createsOffer(): void
    {
        $user = $this->createUser('offer-user');
        $lead = $this->createLead($user, 'offer-test.com', 'test@offer-test.com');
        $leadId = $lead->getId();
        $userId = $user->getId();

        $offer = new Offer();
        $offer->setUser($user);
        $offer->setLead($lead);
        $offer->setSubject('Test Subject');
        $offer->setRecipientEmail('test@offer-test.com');

        $offerService = $this->createMock(OfferService::class);
        $offerService->expects($this->once())
            ->method('createAndGenerate')
            ->with($lead, $user, null, 'test@offer-test.com')
            ->willReturn($offer);

        $handler = new GenerateOfferMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(ProposalRepository::class),
            $offerService,
            new NullLogger(),
        );

        $message = new GenerateOfferMessage(
            leadId: $leadId,
            userId: $userId,
        );

        $handler($message);
    }

    #[Test]
    public function invoke_withRecipientEmail_usesProvidedEmail(): void
    {
        $user = $this->createUser('email-override-user');
        $lead = $this->createLead($user, 'email-override.com', 'original@email.com');
        $leadId = $lead->getId();
        $userId = $user->getId();

        $offer = new Offer();
        $offer->setUser($user);
        $offer->setLead($lead);
        $offer->setSubject('Test');
        $offer->setRecipientEmail('custom@email.com');

        $offerService = $this->createMock(OfferService::class);
        $offerService->expects($this->once())
            ->method('createAndGenerate')
            ->with($lead, $user, null, 'custom@email.com')
            ->willReturn($offer);

        $handler = new GenerateOfferMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(ProposalRepository::class),
            $offerService,
            new NullLogger(),
        );

        $message = new GenerateOfferMessage(
            leadId: $leadId,
            userId: $userId,
            recipientEmail: 'custom@email.com',
        );

        $handler($message);
    }

    #[Test]
    public function invoke_withProposalId_fetchesProposal(): void
    {
        $user = $this->createUser('proposal-offer-user');
        $lead = $this->createLead($user, 'proposal-offer.com', 'test@email.com');
        $proposal = $this->createProposal($lead, $user);

        $leadId = $lead->getId();
        $userId = $user->getId();
        $proposalId = $proposal->getId();

        $offer = new Offer();
        $offer->setUser($user);
        $offer->setLead($lead);
        $offer->setProposal($proposal);
        $offer->setSubject('Test');
        $offer->setRecipientEmail('test@email.com');

        $offerService = $this->createMock(OfferService::class);
        $offerService->expects($this->once())
            ->method('createAndGenerate')
            ->with($lead, $user, $proposal, 'test@email.com')
            ->willReturn($offer);

        $handler = new GenerateOfferMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(ProposalRepository::class),
            $offerService,
            new NullLogger(),
        );

        $message = new GenerateOfferMessage(
            leadId: $leadId,
            userId: $userId,
            proposalId: $proposalId,
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

        $offerService = $this->createMock(OfferService::class);
        $offerService->expects($this->never())
            ->method('createAndGenerate');

        $handler = new GenerateOfferMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(ProposalRepository::class),
            $offerService,
            new NullLogger(),
        );

        $message = new GenerateOfferMessage(
            leadId: $nonExistentLeadId,
            userId: $userId,
        );

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function invoke_userNotFound_returnsEarlyWithoutError(): void
    {
        $user = $this->createUser('user-not-found-offer');
        $lead = $this->createLead($user, 'user-not-found.com', 'test@email.com');
        $leadId = $lead->getId();
        $nonExistentUserId = Uuid::v4();

        $offerService = $this->createMock(OfferService::class);
        $offerService->expects($this->never())
            ->method('createAndGenerate');

        $handler = new GenerateOfferMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(ProposalRepository::class),
            $offerService,
            new NullLogger(),
        );

        $message = new GenerateOfferMessage(
            leadId: $leadId,
            userId: $nonExistentUserId,
        );

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== No Email Cases ====================

    #[Test]
    public function invoke_noRecipientEmail_returnsEarlyWithoutError(): void
    {
        $user = $this->createUser('no-email-user');
        $lead = $this->createLead($user, 'no-email.com', null);
        // Explicitly set email to null
        $lead->setEmail(null);
        self::$em->flush();

        $leadId = $lead->getId();
        $userId = $user->getId();

        $offerService = $this->createMock(OfferService::class);
        $offerService->expects($this->never())
            ->method('createAndGenerate');

        $handler = new GenerateOfferMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(ProposalRepository::class),
            $offerService,
            new NullLogger(),
        );

        $message = new GenerateOfferMessage(
            leadId: $leadId,
            userId: $userId,
            recipientEmail: null, // No custom email either
        );

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== Failure Cases ====================

    #[Test]
    public function invoke_serviceThrows_rethrowsException(): void
    {
        $user = $this->createUser('service-throws-offer');
        $lead = $this->createLead($user, 'service-throws.com', 'test@email.com');
        $leadId = $lead->getId();
        $userId = $user->getId();

        $offerService = $this->createMock(OfferService::class);
        $offerService->method('createAndGenerate')
            ->willThrowException(new \RuntimeException('AI service unavailable'));

        $handler = new GenerateOfferMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(ProposalRepository::class),
            $offerService,
            new NullLogger(),
        );

        $message = new GenerateOfferMessage(
            leadId: $leadId,
            userId: $userId,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI service unavailable');

        $handler($message);
    }

    // ==================== Proposal Fetch Cases ====================

    #[Test]
    public function invoke_nonExistentProposalId_passesNullProposal(): void
    {
        $user = $this->createUser('null-proposal-user');
        $lead = $this->createLead($user, 'null-proposal.com', 'test@email.com');
        $leadId = $lead->getId();
        $userId = $user->getId();
        $nonExistentProposalId = Uuid::v4();

        $offer = new Offer();
        $offer->setUser($user);
        $offer->setLead($lead);
        $offer->setSubject('Test');
        $offer->setRecipientEmail('test@email.com');

        $offerService = $this->createMock(OfferService::class);
        $offerService->expects($this->once())
            ->method('createAndGenerate')
            ->with($lead, $user, null, 'test@email.com') // null proposal
            ->willReturn($offer);

        $handler = new GenerateOfferMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(ProposalRepository::class),
            $offerService,
            new NullLogger(),
        );

        $message = new GenerateOfferMessage(
            leadId: $leadId,
            userId: $userId,
            proposalId: $nonExistentProposalId,
        );

        $handler($message);
    }
}
