<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Scoring;

use App\Entity\Analysis;
use App\Enum\IssueCategory;
use App\Enum\IssueSeverity;
use App\Enum\LeadStatus;
use App\Service\Analyzer\Issue;
use App\Service\Scoring\WeightedScoringService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WeightedScoringService::class)]
final class WeightedScoringServiceTest extends TestCase
{
    private WeightedScoringService $service;

    protected function setUp(): void
    {
        $this->service = new WeightedScoringService();
    }

    // ==================== calculateScores Tests ====================

    #[Test]
    public function calculateScores_emptyIssues_returnsZeroScores(): void
    {
        $result = $this->service->calculateScores([]);

        self::assertArrayHasKey('categoryScores', $result);
        self::assertArrayHasKey('totalScore', $result);
        self::assertSame(0, $result['totalScore']);
    }

    #[Test]
    public function calculateScores_emptyIssues_initializesAllCategories(): void
    {
        $result = $this->service->calculateScores([]);

        foreach (IssueCategory::cases() as $category) {
            self::assertArrayHasKey($category->value, $result['categoryScores']);
            self::assertSame(0, $result['categoryScores'][$category->value]);
        }
    }

    #[Test]
    public function calculateScores_singleIssue_calculatesCorrectly(): void
    {
        $issue = $this->createIssue(IssueCategory::SECURITY, IssueSeverity::CRITICAL);
        $criticalWeight = IssueSeverity::CRITICAL->getWeight();

        $result = $this->service->calculateScores([$issue]);

        self::assertSame($criticalWeight, $result['categoryScores']['security']);
        self::assertSame($criticalWeight, $result['totalScore']);
    }

    #[Test]
    public function calculateScores_multipleIssuesSameCategory_sumsWeights(): void
    {
        $issues = [
            $this->createIssue(IssueCategory::SECURITY, IssueSeverity::CRITICAL),
            $this->createIssue(IssueCategory::SECURITY, IssueSeverity::RECOMMENDED),
            $this->createIssue(IssueCategory::SECURITY, IssueSeverity::OPTIMIZATION),
        ];

        $expectedWeight = IssueSeverity::CRITICAL->getWeight()
            + IssueSeverity::RECOMMENDED->getWeight()
            + IssueSeverity::OPTIMIZATION->getWeight();

        $result = $this->service->calculateScores($issues);

        self::assertSame($expectedWeight, $result['categoryScores']['security']);
        self::assertSame($expectedWeight, $result['totalScore']);
    }

    #[Test]
    public function calculateScores_multipleCategories_calculatesEachSeparately(): void
    {
        $issues = [
            $this->createIssue(IssueCategory::SECURITY, IssueSeverity::CRITICAL),
            $this->createIssue(IssueCategory::SEO, IssueSeverity::RECOMMENDED),
            $this->createIssue(IssueCategory::PERFORMANCE, IssueSeverity::OPTIMIZATION),
        ];

        $result = $this->service->calculateScores($issues);

        self::assertSame(IssueSeverity::CRITICAL->getWeight(), $result['categoryScores']['security']);
        self::assertSame(IssueSeverity::RECOMMENDED->getWeight(), $result['categoryScores']['seo']);
        self::assertSame(IssueSeverity::OPTIMIZATION->getWeight(), $result['categoryScores']['performance']);

        $expectedTotal = IssueSeverity::CRITICAL->getWeight()
            + IssueSeverity::RECOMMENDED->getWeight()
            + IssueSeverity::OPTIMIZATION->getWeight();

        self::assertSame($expectedTotal, $result['totalScore']);
    }

    #[Test]
    public function calculateScores_allCategories_coversAllCases(): void
    {
        $issues = [];
        foreach (IssueCategory::cases() as $category) {
            $issues[] = $this->createIssue($category, IssueSeverity::RECOMMENDED);
        }

        $result = $this->service->calculateScores($issues);

        foreach (IssueCategory::cases() as $category) {
            self::assertSame(
                IssueSeverity::RECOMMENDED->getWeight(),
                $result['categoryScores'][$category->value],
                "Category {$category->value} should have correct weight",
            );
        }
    }

    #[Test]
    public function calculateScores_negativeWeights_calculatesCorrectly(): void
    {
        // All severity weights are negative (penalties)
        $issues = [
            $this->createIssue(IssueCategory::SECURITY, IssueSeverity::CRITICAL),
        ];

        $result = $this->service->calculateScores($issues);

        // Critical has negative weight
        self::assertLessThan(0, $result['totalScore']);
    }

    // ==================== determineLeadStatus Tests ====================

    #[Test]
    public function determineLeadStatus_criticalIssueAndVeryBadScore_returnsVeryBad(): void
    {
        $analysis = $this->createAnalysisMock(-60, true);

        $status = $this->service->determineLeadStatus($analysis);

        self::assertSame(LeadStatus::VERY_BAD, $status);
    }

    #[Test]
    public function determineLeadStatus_criticalIssueAndModerateScore_returnsBad(): void
    {
        $analysis = $this->createAnalysisMock(-30, true);

        $status = $this->service->determineLeadStatus($analysis);

        self::assertSame(LeadStatus::BAD, $status);
    }

    #[Test]
    public function determineLeadStatus_criticalIssueAndGoodScore_returnsBad(): void
    {
        // Any critical issue = at least BAD
        $analysis = $this->createAnalysisMock(0, true);

        $status = $this->service->determineLeadStatus($analysis);

        self::assertSame(LeadStatus::BAD, $status);
    }

    #[Test]
    public function determineLeadStatus_noCriticalAndVeryBadScore_returnsVeryBad(): void
    {
        $analysis = $this->createAnalysisMock(-60, false);

        $status = $this->service->determineLeadStatus($analysis);

        self::assertSame(LeadStatus::VERY_BAD, $status);
    }

