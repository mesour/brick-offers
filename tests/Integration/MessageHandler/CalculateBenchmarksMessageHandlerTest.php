<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\IndustryBenchmark;
use App\Enum\Industry;
use App\Message\CalculateBenchmarksMessage;
use App\MessageHandler\CalculateBenchmarksMessageHandler;
use App\Service\Benchmark\BenchmarkCalculator;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * Integration tests for CalculateBenchmarksMessageHandler.
 */
final class CalculateBenchmarksMessageHandlerTest extends MessageHandlerTestCase
{
    // ==================== Single Industry Cases ====================

    #[Test]
    public function invoke_withSpecificIndustry_calculatesForThatIndustry(): void
    {
        $benchmark = $this->createMockBenchmark(Industry::REAL_ESTATE);

        $benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $benchmarkCalculator->expects($this->once())
            ->method('calculateForIndustry')
            ->with(Industry::REAL_ESTATE)
            ->willReturn($benchmark);

        $handler = new CalculateBenchmarksMessageHandler(
            $benchmarkCalculator,
            new NullLogger(),
        );

        $message = new CalculateBenchmarksMessage(Industry::REAL_ESTATE);
        $handler($message);
    }

    #[Test]
    public function invoke_industryWithNoData_handlesGracefully(): void
    {
        $benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $benchmarkCalculator->method('calculateForIndustry')
            ->willReturn(null);

        $handler = new CalculateBenchmarksMessageHandler(
            $benchmarkCalculator,
            new NullLogger(),
        );

        $message = new CalculateBenchmarksMessage(Industry::ESHOP);

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== All Industries Cases ====================

    #[Test]
    public function invoke_withoutIndustry_calculatesForAll(): void
    {
        $stats = [
            'created' => 5,
            'updated' => 3,
            'skipped' => 2,
        ];

        $benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $benchmarkCalculator->expects($this->once())
            ->method('calculateForAllIndustries')
            ->willReturn($stats);

        $handler = new CalculateBenchmarksMessageHandler(
            $benchmarkCalculator,
            new NullLogger(),
        );

        $message = new CalculateBenchmarksMessage();
        $handler($message);
    }

    #[Test]
    public function invoke_allIndustriesWithNoData_completesWithZeroStats(): void
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 10,
        ];

        $benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $benchmarkCalculator->method('calculateForAllIndustries')
            ->willReturn($stats);

        $handler = new CalculateBenchmarksMessageHandler(
            $benchmarkCalculator,
            new NullLogger(),
        );

        $message = new CalculateBenchmarksMessage();

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== Recalculate All Cases ====================

    #[Test]
    public function invoke_withRecalculateAllFlag_passesToCalculator(): void
    {
        $benchmark = $this->createMockBenchmark(Industry::WEBDESIGN);

        $benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $benchmarkCalculator->expects($this->once())
            ->method('calculateForIndustry')
            ->with(Industry::WEBDESIGN)
            ->willReturn($benchmark);

        $handler = new CalculateBenchmarksMessageHandler(
            $benchmarkCalculator,
            new NullLogger(),
        );

        $message = new CalculateBenchmarksMessage(Industry::WEBDESIGN, recalculateAll: true);
        $handler($message);
    }

    // ==================== Failure Cases ====================

    #[Test]
    public function invoke_calculatorThrows_rethrowsException(): void
    {
        $benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $benchmarkCalculator->method('calculateForIndustry')
            ->willThrowException(new \RuntimeException('Database connection failed'));

        $handler = new CalculateBenchmarksMessageHandler(
            $benchmarkCalculator,
            new NullLogger(),
        );

        $message = new CalculateBenchmarksMessage(Industry::REAL_ESTATE);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database connection failed');

        $handler($message);
    }

    #[Test]
    public function invoke_calculateAllThrows_rethrowsException(): void
    {
        $benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $benchmarkCalculator->method('calculateForAllIndustries')
            ->willThrowException(new \RuntimeException('Memory limit exceeded'));

        $handler = new CalculateBenchmarksMessageHandler(
            $benchmarkCalculator,
            new NullLogger(),
        );

        $message = new CalculateBenchmarksMessage();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Memory limit exceeded');

        $handler($message);
    }

    // ==================== Different Industry Cases ====================

    #[Test]
    public function invoke_realEstateIndustry_callsWithCorrectEnum(): void
    {
        $benchmark = $this->createMockBenchmark(Industry::REAL_ESTATE);

        $benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $benchmarkCalculator->expects($this->once())
            ->method('calculateForIndustry')
            ->with($this->callback(fn ($arg) => $arg === Industry::REAL_ESTATE))
            ->willReturn($benchmark);

        $handler = new CalculateBenchmarksMessageHandler(
            $benchmarkCalculator,
            new NullLogger(),
        );

        $message = new CalculateBenchmarksMessage(Industry::REAL_ESTATE);
        $handler($message);
    }

    #[Test]
    public function invoke_eshopIndustry_callsWithCorrectEnum(): void
    {
        $benchmark = $this->createMockBenchmark(Industry::ESHOP);

        $benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $benchmarkCalculator->expects($this->once())
            ->method('calculateForIndustry')
            ->with($this->callback(fn ($arg) => $arg === Industry::ESHOP))
            ->willReturn($benchmark);

        $handler = new CalculateBenchmarksMessageHandler(
            $benchmarkCalculator,
            new NullLogger(),
        );

        $message = new CalculateBenchmarksMessage(Industry::ESHOP);
        $handler($message);
    }

    #[Test]
    public function invoke_webdesignIndustry_callsWithCorrectEnum(): void
    {
        $benchmark = $this->createMockBenchmark(Industry::WEBDESIGN);

        $benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $benchmarkCalculator->expects($this->once())
            ->method('calculateForIndustry')
            ->with($this->callback(fn ($arg) => $arg === Industry::WEBDESIGN))
            ->willReturn($benchmark);

        $handler = new CalculateBenchmarksMessageHandler(
            $benchmarkCalculator,
            new NullLogger(),
        );

        $message = new CalculateBenchmarksMessage(Industry::WEBDESIGN);
        $handler($message);
    }

    // ==================== Helper Methods ====================

    private function createMockBenchmark(Industry $industry): IndustryBenchmark
    {
        $benchmark = $this->createMock(IndustryBenchmark::class);
        $benchmark->method('getIndustry')->willReturn($industry);
        $benchmark->method('getSampleSize')->willReturn(100);
        $benchmark->method('getAvgScore')->willReturn(72.5);

        return $benchmark;
    }
}
