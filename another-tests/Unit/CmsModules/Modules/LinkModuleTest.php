<?php declare(strict_types = 1);

namespace Tests\Unit\CmsModules\Modules;

use App\CmsModules\Modules\Link\LinkModule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\CmsModules\Modules\Stub\StubModuleSettings;
use Tests\Unit\CmsModules\Modules\Stub\StubPersistentModule;

/**
 * Unit tests for LinkModule rendering.
 *
 * Tests cover:
 * - Basic link rendering (text, url, target)
 * - Icon rendering (left/right position)
 * - Icon-only mode (no text)
 * - Target _blank with rel="noopener noreferrer"
 * - Backward compatibility with old settings structure
 * - XSS prevention
 *
 * @group unit
 */
final class LinkModuleTest extends TestCase
{
    // ==================== Basic Rendering ====================

    #[Test]
    public function renderDefault_basicLinkWithTextAndUrl(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Click me',
                'url' => ['type' => 'url', 'url' => 'https://example.com'],
                'icon' => '',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('<a', $html);
        self::assertStringContainsString('href="https://example.com"', $html);
        self::assertStringContainsString('target="_self"', $html);
        self::assertStringContainsString('Click me', $html);
        self::assertStringNotContainsString('rel=', $html);
    }

    #[Test]
    public function renderDefault_linkWithDefaultUrlWhenEmpty(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Empty URL',
                'url' => ['type' => 'url', 'url' => ''],
                'icon' => '',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        // Empty URL should render as empty href
        self::assertStringContainsString('href=""', $html);
    }

    #[Test]
    public function renderDefault_linkWithHashUrl(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Anchor',
                'url' => ['type' => 'url', 'url' => '#section'],
                'icon' => '',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('href="#section"', $html);
    }

    // ==================== Target Attribute ====================

