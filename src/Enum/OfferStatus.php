<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status enum for Offer lifecycle.
 */
enum OfferStatus: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case SENT = 'sent';
    case OPENED = 'opened';
    case CLICKED = 'clicked';
    case RESPONDED = 'responded';
    case CONVERTED = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING_APPROVAL => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::SENT => 'Sent',
            self::OPENED => 'Opened',
            self::CLICKED => 'Clicked',
            self::RESPONDED => 'Responded',
            self::CONVERTED => 'Converted',
        };
    }

    /**
     * Check if offer can be edited.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::DRAFT, self::REJECTED => true,
            default => false,
        };
    }

    /**
     * Check if offer can be submitted for approval.
     */
    public function canSubmitForApproval(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Check if offer can be approved.
     */
    public function canApprove(): bool
    {
        return $this === self::PENDING_APPROVAL;
    }

    /**
     * Check if offer can be rejected.
     */
    public function canReject(): bool
    {
        return match ($this) {
            self::PENDING_APPROVAL, self::APPROVED => true,
            default => false,
        };
    }

    /**
     * Check if offer can be sent.
     */
    public function canSend(): bool
    {
        return $this === self::APPROVED;
    }

    /**
     * Check if this is a final status (no more transitions).
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::CONVERTED, self::REJECTED => true,
            default => false,
        };
    }

    /**
     * Check if the offer has been sent.
     */
    public function isSent(): bool
    {
        return match ($this) {
            self::SENT, self::OPENED, self::CLICKED, self::RESPONDED, self::CONVERTED => true,
            default => false,
        };
    }

    /**
     * Get the next status in tracking progression.
     */
    public function getNextTrackingStatus(): ?self
    {
        return match ($this) {
            self::SENT => self::OPENED,
            self::OPENED => self::CLICKED,
            self::CLICKED => self::RESPONDED,
            self::RESPONDED => self::CONVERTED,
            default => null,
        };
    }
}
