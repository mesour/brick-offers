<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Industry types for categorizing leads and running industry-specific analyzers.
 *
 * Each industry can have:
 * - Specific analyzers that only run for that industry
 * - Default snapshot period for trending data
 * - Industry benchmarks for comparison
 */
enum Industry: string
{
    case WEBDESIGN = 'webdesign';
    case REAL_ESTATE = 'real_estate';
    case AUTOMOBILE = 'automobile';
    case ESHOP = 'eshop';
    case RESTAURANT = 'restaurant';
    case MEDICAL = 'medical';
    case LEGAL = 'legal';
    case FINANCE = 'finance';
    case EDUCATION = 'education';
    case OTHER = 'other';

    /**
     * Get the default snapshot period for this industry.
     * Can be overridden per-lead.
     */
    public function getDefaultSnapshotPeriod(): SnapshotPeriod
    {
        return match ($this) {
            self::ESHOP => SnapshotPeriod::DAY,           // E-shops change frequently
            self::WEBDESIGN => SnapshotPeriod::WEEK,      // Competition doesn't change fast
            self::REAL_ESTATE => SnapshotPeriod::WEEK,
            self::AUTOMOBILE => SnapshotPeriod::WEEK,
            self::RESTAURANT => SnapshotPeriod::WEEK,
            self::MEDICAL => SnapshotPeriod::MONTH,       // Medical sites are stable
            self::LEGAL => SnapshotPeriod::MONTH,
            self::FINANCE => SnapshotPeriod::WEEK,
            self::EDUCATION => SnapshotPeriod::MONTH,
            self::OTHER => SnapshotPeriod::WEEK,
        };
    }

    /**
     * Get human-readable label for this industry.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::WEBDESIGN => 'Web Design & Development',
            self::REAL_ESTATE => 'Real Estate',
            self::AUTOMOBILE => 'Automobile',
            self::ESHOP => 'E-commerce / E-shop',
            self::RESTAURANT => 'Restaurant & Food',
            self::MEDICAL => 'Healthcare & Medical',
            self::LEGAL => 'Legal Services',
            self::FINANCE => 'Finance & Insurance',
            self::EDUCATION => 'Education',
            self::OTHER => 'Other',
        };
    }

    /**
     * Alias for getLabel() for consistency with other enums.
     */
    public function label(): string
    {
        return $this->getLabel();
    }

    /**
     * Get all available industries as choices array.
     *
     * @return array<string, string>
     */
    public static function getChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->getLabel()] = $case->value;
        }

        return $choices;
    }

}
