<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Types of demand signals indicating business needs.
 */
enum DemandSignalType: string
{
    // Job portal signals
    case HIRING_WEBDEV = 'hiring_webdev';
    case HIRING_DESIGNER = 'hiring_designer';
    case HIRING_MARKETING = 'hiring_marketing';
    case HIRING_IT = 'hiring_it';
    case HIRING_OTHER = 'hiring_other';

    // Tender/RFP signals
    case TENDER_WEB = 'tender_web';
    case TENDER_IT = 'tender_it';
    case TENDER_MARKETING = 'tender_marketing';
    case TENDER_DESIGN = 'tender_design';
    case TENDER_OTHER = 'tender_other';

    // Private RFP signals
    case RFP_WEB = 'rfp_web';
    case RFP_ESHOP = 'rfp_eshop';
    case RFP_APP = 'rfp_app';
    case RFP_MARKETING = 'rfp_marketing';
    case RFP_DESIGN = 'rfp_design';
    case RFP_IT = 'rfp_it';
    case RFP_OTHER = 'rfp_other';

    // ARES change signals
    case NEW_COMPANY = 'new_company';
    case CAPITAL_INCREASE = 'capital_increase';
    case DIRECTOR_CHANGE = 'director_change';
    case BUSINESS_SUBJECT_CHANGE = 'business_subject_change';
    case ADDRESS_CHANGE = 'address_change';

    public function getLabel(): string
    {
        return match ($this) {
            self::HIRING_WEBDEV => 'Hledá web developera',
            self::HIRING_DESIGNER => 'Hledá designéra',
            self::HIRING_MARKETING => 'Hledá marketéra',
            self::HIRING_IT => 'Hledá IT specialistu',
            self::HIRING_OTHER => 'Hledá jiného specialistu',
            self::TENDER_WEB => 'Veřejná zakázka - web',
            self::TENDER_IT => 'Veřejná zakázka - IT',
            self::TENDER_MARKETING => 'Veřejná zakázka - marketing',
            self::TENDER_DESIGN => 'Veřejná zakázka - design',
            self::TENDER_OTHER => 'Veřejná zakázka - jiné',
            self::RFP_WEB => 'Poptávka - web',
            self::RFP_ESHOP => 'Poptávka - e-shop',
            self::RFP_APP => 'Poptávka - aplikace',
            self::RFP_MARKETING => 'Poptávka - marketing',
            self::RFP_DESIGN => 'Poptávka - design',
            self::RFP_IT => 'Poptávka - IT',
            self::RFP_OTHER => 'Poptávka - jiné',
            self::NEW_COMPANY => 'Nová firma',
            self::CAPITAL_INCREASE => 'Navýšení kapitálu',
            self::DIRECTOR_CHANGE => 'Změna statutárního orgánu',
            self::BUSINESS_SUBJECT_CHANGE => 'Změna předmětu podnikání',
            self::ADDRESS_CHANGE => 'Změna sídla',
        };
    }

    public function isHiring(): bool
    {
        return str_starts_with($this->value, 'hiring_');
    }

    public function isTender(): bool
    {
        return str_starts_with($this->value, 'tender_');
    }

    public function isRfp(): bool
    {
        return str_starts_with($this->value, 'rfp_');
    }

    public function isAresChange(): bool
    {
        return in_array($this, [
            self::NEW_COMPANY,
            self::CAPITAL_INCREASE,
            self::DIRECTOR_CHANGE,
            self::BUSINESS_SUBJECT_CHANGE,
            self::ADDRESS_CHANGE,
        ], true);
    }

}
