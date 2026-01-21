<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status of a proposal in its lifecycle.
 */
enum ProposalStatus: string
{
    case GENERATING = 'generating';     // AI is generating content
    case DRAFT = 'draft';               // Generated, waiting for review
    case APPROVED = 'approved';         // Approved for use in offer
    case REJECTED = 'rejected';         // Rejected (eligible for recycling)
    case USED = 'used';                 // Used in an offer
    case RECYCLED = 'recycled';         // Transferred to another user
    case EXPIRED = 'expired';           // Past expiration date

    public function label(): string
    {
        return match ($this) {
            self::GENERATING => 'Generating',
            self::DRAFT => 'Draft',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::USED => 'Used',
            self::RECYCLED => 'Recycled',
            self::EXPIRED => 'Expired',
        };
    }

    /**
     * Check if proposal can be edited.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::DRAFT, self::APPROVED], true);
    }

    /**
     * Check if proposal can be approved.
     */
    public function canApprove(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Check if proposal can be rejected.
     */
    public function canReject(): bool
    {
        return in_array($this, [self::DRAFT, self::APPROVED], true);
    }

    /**
     * Check if proposal is eligible for recycling.
     */
    public function canRecycle(): bool
    {
        return $this === self::REJECTED;
    }

    /**
     * Check if proposal is in a final state.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::USED, self::RECYCLED, self::EXPIRED], true);
    }
}
