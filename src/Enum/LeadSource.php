<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Industry;

enum LeadSource: string
{
    case MANUAL = 'manual';
    case GOOGLE = 'google';
    case SEZNAM = 'seznam';
    case FIRMY_CZ = 'firmy_cz';
    case EKATALOG = 'ekatalog';
    case ATLAS_SKOLSTVI = 'atlas_skolstvi';
    case ZIVE_FIRMY = 'zive_firmy';
    case NAJISTO = 'najisto';
    case ZLATESTRANKY = 'zlatestranky';
    case CRAWLER = 'crawler';
    case REFERENCE_CRAWLER = 'reference_crawler';

    /**
     * Check if this source is from automated discovery (not manual entry).
     */
    public function isDiscovered(): bool
    {
        return $this !== self::MANUAL;
    }

    /**
     * Get the catalog domain for this source if it's a catalog source.
     * Used for automatic filtering of catalog domains from results.
     */
    public function getCatalogDomain(): ?string
    {
        return match ($this) {
            self::FIRMY_CZ => 'firmy.cz',
            self::EKATALOG => 'ekatalog.cz',
            self::ATLAS_SKOLSTVI => 'atlasskolstvi.cz',
            self::SEZNAM => 'firmy.seznam.cz',
            self::ZIVE_FIRMY => 'zivefirmy.cz',
            self::NAJISTO => 'najisto.centrum.cz',
            self::ZLATESTRANKY => 'zlatestranky.cz',
            default => null,
        };
    }

    /**
     * Check if this source uses query-based discovery.
     * Query-based sources search by keywords.
     */
    public function isQueryBased(): bool
    {
        return match ($this) {
            self::GOOGLE, self::SEZNAM, self::FIRMY_CZ, self::EKATALOG,
            self::ZIVE_FIRMY, self::NAJISTO, self::ZLATESTRANKY => true,
            self::ATLAS_SKOLSTVI, self::CRAWLER, self::REFERENCE_CRAWLER, self::MANUAL => false,
        };
    }

    /**
     * Check if this source uses category-based discovery.
     * Category-based sources browse predefined categories.
     */
    public function isCategoryBased(): bool
    {
        return match ($this) {
            self::ATLAS_SKOLSTVI => true,
            default => false,
        };
    }

    /**
     * Get source-specific settings schema.
     *
     * @return array<string, array{type: string, label: string, options?: array<string, string>, multiple?: bool, required?: bool}>
     */
    public function getSettingsSchema(): array
    {
        return match ($this) {
            self::ATLAS_SKOLSTVI => [
                'schoolTypes' => [
                    'type' => 'choice',
                    'label' => 'Typy škol',
                    'multiple' => true,
                    'required' => true,
                    'options' => [
                        'zakladni-skoly' => 'Základní školy',
                        'stredni-skoly' => 'Střední školy',
                        'vysoke-skoly' => 'Vysoké školy',
                        'vyssi-odborne-skoly' => 'Vyšší odborné školy',
                        'jazykove-skoly' => 'Jazykové školy',
                    ],
                ],
                'regions' => [
                    'type' => 'choice',
                    'label' => 'Kraje',
                    'multiple' => true,
                    'required' => false,
                    'options' => [
                        'praha' => 'Praha',
                        'stredocesky' => 'Středočeský',
                        'jihocesky' => 'Jihočeský',
                        'plzensky' => 'Plzeňský',
                        'karlovarsky' => 'Karlovarský',
                        'ustecky' => 'Ústecký',
                        'liberecky' => 'Liberecký',
                        'kralovehradecky' => 'Královéhradecký',
                        'pardubicky' => 'Pardubický',
                        'vysocina' => 'Vysočina',
                        'jihomoravsky' => 'Jihomoravský',
                        'olomoucky' => 'Olomoucký',
                        'zlinsky' => 'Zlínský',
                        'moravskoslezsky' => 'Moravskoslezský',
                    ],
                ],
            ],
            self::GOOGLE, self::SEZNAM, self::FIRMY_CZ, self::EKATALOG,
            self::ZIVE_FIRMY, self::NAJISTO, self::ZLATESTRANKY => [
                'queries' => [
                    'type' => 'textarea',
                    'label' => 'Vyhledávací dotazy (jeden na řádek)',
                    'required' => true,
                ],
            ],
            default => [],
        };
    }

    /**
     * Check if this source is a catalog (business directory) source.
     * Catalog sources have their own domains that should be filtered from results.
     */
    public function isCatalogSource(): bool
    {
        return $this->getCatalogDomain() !== null;
    }

    /**
     * Get the required industry for this source, if any.
     * Returns null if the source is available for all industries.
     */
    public function getRequiredIndustry(): ?Industry
    {
        return match ($this) {
            self::REFERENCE_CRAWLER => Industry::WEBDESIGN,
            default => null,
        };
    }

    /**
     * Check if this source is available for the given industry.
     */
    public function isAvailableForIndustry(?Industry $industry): bool
    {
        $required = $this->getRequiredIndustry();

        // Source is available for all industries
        if ($required === null) {
            return true;
        }

        // Source requires specific industry
        return $industry === $required;
    }
}
