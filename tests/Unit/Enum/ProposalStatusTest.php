<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ProposalStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProposalStatus::class)]
final class ProposalStatusTest extends TestCase
{
    // ==================== Basic Enum Tests ====================

    #[Test]
    public function allCasesExist(): void
    {
        $cases = ProposalStatus::cases();

        self::assertCount(7, $cases);
        self::assertContains(ProposalStatus::GENERATING, $cases);
        self::assertContains(ProposalStatus::DRAFT, $cases);
        self::assertContains(ProposalStatus::APPROVED, $cases);
        self::assertContains(ProposalStatus::REJECTED, $cases);
        self::assertContains(ProposalStatus::USED, $cases);
        self::assertContains(ProposalStatus::RECYCLED, $cases);
        self::assertContains(ProposalStatus::EXPIRED, $cases);
    }

    #[Test]
    #[DataProvider('statusValuesProvider')]
    public function statusHasExpectedValue(ProposalStatus $status, string $expectedValue): void
    {
        self::assertSame($expectedValue, $status->value);
    }

    /**
     * @return iterable<string, array{ProposalStatus, string}>
     */
    public static function statusValuesProvider(): iterable
    {
        yield 'generating' => [ProposalStatus::GENERATING, 'generating'];
        yield 'draft' => [ProposalStatus::DRAFT, 'draft'];
        yield 'approved' => [ProposalStatus::APPROVED, 'approved'];
        yield 'rejected' => [ProposalStatus::REJECTED, 'rejected'];
        yield 'used' => [ProposalStatus::USED, 'used'];
        yield 'recycled' => [ProposalStatus::RECYCLED, 'recycled'];
        yield 'expired' => [ProposalStatus::EXPIRED, 'expired'];
    }

    // ==================== label() Tests ====================

