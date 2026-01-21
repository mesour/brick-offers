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
        ], true);
    }
}
