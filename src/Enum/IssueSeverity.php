<?php

declare(strict_types=1);

namespace App\Enum;

enum IssueSeverity: string
{
    case CRITICAL = 'critical';
    case RECOMMENDED = 'recommended';
    case OPTIMIZATION = 'optimization';

    public function getWeight(): int
    {
        return match ($this) {
            self::CRITICAL => -10,
            self::RECOMMENDED => -3,
            self::OPTIMIZATION => -1,
        };
    }
}
