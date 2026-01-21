<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Analysis;
use App\Entity\AnalysisResult;
use App\Entity\Lead;
use App\Entity\User;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use App\Enum\IssueCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Analysis::class)]
final class AnalysisTest extends TestCase
{
    // ==================== Constructor Tests ====================

    #[Test]
    public function constructor_defaultsToStatusPending(): void
    {
        $analysis = new Analysis();

        self::assertSame(AnalysisStatus::PENDING, $analysis->getStatus());
    }

    #[Test]
    public function constructor_resultsCollectionIsEmpty(): void
    {
        $analysis = new Analysis();

        self::assertTrue($analysis->getResults()->isEmpty());
    }

    #[Test]
    public function constructor_defaultsToSequenceNumberOne(): void
    {
        $analysis = new Analysis();

        self::assertSame(1, $analysis->getSequenceNumber());
    }

    #[Test]
    public function constructor_defaultTotalScoreIsZero(): void
    {
        $analysis = new Analysis();

        self::assertSame(0, $analysis->getTotalScore());
    }

    // ==================== Setters/Getters Tests ====================

    #[Test]
    public function setLead_setsLead(): void
    {
        $analysis = new Analysis();
        $lead = $this->createLead();

        $analysis->setLead($lead);

        self::assertSame($lead, $analysis->getLead());
    }

    #[Test]
    public function setStatus_setsStatus(): void
    {
        $analysis = new Analysis();

        $analysis->setStatus(AnalysisStatus::RUNNING);

        self::assertSame(AnalysisStatus::RUNNING, $analysis->getStatus());
    }

    #[Test]
    public function setTotalScore_setsTotalScore(): void
    {
        $analysis = new Analysis();

        $analysis->setTotalScore(85);

        self::assertSame(85, $analysis->getTotalScore());
    }

    #[Test]
    public function setIndustry_setsIndustry(): void
    {
        $analysis = new Analysis();

        $analysis->setIndustry(Industry::ESHOP);

        self::assertSame(Industry::ESHOP, $analysis->getIndustry());
    }

    #[Test]
    public function setIsEshop_setsIsEshop(): void
    {
        $analysis = new Analysis();

        $analysis->setIsEshop(true);

        self::assertTrue($analysis->isEshop());
    }

    #[Test]
    public function setSequenceNumber_setsSequenceNumber(): void
    {
        $analysis = new Analysis();

        $analysis->setSequenceNumber(5);

        self::assertSame(5, $analysis->getSequenceNumber());
    }

    // ==================== State Transition Tests ====================

    #[Test]
    public function markAsRunning_changesStatusToRunning(): void
    {
        $analysis = new Analysis();

        $analysis->markAsRunning();

        self::assertSame(AnalysisStatus::RUNNING, $analysis->getStatus());
        self::assertNotNull($analysis->getStartedAt());
    }

    #[Test]
    public function markAsCompleted_changesStatusToCompleted(): void
    {
        $analysis = new Analysis();
        $analysis->markAsRunning();

        $analysis->markAsCompleted();

        self::assertSame(AnalysisStatus::COMPLETED, $analysis->getStatus());
        self::assertNotNull($analysis->getCompletedAt());
    }

    #[Test]
    public function markAsFailed_changesStatusToFailed(): void
    {
        $analysis = new Analysis();
        $analysis->markAsRunning();

        $analysis->markAsFailed();

        self::assertSame(AnalysisStatus::FAILED, $analysis->getStatus());
        self::assertNotNull($analysis->getCompletedAt());
    }

    // ==================== Results Collection Tests ====================

    #[Test]
    public function addResult_addsResultToCollection(): void
    {
        $analysis = new Analysis();
        $result = $this->createMockResult(IssueCategory::SECURITY);

        $analysis->addResult($result);

        self::assertCount(1, $analysis->getResults());
        self::assertTrue($analysis->getResults()->contains($result));
    }

