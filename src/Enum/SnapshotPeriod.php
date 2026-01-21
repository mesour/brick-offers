<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Snapshot period types for analysis trending data.
 */
enum SnapshotPeriod: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';

    /**
     * Get human-readable label.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::DAY => 'Daily',
            self::WEEK => 'Weekly',
            self::MONTH => 'Monthly',
        };
    }

    /**
     * Get the DateInterval string for this period.
     */
    public function getInterval(): string
    {
        return match ($this) {
            self::DAY => 'P1D',
            self::WEEK => 'P1W',
            self::MONTH => 'P1M',
        };
    }
}
