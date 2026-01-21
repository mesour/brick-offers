<?php

declare(strict_types=1);

namespace App\Enum;

enum IssueCategory: string
{
    // Universal categories (run for all industries)
    case HTTP = 'http';
    case SECURITY = 'security';
    case SEO = 'seo';
    case LIBRARIES = 'libraries';
    case PERFORMANCE = 'performance';
    case RESPONSIVENESS = 'responsiveness';
    case VISUAL = 'visual';
    case ACCESSIBILITY = 'accessibility';
    case ESHOP_DETECTION = 'eshop_detection';
    case OUTDATED_CODE = 'outdated_code';
    case DESIGN_MODERNITY = 'design_modernity';

    // Industry-specific categories
    case INDUSTRY_ESHOP = 'industry_eshop';
    case INDUSTRY_WEBDESIGN = 'industry_webdesign';
    case INDUSTRY_REAL_ESTATE = 'industry_real_estate';
    case INDUSTRY_AUTOMOBILE = 'industry_automobile';
    case INDUSTRY_RESTAURANT = 'industry_restaurant';
    case INDUSTRY_MEDICAL = 'industry_medical';

    /**
     * Check if this category is industry-specific.
     */
    public function isIndustrySpecific(): bool
    {
        return str_starts_with($this->value, 'industry_');
    }

    /**
     * Check if this category is universal (runs for all industries).
     */
    public function isUniversal(): bool
    {
        return !$this->isIndustrySpecific();
    }

    /**
     * Get the Industry this category belongs to, or null if universal.
     */
    public function getIndustry(): ?Industry
    {
        return match ($this) {
            self::INDUSTRY_ESHOP => Industry::ESHOP,
            self::INDUSTRY_WEBDESIGN => Industry::WEBDESIGN,
            self::INDUSTRY_REAL_ESTATE => Industry::REAL_ESTATE,
            self::INDUSTRY_AUTOMOBILE => Industry::AUTOMOBILE,
            self::INDUSTRY_RESTAURANT => Industry::RESTAURANT,
            self::INDUSTRY_MEDICAL => Industry::MEDICAL,
            default => null,
        };
    }

    /**
     * Get all universal categories.
     *
     * @return array<self>
     */
    public static function getUniversalCategories(): array
    {
        return array_filter(self::cases(), fn (self $case) => $case->isUniversal());
    }

    /**
     * Get the category for a specific industry.
     */
    public static function forIndustry(Industry $industry): ?self
    {
        return match ($industry) {
            Industry::ESHOP => self::INDUSTRY_ESHOP,
            Industry::WEBDESIGN => self::INDUSTRY_WEBDESIGN,
            Industry::REAL_ESTATE => self::INDUSTRY_REAL_ESTATE,
            Industry::AUTOMOBILE => self::INDUSTRY_AUTOMOBILE,
            Industry::RESTAURANT => self::INDUSTRY_RESTAURANT,
            Industry::MEDICAL => self::INDUSTRY_MEDICAL,
            default => null,
        };
    }
}