    #[Test]
    public function addResult_setsAnalysisOnResult(): void
    {
        $analysis = new Analysis();
        $result = new AnalysisResult();
        $result->setCategory(IssueCategory::SECURITY);

        $analysis->addResult($result);

        self::assertSame($analysis, $result->getAnalysis());
    }

    #[Test]
    public function removeResult_removesResultFromCollection(): void
    {
        $analysis = new Analysis();
        $result = $this->createMockResult(IssueCategory::SECURITY);
        $analysis->addResult($result);

        $analysis->removeResult($result);

        self::assertCount(0, $analysis->getResults());
    }

    #[Test]
    public function getResultByCategory_returnsMatchingResult(): void
    {
        $analysis = new Analysis();
        $securityResult = $this->createMockResult(IssueCategory::SECURITY);
        $seoResult = $this->createMockResult(IssueCategory::SEO);
        $analysis->addResult($securityResult);
        $analysis->addResult($seoResult);

        $found = $analysis->getResultByCategory(IssueCategory::SECURITY);

        self::assertSame($securityResult, $found);
    }

    #[Test]
    public function getResultByCategory_nonExistent_returnsNull(): void
    {
        $analysis = new Analysis();
        $securityResult = $this->createMockResult(IssueCategory::SECURITY);
        $analysis->addResult($securityResult);

        $found = $analysis->getResultByCategory(IssueCategory::PERFORMANCE);

        self::assertNull($found);
    }

    // ==================== Score Calculation Tests ====================

    #[Test]
    public function calculateTotalScore_sumsCompletedResultScores(): void
    {
        $analysis = new Analysis();

        $result1 = $this->createCompletedResult(IssueCategory::SECURITY, 30);
        $result2 = $this->createCompletedResult(IssueCategory::SEO, 25);
        $result3 = $this->createCompletedResult(IssueCategory::PERFORMANCE, 20);

        $analysis->addResult($result1);
        $analysis->addResult($result2);
        $analysis->addResult($result3);

        $total = $analysis->calculateTotalScore();

        self::assertSame(75, $total);
        self::assertSame(75, $analysis->getTotalScore());
    }

    #[Test]
    public function calculateTotalScore_ignoresPendingResults(): void
    {
        $analysis = new Analysis();

        $completedResult = $this->createCompletedResult(IssueCategory::SECURITY, 30);
        $pendingResult = $this->createMockResult(IssueCategory::SEO);
        $pendingResult->setScore(50); // Should be ignored

        $analysis->addResult($completedResult);
        $analysis->addResult($pendingResult);

        $total = $analysis->calculateTotalScore();

        self::assertSame(30, $total);
    }

    #[Test]
    public function getScores_returnsScoresByCategory(): void
    {
        $analysis = new Analysis();

        $result1 = $this->createCompletedResult(IssueCategory::SECURITY, 30);
        $result2 = $this->createCompletedResult(IssueCategory::SEO, 25);

        $analysis->addResult($result1);
        $analysis->addResult($result2);

        $scores = $analysis->getScores();

        self::assertArrayHasKey('security', $scores);
        self::assertArrayHasKey('seo', $scores);
        self::assertSame(30, $scores['security']);
        self::assertSame(25, $scores['seo']);
    }

    // ==================== Issue Count Tests ====================

    #[Test]
    public function getIssueCount_sumsTotalIssuesFromResults(): void
    {
        $analysis = new Analysis();

        $result1 = $this->createCompletedResultWithIssues(IssueCategory::SECURITY, 3);
        $result2 = $this->createCompletedResultWithIssues(IssueCategory::SEO, 2);

        $analysis->addResult($result1);
        $analysis->addResult($result2);

        self::assertSame(5, $analysis->getIssueCount());
    }

