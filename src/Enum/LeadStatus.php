<?php

declare(strict_types=1);

namespace App\Enum;

enum LeadStatus: string
{
    // Workflow states
    case NEW = 'new';
    case POTENTIAL = 'potential';
    case GOOD = 'good';
    case DONE = 'done';
    case DEAL = 'deal';
    case DISMISSED = 'dismissed';   // Lead was rejected/dismissed

    // Quality states (set by analysis)
    case VERY_BAD = 'very_bad';     // Critical issues or score < -50
    case BAD = 'bad';               // Score -50 to -20
    case MIDDLE = 'middle';         // Score -20 to -5
    case QUALITY_GOOD = 'quality_good'; // Score -5 to 0
    case SUPER = 'super';           // Score >= 0, no critical issues

    public function isQualityState(): bool
    {
        return in_array($this, [
            self::VERY_BAD,
            self::BAD,
            self::MIDDLE,
            self::QUALITY_GOOD,
            self::SUPER,
        ], true);
    }

    public function isWorkflowState(): bool
    {
        return in_array($this, [
            self::NEW,
            self::POTENTIAL,
            self::GOOD,
            self::DONE,
            self::DEAL,
            self::DISMISSED,
        ], true);
    }

    public function isFinalState(): bool
    {
        return in_array($this, [
            self::DEAL,
            self::DISMISSED,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Nový',
            self::POTENTIAL => 'Potenciální',
            self::GOOD => 'Dobrý',
            self::DONE => 'Hotovo',
            self::DEAL => 'Obchod',
            self::DISMISSED => 'Zamítnutý',
            self::VERY_BAD => 'Velmi špatný',
            self::BAD => 'Špatný',
            self::MIDDLE => 'Průměrný',
            self::QUALITY_GOOD => 'Dobrý',
            self::SUPER => 'Skvělý',
        };
    }
}
