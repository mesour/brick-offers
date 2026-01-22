<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Lead;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Message\DiscoverLeadsMessage;
use App\MessageHandler\DiscoverLeadsMessageHandler;
use App\Repository\AffiliateRepository;
use App\Repository\LeadRepository;
use App\Repository\UserRepository;
use App\Service\Company\CompanyService;
use App\Service\Discovery\DiscoveryResult;
use App\Service\Discovery\DiscoverySourceInterface;
use App\Service\Extractor\PageDataExtractor;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for DiscoverLeadsMessageHandler.
 */
final class DiscoverLeadsMessageHandlerTest extends MessageHandlerTestCase
{
    // ==================== Success Cases ====================

    #[Test]
    public function invoke_validSourceAndQueries_createsLeads(): void
    {
        $user = $this->createUser('discover-user');
        $userId = $user->getId();

        $discoverySource = $this->createMockDiscoverySource(LeadSource::MANUAL, [
            new DiscoveryResult('https://discovered1.com', ['title' => 'Site 1']),
            new DiscoveryResult('https://discovered2.com', ['title' => 'Site 2']),
        ]);

        $handler = $this->createHandler([$discoverySource]);

        $message = new DiscoverLeadsMessage(
            source: 'manual',
            queries: ['test query'],
            userId: $userId,
            limit: 10,
        );

        $handler($message);

        // Verify leads were created
        $leadRepository = self::getContainer()->get(LeadRepository::class);
        $leads = $leadRepository->findBy(['user' => $user]);

        self::assertCount(2, $leads);
        self::assertSame(LeadStatus::NEW, $leads[0]->getStatus());
        self::assertSame(LeadSource::MANUAL, $leads[0]->getSource());
    }

    #[Test]
    public function invoke_respectsLimit_stopsAtLimit(): void
    {
        $user = $this->createUser('limit-user');
        $userId = $user->getId();

        $discoverySource = $this->createMockDiscoverySource(LeadSource::MANUAL, [
            new DiscoveryResult('https://site1.com'),
            new DiscoveryResult('https://site2.com'),
            new DiscoveryResult('https://site3.com'),
            new DiscoveryResult('https://site4.com'),
            new DiscoveryResult('https://site5.com'),
        ]);

        $handler = $this->createHandler([$discoverySource]);

        $message = new DiscoverLeadsMessage(
            source: 'manual',
            queries: ['test'],
            userId: $userId,
            limit: 3, // Limit to 3
        );

        $handler($message);

        // Verify only 3 leads were created
        $leadRepository = self::getContainer()->get(LeadRepository::class);
        $leads = $leadRepository->findBy(['user' => $user]);

        self::assertCount(3, $leads);
    }

    #[Test]
    public function invoke_multipleQueries_combinesResults(): void
    {
        $user = $this->createUser('multi-query-user');
        $userId = $user->getId();

        $discoverySource = $this->createMock(DiscoverySourceInterface::class);
        $discoverySource->method('getSource')->willReturn(LeadSource::MANUAL);
        $discoverySource->method('supports')->willReturn(true);

        // First query returns 2 results, second query returns 2 more
        $discoverySource->expects($this->exactly(2))
            ->method('discover')
            ->willReturnOnConsecutiveCalls(
                [new DiscoveryResult('https://query1-site1.com')],
                [new DiscoveryResult('https://query2-site1.com')],
            );

        $handler = $this->createHandler([$discoverySource]);

        $message = new DiscoverLeadsMessage(
            source: 'manual',
            queries: ['query1', 'query2'],
            userId: $userId,
            limit: 10,
        );

        $handler($message);

        // Verify leads from both queries were created
        $leadRepository = self::getContainer()->get(LeadRepository::class);
        $leads = $leadRepository->findBy(['user' => $user]);

        self::assertCount(2, $leads);
    }

    // ==================== Deduplication Tests ====================

    #[Test]
    public function invoke_duplicateDomains_deduplicates(): void
    {
        $user = $this->createUser('dedup-user');
        $userId = $user->getId();

        $discoverySource = $this->createMockDiscoverySource(LeadSource::MANUAL, [
            new DiscoveryResult('https://duplicate.com/page1'),
            new DiscoveryResult('https://duplicate.com/page2'), // Same domain
            new DiscoveryResult('https://unique.com'),
        ]);

        $handler = $this->createHandler([$discoverySource]);

        $message = new DiscoverLeadsMessage(
            source: 'manual',
            queries: ['test'],
            userId: $userId,
            limit: 10,
        );

        $handler($message);

        // Verify only unique domains were created
        $leadRepository = self::getContainer()->get(LeadRepository::class);
        $leads = $leadRepository->findBy(['user' => $user]);

        self::assertCount(2, $leads);
    }

    #[Test]
    public function invoke_existingLeadDomain_skipsExisting(): void
    {
        $user = $this->createUser('existing-user');
        $userId = $user->getId();

        // Create existing lead
        $existingLead = $this->createLead($user, 'existing-domain.com');
        $existingDomain = $existingLead->getDomain();

        $discoverySource = $this->createMockDiscoverySource(LeadSource::MANUAL, [
            new DiscoveryResult('https://' . $existingDomain), // Already exists
            new DiscoveryResult('https://new-domain.com'),
        ]);

        $handler = $this->createHandler([$discoverySource]);

        $message = new DiscoverLeadsMessage(
            source: 'manual',
            queries: ['test'],
            userId: $userId,
            limit: 10,
        );

        $handler($message);

        // Verify only new lead was created (existing + 1 new = 2 total)
        $leadRepository = self::getContainer()->get(LeadRepository::class);
        $leads = $leadRepository->findBy(['user' => $user]);

        self::assertCount(2, $leads);
    }

