<?php declare(strict_types = 1);

namespace Tests\Unit\Tier;

use App\Tier\CmsFeature;
use App\Tier\CmsTier;
use App\Tier\TierSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TierSettingsTest extends TestCase
{
    // ==================== Constructor Tests ====================

    #[Test]
    public function constructor_basicTier_setsCorrectTier(): void
    {
        $settings = new TierSettings('basic');

        self::assertSame(CmsTier::Basic, $settings->getTier());
    }

    #[Test]
    public function constructor_proTier_setsCorrectTier(): void
    {
        $settings = new TierSettings('pro');

        self::assertSame(CmsTier::Pro, $settings->getTier());
    }

    #[Test]
    public function constructor_enterpriseTier_setsCorrectTier(): void
    {
        $settings = new TierSettings('enterprise');

        self::assertSame(CmsTier::Enterprise, $settings->getTier());
    }

    #[Test]
    public function constructor_invalidTier_defaultsToBasic(): void
    {
        $settings = new TierSettings('invalid');

        self::assertSame(CmsTier::Basic, $settings->getTier());
    }

    #[Test]
    public function constructor_emptyString_defaultsToBasic(): void
    {
        $settings = new TierSettings('');

        self::assertSame(CmsTier::Basic, $settings->getTier());
    }

    #[Test]
    public function constructor_uppercaseTier_defaultsToBasic(): void
    {
        // Enum values are lowercase, uppercase should not match
        $settings = new TierSettings('BASIC');

        self::assertSame(CmsTier::Basic, $settings->getTier());
    }

    // ==================== hasMultilingual Tests ====================

    #[Test]
    public function hasMultilingual_basicTier_returnsFalse(): void
    {
        $settings = new TierSettings('basic');

        self::assertFalse($settings->hasMultilingual());
    }

    #[Test]
    public function hasMultilingual_proTier_returnsTrue(): void
    {
        $settings = new TierSettings('pro');

        self::assertTrue($settings->hasMultilingual());
    }

    #[Test]
    public function hasMultilingual_enterpriseTier_returnsTrue(): void
    {
        $settings = new TierSettings('enterprise');

        self::assertTrue($settings->hasMultilingual());
    }

    // ==================== isFeatureEnabled Tests ====================

    #[Test]
    public function isFeatureEnabled_multilingualOnBasic_returnsFalse(): void
    {
        $settings = new TierSettings('basic');

        self::assertFalse($settings->isFeatureEnabled(CmsFeature::Multilingual));
    }

    #[Test]
    public function isFeatureEnabled_multilingualOnPro_returnsTrue(): void
    {
        $settings = new TierSettings('pro');

        self::assertTrue($settings->isFeatureEnabled(CmsFeature::Multilingual));
    }

    #[Test]
    public function isFeatureEnabled_multilingualOnEnterprise_returnsTrue(): void
    {
        $settings = new TierSettings('enterprise');

        self::assertTrue($settings->isFeatureEnabled(CmsFeature::Multilingual));
    }

    // ==================== toArray Tests ====================

    #[Test]
    public function toArray_basicTier_returnsCorrectStructure(): void
    {
        $settings = new TierSettings('basic');

        $result = $settings->toArray();

        self::assertArrayHasKey('tier', $result);
        self::assertArrayHasKey('features', $result);
        self::assertEquals('basic', $result['tier']);
        self::assertIsArray($result['features']);
    }

    #[Test]
    public function toArray_basicTier_hasMultilingualDisabled(): void
    {
        $settings = new TierSettings('basic');

        $result = $settings->toArray();

        self::assertArrayHasKey('multilingual', $result['features']);
        self::assertFalse($result['features']['multilingual']);
    }

    #[Test]
    public function toArray_proTier_hasMultilingualEnabled(): void
    {
        $settings = new TierSettings('pro');

        $result = $settings->toArray();

        self::assertEquals('pro', $result['tier']);
        self::assertArrayHasKey('multilingual', $result['features']);
        self::assertTrue($result['features']['multilingual']);
    }

    #[Test]
    public function toArray_enterpriseTier_hasMultilingualEnabled(): void
    {
        $settings = new TierSettings('enterprise');

        $result = $settings->toArray();

        self::assertEquals('enterprise', $result['tier']);
        self::assertArrayHasKey('multilingual', $result['features']);
        self::assertTrue($result['features']['multilingual']);
    }

    #[Test]
    public function toArray_containsAllFeatures(): void
    {
        $settings = new TierSettings('basic');

        $result = $settings->toArray();
        $features = $result['features'];

        // All CmsFeature cases should be present in the features map
        foreach (CmsFeature::cases() as $feature) {
            self::assertArrayHasKey($feature->value, $features);
            self::assertIsBool($features[$feature->value]);
        }
    }

    // ==================== CmsTier Enum Tests ====================

    #[Test]
    public function cmsTier_getEnabledFeatures_basicReturnsEmptyArray(): void
    {
        $features = CmsTier::Basic->getEnabledFeatures();

        self::assertEmpty($features);
    }

    #[Test]
    public function cmsTier_getEnabledFeatures_proReturnsMultilingual(): void
    {
        $features = CmsTier::Pro->getEnabledFeatures();

        self::assertContains(CmsFeature::Multilingual, $features);
    }

    #[Test]
    public function cmsTier_getEnabledFeatures_enterpriseReturnsMultilingual(): void
    {
        $features = CmsTier::Enterprise->getEnabledFeatures();

        self::assertContains(CmsFeature::Multilingual, $features);
    }

    // ==================== CmsFeature Enum Tests ====================

    #[Test]
    public function cmsFeature_multilingualHasCorrectValue(): void
    {
        self::assertEquals('multilingual', CmsFeature::Multilingual->value);
    }
}
