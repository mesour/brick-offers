<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Analyzer;

use App\Enum\IssueCategory;
use App\Enum\IssueSeverity;
use App\Service\Analyzer\IssueRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueRegistry::class)]
final class IssueRegistryTest extends TestCase
{
    // ==================== get() Tests ====================

    #[Test]
    public function get_existingCode_returnsDefinition(): void
    {
        $definition = IssueRegistry::get('ssl_not_https');

        self::assertNotNull($definition);
        self::assertArrayHasKey('category', $definition);
        self::assertArrayHasKey('severity', $definition);
        self::assertArrayHasKey('title', $definition);
        self::assertArrayHasKey('description', $definition);
        self::assertArrayHasKey('impact', $definition);
    }

    #[Test]
    public function get_nonExistingCode_returnsNull(): void
    {
        self::assertNull(IssueRegistry::get('non_existing_code'));
    }

    #[Test]
    public function get_returnsCorrectTypes(): void
    {
        $definition = IssueRegistry::get('ssl_not_https');

        self::assertInstanceOf(IssueCategory::class, $definition['category']);
        self::assertInstanceOf(IssueSeverity::class, $definition['severity']);
        self::assertIsString($definition['title']);
        self::assertIsString($definition['description']);
        self::assertIsString($definition['impact']);
    }

    // ==================== getTitle() Tests ====================

    #[Test]
    public function getTitle_existingCode_returnsTitle(): void
    {
        $title = IssueRegistry::getTitle('ssl_not_https');

        self::assertSame('Web nepoužívá HTTPS', $title);
    }

    #[Test]
    public function getTitle_nonExistingCode_returnsCode(): void
    {
        $title = IssueRegistry::getTitle('unknown_code');

        self::assertSame('unknown_code', $title);
    }

    // ==================== getSeverity() Tests ====================

    #[Test]
    public function getSeverity_criticalIssue_returnsCritical(): void
    {
        $severity = IssueRegistry::getSeverity('ssl_not_https');

        self::assertSame(IssueSeverity::CRITICAL, $severity);
    }

    #[Test]
    public function getSeverity_recommendedIssue_returnsRecommended(): void
    {
        $severity = IssueRegistry::getSeverity('ssl_expiring_soon');

        self::assertSame(IssueSeverity::RECOMMENDED, $severity);
    }

    #[Test]
    public function getSeverity_optimizationIssue_returnsOptimization(): void
    {
        $severity = IssueRegistry::getSeverity('security_missing_x_content_type_options');

        self::assertSame(IssueSeverity::OPTIMIZATION, $severity);
    }

    #[Test]
    public function getSeverity_nonExistingCode_returnsOptimization(): void
    {
        // Default fallback for unknown codes
        $severity = IssueRegistry::getSeverity('unknown_code');

        self::assertSame(IssueSeverity::OPTIMIZATION, $severity);
    }

    // ==================== getCategory() Tests ====================

    #[Test]
    public function getCategory_httpIssue_returnsHttp(): void
    {
        $category = IssueRegistry::getCategory('ssl_not_https');

        self::assertSame(IssueCategory::HTTP, $category);
    }

    #[Test]
    public function getCategory_securityIssue_returnsSecurity(): void
    {
        $category = IssueRegistry::getCategory('security_missing_content_security_policy');

        self::assertSame(IssueCategory::SECURITY, $category);
    }

    #[Test]
    public function getCategory_seoIssue_returnsSeo(): void
    {
        $category = IssueRegistry::getCategory('seo_missing_title');

        self::assertSame(IssueCategory::SEO, $category);
    }

    #[Test]
    public function getCategory_nonExistingCode_returnsHttp(): void
    {
        // Default fallback for unknown codes
        $category = IssueRegistry::getCategory('unknown_code');

        self::assertSame(IssueCategory::HTTP, $category);
    }

    // ==================== getDescription() Tests ====================

    #[Test]
    public function getDescription_existingCode_returnsDescription(): void
    {
        $description = IssueRegistry::getDescription('ssl_not_https');

        self::assertNotEmpty($description);
        self::assertStringContainsString('SSL', $description);
    }

    #[Test]
    public function getDescription_nonExistingCode_returnsEmptyString(): void
    {
        $description = IssueRegistry::getDescription('unknown_code');

        self::assertSame('', $description);
    }

    // ==================== getImpact() Tests ====================