    #[Test]
    public function renderDefault_targetBlankAddsRelAttribute(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'External',
                'url' => ['type' => 'url', 'url' => 'https://external.com'],
                'icon' => '',
                'iconPosition' => 'left',
                'target' => '_blank',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    #[Test]
    public function renderDefault_targetSelfNoRelAttribute(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Internal',
                'url' => ['type' => 'url', 'url' => '/page'],
                'icon' => '',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('target="_self"', $html);
        self::assertStringNotContainsString('rel=', $html);
    }

    // ==================== Icon Rendering ====================

    #[Test]
    public function renderDefault_iconOnLeft(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Home',
                'url' => ['type' => 'url', 'url' => '/'],
                'icon' => 'house',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        // Icon should appear before text
        self::assertStringContainsString('<span class="link-icon">', $html);
        self::assertStringContainsString('<i class="bi bi-house"></i>', $html);
        self::assertStringContainsString('<span class="link-text">Home</span>', $html);

        // Verify order: icon before text
        $iconPos = \strpos($html, 'link-icon');
        $textPos = \strpos($html, 'link-text');
        self::assertNotFalse($iconPos);
        self::assertNotFalse($textPos);
        self::assertLessThan($textPos, $iconPos, 'Icon should appear before text');
    }

    #[Test]
    public function renderDefault_iconOnRight(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Next',
                'url' => ['type' => 'url', 'url' => '/next'],
                'icon' => 'arrow-right',
                'iconPosition' => 'right',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        // Verify order: text before icon
        $iconPos = \strpos($html, 'link-icon');
        $textPos = \strpos($html, 'link-text');
        self::assertNotFalse($iconPos);
        self::assertNotFalse($textPos);
        self::assertLessThan($iconPos, $textPos, 'Text should appear before icon');
    }

    #[Test]
    public function renderDefault_iconOnly(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => '',
                'url' => ['type' => 'url', 'url' => '/search'],
                'icon' => 'search',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('<span class="link-icon">', $html);
        self::assertStringContainsString('<i class="bi bi-search"></i>', $html);
        // Text span should be empty but present
        self::assertStringContainsString('<span class="link-text"></span>', $html);
    }

    #[Test]
    public function renderDefault_noIconWhenEmpty(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Just text',
                'url' => ['type' => 'url', 'url' => '/'],
                'icon' => '',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringNotContainsString('link-icon', $html);
        self::assertStringContainsString('<span class="link-text">Just text</span>', $html);
    }

    // ==================== Inline Styles ====================

    #[Test]
    public function renderDefault_hasInlineFlexStyles(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Link',
                'url' => ['type' => 'url', 'url' => '/'],
                'icon' => '',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('display: inline-flex', $html);
        self::assertStringContainsString('align-items: center', $html);
        self::assertStringContainsString('gap: 0.35em', $html);
        self::assertStringContainsString('text-decoration: none', $html);
    }

    // ==================== URL Types ====================

    #[Test]
    public function renderDefault_pageTypeUrl(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Page link',
                'url' => [
                    'type' => 'page',
                    'pageId' => 123,
                    'pageSlug' => '/about-us',
                ],
                'icon' => '',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        // Page links should use pageSlug as href
        self::assertStringContainsString('href="/about-us"', $html);
    }

    #[Test]
    public function renderDefault_pageTypeUrlWithoutSlugFallsBackToSlash(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Page link',
                'url' => [
                    'type' => 'page',
                    'pageId' => 123,
                    // No pageSlug
                ],
                'icon' => '',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('href="/"', $html);
    }

    // ==================== JSON String Settings ====================

    #[Test]
    public function renderDefault_parsesJsonStringSettings(): void
    {
        $linkJson = \json_encode([
            'text' => 'JSON Link',
            'url' => ['type' => 'url', 'url' => 'https://json.test'],
            'icon' => 'star',
            'iconPosition' => 'right',
            'target' => '_blank',
        ], \JSON_THROW_ON_ERROR);

        $settings = new StubModuleSettings([
            'link' => $linkJson,
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('href="https://json.test"', $html);
        self::assertStringContainsString('JSON Link', $html);
        self::assertStringContainsString('bi bi-star', $html);
        self::assertStringContainsString('target="_blank"', $html);
    }

    // ==================== VariableInputValue Format ====================

    #[Test]
    public function renderDefault_handlesVariableInputValueFormat(): void
    {
        $linkValue = [
            'text' => 'Variable Link',
            'url' => ['type' => 'url', 'url' => '/var-link'],
            'icon' => '',
            'iconPosition' => 'left',
            'target' => '_self',
        ];

        $settings = new StubModuleSettings([
            'link' => [
                'value' => $linkValue,
                'linkedVariable' => null,
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('Variable Link', $html);
        self::assertStringContainsString('href="/var-link"', $html);
    }

    #[Test]
    public function renderDefault_handlesVariableInputValueWithJsonString(): void
    {
        $linkJson = \json_encode([
            'text' => 'JSON Variable',
            'url' => ['type' => 'url', 'url' => '/json-var'],
            'icon' => '',
            'iconPosition' => 'left',
            'target' => '_self',
        ], \JSON_THROW_ON_ERROR);

        $settings = new StubModuleSettings([
            'link' => [
                'value' => $linkJson,
                'linkedVariable' => null,
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('JSON Variable', $html);
    }

    // ==================== Backward Compatibility ====================

    #[Test]
    public function renderDefault_backwardCompatibilityWithOldStructure(): void
    {
        // Old structure: separate text, url, icon, iconPosition, target fields
        $settings = new StubModuleSettings([
            'text' => 'Old Link',
            'url' => 'https://old.example.com',
            'icon' => 'link',
            'iconPosition' => 'left',
            'target' => '_blank',
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('Old Link', $html);
        self::assertStringContainsString('href="https://old.example.com"', $html);
        self::assertStringContainsString('bi bi-link', $html);
        self::assertStringContainsString('target="_blank"', $html);
    }

    // ==================== XSS Prevention ====================

    #[Test]
    public function renderDefault_escapesHtmlInText(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => '<script>alert("xss")</script>',
                'url' => ['type' => 'url', 'url' => '/'],
                'icon' => '',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        // Script should be escaped
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderDefault_escapesHtmlInIconName(): void
    {
        $settings = new StubModuleSettings([
            'link' => [
                'text' => 'Test',
                'url' => ['type' => 'url', 'url' => '/'],
                'icon' => '"><script>alert(1)</script><i class="',
                'iconPosition' => 'left',
                'target' => '_self',
            ],
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        // Malicious icon should be escaped
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ==================== Edge Cases ====================

    #[Test]
    public function renderDefault_handlesNullLinkValue(): void
    {
        $settings = new StubModuleSettings([
            'link' => null,
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        // Should render with defaults
        self::assertStringContainsString('<a', $html);
        self::assertStringContainsString('href=""', $html);
    }

    #[Test]
    public function renderDefault_handlesMissingLinkKey(): void
    {
        $settings = new StubModuleSettings([]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        // Should render with defaults
        self::assertStringContainsString('<a', $html);
    }

    #[Test]
    public function renderDefault_handlesInvalidJsonString(): void
    {
        $settings = new StubModuleSettings([
            'link' => 'not valid json {{{',
        ]);

        $module = $this->createLinkModule($settings);
        $html = $module->renderDefault();

        // Should render with defaults (graceful fallback)
        self::assertStringContainsString('<a', $html);
    }

    // ==================== Helper Methods ====================

    private function createLinkModule(StubModuleSettings $settings): LinkModule
    {
        $persistentModule = new StubPersistentModule(
            id: 1,
            type: 'link',
            settings: $settings->toArray(),
        );

        return new LinkModule($persistentModule, 'link', $settings);
    }
}