    #[Test]
    public function determineLeadStatus_noCriticalAndBadScore_returnsBad(): void
    {
        $analysis = $this->createAnalysisMock(-30, false);

        $status = $this->service->determineLeadStatus($analysis);

        self::assertSame(LeadStatus::BAD, $status);
    }

    #[Test]
    public function determineLeadStatus_noCriticalAndMiddleScore_returnsMiddle(): void
    {
        $analysis = $this->createAnalysisMock(-10, false);

        $status = $this->service->determineLeadStatus($analysis);

        self::assertSame(LeadStatus::MIDDLE, $status);
    }

    #[Test]
    public function determineLeadStatus_noCriticalAndGoodScore_returnsQualityGood(): void
    {
        $analysis = $this->createAnalysisMock(-3, false);

        $status = $this->service->determineLeadStatus($analysis);

        self::assertSame(LeadStatus::QUALITY_GOOD, $status);
    }

    #[Test]
    public function determineLeadStatus_noCriticalAndZeroScore_returnsSuper(): void
    {
        $analysis = $this->createAnalysisMock(0, false);

        $status = $this->service->determineLeadStatus($analysis);

        self::assertSame(LeadStatus::SUPER, $status);
    }

    #[Test]
    public function determineLeadStatus_noCriticalAndPositiveScore_returnsSuper(): void
    {
        $analysis = $this->createAnalysisMock(10, false);

        $status = $this->service->determineLeadStatus($analysis);

        self::assertSame(LeadStatus::SUPER, $status);
    }

    // ==================== Threshold Boundary Tests ====================

    #[Test]
    #[DataProvider('thresholdBoundaryProvider')]
    public function determineLeadStatus_thresholdBoundaries(
        int $score,
        bool $hasCritical,
        LeadStatus $expectedStatus,
    ): void {
        $analysis = $this->createAnalysisMock($score, $hasCritical);

        $status = $this->service->determineLeadStatus($analysis);

        self::assertSame($expectedStatus, $status);
    }

    /**
     * @return iterable<string, array{int, bool, LeadStatus}>
     */
    public static function thresholdBoundaryProvider(): iterable
    {
        // VERY_BAD_THRESHOLD = -50
        yield 'score -51 no critical' => [-51, false, LeadStatus::VERY_BAD];
        yield 'score -50 no critical' => [-50, false, LeadStatus::BAD];
        yield 'score -49 no critical' => [-49, false, LeadStatus::BAD];

        // BAD_THRESHOLD = -20
        yield 'score -21 no critical' => [-21, false, LeadStatus::BAD];
        yield 'score -20 no critical' => [-20, false, LeadStatus::MIDDLE];
        yield 'score -19 no critical' => [-19, false, LeadStatus::MIDDLE];

        // MIDDLE_THRESHOLD = -5
        yield 'score -6 no critical' => [-6, false, LeadStatus::MIDDLE];
        yield 'score -5 no critical' => [-5, false, LeadStatus::QUALITY_GOOD];
        yield 'score -4 no critical' => [-4, false, LeadStatus::QUALITY_GOOD];

        // GOOD_THRESHOLD = 0
        yield 'score -1 no critical' => [-1, false, LeadStatus::QUALITY_GOOD];
        yield 'score 0 no critical' => [0, false, LeadStatus::SUPER];
        yield 'score 1 no critical' => [1, false, LeadStatus::SUPER];

        // With critical issues - always at least BAD
        yield 'score 10 with critical' => [10, true, LeadStatus::BAD];
        yield 'score 0 with critical' => [0, true, LeadStatus::BAD];
        yield 'score -49 with critical' => [-49, true, LeadStatus::BAD];
        yield 'score -51 with critical' => [-51, true, LeadStatus::VERY_BAD];
    }

    // ==================== Integration-style Tests ====================

    #[Test]
    public function calculateScoresAndDetermineStatus_fullWorkflow(): void
    {
        // Create a set of issues
        $issues = [
            $this->createIssue(IssueCategory::SECURITY, IssueSeverity::CRITICAL),  // -20
            $this->createIssue(IssueCategory::SEO, IssueSeverity::RECOMMENDED),         // -5
            $this->createIssue(IssueCategory::HTTP, IssueSeverity::OPTIMIZATION),  // -1
        ];

        // Calculate scores
        $scores = $this->service->calculateScores($issues);

        // Verify total
        $expectedTotal = IssueSeverity::CRITICAL->getWeight()
            + IssueSeverity::RECOMMENDED->getWeight()
            + IssueSeverity::OPTIMIZATION->getWeight();

        self::assertSame($expectedTotal, $scores['totalScore']);

        // Create analysis mock with these scores
        $analysis = $this->createAnalysisMock($scores['totalScore'], true); // has critical

        // Should be BAD or VERY_BAD depending on score
        $status = $this->service->determineLeadStatus($analysis);

        self::assertTrue(
            $status === LeadStatus::BAD || $status === LeadStatus::VERY_BAD,
            'Status should be BAD or VERY_BAD due to critical issue',
        );
    }

    // ==================== Helper Methods ====================

    private function createIssue(IssueCategory $category, IssueSeverity $severity): Issue
    {
        return new Issue(
            category: $category,
            severity: $severity,
            code: 'TEST_' . strtoupper($category->value),
            title: 'Test Issue',
            description: 'Test description',
        );
    }

    private function createAnalysisMock(int $totalScore, bool $hasCritical): Analysis
    {
        $analysis = $this->createMock(Analysis::class);
        $analysis->method('getTotalScore')->willReturn($totalScore);
        $analysis->method('hasCriticalIssues')->willReturn($hasCritical);

        return $analysis;
    }
}
