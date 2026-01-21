<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryBenchmark;
use App\Enum\Industry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryBenchmark::class)]
final class IndustryBenchmarkTest extends TestCase
{
    // ==================== Basic Getter/Setter Tests ====================

    #[Test]
    public function setAndGetIndustry_works(): void
    {
        $benchmark = new IndustryBenchmark();
        $benchmark->setIndustry(Industry::ESHOP);

        self::assertSame(Industry::ESHOP, $benchmark->getIndustry());
    }

    #[Test]
    public function setAndGetPeriodStart_works(): void
    {
        $benchmark = new IndustryBenchmark();
        $date = new \DateTimeImmutable('2026-01-20');
        $benchmark->setPeriodStart($date);

        self::assertSame($date, $benchmark->getPeriodStart());
    }

    #[Test]
    public function setAndGetAvgScore_works(): void
    {
        $benchmark = new IndustryBenchmark();
        $benchmark->setAvgScore(-15.5);

        self::assertSame(-15.5, $benchmark->getAvgScore());
    }

    #[Test]
    public function setAndGetMedianScore_works(): void
    {
        $benchmark = new IndustryBenchmark();
        $benchmark->setMedianScore(-10.0);

        self::assertSame(-10.0, $benchmark->getMedianScore());
    }

    #[Test]
    public function setAndGetPercentiles_works(): void
    {
        $benchmark = new IndustryBenchmark();
        $percentiles = ['p10' => -50.0, 'p25' => -30.0, 'p50' => -15.0, 'p75' => -5.0, 'p90' => 0.0];
        $benchmark->setPercentiles($percentiles);

        self::assertSame($percentiles, $benchmark->getPercentiles());
    }

    #[Test]
    public function setAndGetAvgCategoryScores_works(): void
    {
        $benchmark = new IndustryBenchmark();
        $scores = ['security' => -5.0, 'seo' => -3.0, 'performance' => -2.0];
        $benchmark->setAvgCategoryScores($scores);

        self::assertSame($scores, $benchmark->getAvgCategoryScores());
    }

    #[Test]
    public function setAndGetTopIssues_works(): void
    {
        $benchmark = new IndustryBenchmark();
        $issues = [
            ['code' => 'SSL_MISSING', 'count' => 50, 'percentage' => 25.0],
            ['code' => 'NO_DESCRIPTION', 'count' => 40, 'percentage' => 20.0],
        ];
        $benchmark->setTopIssues($issues);

        self::assertSame($issues, $benchmark->getTopIssues());
    }

    #[Test]
    public function setAndGetSampleSize_works(): void
    {
        $benchmark = new IndustryBenchmark();
        $benchmark->setSampleSize(200);

        self::assertSame(200, $benchmark->getSampleSize());
    }

    #[Test]
    public function setAndGetAvgIssueCount_works(): void
    {
        $benchmark = new IndustryBenchmark();
        $benchmark->setAvgIssueCount(12.5);

        self::assertSame(12.5, $benchmark->getAvgIssueCount());
    }

    #[Test]
    public function setAndGetAvgCriticalIssueCount_works(): void
    {
        $benchmark = new IndustryBenchmark();
        $benchmark->setAvgCriticalIssueCount(1.2);

        self::assertSame(1.2, $benchmark->getAvgCriticalIssueCount());
    }

    // ==================== getPercentileRanking Tests ====================

    #[Test]
    public function getPercentileRanking_emptyPercentiles_returnsUnknown(): void
    {
        $benchmark = new IndustryBenchmark();

        self::assertSame('unknown', $benchmark->getPercentileRanking(-10));
    }

