<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\LeadStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeadStatus::class)]
final class LeadStatusTest extends TestCase
{
    // ==================== Enum Values Tests ====================

    #[Test]
    public function enum_hasExpectedCases(): void
    {
        $cases = LeadStatus::cases();

        self::assertCount(10, $cases);
    }

    #[Test]
    public function enum_workflowStatesExist(): void
    {
        self::assertSame('new', LeadStatus::NEW->value);
        self::assertSame('potential', LeadStatus::POTENTIAL->value);
        self::assertSame('good', LeadStatus::GOOD->value);
        self::assertSame('done', LeadStatus::DONE->value);
        self::assertSame('deal', LeadStatus::DEAL->value);
    }

    #[Test]
    public function enum_qualityStatesExist(): void
    {
        self::assertSame('very_bad', LeadStatus::VERY_BAD->value);
        self::assertSame('bad', LeadStatus::BAD->value);
        self::assertSame('middle', LeadStatus::MIDDLE->value);
        self::assertSame('quality_good', LeadStatus::QUALITY_GOOD->value);
        self::assertSame('super', LeadStatus::SUPER->value);
    }

    // ==================== from() Tests ====================

    #[Test]
    #[DataProvider('validStatusValuesProvider')]
    public function from_validValue_returnsEnum(string $value, LeadStatus $expected): void
    {
        $status = LeadStatus::from($value);

        self::assertSame($expected, $status);
    }

    /**
     * @return iterable<string, array{string, LeadStatus}>
     */
    public static function validStatusValuesProvider(): iterable
    {
        yield 'new' => ['new', LeadStatus::NEW];
        yield 'potential' => ['potential', LeadStatus::POTENTIAL];
        yield 'good' => ['good', LeadStatus::GOOD];
        yield 'done' => ['done', LeadStatus::DONE];
        yield 'deal' => ['deal', LeadStatus::DEAL];
        yield 'very_bad' => ['very_bad', LeadStatus::VERY_BAD];
        yield 'bad' => ['bad', LeadStatus::BAD];
        yield 'middle' => ['middle', LeadStatus::MIDDLE];
        yield 'quality_good' => ['quality_good', LeadStatus::QUALITY_GOOD];
        yield 'super' => ['super', LeadStatus::SUPER];
    }

    #[Test]
    public function from_invalidValue_throwsException(): void
    {
        $this->expectException(\ValueError::class);

        LeadStatus::from('invalid');
    }

    // ==================== tryFrom() Tests ====================

    #[Test]
    public function tryFrom_validValue_returnsEnum(): void
    {
        $status = LeadStatus::tryFrom('new');

        self::assertSame(LeadStatus::NEW, $status);
    }

    #[Test]
    public function tryFrom_invalidValue_returnsNull(): void
    {
        $status = LeadStatus::tryFrom('invalid');

        self::assertNull($status);
    }

    // ==================== isQualityState() Tests ====================

    #[Test]
    #[DataProvider('qualityStatesProvider')]
    public function isQualityState_qualityState_returnsTrue(LeadStatus $status): void
    {
        self::assertTrue($status->isQualityState());
    }

    /**
     * @return iterable<string, array{LeadStatus}>
     */
    public static function qualityStatesProvider(): iterable
    {
        yield 'very_bad' => [LeadStatus::VERY_BAD];
        yield 'bad' => [LeadStatus::BAD];
        yield 'middle' => [LeadStatus::MIDDLE];
        yield 'quality_good' => [LeadStatus::QUALITY_GOOD];
        yield 'super' => [LeadStatus::SUPER];
    }

    #[Test]
    #[DataProvider('workflowStatesProvider')]
    public function isQualityState_workflowState_returnsFalse(LeadStatus $status): void
    {
        self::assertFalse($status->isQualityState());
    }

    /**
     * @return iterable<string, array{LeadStatus}>
     */
    public static function workflowStatesProvider(): iterable
    {
        yield 'new' => [LeadStatus::NEW];
        yield 'potential' => [LeadStatus::POTENTIAL];
        yield 'good' => [LeadStatus::GOOD];
        yield 'done' => [LeadStatus::DONE];
        yield 'deal' => [LeadStatus::DEAL];
    }

    // ==================== isWorkflowState() Tests ====================

    #[Test]
    #[DataProvider('workflowStatesProvider')]
    public function isWorkflowState_workflowState_returnsTrue(LeadStatus $status): void
    {
        self::assertTrue($status->isWorkflowState());
    }

    #[Test]
    #[DataProvider('qualityStatesProvider')]
    public function isWorkflowState_qualityState_returnsFalse(LeadStatus $status): void
    {
        self::assertFalse($status->isWorkflowState());
    }

    // ==================== Mutual Exclusivity Tests ====================

    #[Test]
    public function allStatuses_eitherWorkflowOrQuality(): void
    {
        foreach (LeadStatus::cases() as $status) {
            $isWorkflow = $status->isWorkflowState();
            $isQuality = $status->isQualityState();

            // Each status must be exactly one type
            self::assertTrue(
                $isWorkflow xor $isQuality,
                sprintf('Status %s should be either workflow or quality, not both or neither', $status->value),
            );
        }
    }

    #[Test]
    public function workflowStates_countIs5(): void
    {
        $count = 0;
        foreach (LeadStatus::cases() as $status) {
            if ($status->isWorkflowState()) {
                $count++;
            }
        }

        self::assertSame(5, $count);
    }

    #[Test]
    public function qualityStates_countIs5(): void
    {
        $count = 0;
        foreach (LeadStatus::cases() as $status) {
            if ($status->isQualityState()) {
                $count++;
            }
        }

        self::assertSame(5, $count);
    }
}