    #[Test]
    #[DataProvider('labelProvider')]
    public function label_returnsExpectedLabel(ProposalStatus $status, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $status->label());
    }

    /**
     * @return iterable<string, array{ProposalStatus, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'generating' => [ProposalStatus::GENERATING, 'Generating'];
        yield 'draft' => [ProposalStatus::DRAFT, 'Draft'];
        yield 'approved' => [ProposalStatus::APPROVED, 'Approved'];
        yield 'rejected' => [ProposalStatus::REJECTED, 'Rejected'];
        yield 'used' => [ProposalStatus::USED, 'Used'];
        yield 'recycled' => [ProposalStatus::RECYCLED, 'Recycled'];
        yield 'expired' => [ProposalStatus::EXPIRED, 'Expired'];
    }

    // ==================== isEditable() Tests ====================

    #[Test]
    public function isEditable_draft_returnsTrue(): void
    {
        self::assertTrue(ProposalStatus::DRAFT->isEditable());
    }

    #[Test]
    public function isEditable_approved_returnsTrue(): void
    {
        self::assertTrue(ProposalStatus::APPROVED->isEditable());
    }

    #[Test]
    #[DataProvider('nonEditableStatusesProvider')]
    public function isEditable_nonEditableStatus_returnsFalse(ProposalStatus $status): void
    {
        self::assertFalse($status->isEditable());
    }

    /**
     * @return iterable<string, array{ProposalStatus}>
     */
    public static function nonEditableStatusesProvider(): iterable
    {
        yield 'generating' => [ProposalStatus::GENERATING];
        yield 'rejected' => [ProposalStatus::REJECTED];
        yield 'used' => [ProposalStatus::USED];
        yield 'recycled' => [ProposalStatus::RECYCLED];
        yield 'expired' => [ProposalStatus::EXPIRED];
    }

    // ==================== canApprove() Tests ====================

    #[Test]
    public function canApprove_draft_returnsTrue(): void
    {
        self::assertTrue(ProposalStatus::DRAFT->canApprove());
    }

    #[Test]
    #[DataProvider('nonApprovableStatusesProvider')]
    public function canApprove_nonApprovableStatus_returnsFalse(ProposalStatus $status): void
    {
        self::assertFalse($status->canApprove());
    }

    /**
     * @return iterable<string, array{ProposalStatus}>
     */
    public static function nonApprovableStatusesProvider(): iterable
    {
        yield 'generating' => [ProposalStatus::GENERATING];
        yield 'approved' => [ProposalStatus::APPROVED];
        yield 'rejected' => [ProposalStatus::REJECTED];
        yield 'used' => [ProposalStatus::USED];
        yield 'recycled' => [ProposalStatus::RECYCLED];
        yield 'expired' => [ProposalStatus::EXPIRED];
    }

    // ==================== canReject() Tests ====================

    #[Test]
    public function canReject_draft_returnsTrue(): void
    {
        self::assertTrue(ProposalStatus::DRAFT->canReject());
    }

    #[Test]
    public function canReject_approved_returnsTrue(): void
    {
        self::assertTrue(ProposalStatus::APPROVED->canReject());
    }

    #[Test]
    #[DataProvider('nonRejectableStatusesProvider')]
    public function canReject_nonRejectableStatus_returnsFalse(ProposalStatus $status): void
    {
        self::assertFalse($status->canReject());
    }

    /**
     * @return iterable<string, array{ProposalStatus}>
     */
    public static function nonRejectableStatusesProvider(): iterable
    {
        yield 'generating' => [ProposalStatus::GENERATING];
        yield 'rejected' => [ProposalStatus::REJECTED];
        yield 'used' => [ProposalStatus::USED];
        yield 'recycled' => [ProposalStatus::RECYCLED];
        yield 'expired' => [ProposalStatus::EXPIRED];
    }

    // ==================== canRecycle() Tests ====================

    #[Test]
    public function canRecycle_rejected_returnsTrue(): void
    {
        self::assertTrue(ProposalStatus::REJECTED->canRecycle());
    }

    #[Test]
    #[DataProvider('nonRecyclableStatusesProvider')]
    public function canRecycle_nonRecyclableStatus_returnsFalse(ProposalStatus $status): void
    {
        self::assertFalse($status->canRecycle());
    }

    /**
     * @return iterable<string, array{ProposalStatus}>
     */
    public static function nonRecyclableStatusesProvider(): iterable
    {
        yield 'generating' => [ProposalStatus::GENERATING];
        yield 'draft' => [ProposalStatus::DRAFT];
        yield 'approved' => [ProposalStatus::APPROVED];
        yield 'used' => [ProposalStatus::USED];
        yield 'recycled' => [ProposalStatus::RECYCLED];
        yield 'expired' => [ProposalStatus::EXPIRED];
    }

    // ==================== isFinal() Tests ====================

    #[Test]
    public function isFinal_used_returnsTrue(): void
    {
        self::assertTrue(ProposalStatus::USED->isFinal());
    }

    #[Test]
    public function isFinal_recycled_returnsTrue(): void
    {
        self::assertTrue(ProposalStatus::RECYCLED->isFinal());
    }

    #[Test]
    public function isFinal_expired_returnsTrue(): void
    {
        self::assertTrue(ProposalStatus::EXPIRED->isFinal());
    }

    #[Test]
    #[DataProvider('nonFinalStatusesProvider')]
    public function isFinal_nonFinalStatus_returnsFalse(ProposalStatus $status): void
    {
        self::assertFalse($status->isFinal());
    }

    /**
     * @return iterable<string, array{ProposalStatus}>
     */
    public static function nonFinalStatusesProvider(): iterable
    {
        yield 'generating' => [ProposalStatus::GENERATING];
        yield 'draft' => [ProposalStatus::DRAFT];
        yield 'approved' => [ProposalStatus::APPROVED];
        yield 'rejected' => [ProposalStatus::REJECTED];
    }

    // ==================== tryFrom/from Tests ====================

    #[Test]
    public function tryFrom_validString_returnsStatus(): void
    {
        self::assertSame(ProposalStatus::GENERATING, ProposalStatus::tryFrom('generating'));
        self::assertSame(ProposalStatus::DRAFT, ProposalStatus::tryFrom('draft'));
        self::assertSame(ProposalStatus::APPROVED, ProposalStatus::tryFrom('approved'));
        self::assertSame(ProposalStatus::REJECTED, ProposalStatus::tryFrom('rejected'));
        self::assertSame(ProposalStatus::USED, ProposalStatus::tryFrom('used'));
        self::assertSame(ProposalStatus::RECYCLED, ProposalStatus::tryFrom('recycled'));
        self::assertSame(ProposalStatus::EXPIRED, ProposalStatus::tryFrom('expired'));
    }

    #[Test]
    public function tryFrom_invalidString_returnsNull(): void
    {
        self::assertNull(ProposalStatus::tryFrom('invalid'));
        self::assertNull(ProposalStatus::tryFrom(''));
        self::assertNull(ProposalStatus::tryFrom('DRAFT')); // Case sensitive
    }

    #[Test]
    public function from_validString_returnsStatus(): void
    {
        self::assertSame(ProposalStatus::DRAFT, ProposalStatus::from('draft'));
    }

    #[Test]
    public function from_invalidString_throwsException(): void
    {
        $this->expectException(\ValueError::class);
        ProposalStatus::from('invalid');
    }

    // ==================== State Machine Consistency Tests ====================

    #[Test]
    public function editableStatuses_areNotFinal(): void
    {
        foreach (ProposalStatus::cases() as $status) {
            if ($status->isEditable()) {
                self::assertFalse($status->isFinal(), sprintf(
                    'Status %s is editable but also final - this is inconsistent',
                    $status->value,
                ));
            }
        }
    }

    #[Test]
    public function recyclableStatus_isNotFinal(): void
    {
        foreach (ProposalStatus::cases() as $status) {
            if ($status->canRecycle()) {
                self::assertFalse($status->isFinal(), sprintf(
                    'Status %s can be recycled but is also final - this is inconsistent',
                    $status->value,
                ));
            }
        }
    }

    #[Test]
    public function approvableStatus_isEditable(): void
    {
        foreach (ProposalStatus::cases() as $status) {
            if ($status->canApprove()) {
                self::assertTrue($status->isEditable(), sprintf(
                    'Status %s can be approved but is not editable - this may be inconsistent',
                    $status->value,
                ));
            }
        }
    }
}
