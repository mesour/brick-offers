<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Lead;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use App\Enum\IssueCategory;
use App\Enum\LeadStatus;
use App\Message\AnalyzeLeadMessage;
use App\MessageHandler\AnalyzeLeadMessageHandler;
use App\Repository\AnalysisRepository;
use App\Repository\LeadRepository;
use App\Service\Analyzer\AnalyzerResult;
use App\Service\Analyzer\LeadAnalyzerInterface;
use App\Service\Scoring\ScoringServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for AnalyzeLeadMessageHandler.
 */
final class AnalyzeLeadMessageHandlerTest extends MessageHandlerTestCase
{
    // ==================== Success Cases ====================

    #[Test]
    public function invoke_newLead_createsAnalysisAndUpdatesLead(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user, 'test-analyze.com', null, LeadStatus::NEW);
        $leadId = $lead->getId();

        $analyzer = $this->createMockAnalyzer(IssueCategory::HTTP);
        $scoringService = $this->createMockScoringService(LeadStatus::GOOD);

        $handler = new AnalyzeLeadMessageHandler(
            [$analyzer],
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            self::$em,
            $scoringService,
            new NullLogger(),
        );

        $message = new AnalyzeLeadMessage($leadId);
        $handler($message);

        // Refresh and verify
        self::$em->clear();
        $updatedLead = $this->findEntity(Lead::class, $leadId);

