<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Benchmark;

use App\Entity\Analysis;
use App\Entity\IndustryBenchmark;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use App\Repository\AnalysisRepository;
use App\Repository\IndustryBenchmarkRepository;
use App\Service\Benchmark\BenchmarkCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(BenchmarkCalculator::class)]
final class BenchmarkCalculatorTest extends TestCase
{
    private BenchmarkCalculator $calculator;
    private EntityManagerInterface $em;
    private AnalysisRepository $analysisRepository;
    private IndustryBenchmarkRepository $benchmarkRepository;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->analysisRepository = $this->createMock(AnalysisRepository::class);
        $this->benchmarkRepository = $this->createMock(IndustryBenchmarkRepository::class);

        $this->calculator = new BenchmarkCalculator(
            $this->em,
            $this->analysisRepository,
            $this->benchmarkRepository,
            new NullLogger(),
        );
    }

    // ==================== getCurrentPeriodStart Tests ====================

    #[Test]
    public function getCurrentPeriodStart_returnsMondayOfCurrentWeek(): void
    {
        $result = $this->calculator->getCurrentPeriodStart();

        // Should be a Monday at midnight
        self::assertSame('Monday', $result->format('l'));
        self::assertSame('00:00:00', $result->format('H:i:s'));
    }

    #[Test]
    public function getCurrentPeriodStart_returnsImmutableDate(): void
    {
        $result = $this->calculator->getCurrentPeriodStart();

        self::assertInstanceOf(\DateTimeImmutable::class, $result);
    }

    // ==================== calculateAverage Tests (via reflection) ====================

    #[Test]
    public function calculateAverage_emptyArray_returnsZero(): void
    {
        $result = $this->invokePrivateMethod('calculateAverage', [[]]);

        self::assertSame(0.0, $result);
    }

    #[Test]
    public function calculateAverage_singleValue_returnsThatValue(): void
    {
        $result = $this->invokePrivateMethod('calculateAverage', [[10.0]]);

        self::assertSame(10.0, $result);
    }

    #[Test]
    public function calculateAverage_multipleValues_returnsCorrectAverage(): void
    {
        $result = $this->invokePrivateMethod('calculateAverage', [[10, 20, 30]]);

        self::assertSame(20.0, $result);
    }

    #[Test]
    public function calculateAverage_negativeValues_handlesCorrectly(): void
    {
        $result = $this->invokePrivateMethod('calculateAverage', [[-10, -20, -30]]);

        self::assertSame(-20.0, $result);
    }

    #[Test]
    public function calculateAverage_mixedValues_handlesCorrectly(): void
    {
        $result = $this->invokePrivateMethod('calculateAverage', [[-10, 0, 10]]);

        self::assertSame(0.0, $result);
    }

    // ==================== calculateMedian Tests (via reflection) ====================

    #[Test]
    public function calculateMedian_emptyArray_returnsZero(): void
    {
        $result = $this->invokePrivateMethod('calculateMedian', [[]]);

        self::assertSame(0.0, $result);
    }

    #[Test]
    public function calculateMedian_singleValue_returnsThatValue(): void
    {
        $result = $this->invokePrivateMethod('calculateMedian', [[15.0]]);

        self::assertSame(15.0, $result);
    }

    #[Test]
    public function calculateMedian_oddCount_returnsMiddleValue(): void
    {
        $result = $this->invokePrivateMethod('calculateMedian', [[1, 5, 9]]);

        self::assertSame(5.0, $result);
    }

    #[Test]
    public function calculateMedian_evenCount_returnsAverageOfTwoMiddle(): void
    {
        $result = $this->invokePrivateMethod('calculateMedian', [[1, 5, 9, 13]]);

        self::assertSame(7.0, $result); // (5 + 9) / 2
    }

    #[Test]
    public function calculateMedian_unsortedArray_sortsAndReturnsCorrect(): void
    {
        $result = $this->invokePrivateMethod('calculateMedian', [[9, 1, 5]]);

        self::assertSame(5.0, $result);
    }

    #[Test]
    public function calculateMedian_negativeValues_handlesCorrectly(): void
    {
        $result = $this->invokePrivateMethod('calculateMedian', [[-30, -10, -20]]);

        self::assertSame(-20.0, $result);
    }

    // ==================== calculatePercentiles Tests (via reflection) ====================

    #[Test]
    public function calculatePercentiles_emptyArray_returnsZeros(): void
    {
        $result = $this->invokePrivateMethod('calculatePercentiles', [[]]);

        self::assertSame(['p10' => 0, 'p25' => 0, 'p50' => 0, 'p75' => 0, 'p90' => 0], $result);
    }

    #[Test]
    public function calculatePercentiles_returnsAllKeys(): void
    {
        $values = range(1, 100);
        $result = $this->invokePrivateMethod('calculatePercentiles', [$values]);

        self::assertArrayHasKey('p10', $result);
        self::assertArrayHasKey('p25', $result);
        self::assertArrayHasKey('p50', $result);
        self::assertArrayHasKey('p75', $result);
        self::assertArrayHasKey('p90', $result);
    }

    #[Test]
    public function calculatePercentiles_correctOrder(): void
    {
        $values = range(1, 100);
        $result = $this->invokePrivateMethod('calculatePercentiles', [$values]);

        // p10 <= p25 <= p50 <= p75 <= p90
        self::assertTrue($result['p10'] <= $result['p25'], 'p10 should be <= p25');
        self::assertTrue($result['p25'] <= $result['p50'], 'p25 should be <= p50');
        self::assertTrue($result['p50'] <= $result['p75'], 'p50 should be <= p75');
        self::assertTrue($result['p75'] <= $result['p90'], 'p75 should be <= p90');
    }

    #[Test]
    public function calculatePercentiles_p50EqualsMedian(): void
    {
        $values = [10, 20, 30, 40, 50];
        $percentiles = $this->invokePrivateMethod('calculatePercentiles', [$values]);
        $median = $this->invokePrivateMethod('calculateMedian', [$values]);

        self::assertEquals($median, $percentiles['p50']);
    }

    // ==================== percentile Tests (via reflection) ====================

    #[Test]
    #[DataProvider('percentileProvider')]
    public function percentile_calculatesCorrectly(array $values, int $percentile, float $expected): void
    {
        sort($values);
        $result = $this->invokePrivateMethod('percentile', [$values, $percentile]);

        self::assertEquals($expected, $result, '', 0.01);
    }

    /**
     * @return iterable<string, array{array<int>, int, float}>
     */
    public static function percentileProvider(): iterable
    {
        yield '10 values, p50' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 50, 5.5];
        yield '10 values, p10' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 10, 1.9];
        yield '10 values, p90' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 90, 9.1];
        yield '5 values, p25' => [[1, 2, 3, 4, 5], 25, 2.0];
        yield '5 values, p75' => [[1, 2, 3, 4, 5], 75, 4.0];
    }

    // ==================== compareWithBenchmark Tests ====================

    #[Test]
    public function compareWithBenchmark_noIndustry_returnsUnknown(): void
    {
        $analysis = $this->createMock(Analysis::class);
        $analysis->method('getIndustry')->willReturn(null);

        $result = $this->calculator->compareWithBenchmark($analysis);

        self::assertSame('unknown', $result['ranking']);
        self::assertNull($result['percentile']);
        self::assertSame([], $result['comparison']);
    }

    #[Test]
    public function compareWithBenchmark_noBenchmark_returnsNoBenchmark(): void
    {
        $analysis = $this->createMock(Analysis::class);
        $analysis->method('getIndustry')->willReturn(Industry::ESHOP);

        $this->benchmarkRepository->method('findLatestByIndustry')->willReturn(null);

        $result = $this->calculator->compareWithBenchmark($analysis);

        self::assertSame('no_benchmark', $result['ranking']);
        self::assertNull($result['percentile']);
        self::assertSame([], $result['comparison']);
    }

    #[Test]
    public function compareWithBenchmark_withBenchmark_returnsComparison(): void
    {
        $analysis = $this->createMock(Analysis::class);
        $analysis->method('getIndustry')->willReturn(Industry::ESHOP);
        $analysis->method('getTotalScore')->willReturn(-15);
        $analysis->method('getIssueCount')->willReturn(8);

        $benchmark = $this->createMock(IndustryBenchmark::class);
        $benchmark->method('getPercentileRanking')->willReturn('above_average');
        $benchmark->method('calculatePercentile')->willReturn(60.0);
        $benchmark->method('getAvgScore')->willReturn(-20.0);
        $benchmark->method('getMedianScore')->willReturn(-18.0);
        $benchmark->method('getAvgIssueCount')->willReturn(10.0);
        $benchmark->method('getSampleSize')->willReturn(100);

        $this->benchmarkRepository->method('findLatestByIndustry')->willReturn($benchmark);

        $result = $this->calculator->compareWithBenchmark($analysis);

        self::assertSame('above_average', $result['ranking']);
        self::assertSame(60.0, $result['percentile']);
        self::assertArrayHasKey('score', $result['comparison']);
        self::assertArrayHasKey('industryAvg', $result['comparison']);
        self::assertArrayHasKey('industryMedian', $result['comparison']);
        self::assertArrayHasKey('diffFromAvg', $result['comparison']);
        self::assertArrayHasKey('issueCount', $result['comparison']);
        self::assertArrayHasKey('industryAvgIssues', $result['comparison']);
        self::assertArrayHasKey('sampleSize', $result['comparison']);

        self::assertSame(-15, $result['comparison']['score']);
        self::assertSame(-20.0, $result['comparison']['industryAvg']);
        self::assertSame(5.0, $result['comparison']['diffFromAvg']); // -15 - (-20) = 5
    }


    // ==================== Helper Methods ====================

    /**
     * Invoke a private method on the calculator.
     *
     * @param array<mixed> $args
     */
    private function invokePrivateMethod(string $methodName, array $args): mixed
    {
        $reflection = new \ReflectionClass($this->calculator);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->calculator, $args);
    }
}
