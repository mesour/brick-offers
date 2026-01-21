<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\Industry;
use App\Enum\IssueCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueCategory::class)]
final class IssueCategoryTest extends TestCase
{
    // ==================== Basic Enum Tests ====================

    #[Test]
    public function allCasesExist(): void
    {
        $cases = IssueCategory::cases();

        // 11 universal + 6 industry-specific = 17 total
        self::assertCount(17, $cases);

        // Universal categories
        self::assertContains(IssueCategory::HTTP, $cases);
        self::assertContains(IssueCategory::SECURITY, $cases);
        self::assertContains(IssueCategory::SEO, $cases);
        self::assertContains(IssueCategory::LIBRARIES, $cases);
        self::assertContains(IssueCategory::PERFORMANCE, $cases);
        self::assertContains(IssueCategory::RESPONSIVENESS, $cases);
        self::assertContains(IssueCategory::VISUAL, $cases);
        self::assertContains(IssueCategory::ACCESSIBILITY, $cases);
        self::assertContains(IssueCategory::ESHOP_DETECTION, $cases);
        self::assertContains(IssueCategory::OUTDATED_CODE, $cases);
        self::assertContains(IssueCategory::DESIGN_MODERNITY, $cases);

        // Industry-specific categories
        self::assertContains(IssueCategory::INDUSTRY_ESHOP, $cases);
        self::assertContains(IssueCategory::INDUSTRY_WEBDESIGN, $cases);
        self::assertContains(IssueCategory::INDUSTRY_REAL_ESTATE, $cases);
        self::assertContains(IssueCategory::INDUSTRY_AUTOMOBILE, $cases);
        self::assertContains(IssueCategory::INDUSTRY_RESTAURANT, $cases);
        self::assertContains(IssueCategory::INDUSTRY_MEDICAL, $cases);
    }

    // ==================== isIndustrySpecific Tests ====================

    #[Test]
    #[DataProvider('universalCategoriesProvider')]
    public function isIndustrySpecific_universalCategory_returnsFalse(IssueCategory $category): void
    {
        self::assertFalse($category->isIndustrySpecific());
    }

    #[Test]
    #[DataProvider('industrySpecificCategoriesProvider')]
    public function isIndustrySpecific_industryCategory_returnsTrue(IssueCategory $category): void
    {
        self::assertTrue($category->isIndustrySpecific());
    }

    /**
     * @return iterable<string, array{IssueCategory}>
     */
    public static function universalCategoriesProvider(): iterable
    {
        yield 'http' => [IssueCategory::HTTP];
        yield 'security' => [IssueCategory::SECURITY];
        yield 'seo' => [IssueCategory::SEO];
        yield 'libraries' => [IssueCategory::LIBRARIES];
        yield 'performance' => [IssueCategory::PERFORMANCE];
        yield 'responsiveness' => [IssueCategory::RESPONSIVENESS];
        yield 'visual' => [IssueCategory::VISUAL];
        yield 'accessibility' => [IssueCategory::ACCESSIBILITY];
        yield 'eshop_detection' => [IssueCategory::ESHOP_DETECTION];
        yield 'outdated_code' => [IssueCategory::OUTDATED_CODE];
        yield 'design_modernity' => [IssueCategory::DESIGN_MODERNITY];
    }

    /**
     * @return iterable<string, array{IssueCategory}>
     */
    public static function industrySpecificCategoriesProvider(): iterable
    {
        yield 'industry_eshop' => [IssueCategory::INDUSTRY_ESHOP];
        yield 'industry_webdesign' => [IssueCategory::INDUSTRY_WEBDESIGN];
        yield 'industry_real_estate' => [IssueCategory::INDUSTRY_REAL_ESTATE];
        yield 'industry_automobile' => [IssueCategory::INDUSTRY_AUTOMOBILE];
        yield 'industry_restaurant' => [IssueCategory::INDUSTRY_RESTAURANT];
        yield 'industry_medical' => [IssueCategory::INDUSTRY_MEDICAL];
    }

    // ==================== isUniversal Tests ====================

    #[Test]
    #[DataProvider('universalCategoriesProvider')]
    public function isUniversal_universalCategory_returnsTrue(IssueCategory $category): void
    {
        self::assertTrue($category->isUniversal());
    }

    #[Test]
    #[DataProvider('industrySpecificCategoriesProvider')]
    public function isUniversal_industryCategory_returnsFalse(IssueCategory $category): void
    {
        self::assertFalse($category->isUniversal());
    }

    // ==================== getIndustry Tests ====================

