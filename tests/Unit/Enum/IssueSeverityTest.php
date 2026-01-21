<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\IssueSeverity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueSeverity::class)]
final class IssueSeverityTest extends TestCase
{
    // ==================== Basic Enum Tests ====================

    #[Test]
    public function allCasesExist(): void
    {
        $cases = IssueSeverity::cases();

        self::assertCount(3, $cases);
        self::assertContains(IssueSeverity::CRITICAL, $cases);
        self::assertContains(IssueSeverity::RECOMMENDED, $cases);
        self::assertContains(IssueSeverity::OPTIMIZATION, $cases);
    }

    #[Test]
    #[DataProvider('severityValuesProvider')]
    public function severityHasExpectedValue(IssueSeverity $severity, string $expectedValue): void
    {
        self::assertSame($expectedValue, $severity->value);
    }

    /**
     * @return iterable<string, array{IssueSeverity, string}>
     */
    public static function severityValuesProvider(): iterable
    {
        yield 'critical' => [IssueSeverity::CRITICAL, 'critical'];
        yield 'recommended' => [IssueSeverity::RECOMMENDED, 'recommended'];
        yield 'optimization' => [IssueSeverity::OPTIMIZATION, 'optimization'];
    }

    // ==================== getWeight Tests ====================

    #[Test]
    public function getWeight_critical_returnsNegativeTen(): void
    {
        self::assertSame(-10, IssueSeverity::CRITICAL->getWeight());
    }

    #[Test]
    public function getWeight_recommended_returnsNegativeThree(): void
    {
        self::assertSame(-3, IssueSeverity::RECOMMENDED->getWeight());
    }

    #[Test]
    public function getWeight_optimization_returnsNegativeOne(): void
    {
        self::assertSame(-1, IssueSeverity::OPTIMIZATION->getWeight());
    }

    #[Test]
    public function getWeight_criticalHasHighestImpact(): void
    {
        // More negative = higher impact
        self::assertLessThan(
            IssueSeverity::RECOMMENDED->getWeight(),
            IssueSeverity::CRITICAL->getWeight(),
        );
        self::assertLessThan(
            IssueSeverity::OPTIMIZATION->getWeight(),
            IssueSeverity::RECOMMENDED->getWeight(),
        );
    }

    #[Test]
    public function allWeightsAreNegative(): void
    {
        foreach (IssueSeverity::cases() as $severity) {
            self::assertLessThan(0, $severity->getWeight(), sprintf(
                'Severity %s should have negative weight',
                $severity->value,
            ));
        }
    }

    // ==================== tryFrom Tests ====================

    #[Test]
    public function tryFrom_validString_returnsSeverity(): void
    {
        self::assertSame(IssueSeverity::CRITICAL, IssueSeverity::tryFrom('critical'));
        self::assertSame(IssueSeverity::RECOMMENDED, IssueSeverity::tryFrom('recommended'));
        self::assertSame(IssueSeverity::OPTIMIZATION, IssueSeverity::tryFrom('optimization'));
    }

    #[Test]
    public function tryFrom_invalidString_returnsNull(): void
    {
        self::assertNull(IssueSeverity::tryFrom('invalid'));
        self::assertNull(IssueSeverity::tryFrom(''));
        self::assertNull(IssueSeverity::tryFrom('CRITICAL')); // Case sensitive
    }

    // ==================== from Tests ====================

    #[Test]
    public function from_validString_returnsSeverity(): void
    {
        self::assertSame(IssueSeverity::CRITICAL, IssueSeverity::from('critical'));
    }

    #[Test]
    public function from_invalidString_throwsException(): void
    {
        $this->expectException(\ValueError::class);
        IssueSeverity::from('invalid');
    }
}
