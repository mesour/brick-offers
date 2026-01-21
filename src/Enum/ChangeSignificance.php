<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Significance level of detected changes in competitor monitoring.
 */
enum ChangeSignificance: string
{
    case CRITICAL = 'critical';   // Major changes requiring immediate attention
    case HIGH = 'high';           // Significant changes worth noting
    case MEDIUM = 'medium';       // Moderate changes
    case LOW = 'low';             // Minor/cosmetic changes

    public function getLabel(): string
    {
        return match ($this) {
            self::CRITICAL => 'Kritická',
            self::HIGH => 'Vysoká',
            self::MEDIUM => 'Střední',
            self::LOW => 'Nízká',
        };
    }

    public function getWeight(): int
    {
        return match ($this) {
            self::CRITICAL => 100,
            self::HIGH => 75,
            self::MEDIUM => 50,
            self::LOW => 25,
        };
    }

    /**
     * Should this change trigger an alert?
     */
    public function shouldAlert(): bool
    {
        return in_array($this, [self::CRITICAL, self::HIGH], true);
    }
}