    #[Test]
    public function getCriticalIssueCount_sumsCriticalIssuesFromResults(): void
    {
        $analysis = new Analysis();

        $result = $this->createCompletedResultWithCriticalIssues(IssueCategory::SECURITY, 2);
        $analysis->addResult($result);

        self::assertSame(2, $analysis->getCriticalIssueCount());
    }

    #[Test]
    public function hasCriticalIssues_withCriticalIssues_returnsTrue(): void
    {
        $analysis = new Analysis();

        $result = $this->createCompletedResultWithCriticalIssues(IssueCategory::SECURITY, 1);
        $analysis->addResult($result);

        self::assertTrue($analysis->hasCriticalIssues());
    }

    #[Test]
    public function hasCriticalIssues_withoutCriticalIssues_returnsFalse(): void
    {
        $analysis = new Analysis();

        $result = $this->createCompletedResultWithIssues(IssueCategory::SEO, 3);
        $analysis->addResult($result);

        self::assertFalse($analysis->hasCriticalIssues());
    }

    // ==================== Results Completion Tests ====================

    #[Test]
    public function areAllResultsComplete_allCompleted_returnsTrue(): void
    {
        $analysis = new Analysis();

        $result1 = $this->createCompletedResult(IssueCategory::SECURITY, 30);
        $result2 = $this->createCompletedResult(IssueCategory::SEO, 25);

        $analysis->addResult($result1);
        $analysis->addResult($result2);

        self::assertTrue($analysis->areAllResultsComplete());
    }

    #[Test]
    public function areAllResultsComplete_withPending_returnsFalse(): void
    {
        $analysis = new Analysis();

        $completedResult = $this->createCompletedResult(IssueCategory::SECURITY, 30);
        $pendingResult = $this->createMockResult(IssueCategory::SEO);

        $analysis->addResult($completedResult);
        $analysis->addResult($pendingResult);

        self::assertFalse($analysis->areAllResultsComplete());
    }

    #[Test]
    public function areAllResultsComplete_empty_returnsFalse(): void
    {
        $analysis = new Analysis();

        self::assertFalse($analysis->areAllResultsComplete());
    }

    #[Test]
    public function getCompletedResultsCount_countsCompleted(): void
    {
        $analysis = new Analysis();

        $result1 = $this->createCompletedResult(IssueCategory::SECURITY, 30);
        $result2 = $this->createCompletedResult(IssueCategory::SEO, 25);
        $result3 = $this->createMockResult(IssueCategory::PERFORMANCE);

        $analysis->addResult($result1);
        $analysis->addResult($result2);
        $analysis->addResult($result3);

        self::assertSame(2, $analysis->getCompletedResultsCount());
    }

    #[Test]
    public function getFailedResultsCount_countsFailed(): void
    {
        $analysis = new Analysis();

        $completedResult = $this->createCompletedResult(IssueCategory::SECURITY, 30);
        $failedResult = $this->createMockResult(IssueCategory::SEO);
        $failedResult->setStatus(AnalysisStatus::FAILED);

        $analysis->addResult($completedResult);
        $analysis->addResult($failedResult);

        self::assertSame(1, $analysis->getFailedResultsCount());
    }

    // ==================== Delta Calculation Tests ====================

    #[Test]
    public function setPreviousAnalysis_setsPreviousAnalysis(): void
    {
        $current = new Analysis();
        $previous = new Analysis();

        $current->setPreviousAnalysis($previous);

        self::assertSame($previous, $current->getPreviousAnalysis());
    }

    #[Test]
    public function setScoreDelta_setsScoreDelta(): void
    {
        $analysis = new Analysis();

        $analysis->setScoreDelta(10);

        self::assertSame(10, $analysis->getScoreDelta());
    }

    #[Test]
    public function setIsImproved_setsIsImproved(): void
    {
        $analysis = new Analysis();

        $analysis->setIsImproved(true);

        self::assertTrue($analysis->isImproved());
    }