        self::assertNotNull($updatedLead);
        self::assertSame(LeadStatus::GOOD, $updatedLead->getStatus());
        self::assertNotNull($updatedLead->getAnalyzedAt());
        self::assertNotNull($updatedLead->getLatestAnalysis());
        self::assertSame(1, $updatedLead->getAnalysisCount());
    }

    #[Test]
    public function invoke_withMultipleAnalyzers_runsAllAndCreatesResults(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user, 'multi-analyze.com', null, LeadStatus::NEW);
        $leadId = $lead->getId();

        $analyzer1 = $this->createMockAnalyzer(IssueCategory::HTTP);
        $analyzer2 = $this->createMockAnalyzer(IssueCategory::SECURITY);

        $scoringService = $this->createMockScoringService(LeadStatus::GOOD);

        $handler = new AnalyzeLeadMessageHandler(
            [$analyzer1, $analyzer2],
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            self::$em,
            $scoringService,
            new NullLogger(),
        );

        $message = new AnalyzeLeadMessage($leadId);
        $handler($message);

        // Refresh and verify
        self::$em->clear();
        $updatedLead = $this->findEntity(Lead::class, $leadId);

        $analysis = $updatedLead->getLatestAnalysis();
        self::assertNotNull($analysis);
        self::assertSame(AnalysisStatus::COMPLETED, $analysis->getStatus());
        self::assertCount(2, $analysis->getResults());
    }

    // ==================== Reanalyze Cases ====================

    #[Test]
    public function invoke_withReanalyzeFlag_createsNewAnalysis(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user, 'reanalyze-test.com', null, LeadStatus::GOOD);
        $leadId = $lead->getId();

        // Create existing analysis
        $existingAnalysis = $this->createAnalysis($lead, AnalysisStatus::COMPLETED);
        $lead->setLatestAnalysis($existingAnalysis);
        $lead->incrementAnalysisCount();
        self::$em->flush();

        $analyzer = $this->createMockAnalyzer(IssueCategory::HTTP);
        $scoringService = $this->createMockScoringService(LeadStatus::GOOD);

        $handler = new AnalyzeLeadMessageHandler(
            [$analyzer],
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            self::$em,
            $scoringService,
            new NullLogger(),
        );

        $message = new AnalyzeLeadMessage($leadId, reanalyze: true);
        $handler($message);

        // Refresh and verify
        self::$em->clear();
        $updatedLead = $this->findEntity(Lead::class, $leadId);

        self::assertSame(2, $updatedLead->getAnalysisCount());
        self::assertNotSame($existingAnalysis->getId(), $updatedLead->getLatestAnalysis()->getId());
    }

    #[Test]
    public function invoke_alreadyAnalyzedWithoutReanalyzeFlag_skipsAnalysis(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user, 'already-analyzed.com', null, LeadStatus::GOOD);
        $leadId = $lead->getId();

        // Create existing analysis
        $existingAnalysis = $this->createAnalysis($lead, AnalysisStatus::COMPLETED);
        $lead->setLatestAnalysis($existingAnalysis);
        self::$em->flush();

        $analyzer = $this->createMockAnalyzer(IssueCategory::HTTP);
        $analyzer->expects($this->never())->method('analyze');

        $scoringService = $this->createMock(ScoringServiceInterface::class);

        $handler = new AnalyzeLeadMessageHandler(
            [$analyzer],
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            self::$em,
            $scoringService,
            new NullLogger(),
        );

        $message = new AnalyzeLeadMessage($leadId, reanalyze: false);
        $handler($message);

        // Analysis count should be unchanged
        $existingAnalysisId = $existingAnalysis->getId();
        self::$em->clear();
        $updatedLead = $this->findEntity(Lead::class, $leadId);
        self::assertEquals($existingAnalysisId->toRfc4122(), $updatedLead->getLatestAnalysis()->getId()->toRfc4122());
    }

    // ==================== Not Found Cases ====================

    #[Test]
    public function invoke_nonExistentLead_returnsEarlyWithoutError(): void
    {
        $nonExistentId = Uuid::v4();

        $analyzer = $this->createMockAnalyzer(IssueCategory::HTTP);
        $analyzer->expects($this->never())->method('analyze');

        $scoringService = $this->createMock(ScoringServiceInterface::class);

        $handler = new AnalyzeLeadMessageHandler(
            [$analyzer],
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            self::$em,
            $scoringService,
            new NullLogger(),
        );

        $message = new AnalyzeLeadMessage($nonExistentId);

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== Industry Filter Cases ====================

    #[Test]
    public function invoke_withIndustryFilter_setsIndustryOnLead(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user, 'industry-test.com', null, LeadStatus::NEW);
        $leadId = $lead->getId();

        self::assertNull($lead->getIndustry());

        $analyzer = $this->createMockAnalyzer(IssueCategory::HTTP);
        $scoringService = $this->createMockScoringService(LeadStatus::GOOD);

        $handler = new AnalyzeLeadMessageHandler(
            [$analyzer],
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            self::$em,
            $scoringService,
            new NullLogger(),
        );

        $message = new AnalyzeLeadMessage($leadId, industryFilter: 'real_estate');
        $handler($message);

        // Refresh and verify
        self::$em->clear();
        $updatedLead = $this->findEntity(Lead::class, $leadId);

        self::assertSame(Industry::REAL_ESTATE, $updatedLead->getIndustry());
    }

    // ==================== Analyzer Failure Cases ====================

    #[Test]
    public function invoke_analyzerFails_marksResultAsFailed(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user, 'fail-test.com', null, LeadStatus::NEW);
        $leadId = $lead->getId();

        $analyzer = $this->createMockAnalyzer(IssueCategory::HTTP, success: false);
        $scoringService = $this->createMockScoringService(LeadStatus::BAD);

        $handler = new AnalyzeLeadMessageHandler(
            [$analyzer],
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            self::$em,
            $scoringService,
            new NullLogger(),
        );

        $message = new AnalyzeLeadMessage($leadId);
        $handler($message);

        // Refresh and verify - analysis should be failed since all analyzers failed
        self::$em->clear();
        $updatedLead = $this->findEntity(Lead::class, $leadId);
        $analysis = $updatedLead->getLatestAnalysis();

        self::assertNotNull($analysis);
        self::assertSame(AnalysisStatus::FAILED, $analysis->getStatus());
    }

    #[Test]
    public function invoke_someAnalyzersFail_analysisStillCompletes(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user, 'partial-fail.com', null, LeadStatus::NEW);
        $leadId = $lead->getId();

        $analyzer1 = $this->createMockAnalyzer(IssueCategory::HTTP, success: true);
        $analyzer2 = $this->createMockAnalyzer(IssueCategory::SECURITY, success: false);

        $scoringService = $this->createMockScoringService(LeadStatus::GOOD);

        $handler = new AnalyzeLeadMessageHandler(
            [$analyzer1, $analyzer2],
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            self::$em,
            $scoringService,
            new NullLogger(),
        );

        $message = new AnalyzeLeadMessage($leadId);
        $handler($message);

        // Refresh and verify - analysis should complete since at least one analyzer succeeded
        self::$em->clear();
        $updatedLead = $this->findEntity(Lead::class, $leadId);
        $analysis = $updatedLead->getLatestAnalysis();

        self::assertNotNull($analysis);
        self::assertSame(AnalysisStatus::COMPLETED, $analysis->getStatus());
    }

    // ==================== No Analyzers Cases ====================

    #[Test]
    public function invoke_noAnalyzersAvailable_skipsAnalysis(): void
    {
        $user = $this->createUser();
        $lead = $this->createLead($user, 'no-analyzers.com', null, LeadStatus::NEW);
        $leadId = $lead->getId();

        $scoringService = $this->createMock(ScoringServiceInterface::class);

        $handler = new AnalyzeLeadMessageHandler(
            [], // No analyzers
            self::getContainer()->get(LeadRepository::class),
            self::getContainer()->get(AnalysisRepository::class),
            self::$em,
            $scoringService,
            new NullLogger(),
        );

        $message = new AnalyzeLeadMessage($leadId);
        $handler($message);

        // Refresh and verify - no analysis should be created
        self::$em->clear();
        $updatedLead = $this->findEntity(Lead::class, $leadId);

        self::assertNull($updatedLead->getLatestAnalysis());
    }

    // ==================== Helper Methods ====================

    private function createMockAnalyzer(
        IssueCategory $category,
        bool $success = true,
        int $priority = 10,
    ): LeadAnalyzerInterface {
        $analyzer = $this->createMock(LeadAnalyzerInterface::class);

        $analyzer->method('getCategory')->willReturn($category);
        $analyzer->method('supports')->willReturn(true);
        $analyzer->method('getPriority')->willReturn($priority);
        $analyzer->method('getSupportedIndustries')->willReturn([]);
        $analyzer->method('isUniversal')->willReturn(true);
        $analyzer->method('supportsIndustry')->willReturn(true);

        if ($success) {
            $result = AnalyzerResult::success($category, [], ['test' => true]);
        } else {
            $result = AnalyzerResult::failure($category, 'Test failure');
        }

        $analyzer->method('analyze')->willReturn($result);

        return $analyzer;
    }

    private function createMockScoringService(LeadStatus $status): ScoringServiceInterface
    {
        $scoringService = $this->createMock(ScoringServiceInterface::class);
        $scoringService->method('determineLeadStatus')->willReturn($status);
        $scoringService->method('calculateScores')->willReturn([
            'categoryScores' => [],
            'totalScore' => 75,
        ]);

        return $scoringService;
    }
}