    #[Test]
    #[DataProvider('industryMappingProvider')]
    public function getIndustry_industryCategory_returnsCorrectIndustry(
        IssueCategory $category,
        Industry $expectedIndustry,
    ): void {
        self::assertSame($expectedIndustry, $category->getIndustry());
    }

    #[Test]
    #[DataProvider('universalCategoriesProvider')]
    public function getIndustry_universalCategory_returnsNull(IssueCategory $category): void
    {
        self::assertNull($category->getIndustry());
    }

    /**
     * @return iterable<string, array{IssueCategory, Industry}>
     */
    public static function industryMappingProvider(): iterable
    {
        yield 'eshop' => [IssueCategory::INDUSTRY_ESHOP, Industry::ESHOP];
        yield 'webdesign' => [IssueCategory::INDUSTRY_WEBDESIGN, Industry::WEBDESIGN];
        yield 'real_estate' => [IssueCategory::INDUSTRY_REAL_ESTATE, Industry::REAL_ESTATE];
        yield 'automobile' => [IssueCategory::INDUSTRY_AUTOMOBILE, Industry::AUTOMOBILE];
        yield 'restaurant' => [IssueCategory::INDUSTRY_RESTAURANT, Industry::RESTAURANT];
        yield 'medical' => [IssueCategory::INDUSTRY_MEDICAL, Industry::MEDICAL];
    }

    // ==================== getUniversalCategories Tests ====================

    #[Test]
    public function getUniversalCategories_returnsOnlyUniversal(): void
    {
        $universal = IssueCategory::getUniversalCategories();

        self::assertCount(11, $universal);

        foreach ($universal as $category) {
            self::assertTrue($category->isUniversal(), sprintf(
                'Category %s should be universal',
                $category->value,
            ));
        }
    }

    #[Test]
    public function getUniversalCategories_excludesIndustrySpecific(): void
    {
        $universal = IssueCategory::getUniversalCategories();

        foreach ($universal as $category) {
            self::assertFalse($category->isIndustrySpecific(), sprintf(
                'Category %s should not be industry-specific',
                $category->value,
            ));
        }
    }

    // ==================== forIndustry Tests ====================

    #[Test]
    #[DataProvider('industryToCategoryProvider')]
    public function forIndustry_supportedIndustry_returnsCategory(
        Industry $industry,
        IssueCategory $expectedCategory,
    ): void {
        self::assertSame($expectedCategory, IssueCategory::forIndustry($industry));
    }

    #[Test]
    public function forIndustry_unsupportedIndustry_returnsNull(): void
    {
        // Industries without specific categories
        self::assertNull(IssueCategory::forIndustry(Industry::LEGAL));
        self::assertNull(IssueCategory::forIndustry(Industry::FINANCE));
        self::assertNull(IssueCategory::forIndustry(Industry::EDUCATION));
        self::assertNull(IssueCategory::forIndustry(Industry::OTHER));
    }

    /**
     * @return iterable<string, array{Industry, IssueCategory}>
     */
    public static function industryToCategoryProvider(): iterable
    {
        yield 'eshop' => [Industry::ESHOP, IssueCategory::INDUSTRY_ESHOP];
        yield 'webdesign' => [Industry::WEBDESIGN, IssueCategory::INDUSTRY_WEBDESIGN];
        yield 'real_estate' => [Industry::REAL_ESTATE, IssueCategory::INDUSTRY_REAL_ESTATE];
        yield 'automobile' => [Industry::AUTOMOBILE, IssueCategory::INDUSTRY_AUTOMOBILE];
        yield 'restaurant' => [Industry::RESTAURANT, IssueCategory::INDUSTRY_RESTAURANT];
        yield 'medical' => [Industry::MEDICAL, IssueCategory::INDUSTRY_MEDICAL];
    }

    // ==================== tryFrom/from Tests ====================

    #[Test]
    public function tryFrom_validString_returnsCategory(): void
    {
        self::assertSame(IssueCategory::SECURITY, IssueCategory::tryFrom('security'));
        self::assertSame(IssueCategory::INDUSTRY_ESHOP, IssueCategory::tryFrom('industry_eshop'));
    }

    #[Test]
    public function tryFrom_invalidString_returnsNull(): void
    {
        self::assertNull(IssueCategory::tryFrom('invalid'));
        self::assertNull(IssueCategory::tryFrom(''));
    }

    #[Test]
    public function from_invalidString_throwsException(): void
    {
        $this->expectException(\ValueError::class);
        IssueCategory::from('invalid');
    }
}
