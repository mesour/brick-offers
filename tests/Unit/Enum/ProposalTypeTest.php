<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\Industry;
use App\Enum\ProposalType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProposalType::class)]
final class ProposalTypeTest extends TestCase
{
    // ==================== Basic Enum Tests ====================

    #[Test]
    public function allCasesExist(): void
    {
        $cases = ProposalType::cases();

        self::assertCount(7, $cases);
        self::assertContains(ProposalType::DESIGN_MOCKUP, $cases);
        self::assertContains(ProposalType::MARKETING_AUDIT, $cases);
        self::assertContains(ProposalType::CONVERSION_REPORT, $cases);
        self::assertContains(ProposalType::SECURITY_REPORT, $cases);
        self::assertContains(ProposalType::COMPLIANCE_CHECK, $cases);
        self::assertContains(ProposalType::MARKET_ANALYSIS, $cases);
        self::assertContains(ProposalType::GENERIC_REPORT, $cases);
    }

    #[Test]
    #[DataProvider('typeValuesProvider')]
    public function typeHasExpectedValue(ProposalType $type, string $expectedValue): void
    {
        self::assertSame($expectedValue, $type->value);
    }

    /**
     * @return iterable<string, array{ProposalType, string}>
     */
    public static function typeValuesProvider(): iterable
    {
        yield 'design_mockup' => [ProposalType::DESIGN_MOCKUP, 'design_mockup'];
        yield 'marketing_audit' => [ProposalType::MARKETING_AUDIT, 'marketing_audit'];
        yield 'conversion_report' => [ProposalType::CONVERSION_REPORT, 'conversion_report'];
        yield 'security_report' => [ProposalType::SECURITY_REPORT, 'security_report'];
        yield 'compliance_check' => [ProposalType::COMPLIANCE_CHECK, 'compliance_check'];
        yield 'market_analysis' => [ProposalType::MARKET_ANALYSIS, 'market_analysis'];
        yield 'generic_report' => [ProposalType::GENERIC_REPORT, 'generic_report'];
    }

    // ==================== label() Tests ====================

