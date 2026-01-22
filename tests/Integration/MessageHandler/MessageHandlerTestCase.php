<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Analysis;
use App\Entity\Lead;
use App\Entity\Offer;
use App\Entity\Proposal;
use App\Entity\User;
use App\Enum\AnalysisStatus;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Enum\OfferStatus;
use App\Enum\ProposalStatus;
use App\Enum\ProposalType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Base class for MessageHandler integration tests.
 *
 * Features:
 * - Provides access to real services from container
 * - Wraps each test in a transaction for isolation
 * - Provides helper methods for creating test entities
 */
abstract class MessageHandlerTestCase extends KernelTestCase
{
    protected static ?EntityManagerInterface $em = null;
    protected static ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        self::$em = self::getContainer()->get(EntityManagerInterface::class);
        self::$connection = self::$em->getConnection();

        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollbackTransaction();

        parent::tearDown();
    }

    protected function beginTransaction(): void
    {
        if (self::$connection !== null && !self::$connection->isTransactionActive()) {
            self::$connection->beginTransaction();
        }
    }

    protected function rollbackTransaction(): void
    {
        try {
            if (self::$connection !== null && self::$connection->isTransactionActive()) {
                self::$connection->rollBack();
            }

            if (self::$em !== null && self::$em->isOpen()) {
                self::$em->clear();
            }
        } catch (\Throwable) {
            // Ignore rollback errors during teardown
        }
    }

    // ==================== Service Helpers ====================

    /**
     * Get service from container.
     *
     * @template T of object
     *
     * @param class-string<T> $serviceClass
     *
     * @return T
     */
    protected function getService(string $serviceClass): object
    {
        return self::getContainer()->get($serviceClass);
    }

    /**
     * Get a mock logger.
     */
    protected function createMockLogger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    // ==================== Entity Factory Helpers ====================

    /**
     * Create and persist a test user.
     */
    protected function createUser(string $code = 'test-user', string $name = 'Test User'): User
    {
        $user = new User();
        $user->setCode($code . '-' . uniqid());
        $user->setName($name);
        $user->setEmail($code . '-' . uniqid() . '@example.com');

        self::$em->persist($user);
        self::$em->flush();

        return $user;
    }

    /**
     * Create and persist a test lead.
     */
    protected function createLead(
        User $user,
        string $domain = 'example.com',
        ?string $email = null,
        LeadStatus $status = LeadStatus::NEW,
    ): Lead {
        $uniqueDomain = uniqid() . '-' . $domain;

        $lead = new Lead();
        $lead->setUser($user);
        $lead->setUrl('https://' . $uniqueDomain);
        $lead->setDomain($uniqueDomain);
        $lead->setEmail($email ?? 'contact@' . $uniqueDomain);
        $lead->setCompanyName('Example Company');
        $lead->setSource(LeadSource::MANUAL);
        $lead->setStatus($status);

        self::$em->persist($lead);
        self::$em->flush();

        return $lead;
    }

    /**
     * Create and persist a test analysis.
     */
    protected function createAnalysis(
        Lead $lead,
        AnalysisStatus $status = AnalysisStatus::COMPLETED,
    ): Analysis {
        $analysis = new Analysis();
        $analysis->setLead($lead);
        $analysis->setSequenceNumber(1);

        if ($status === AnalysisStatus::COMPLETED) {
            $analysis->markAsCompleted();
        } elseif ($status === AnalysisStatus::RUNNING) {
            $analysis->markAsRunning();
        } elseif ($status === AnalysisStatus::FAILED) {
            $analysis->markAsFailed();
        }

        self::$em->persist($analysis);
        self::$em->flush();

        return $analysis;
    }

    /**
     * Create and persist a test proposal.
     */
    protected function createProposal(
        Lead $lead,
        User $user,
        ProposalStatus $status = ProposalStatus::DRAFT,
        ProposalType $type = ProposalType::DESIGN_MOCKUP,
    ): Proposal {
        $proposal = new Proposal();
        $proposal->setLead($lead);
        $proposal->setUser($user);
        $proposal->setType($type);
        $proposal->setStatus($status);
        $proposal->setTitle('Test Proposal');

        if ($status === ProposalStatus::DRAFT || $status === ProposalStatus::APPROVED) {
            $proposal->setContent('<html><body>Test content</body></html>');
            $proposal->setSummary('Test summary');
        }

        self::$em->persist($proposal);
        self::$em->flush();

        return $proposal;
    }

    /**
     * Create and persist a test offer.
     */
    protected function createOffer(
        Lead $lead,
        User $user,
        OfferStatus $status = OfferStatus::DRAFT,
        ?Proposal $proposal = null,
    ): Offer {
        $offer = new Offer();
        $offer->setUser($user);
        $offer->setLead($lead);
        $offer->setSubject('Test Offer Subject');
        $offer->setBody('<p>Test email body</p>');
        $offer->setRecipientEmail($lead->getEmail() ?? 'test@example.com');

        if ($proposal !== null) {
            $offer->setProposal($proposal);
        }

        // Set status via state transitions (DRAFT → PENDING_APPROVAL → APPROVED → SENT)
        if ($status === OfferStatus::APPROVED) {
            $offer->submitForApproval();
            $offer->approve($user);
        } elseif ($status === OfferStatus::SENT) {
            $offer->submitForApproval();
            $offer->approve($user);
            $offer->markSent();
        } elseif ($status === OfferStatus::PENDING_APPROVAL) {
            $offer->submitForApproval();
        }

        self::$em->persist($offer);
        self::$em->flush();

        return $offer;
    }

    /**
     * Flush and clear entity manager.
     */
    protected function flushAndClear(): void
    {
        self::$em->flush();
        self::$em->clear();
    }

    /**
     * Refresh entity from database.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T|null
     */
    protected function refreshEntity(object $entity): ?object
    {
        $class = get_class($entity);
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;

        if ($id === null) {
            return null;
        }

        self::$em->clear();

        return self::$em->find($class, $id);
    }

    /**
     * Find entity by ID.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    protected function findEntity(string $class, Uuid $id): ?object
    {
        return self::$em->find($class, $id);
    }
}
