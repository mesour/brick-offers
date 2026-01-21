<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status of demand signal processing.
 */
enum DemandSignalStatus: string
{
    case NEW = 'new';                   // Newly discovered, not processed
    case QUALIFIED = 'qualified';       // Qualified as relevant opportunity
    case DISQUALIFIED = 'disqualified'; // Not relevant or duplicate
    case CONVERTED = 'converted';       // Converted to Lead
    case EXPIRED = 'expired';           // Deadline passed or no longer active

    public function getLabel(): string
    {
        return match ($this) {
            self::NEW => 'Nový',
            self::QUALIFIED => 'Kvalifikovaný',
            self::DISQUALIFIED => 'Nekvalifikovaný',
            self::CONVERTED => 'Převeden na lead',
            self::EXPIRED => 'Expirovaný',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::NEW, self::QUALIFIED], true);
    }
}
