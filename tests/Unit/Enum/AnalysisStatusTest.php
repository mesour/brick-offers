<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\AnalysisStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalysisStatus::class)]
final class AnalysisStatusTest extends TestCase
{
    // ==================== Basic Enum Tests ====================

    #[Test]
    public function allCasesExist(): void
    {
        $cases = AnalysisStatus::cases();

        self::assertCount(4, $cases);
        self::assertContains(AnalysisStatus::PENDING, $cases);
        self::assertContains(AnalysisStatus::RUNNING, $cases);
        self::assertContains(AnalysisStatus::COMPLETED, $cases);
        self::assertContains(AnalysisStatus::FAILED, $cases);
    }

    #[Test]
    #[DataProvider('statusValuesProvider')]
    public function statusHasExpectedValue(AnalysisStatus $status, string $expectedValue): void
    {
        self::assertSame($expectedValue, $status->value);
    }

    /**
     * @return iterable<string, array{AnalysisStatus, string}>
     */
    public static function statusValuesProvider(): iterable
    {
        yield 'pending' => [AnalysisStatus::PENDING, 'pending'];
        yield 'running' => [AnalysisStatus::RUNNING, 'running'];
        yield 'completed' => [AnalysisStatus::COMPLETED, 'completed'];
        yield 'failed' => [AnalysisStatus::FAILED, 'failed'];
    }

    // ==================== tryFrom Tests ====================

    #[Test]
    #[DataProvider('validStatusStringsProvider')]
    public function tryFrom_validString_returnsStatus(string $value, AnalysisStatus $expected): void
    {
        self::assertSame($expected, AnalysisStatus::tryFrom($value));
    }

    /**
     * @return iterable<string, array{string, AnalysisStatus}>
     */
    public static function validStatusStringsProvider(): iterable
    {
        yield 'pending' => ['pending', AnalysisStatus::PENDING];
        yield 'running' => ['running', AnalysisStatus::RUNNING];
        yield 'completed' => ['completed', AnalysisStatus::COMPLETED];
        yield 'failed' => ['failed', AnalysisStatus::FAILED];
    }

    #[Test]
    public function tryFrom_invalidString_returnsNull(): void
    {
        self::assertNull(AnalysisStatus::tryFrom('invalid'));
        self::assertNull(AnalysisStatus::tryFrom(''));
        self::assertNull(AnalysisStatus::tryFrom('PENDING')); // Case sensitive
    }

    // ==================== from Tests ====================

    #[Test]
    public function from_validString_returnsStatus(): void
    {
        self::assertSame(AnalysisStatus::PENDING, AnalysisStatus::from('pending'));
        self::assertSame(AnalysisStatus::RUNNING, AnalysisStatus::from('running'));
        self::assertSame(AnalysisStatus::COMPLETED, AnalysisStatus::from('completed'));
        self::assertSame(AnalysisStatus::FAILED, AnalysisStatus::from('failed'));
    }

    #[Test]
    public function from_invalidString_throwsException(): void
    {
        $this->expectException(\ValueError::class);
        AnalysisStatus::from('invalid');
    }

    // ==================== Workflow State Tests ====================

    #[Test]
    public function pendingIsInitialState(): void
    {
        // PENDING is the initial state for new analyses
        $status = AnalysisStatus::PENDING;
        self::assertSame('pending', $status->value);
    }

    #[Test]
    public function completedAndFailedAreFinalStates(): void
    {
        // These are the only terminal states
        $terminalStates = [AnalysisStatus::COMPLETED, AnalysisStatus::FAILED];

        foreach ($terminalStates as $state) {
            self::assertContains($state, [AnalysisStatus::COMPLETED, AnalysisStatus::FAILED]);
        }
    }

    #[Test]
    public function runningIsIntermediateState(): void
    {
        // RUNNING is an intermediate state between PENDING and COMPLETED/FAILED
        $status = AnalysisStatus::RUNNING;
        self::assertSame('running', $status->value);
    }
}
