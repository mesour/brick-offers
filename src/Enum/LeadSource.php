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
    case BRNO_KATALOG_SKOL = 'brno_katalog_skol';
    case SEZNAM_SKOL_EU = 'seznam_skol_eu';
    case SEZNAM_SKOL = 'seznam_skol';
    case JMK_KATALOG_SKOL = 'jmk_katalog_skol';
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
            self::BRNO_KATALOG_SKOL => 'brno.cz',
            self::SEZNAM_SKOL_EU => 'seznamskol.eu',
            self::SEZNAM_SKOL => 'seznamskol.cz',
            self::JMK_KATALOG_SKOL => 'skoly.jmk.cz',
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
            self::ATLAS_SKOLSTVI, self::BRNO_KATALOG_SKOL,
            self::SEZNAM_SKOL_EU, self::SEZNAM_SKOL, self::JMK_KATALOG_SKOL, self::CRAWLER, self::REFERENCE_CRAWLER, self::MANUAL => false,
        };
    }

    /**
     * Check if this source uses category-based discovery.
     * Category-based sources browse predefined categories.
     */
    public function isCategoryBased(): bool
    {
        return match ($this) {
            self::ATLAS_SKOLSTVI, self::BRNO_KATALOG_SKOL,
            self::SEZNAM_SKOL_EU, self::SEZNAM_SKOL, self::JMK_KATALOG_SKOL => true,
            default => false,
        };
    }

    /**
     * Get source-specific settings schema.
     *
     * @return array<string, array{
     *     type: string,
     *     label: string,
     *     options?: array<string, string>,
     *     multiple?: bool,
     *     required?: bool
     * }>
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
            self::BRNO_KATALOG_SKOL => [
                'schoolTypes' => [
                    'type' => 'choice',
                    'label' => 'Typy škol',
                    'multiple' => true,
                    'required' => true,
                    'options' => [
                        'ms' => 'Mateřské školy',
                        'zs' => 'Základní školy',
                    ],
                ],
            ],
            self::SEZNAM_SKOL_EU => [
                'schoolTypes' => [
                    'type' => 'choice',
                    'label' => 'Typy škol',
                    'multiple' => true,
                    'required' => true,
                    'options' => [
                        'materska-skola' => 'Mateřské školy',
                        'zakladni-skola' => 'Základní školy',
                        'stredni-skola' => 'Střední školy',
                        'vysoka-skola' => 'Vysoké školy',
                        'jazykova-skola' => 'Jazykové školy',
                        'umelecka-skola' => 'Umělecké školy',
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
            self::SEZNAM_SKOL => [
                'schoolTypes' => [
                    'type' => 'choice',
                    'label' => 'Typy škol',
                    'multiple' => true,
                    'required' => true,
                    'options' => [
                        'materske-skoly' => 'Mateřské školy',
                        'zakladni-skoly' => 'Základní školy',
                        'zakladni-umelecke-skoly' => 'Základní umělecké školy',
                    ],
                ],
                'regions' => [
                    'type' => 'choice',
                    'label' => 'Kraje',
                    'multiple' => true,
                    'required' => false,
                    'options' => [
                        'praha' => 'Praha',
                        'stredocesky-kraj' => 'Středočeský kraj',
                        'jihocesky-kraj' => 'Jihočeský kraj',
                        'plzensky-kraj' => 'Plzeňský kraj',
                        'karlovarsky-kraj' => 'Karlovarský kraj',
                        'ustecky-kraj' => 'Ústecký kraj',
                        'liberecky-kraj' => 'Liberecký kraj',
                        'kralovehradecky-kraj' => 'Královéhradecký kraj',
                        'pardubicky-kraj' => 'Pardubický kraj',
                        'kraj-vysocina' => 'Kraj Vysočina',
                        'jihomoravsky-kraj' => 'Jihomoravský kraj',
                        'olomoucky-kraj' => 'Olomoucký kraj',
                        'zlinsky-kraj' => 'Zlínský kraj',
                        'moravskoslezsky-kraj' => 'Moravskoslezský kraj',
                    ],
                ],
            ],
            self::JMK_KATALOG_SKOL => [
                'schoolTypes' => [
                    'type' => 'choice',
                    'label' => 'Typy škol',
                    'multiple' => true,
                    'required' => true,
                    'options' => [
                        'A' => 'Mateřská škola',
                        'B' => 'Základní škola',
                        'C' => 'Střední škola',
                        'E' => 'Vyšší odborná škola',
                        'F' => 'Základní umělecká škola',
                        'G' => 'Dům dětí a mládeže',
                        'K' => 'Psychologická poradna',
                    ],
                ],
                'districts' => [
                    'type' => 'choice',
                    'label' => 'Okresy',
                    'multiple' => true,
                    'required' => false,
                    'options' => [
                        'Blansko' => 'Blansko',
                        'Břeclav' => 'Břeclav',
                        'Brno-město' => 'Brno-město',
                        'Brno-venkov' => 'Brno-venkov',
                        'Hodonín' => 'Hodonín',
                        'Vyškov' => 'Vyškov',
                        'Znojmo' => 'Znojmo',
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