    #[Test]
    public function getImpact_existingCode_returnsImpact(): void
    {
        $impact = IssueRegistry::getImpact('ssl_not_https');

        self::assertNotEmpty($impact);
    }

    #[Test]
    public function getImpact_nonExistingCode_returnsEmptyString(): void
    {
        $impact = IssueRegistry::getImpact('unknown_code');

        self::assertSame('', $impact);
    }

    // ==================== has() Tests ====================

    #[Test]
    public function has_existingCode_returnsTrue(): void
    {
        self::assertTrue(IssueRegistry::has('ssl_not_https'));
        self::assertTrue(IssueRegistry::has('seo_missing_title'));
        self::assertTrue(IssueRegistry::has('security_insecure_form'));
    }

    #[Test]
    public function has_nonExistingCode_returnsFalse(): void
    {
        self::assertFalse(IssueRegistry::has('unknown_code'));
        self::assertFalse(IssueRegistry::has(''));
    }

    // ==================== getAllCodes() Tests ====================

    #[Test]
    public function getAllCodes_returnsNonEmptyArray(): void
    {
        $codes = IssueRegistry::getAllCodes();

        self::assertNotEmpty($codes);
        self::assertContainsOnly('string', $codes);
    }

    #[Test]
    public function getAllCodes_containsKnownCodes(): void
    {
        $codes = IssueRegistry::getAllCodes();

        self::assertContains('ssl_not_https', $codes);
        self::assertContains('seo_missing_title', $codes);
        self::assertContains('security_insecure_form', $codes);
    }

    #[Test]
    public function getAllCodes_everyCodeExists(): void
    {
        $codes = IssueRegistry::getAllCodes();

        foreach ($codes as $code) {
            self::assertTrue(IssueRegistry::has($code), sprintf(
                'Code %s should exist in registry',
                $code,
            ));
        }
    }

    // ==================== getByCategory() Tests ====================

    #[Test]
    public function getByCategory_http_returnsOnlyHttpIssues(): void
    {
        $issues = IssueRegistry::getByCategory(IssueCategory::HTTP);

        self::assertNotEmpty($issues);
        foreach ($issues as $code => $definition) {
            self::assertSame(IssueCategory::HTTP, $definition['category'], sprintf(
                'Issue %s should be HTTP category',
                $code,
            ));
        }
    }

    #[Test]
    public function getByCategory_security_returnsOnlySecurityIssues(): void
    {
        $issues = IssueRegistry::getByCategory(IssueCategory::SECURITY);

        self::assertNotEmpty($issues);
        foreach ($issues as $code => $definition) {
            self::assertSame(IssueCategory::SECURITY, $definition['category']);
        }
    }

    #[Test]
    public function getByCategory_seo_returnsOnlySeoIssues(): void
    {
        $issues = IssueRegistry::getByCategory(IssueCategory::SEO);

        self::assertNotEmpty($issues);
        foreach ($issues as $code => $definition) {
            self::assertSame(IssueCategory::SEO, $definition['category']);
        }
    }

    #[Test]
    #[DataProvider('allCategoriesProvider')]
    public function getByCategory_allCategories_returnCorrectIssues(IssueCategory $category): void
    {
        $issues = IssueRegistry::getByCategory($category);

        // Just verify the method doesn't throw and returns an array
        self::assertIsArray($issues);
        foreach ($issues as $definition) {
            self::assertSame($category, $definition['category']);
        }
    }

    /**
     * @return iterable<string, array{IssueCategory}>
     */
    public static function allCategoriesProvider(): iterable
    {
        foreach (IssueCategory::cases() as $category) {
            yield $category->value => [$category];
        }
    }

    // ==================== getBySeverity() Tests ====================

    #[Test]
    public function getBySeverity_critical_returnsOnlyCriticalIssues(): void
    {
        $issues = IssueRegistry::getBySeverity(IssueSeverity::CRITICAL);

        self::assertNotEmpty($issues);
        foreach ($issues as $code => $definition) {
            self::assertSame(IssueSeverity::CRITICAL, $definition['severity'], sprintf(
                'Issue %s should be CRITICAL severity',
                $code,
            ));
        }
    }

    #[Test]
    public function getBySeverity_recommended_returnsOnlyRecommendedIssues(): void
    {
        $issues = IssueRegistry::getBySeverity(IssueSeverity::RECOMMENDED);

        self::assertNotEmpty($issues);
        foreach ($issues as $code => $definition) {
            self::assertSame(IssueSeverity::RECOMMENDED, $definition['severity']);
        }
    }

