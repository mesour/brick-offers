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
    case CMS_QUALITY = 'cms_quality';
    case CONTENT_QUALITY = 'content_quality';
    case BRANDING = 'branding';
    case NAVIGATION_UX = 'navigation_ux';
    case CONVERSION = 'conversion';
    case LEGAL_COMPLIANCE = 'legal_compliance';
    case TYPOGRAPHY = 'typography';

    // Industry-specific categories
    case INDUSTRY_ESHOP = 'industry_eshop';
    case INDUSTRY_WEBDESIGN = 'industry_webdesign';
    case INDUSTRY_REAL_ESTATE = 'industry_real_estate';
    case INDUSTRY_AUTOMOBILE = 'industry_automobile';
    case INDUSTRY_RESTAURANT = 'industry_restaurant';
    case INDUSTRY_MEDICAL = 'industry_medical';

    /**
     * Get human-readable label.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::HTTP => 'HTTP & SSL',
            self::SECURITY => 'Bezpečnost',
            self::SEO => 'SEO',
            self::LIBRARIES => 'Knihovny',
            self::PERFORMANCE => 'Výkon',
            self::RESPONSIVENESS => 'Responzivita',
            self::VISUAL => 'Vizuální',
            self::ACCESSIBILITY => 'Přístupnost',
            self::ESHOP_DETECTION => 'Detekce e-shopu',
            self::OUTDATED_CODE => 'Zastaralý kód',
            self::DESIGN_MODERNITY => 'Modernost designu',
            self::CMS_QUALITY => 'Kvalita CMS',
            self::CONTENT_QUALITY => 'Kvalita obsahu',
            self::BRANDING => 'Branding',
            self::NAVIGATION_UX => 'Navigace a UX',
            self::CONVERSION => 'Konverzní prvky',
            self::LEGAL_COMPLIANCE => 'Právní náležitosti',
            self::TYPOGRAPHY => 'Typografie',
            self::INDUSTRY_ESHOP => 'E-shop specifické',
            self::INDUSTRY_WEBDESIGN => 'Webdesign specifické',
            self::INDUSTRY_REAL_ESTATE => 'Reality specifické',
            self::INDUSTRY_AUTOMOBILE => 'Auto specifické',
            self::INDUSTRY_RESTAURANT => 'Restaurace specifické',
            self::INDUSTRY_MEDICAL => 'Zdravotnictví specifické',
        };
    }

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
