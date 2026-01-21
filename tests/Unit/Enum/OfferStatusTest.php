<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\OfferStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OfferStatus::class)]
final class OfferStatusTest extends TestCase
{
    // ==================== Cases Tests ====================

    #[Test]
    public function cases_containsAllStatuses(): void
    {
        $cases = OfferStatus::cases();

        self::assertCount(9, $cases);
        self::assertContains(OfferStatus::DRAFT, $cases);
        self::assertContains(OfferStatus::PENDING_APPROVAL, $cases);
        self::assertContains(OfferStatus::APPROVED, $cases);
        self::assertContains(OfferStatus::REJECTED, $cases);
        self::assertContains(OfferStatus::SENT, $cases);
        self::assertContains(OfferStatus::OPENED, $cases);
        self::assertContains(OfferStatus::CLICKED, $cases);
        self::assertContains(OfferStatus::RESPONDED, $cases);
        self::assertContains(OfferStatus::CONVERTED, $cases);
    }

    // ==================== Value Tests ====================

    #[Test]
    public function values_areCorrect(): void
    {
        self::assertSame('draft', OfferStatus::DRAFT->value);
        self::assertSame('pending_approval', OfferStatus::PENDING_APPROVAL->value);
        self::assertSame('approved', OfferStatus::APPROVED->value);
        self::assertSame('rejected', OfferStatus::REJECTED->value);
        self::assertSame('sent', OfferStatus::SENT->value);
        self::assertSame('opened', OfferStatus::OPENED->value);
        self::assertSame('clicked', OfferStatus::CLICKED->value);
        self::assertSame('responded', OfferStatus::RESPONDED->value);
        self::assertSame('converted', OfferStatus::CONVERTED->value);
    }

    // ==================== Label Tests ====================

    #[Test]
    public function label_returnsCorrectLabels(): void
    {
        self::assertSame('Draft', OfferStatus::DRAFT->label());
        self::assertSame('Pending Approval', OfferStatus::PENDING_APPROVAL->label());
        self::assertSame('Approved', OfferStatus::APPROVED->label());
        self::assertSame('Rejected', OfferStatus::REJECTED->label());
        self::assertSame('Sent', OfferStatus::SENT->label());
        self::assertSame('Opened', OfferStatus::OPENED->label());
        self::assertSame('Clicked', OfferStatus::CLICKED->label());
        self::assertSame('Responded', OfferStatus::RESPONDED->label());
        self::assertSame('Converted', OfferStatus::CONVERTED->label());
    }

    // ==================== isEditable Tests ====================

    #[Test]
    public function isEditable_draft_returnsTrue(): void
    {
        self::assertTrue(OfferStatus::DRAFT->isEditable());
    }

    #[Test]
    public function isEditable_rejected_returnsTrue(): void
    {
        self::assertTrue(OfferStatus::REJECTED->isEditable());
    }

    #[Test]
    public function isEditable_otherStatuses_returnsFalse(): void
    {
        self::assertFalse(OfferStatus::PENDING_APPROVAL->isEditable());
        self::assertFalse(OfferStatus::APPROVED->isEditable());
        self::assertFalse(OfferStatus::SENT->isEditable());
        self::assertFalse(OfferStatus::OPENED->isEditable());
        self::assertFalse(OfferStatus::CLICKED->isEditable());
        self::assertFalse(OfferStatus::RESPONDED->isEditable());
        self::assertFalse(OfferStatus::CONVERTED->isEditable());
    }

    // ==================== canSubmitForApproval Tests ====================

    #[Test]
    public function canSubmitForApproval_draft_returnsTrue(): void
    {
        self::assertTrue(OfferStatus::DRAFT->canSubmitForApproval());
    }

    #[Test]
    public function canSubmitForApproval_otherStatuses_returnsFalse(): void
    {
        self::assertFalse(OfferStatus::PENDING_APPROVAL->canSubmitForApproval());
        self::assertFalse(OfferStatus::APPROVED->canSubmitForApproval());
        self::assertFalse(OfferStatus::REJECTED->canSubmitForApproval());
        self::assertFalse(OfferStatus::SENT->canSubmitForApproval());
    }

    // ==================== canApprove Tests ====================

    #[Test]
    public function canApprove_pendingApproval_returnsTrue(): void
    {
        self::assertTrue(OfferStatus::PENDING_APPROVAL->canApprove());
    }

    #[Test]
    public function canApprove_otherStatuses_returnsFalse(): void
    {
        self::assertFalse(OfferStatus::DRAFT->canApprove());
        self::assertFalse(OfferStatus::APPROVED->canApprove());
        self::assertFalse(OfferStatus::REJECTED->canApprove());
        self::assertFalse(OfferStatus::SENT->canApprove());
    }

