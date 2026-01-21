<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type of proposal - determines which generator to use.
 */
enum ProposalType: string
{
    case DESIGN_MOCKUP = 'design_mockup';           // Web design screenshot/mockup
    case MARKETING_AUDIT = 'marketing_audit';       // Marketing audit report
    case CONVERSION_REPORT = 'conversion_report';   // E-commerce optimization report
    case SECURITY_REPORT = 'security_report';       // IT security audit
    case COMPLIANCE_CHECK = 'compliance_check';     // Legal/compliance checklist
    case MARKET_ANALYSIS = 'market_analysis';       // Real estate market analysis
    case GENERIC_REPORT = 'generic_report';         // Generic fallback report

    public function label(): string
    {
        return match ($this) {
            self::DESIGN_MOCKUP => 'Design Mockup',
            self::MARKETING_AUDIT => 'Marketing Audit',
            self::CONVERSION_REPORT => 'Conversion Report',
            self::SECURITY_REPORT => 'Security Report',
            self::COMPLIANCE_CHECK => 'Compliance Check',
            self::MARKET_ANALYSIS => 'Market Analysis',
            self::GENERIC_REPORT => 'Generic Report',
        };
    }

    /**
     * Get the primary industry for this proposal type.
     */
    public function getPrimaryIndustry(): ?Industry
    {
        return match ($this) {
            self::DESIGN_MOCKUP => Industry::WEBDESIGN,
            self::MARKETING_AUDIT => null, // Multiple industries
            self::CONVERSION_REPORT => Industry::ESHOP,
            self::SECURITY_REPORT => null, // IT Services - not in enum yet
            self::COMPLIANCE_CHECK => Industry::LEGAL,
            self::MARKET_ANALYSIS => Industry::REAL_ESTATE,
            self::GENERIC_REPORT => null,
        };
    }

    /**
     * Get supported output formats for this type.
     */
    public function getSupportedOutputs(): array
    {
        return match ($this) {
            self::DESIGN_MOCKUP => ['html', 'screenshot', 'pdf'],
            self::MARKETING_AUDIT => ['html', 'pdf'],
            self::CONVERSION_REPORT => ['html', 'pdf'],
            self::SECURITY_REPORT => ['html', 'pdf'],
            self::COMPLIANCE_CHECK => ['html', 'pdf'],
            self::MARKET_ANALYSIS => ['html', 'pdf'],
            self::GENERIC_REPORT => ['html', 'pdf'],
        };
    }
}
