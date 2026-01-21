<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Represents the relationship status between a user and a company.
 */
enum RelationshipStatus: string
{
    case PROSPECT = 'prospect';
    case CONTACTED = 'contacted';
    case NEGOTIATING = 'negotiating';
    case CLIENT = 'client';
    case FORMER_CLIENT = 'former_client';
    case BLACKLISTED = 'blacklisted';

    /**
     * Check if this status indicates an active business relationship.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::NEGOTIATING, self::CLIENT => true,
            default => false,
        };
    }

    /**
     * Check if this status allows outreach.
     */
    public function allowsOutreach(): bool
    {
        return match ($this) {
            self::BLACKLISTED, self::CLIENT => false,
            default => true,
        };
    }

    /**
     * Get display label.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::PROSPECT => 'Prospect',
            self::CONTACTED => 'Contacted',
            self::NEGOTIATING => 'Negotiating',
            self::CLIENT => 'Client',
            self::FORMER_CLIENT => 'Former Client',
            self::BLACKLISTED => 'Blacklisted',
        };
    }
}