    // ==================== canReject Tests ====================

    #[Test]
    public function canReject_pendingApproval_returnsTrue(): void
    {
        self::assertTrue(OfferStatus::PENDING_APPROVAL->canReject());
    }

    #[Test]
    public function canReject_approved_returnsTrue(): void
    {
        self::assertTrue(OfferStatus::APPROVED->canReject());
    }

    #[Test]
    public function canReject_otherStatuses_returnsFalse(): void
    {
        self::assertFalse(OfferStatus::DRAFT->canReject());
        self::assertFalse(OfferStatus::REJECTED->canReject());
        self::assertFalse(OfferStatus::SENT->canReject());
    }

    // ==================== canSend Tests ====================

    #[Test]
    public function canSend_approved_returnsTrue(): void
    {
        self::assertTrue(OfferStatus::APPROVED->canSend());
    }

    #[Test]
    public function canSend_otherStatuses_returnsFalse(): void
    {
        self::assertFalse(OfferStatus::DRAFT->canSend());
        self::assertFalse(OfferStatus::PENDING_APPROVAL->canSend());
        self::assertFalse(OfferStatus::REJECTED->canSend());
        self::assertFalse(OfferStatus::SENT->canSend());
    }

    // ==================== isFinal Tests ====================

    #[Test]
    public function isFinal_converted_returnsTrue(): void
    {
        self::assertTrue(OfferStatus::CONVERTED->isFinal());
    }

    #[Test]
    public function isFinal_rejected_returnsTrue(): void
    {
        self::assertTrue(OfferStatus::REJECTED->isFinal());
    }

    #[Test]
    public function isFinal_otherStatuses_returnsFalse(): void
    {
        self::assertFalse(OfferStatus::DRAFT->isFinal());
        self::assertFalse(OfferStatus::PENDING_APPROVAL->isFinal());
        self::assertFalse(OfferStatus::APPROVED->isFinal());
        self::assertFalse(OfferStatus::SENT->isFinal());
        self::assertFalse(OfferStatus::OPENED->isFinal());
        self::assertFalse(OfferStatus::CLICKED->isFinal());
        self::assertFalse(OfferStatus::RESPONDED->isFinal());
    }

    // ==================== isSent Tests ====================

    #[Test]
    public function isSent_sentStatuses_returnsTrue(): void
    {
        self::assertTrue(OfferStatus::SENT->isSent());
        self::assertTrue(OfferStatus::OPENED->isSent());
        self::assertTrue(OfferStatus::CLICKED->isSent());
        self::assertTrue(OfferStatus::RESPONDED->isSent());
        self::assertTrue(OfferStatus::CONVERTED->isSent());
    }

    #[Test]
    public function isSent_notSentStatuses_returnsFalse(): void
    {
        self::assertFalse(OfferStatus::DRAFT->isSent());
        self::assertFalse(OfferStatus::PENDING_APPROVAL->isSent());
        self::assertFalse(OfferStatus::APPROVED->isSent());
        self::assertFalse(OfferStatus::REJECTED->isSent());
    }

    // ==================== getNextTrackingStatus Tests ====================

    #[Test]
    public function getNextTrackingStatus_followsCorrectProgression(): void
    {
        self::assertSame(OfferStatus::OPENED, OfferStatus::SENT->getNextTrackingStatus());
        self::assertSame(OfferStatus::CLICKED, OfferStatus::OPENED->getNextTrackingStatus());
        self::assertSame(OfferStatus::RESPONDED, OfferStatus::CLICKED->getNextTrackingStatus());
        self::assertSame(OfferStatus::CONVERTED, OfferStatus::RESPONDED->getNextTrackingStatus());
    }

    #[Test]
    public function getNextTrackingStatus_finalStatus_returnsNull(): void
    {
        self::assertNull(OfferStatus::CONVERTED->getNextTrackingStatus());
        self::assertNull(OfferStatus::DRAFT->getNextTrackingStatus());
        self::assertNull(OfferStatus::REJECTED->getNextTrackingStatus());
    }

    // ==================== From Value Tests ====================

    #[Test]
    public function from_validValue_returnsStatus(): void
    {
        self::assertSame(OfferStatus::DRAFT, OfferStatus::from('draft'));
        self::assertSame(OfferStatus::SENT, OfferStatus::from('sent'));
    }

    #[Test]
    public function from_invalidValue_throwsException(): void
    {
        $this->expectException(\ValueError::class);

        OfferStatus::from('invalid');
    }
}