    // ==================== Invalid Source Cases ====================

    #[Test]
    public function invoke_invalidSource_returnsEarlyWithoutError(): void
    {
        $user = $this->createUser('invalid-source-user');
        $userId = $user->getId();

        $discoverySource = $this->createMockDiscoverySource(LeadSource::MANUAL, []);

        $handler = $this->createHandler([$discoverySource]);

        $message = new DiscoverLeadsMessage(
            source: 'invalid_source',
            queries: ['test'],
            userId: $userId,
            limit: 10,
        );

        // Should not throw
        $handler($message);

        // No leads created
        $leadRepository = self::getContainer()->get(LeadRepository::class);
        $leads = $leadRepository->findBy(['user' => $user]);

        self::assertCount(0, $leads);
    }

    #[Test]
    public function invoke_noSourceImplementation_returnsEarlyWithoutError(): void
    {
        $user = $this->createUser('no-impl-user');
        $userId = $user->getId();

        // No discovery sources registered
        $handler = $this->createHandler([]);

        $message = new DiscoverLeadsMessage(
            source: 'manual',
            queries: ['test'],
            userId: $userId,
            limit: 10,
        );

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== User Not Found Cases ====================

    #[Test]
    public function invoke_userNotFound_returnsEarlyWithoutError(): void
    {
        $nonExistentUserId = Uuid::v4();

        $discoverySource = $this->createMockDiscoverySource(LeadSource::MANUAL, [
            new DiscoveryResult('https://test.com'),
        ]);

        $handler = $this->createHandler([$discoverySource]);

        $message = new DiscoverLeadsMessage(
            source: 'manual',
            queries: ['test'],
            userId: $nonExistentUserId,
            limit: 10,
        );

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== No Results Cases ====================

    #[Test]
    public function invoke_noResults_completesWithoutCreatingLeads(): void
    {
        $user = $this->createUser('no-results-user');
        $userId = $user->getId();

        $discoverySource = $this->createMockDiscoverySource(LeadSource::MANUAL, []);

        $handler = $this->createHandler([$discoverySource]);

        $message = new DiscoverLeadsMessage(
            source: 'manual',
            queries: ['no results query'],
            userId: $userId,
            limit: 10,
        );

        $handler($message);

        // No leads created
        $leadRepository = self::getContainer()->get(LeadRepository::class);
        $leads = $leadRepository->findBy(['user' => $user]);

        self::assertCount(0, $leads);
    }

    // ==================== Metadata Population Tests ====================

    #[Test]
    public function invoke_withMetadata_populatesLeadFields(): void
    {
        $user = $this->createUser('metadata-user');
        $userId = $user->getId();

        $discoverySource = $this->createMockDiscoverySource(LeadSource::MANUAL, [
            new DiscoveryResult('https://metadata-test.com', [
                'extracted_emails' => ['test@metadata-test.com'],
                'extracted_phones' => ['+420123456789'],
                'extracted_company_name' => 'Test Company s.r.o.',
            ]),
        ]);

        $handler = $this->createHandler([$discoverySource]);

        $message = new DiscoverLeadsMessage(
            source: 'manual',
            queries: ['test'],
            userId: $userId,
            limit: 10,
        );

        $handler($message);

        // Verify metadata was populated
        $leadRepository = self::getContainer()->get(LeadRepository::class);
        $leads = $leadRepository->findBy(['user' => $user]);

        self::assertCount(1, $leads);
        self::assertSame('test@metadata-test.com', $leads[0]->getEmail());
        self::assertSame('+420123456789', $leads[0]->getPhone());
        self::assertSame('Test Company s.r.o.', $leads[0]->getCompanyName());
    }

    // ==================== Priority Tests ====================

    #[Test]
    public function invoke_withPriority_setsPriorityOnLeads(): void
    {
        $user = $this->createUser('priority-user');
        $userId = $user->getId();

        $discoverySource = $this->createMockDiscoverySource(LeadSource::MANUAL, [
            new DiscoveryResult('https://priority-test.com'),
        ]);

        $handler = $this->createHandler([$discoverySource]);

        $message = new DiscoverLeadsMessage(
            source: 'manual',
            queries: ['test'],
            userId: $userId,
            limit: 10,
            priority: 8,
        );

        $handler($message);

        // Verify priority was set
        $leadRepository = self::getContainer()->get(LeadRepository::class);
        $leads = $leadRepository->findBy(['user' => $user]);

        self::assertCount(1, $leads);
        self::assertSame(8, $leads[0]->getPriority());
    }

    // ==================== Helper Methods ====================

    /**
     * @param array<DiscoverySourceInterface> $sources
     */
    private function createHandler(array $sources): DiscoverLeadsMessageHandler
    {
        return new DiscoverLeadsMessageHandler(
            $sources,
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(AffiliateRepository::class),
            self::$em,
            self::getContainer()->get(PageDataExtractor::class),
            self::getContainer()->get(CompanyService::class),
            new NullLogger(),
        );
    }

    /**
     * @param array<DiscoveryResult> $results
     */
    private function createMockDiscoverySource(LeadSource $source, array $results): DiscoverySourceInterface
    {
        $discoverySource = $this->createMock(DiscoverySourceInterface::class);
        $discoverySource->method('getSource')->willReturn($source);
        $discoverySource->method('supports')->willReturn(true);
        $discoverySource->method('discover')->willReturn($results);

        return $discoverySource;
    }
}