    #[Test]
    public function getBySeverity_optimization_returnsOnlyOptimizationIssues(): void
    {
        $issues = IssueRegistry::getBySeverity(IssueSeverity::OPTIMIZATION);

        self::assertNotEmpty($issues);
        foreach ($issues as $code => $definition) {
            self::assertSame(IssueSeverity::OPTIMIZATION, $definition['severity']);
        }
    }

    // ==================== Data Integrity Tests ====================

    #[Test]
    public function allDefinitions_haveRequiredKeys(): void
    {
        $requiredKeys = ['category', 'severity', 'title', 'description', 'impact'];
        $codes = IssueRegistry::getAllCodes();

        foreach ($codes as $code) {
            $definition = IssueRegistry::get($code);
            self::assertNotNull($definition, sprintf('Code %s should exist', $code));

            foreach ($requiredKeys as $key) {
                self::assertArrayHasKey($key, $definition, sprintf(
                    'Code %s should have key %s',
                    $code,
                    $key,
                ));
            }
        }
    }

    #[Test]
    public function allDefinitions_haveNonEmptyStrings(): void
    {
        $codes = IssueRegistry::getAllCodes();

        foreach ($codes as $code) {
            $definition = IssueRegistry::get($code);

            self::assertNotEmpty($definition['title'], sprintf(
                'Code %s should have non-empty title',
                $code,
            ));
            self::assertNotEmpty($definition['description'], sprintf(
                'Code %s should have non-empty description',
                $code,
            ));
            self::assertNotEmpty($definition['impact'], sprintf(
                'Code %s should have non-empty impact',
                $code,
            ));
        }
    }

    #[Test]
    public function allDefinitions_haveValidEnums(): void
    {
        $codes = IssueRegistry::getAllCodes();

        foreach ($codes as $code) {
            $definition = IssueRegistry::get($code);

            self::assertInstanceOf(IssueCategory::class, $definition['category'], sprintf(
                'Code %s should have valid IssueCategory',
                $code,
            ));
            self::assertInstanceOf(IssueSeverity::class, $definition['severity'], sprintf(
                'Code %s should have valid IssueSeverity',
                $code,
            ));
        }
    }

    // ==================== Industry-Specific Issues Tests ====================

    #[Test]
    public function industryEshop_hasEshopIssues(): void
    {
        $issues = IssueRegistry::getByCategory(IssueCategory::INDUSTRY_ESHOP);

        self::assertNotEmpty($issues);
        self::assertArrayHasKey('eshop_no_product_pages', $issues);
        self::assertArrayHasKey('eshop_no_cart', $issues);
    }

    #[Test]
    public function industryWebdesign_hasWebdesignIssues(): void
    {
        $issues = IssueRegistry::getByCategory(IssueCategory::INDUSTRY_WEBDESIGN);

        self::assertNotEmpty($issues);
        self::assertArrayHasKey('webdesign_no_portfolio', $issues);
        self::assertArrayHasKey('webdesign_no_services', $issues);
    }

    #[Test]
    public function industryRealEstate_hasRealEstateIssues(): void
    {
        $issues = IssueRegistry::getByCategory(IssueCategory::INDUSTRY_REAL_ESTATE);

        self::assertNotEmpty($issues);
        self::assertArrayHasKey('realestate_no_listings', $issues);
    }

    #[Test]
    public function industryAutomobile_hasAutomobileIssues(): void
    {
        $issues = IssueRegistry::getByCategory(IssueCategory::INDUSTRY_AUTOMOBILE);

        self::assertNotEmpty($issues);
        self::assertArrayHasKey('automobile_no_inventory', $issues);
    }

    #[Test]
    public function industryRestaurant_hasRestaurantIssues(): void
    {
        $issues = IssueRegistry::getByCategory(IssueCategory::INDUSTRY_RESTAURANT);

        self::assertNotEmpty($issues);
        self::assertArrayHasKey('restaurant_no_menu', $issues);
    }

    #[Test]
    public function industryMedical_hasMedicalIssues(): void
    {
        $issues = IssueRegistry::getByCategory(IssueCategory::INDUSTRY_MEDICAL);

        self::assertNotEmpty($issues);
        self::assertArrayHasKey('medical_no_appointment', $issues);
    }
}