    #[Test]
    public function calculateDelta_noPreviousAnalysis_doesNothing(): void
    {
        $analysis = new Analysis();
        $analysis->setTotalScore(50);

        $analysis->calculateDelta();

        self::assertNull($analysis->getScoreDelta());
        self::assertFalse($analysis->isImproved());
    }

    #[Test]
    public function calculateDelta_withPreviousAnalysis_calculatesScoreDelta(): void
    {
        $previous = new Analysis();
        $previous->setTotalScore(40);

        $current = new Analysis();
        $current->setTotalScore(60);
        $current->setPreviousAnalysis($previous);

        $current->calculateDelta();

        self::assertSame(20, $current->getScoreDelta());
        self::assertTrue($current->isImproved());
    }

    #[Test]
    public function calculateDelta_negativeImprovement_setsIsImprovedFalse(): void
    {
        $previous = new Analysis();
        $previous->setTotalScore(70);

        $current = new Analysis();
        $current->setTotalScore(50);
        $current->setPreviousAnalysis($previous);

        $current->calculateDelta();

        self::assertSame(-20, $current->getScoreDelta());
        self::assertFalse($current->isImproved());
    }

    #[Test]
    public function getIssueDelta_returnsDefaultStructure(): void
    {
        $analysis = new Analysis();

        $delta = $analysis->getIssueDelta();

        self::assertArrayHasKey('added', $delta);
        self::assertArrayHasKey('removed', $delta);
        self::assertArrayHasKey('unchanged_count', $delta);
        self::assertEmpty($delta['added']);
        self::assertEmpty($delta['removed']);
        self::assertSame(0, $delta['unchanged_count']);
    }

    #[Test]
    public function setIssueDelta_setsIssueDelta(): void
    {
        $analysis = new Analysis();

        $delta = [
            'added' => ['MISSING_SSL'],
            'removed' => ['OLD_ISSUE'],
            'unchanged_count' => 3,
        ];

        $analysis->setIssueDelta($delta);

        self::assertSame($delta, $analysis->getIssueDelta());
    }

    // ==================== Helper Methods ====================

    private function createLead(): Lead
    {
        $user = new User();
        $user->setCode('test-user');
        $user->setName('Test User');

        $lead = new Lead();
        $lead->setUser($user);
        $lead->setUrl('https://example.com');
        $lead->setDomain('example.com');

        return $lead;
    }

    private function createMockResult(IssueCategory $category): AnalysisResult
    {
        $result = new AnalysisResult();
        $result->setCategory($category);
        $result->setStatus(AnalysisStatus::PENDING);

        return $result;
    }

    private function createCompletedResult(IssueCategory $category, int $score): AnalysisResult
    {
        $result = new AnalysisResult();
        $result->setCategory($category);
        $result->setStatus(AnalysisStatus::COMPLETED);
        $result->setScore($score);

        return $result;
    }

    private function createCompletedResultWithIssues(IssueCategory $category, int $issueCount): AnalysisResult
    {
        $result = new AnalysisResult();
        $result->setCategory($category);
        $result->setStatus(AnalysisStatus::COMPLETED);
        $result->setScore(0);

        // Create mock issues
        $issues = [];
        for ($i = 0; $i < $issueCount; $i++) {
            $issues[] = ['code' => 'ISSUE_' . $i, 'evidence' => null];
        }
        $result->setIssues($issues);

        return $result;
    }

    private function createCompletedResultWithCriticalIssues(IssueCategory $category, int $criticalCount): AnalysisResult
    {
        $result = new AnalysisResult();
        $result->setCategory($category);
        $result->setStatus(AnalysisStatus::COMPLETED);
        $result->setScore(0);

        // Create issues with critical severity (old format)
        $issues = [];
        for ($i = 0; $i < $criticalCount; $i++) {
            $issues[] = ['code' => 'CRITICAL_' . $i, 'severity' => 'critical', 'evidence' => null];
        }
        $result->setIssues($issues);

        return $result;
    }
}
