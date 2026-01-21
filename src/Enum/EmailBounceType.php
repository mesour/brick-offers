<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type of email bounce/blacklist entry.
 */
enum EmailBounceType: string
{
    case HARD_BOUNCE = 'hard';
    case SOFT_BOUNCE = 'soft';
    case COMPLAINT = 'complaint';
    case UNSUBSCRIBE = 'unsubscribe';

    public function label(): string
    {
        return match ($this) {
            self::HARD_BOUNCE => 'Hard Bounce',
            self::SOFT_BOUNCE => 'Soft Bounce',
            self::COMPLAINT => 'Spam Complaint',
            self::UNSUBSCRIBE => 'Unsubscribe',
        };
    }

    /**
     * Check if this type should result in permanent block.
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::HARD_BOUNCE, self::COMPLAINT, self::UNSUBSCRIBE => true,
            self::SOFT_BOUNCE => false,
        };
    }

    /**
     * Check if this type should be global (not per-user).
     */
    public function isGlobal(): bool
    {
        return match ($this) {
            self::HARD_BOUNCE, self::COMPLAINT => true,
            self::SOFT_BOUNCE, self::UNSUBSCRIBE => false,
        };
    }
}