    #[Test]
    #[DataProvider('percentileRankingProvider')]
    public function getPercentileRanking_returnsCorrectRanking(
        int $score,
        string $expectedRanking,
    ): void {
        $benchmark = new IndustryBenchmark();
        $benchmark->setPercentiles([
            'p10' => -50.0,
            'p25' => -30.0,
            'p50' => -15.0,
            'p75' => -5.0,
            'p90' => 0.0,
        ]);

        self::assertSame($expectedRanking, $benchmark->getPercentileRanking($score));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function percentileRankingProvider(): iterable
    {
        // Top 10% (score >= p90)
        yield 'score 10 (above p90)' => [10, 'top10'];
        yield 'score 0 (equals p90)' => [0, 'top10'];

        // Top 25% (score >= p75)
        yield 'score -1 (between p75 and p90)' => [-1, 'top25'];
        yield 'score -5 (equals p75)' => [-5, 'top25'];

        // Above average (score >= p50)
        yield 'score -10 (between p50 and p75)' => [-10, 'above_average'];
        yield 'score -15 (equals p50)' => [-15, 'above_average'];

        // Below average (score >= p25)
        yield 'score -20 (between p25 and p50)' => [-20, 'below_average'];
        yield 'score -30 (equals p25)' => [-30, 'below_average'];

        // Bottom 25% (score < p25)
        yield 'score -40 (between p10 and p25)' => [-40, 'bottom25'];
        yield 'score -60 (below p10)' => [-60, 'bottom25'];
    }

    // ==================== calculatePercentile Tests ====================

    #[Test]
    public function calculatePercentile_emptyPercentiles_returnsNull(): void
    {
        $benchmark = new IndustryBenchmark();

        self::assertNull($benchmark->calculatePercentile(-10));
    }

    #[Test]
    public function calculatePercentile_atP90_returnsAbove90(): void
    {
        $benchmark = $this->createBenchmarkWithPercentiles();

        $result = $benchmark->calculatePercentile(0); // At p90

        self::assertGreaterThanOrEqual(90, $result);
    }

    #[Test]
    public function calculatePercentile_aboveP90_returns95Plus(): void
    {
        $benchmark = $this->createBenchmarkWithPercentiles();

        $result = $benchmark->calculatePercentile(10); // Above p90

        self::assertGreaterThanOrEqual(90, $result);
    }

    #[Test]
    public function calculatePercentile_atP50_returnsAround50(): void
    {
        $benchmark = $this->createBenchmarkWithPercentiles();

        $result = $benchmark->calculatePercentile(-15); // At p50

        self::assertGreaterThanOrEqual(50, $result);
        self::assertLessThanOrEqual(75, $result);
    }

    #[Test]
    public function calculatePercentile_belowP10_returnsUnder10(): void
    {
        $benchmark = $this->createBenchmarkWithPercentiles();

        $result = $benchmark->calculatePercentile(-60); // Below p10

        self::assertLessThanOrEqual(10, $result);
    }

    #[Test]
    public function calculatePercentile_returnsFloat(): void
    {
        $benchmark = $this->createBenchmarkWithPercentiles();

        $result = $benchmark->calculatePercentile(-25);

        self::assertIsFloat($result);
        self::assertGreaterThanOrEqual(0, $result);
        self::assertLessThanOrEqual(100, $result);
    }

    // ==================== Default Values Tests ====================

    #[Test]
    public function newBenchmark_hasDefaultValues(): void
    {
        $benchmark = new IndustryBenchmark();

        self::assertNull($benchmark->getId());
        self::assertNull($benchmark->getUser());
        self::assertNull($benchmark->getIndustry());
        self::assertNull($benchmark->getPeriodStart());
        self::assertSame(0.0, $benchmark->getAvgScore());
        self::assertSame(0.0, $benchmark->getMedianScore());
        self::assertSame([], $benchmark->getPercentiles());
        self::assertSame([], $benchmark->getAvgCategoryScores());
        self::assertSame([], $benchmark->getTopIssues());
        self::assertSame(0, $benchmark->getSampleSize());
        self::assertSame(0.0, $benchmark->getAvgIssueCount());
        self::assertSame(0.0, $benchmark->getAvgCriticalIssueCount());
        self::assertNull($benchmark->getCreatedAt());
    }

    // ==================== Fluent Interface Tests ====================

    #[Test]
    public function setters_returnSelf(): void
    {
        $benchmark = new IndustryBenchmark();

        self::assertSame($benchmark, $benchmark->setIndustry(Industry::ESHOP));
        self::assertSame($benchmark, $benchmark->setPeriodStart(new \DateTimeImmutable()));
        self::assertSame($benchmark, $benchmark->setAvgScore(-10.0));
        self::assertSame($benchmark, $benchmark->setMedianScore(-8.0));
        self::assertSame($benchmark, $benchmark->setPercentiles([]));
        self::assertSame($benchmark, $benchmark->setAvgCategoryScores([]));
        self::assertSame($benchmark, $benchmark->setTopIssues([]));
        self::assertSame($benchmark, $benchmark->setSampleSize(100));
        self::assertSame($benchmark, $benchmark->setAvgIssueCount(5.0));
        self::assertSame($benchmark, $benchmark->setAvgCriticalIssueCount(0.5));
    }

    // ==================== Helper Methods ====================

    private function createBenchmarkWithPercentiles(): IndustryBenchmark
    {
        $benchmark = new IndustryBenchmark();
        $benchmark->setPercentiles([
            'p10' => -50.0,
            'p25' => -30.0,
            'p50' => -15.0,
            'p75' => -5.0,
            'p90' => 0.0,
        ]);

        return $benchmark;
    }
}
