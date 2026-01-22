<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\IndustryBenchmark;
use App\Enum\Industry;
use App\Message\CalculateBenchmarksMessage;
use App\MessageHandler\CalculateBenchmarksMessageHandler;
use App\Service\Benchmark\BenchmarkCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CalculateBenchmarksMessageHandlerTest extends TestCase
{
    private BenchmarkCalculator&MockObject $benchmarkCalculator;
    private LoggerInterface&MockObject $logger;
    private CalculateBenchmarksMessageHandler $handler;

    protected function setUp(): void
    {
        $this->benchmarkCalculator = $this->createMock(BenchmarkCalculator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new CalculateBenchmarksMessageHandler(
            $this->benchmarkCalculator,
            $this->logger,
        );
    }

    public function testInvoke_withSpecificIndustry_calculatesForIndustry(): void
    {
        $industry = Industry::WEBDESIGN;
        $message = new CalculateBenchmarksMessage($industry);

        $benchmark = $this->createMock(IndustryBenchmark::class);
        $benchmark->method('getSampleSize')->willReturn(100);
        $benchmark->method('getAvgScore')->willReturn(75.5);

        $this->benchmarkCalculator->expects(self::once())
            ->method('calculateForIndustry')
            ->with($industry)
            ->willReturn($benchmark);

        $this->logger->expects(self::exactly(2))
            ->method('info');

        ($this->handler)($message);
    }

    public function testInvoke_withSpecificIndustry_noData_logsWarning(): void
    {
        $industry = Industry::WEBDESIGN;
        $message = new CalculateBenchmarksMessage($industry);

        $this->benchmarkCalculator->expects(self::once())
            ->method('calculateForIndustry')
            ->with($industry)
            ->willReturn(null);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('No data available for benchmark', self::anything());

        ($this->handler)($message);
    }

    public function testInvoke_withoutIndustry_calculatesForAll(): void
    {
        $message = new CalculateBenchmarksMessage();

        $stats = ['created' => 5, 'updated' => 10, 'skipped' => 3];

        $this->benchmarkCalculator->expects(self::once())
            ->method('calculateForAllIndustries')
            ->willReturn($stats);

        $this->logger->expects(self::exactly(2))
            ->method('info');

        ($this->handler)($message);
    }

    public function testInvoke_exception_logsErrorAndRethrows(): void
    {
        $message = new CalculateBenchmarksMessage();

        $exception = new \RuntimeException('Database error');

        $this->benchmarkCalculator->expects(self::once())
            ->method('calculateForAllIndustries')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Benchmark calculation failed', self::anything());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database error');

        ($this->handler)($message);
    }
}
