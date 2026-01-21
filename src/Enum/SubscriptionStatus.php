<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status of a user's subscription to a demand signal.
 */
enum SubscriptionStatus: string
{
    case NEW = 'new';
    case REVIEWED = 'reviewed';
    case DISMISSED = 'dismissed';
    case CONVERTED = 'converted';

    /**
     * Check if this status is actionable (not yet processed).
     */
    public function isActionable(): bool
    {
        return match ($this) {
            self::NEW, self::REVIEWED => true,
            default => false,
        };
    }

    /**
     * Get display label.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::NEW => 'New',
            self::REVIEWED => 'Reviewed',
            self::DISMISSED => 'Dismissed',
            self::CONVERTED => 'Converted',
        };
    }
}
