<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Types of competitor snapshots for monitoring different aspects.
 */
enum CompetitorSnapshotType: string
{
    case PORTFOLIO = 'portfolio';   // Portfolio/references page monitoring
    case PRICING = 'pricing';       // Pricing page monitoring
    case SERVICES = 'services';     // Service offering monitoring
    case TEAM = 'team';             // Team page monitoring
    case TECHNOLOGY = 'technology'; // Technology stack monitoring
    case CONTENT = 'content';       // General content changes

    public function getLabel(): string
    {
        return match ($this) {
            self::PORTFOLIO => 'Portfolio',
            self::PRICING => 'Ceník',
            self::SERVICES => 'Služby',
            self::TEAM => 'Tým',
            self::TECHNOLOGY => 'Technologie',
            self::CONTENT => 'Obsah',
        };
    }

    /**
     * Get recommended check frequency in days.
     */
    public function getCheckFrequencyDays(): int
    {
        return match ($this) {
            self::PORTFOLIO => 7,     // Weekly
            self::PRICING => 14,      // Bi-weekly
            self::SERVICES => 14,     // Bi-weekly
            self::TEAM => 30,         // Monthly
            self::TECHNOLOGY => 30,   // Monthly
            self::CONTENT => 7,       // Weekly
        };
    }
}
