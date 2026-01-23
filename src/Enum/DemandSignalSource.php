<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Sources of demand signals - where we find active inquiries/demands.
 */
enum DemandSignalSource: string
{
    // B2B demand portals
    case POPTAVKY_CZ = 'poptavky_cz';
    case AAAPOPTAVKA = 'aaapoptavka';
    case POPTAVEJ = 'poptavej';
    case WEBTRH = 'webtrh';

    // Public tenders
    case NEN = 'nen';
    case EZAKAZKY = 'ezakazky';
    case ROZZA = 'rozza';

    // Job portals
    case JOBS_CZ = 'jobs_cz';
    case PRACE_CZ = 'prace_cz';
    case LINKEDIN = 'linkedin';
    case STARTUP_JOBS = 'startup_jobs';

    // Other
    case ARES_CHANGE = 'ares_change';
    case MANUAL = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::POPTAVKY_CZ => 'Poptavky.cz',
            self::AAAPOPTAVKA => 'AAAPoptávka.cz',
            self::POPTAVEJ => 'Poptavej.cz',
            self::WEBTRH => 'WebTrh.cz',
            self::NEN => 'Věstník veřejných zakázek (NEN)',
            self::EZAKAZKY => 'E-zakázky.cz',
            self::ROZZA => 'ROZZA portál',
            self::JOBS_CZ => 'Jobs.cz',
            self::PRACE_CZ => 'Práce.cz',
            self::LINKEDIN => 'LinkedIn',
            self::STARTUP_JOBS => 'StartupJobs',
            self::ARES_CHANGE => 'ARES změny',
            self::MANUAL => 'Manuální',
        };
    }


    public function isJobPortal(): bool
    {
        return in_array($this, [self::JOBS_CZ, self::PRACE_CZ, self::LINKEDIN, self::STARTUP_JOBS], true);
    }

    public function isPublicTender(): bool
    {
        return in_array($this, [self::NEN, self::EZAKAZKY, self::ROZZA], true);
    }

    public function isRfpPlatform(): bool
    {
        return in_array($this, [self::POPTAVKY_CZ, self::AAAPOPTAVKA, self::POPTAVEJ, self::WEBTRH], true);
    }
}