    #[Test]
    #[DataProvider('labelProvider')]
    public function label_returnsExpectedLabel(ProposalType $type, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $type->label());
    }

    /**
     * @return iterable<string, array{ProposalType, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'design_mockup' => [ProposalType::DESIGN_MOCKUP, 'Design Mockup'];
        yield 'marketing_audit' => [ProposalType::MARKETING_AUDIT, 'Marketing Audit'];
        yield 'conversion_report' => [ProposalType::CONVERSION_REPORT, 'Conversion Report'];
        yield 'security_report' => [ProposalType::SECURITY_REPORT, 'Security Report'];
        yield 'compliance_check' => [ProposalType::COMPLIANCE_CHECK, 'Compliance Check'];
        yield 'market_analysis' => [ProposalType::MARKET_ANALYSIS, 'Market Analysis'];
        yield 'generic_report' => [ProposalType::GENERIC_REPORT, 'Generic Report'];
    }

    // ==================== getPrimaryIndustry() Tests ====================

    #[Test]
    public function getPrimaryIndustry_designMockup_returnsWebdesign(): void
    {
        self::assertSame(Industry::WEBDESIGN, ProposalType::DESIGN_MOCKUP->getPrimaryIndustry());
    }

    #[Test]
    public function getPrimaryIndustry_conversionReport_returnsEshop(): void
    {
        self::assertSame(Industry::ESHOP, ProposalType::CONVERSION_REPORT->getPrimaryIndustry());
    }

    #[Test]
    public function getPrimaryIndustry_complianceCheck_returnsLegal(): void
    {
        self::assertSame(Industry::LEGAL, ProposalType::COMPLIANCE_CHECK->getPrimaryIndustry());
    }

    #[Test]
    public function getPrimaryIndustry_marketAnalysis_returnsRealEstate(): void
    {
        self::assertSame(Industry::REAL_ESTATE, ProposalType::MARKET_ANALYSIS->getPrimaryIndustry());
    }

    #[Test]
    #[DataProvider('typesWithNoPrimaryIndustryProvider')]
    public function getPrimaryIndustry_multiIndustryType_returnsNull(ProposalType $type): void
    {
        self::assertNull($type->getPrimaryIndustry());
    }

    /**
     * @return iterable<string, array{ProposalType}>
     */
    public static function typesWithNoPrimaryIndustryProvider(): iterable
    {
        yield 'marketing_audit' => [ProposalType::MARKETING_AUDIT];
        yield 'security_report' => [ProposalType::SECURITY_REPORT];
        yield 'generic_report' => [ProposalType::GENERIC_REPORT];
    }

    #[Test]
    #[DataProvider('industryMappingProvider')]
    public function getPrimaryIndustry_returnsExpectedIndustry(ProposalType $type, ?Industry $expectedIndustry): void
    {
        self::assertSame($expectedIndustry, $type->getPrimaryIndustry());
    }

    /**
     * @return iterable<string, array{ProposalType, ?Industry}>
     */
    public static function industryMappingProvider(): iterable
    {
        yield 'design_mockup' => [ProposalType::DESIGN_MOCKUP, Industry::WEBDESIGN];
        yield 'marketing_audit' => [ProposalType::MARKETING_AUDIT, null];
        yield 'conversion_report' => [ProposalType::CONVERSION_REPORT, Industry::ESHOP];
        yield 'security_report' => [ProposalType::SECURITY_REPORT, null];
        yield 'compliance_check' => [ProposalType::COMPLIANCE_CHECK, Industry::LEGAL];
        yield 'market_analysis' => [ProposalType::MARKET_ANALYSIS, Industry::REAL_ESTATE];
        yield 'generic_report' => [ProposalType::GENERIC_REPORT, null];
    }

    // ==================== getSupportedOutputs() Tests ====================

    #[Test]
    public function getSupportedOutputs_designMockup_includesScreenshot(): void
    {
        $outputs = ProposalType::DESIGN_MOCKUP->getSupportedOutputs();

        self::assertContains('html', $outputs);
        self::assertContains('screenshot', $outputs);
        self::assertContains('pdf', $outputs);
        self::assertCount(3, $outputs);
    }

    #[Test]
    #[DataProvider('standardOutputTypesProvider')]
    public function getSupportedOutputs_standardType_returnsHtmlAndPdf(ProposalType $type): void
    {
        $outputs = $type->getSupportedOutputs();

        self::assertContains('html', $outputs);
        self::assertContains('pdf', $outputs);
        self::assertCount(2, $outputs);
    }

    /**
     * @return iterable<string, array{ProposalType}>
     */
    public static function standardOutputTypesProvider(): iterable
    {
        yield 'marketing_audit' => [ProposalType::MARKETING_AUDIT];
        yield 'conversion_report' => [ProposalType::CONVERSION_REPORT];
        yield 'security_report' => [ProposalType::SECURITY_REPORT];
        yield 'compliance_check' => [ProposalType::COMPLIANCE_CHECK];
        yield 'market_analysis' => [ProposalType::MARKET_ANALYSIS];
        yield 'generic_report' => [ProposalType::GENERIC_REPORT];
    }

    #[Test]
    public function getSupportedOutputs_allTypes_returnNonEmpty(): void
    {
        foreach (ProposalType::cases() as $type) {
            $outputs = $type->getSupportedOutputs();

            self::assertNotEmpty($outputs, sprintf(
                'Type %s should have at least one supported output',
                $type->value,
            ));
        }
    }

    #[Test]
    public function getSupportedOutputs_allTypes_includeHtml(): void
    {
        foreach (ProposalType::cases() as $type) {
            $outputs = $type->getSupportedOutputs();

            self::assertContains('html', $outputs, sprintf(
                'Type %s should support HTML output',
                $type->value,
            ));
        }
    }

    #[Test]
    public function getSupportedOutputs_allTypes_includePdf(): void
    {
        foreach (ProposalType::cases() as $type) {
            $outputs = $type->getSupportedOutputs();

            self::assertContains('pdf', $outputs, sprintf(
                'Type %s should support PDF output',
                $type->value,
            ));
        }
    }

    // ==================== tryFrom/from Tests ====================

    #[Test]
    public function tryFrom_validString_returnsType(): void
    {
        self::assertSame(ProposalType::DESIGN_MOCKUP, ProposalType::tryFrom('design_mockup'));
        self::assertSame(ProposalType::MARKETING_AUDIT, ProposalType::tryFrom('marketing_audit'));
        self::assertSame(ProposalType::CONVERSION_REPORT, ProposalType::tryFrom('conversion_report'));
        self::assertSame(ProposalType::SECURITY_REPORT, ProposalType::tryFrom('security_report'));
        self::assertSame(ProposalType::COMPLIANCE_CHECK, ProposalType::tryFrom('compliance_check'));
        self::assertSame(ProposalType::MARKET_ANALYSIS, ProposalType::tryFrom('market_analysis'));
        self::assertSame(ProposalType::GENERIC_REPORT, ProposalType::tryFrom('generic_report'));
    }

    #[Test]
    public function tryFrom_invalidString_returnsNull(): void
    {
        self::assertNull(ProposalType::tryFrom('invalid'));
        self::assertNull(ProposalType::tryFrom(''));
        self::assertNull(ProposalType::tryFrom('DESIGN_MOCKUP')); // Case sensitive
    }

    #[Test]
    public function from_validString_returnsType(): void
    {
        self::assertSame(ProposalType::DESIGN_MOCKUP, ProposalType::from('design_mockup'));
    }

    #[Test]
    public function from_invalidString_throwsException(): void
    {
        $this->expectException(\ValueError::class);
        ProposalType::from('invalid');
    }
}
