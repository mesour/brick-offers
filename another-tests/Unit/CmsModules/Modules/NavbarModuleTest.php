<?php declare(strict_types = 1);

namespace Tests\Unit\CmsModules\Modules;

use App\CmsModules\Modules\Link\LinkModule;
use App\CmsModules\Modules\Navbar\NavbarModule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\CmsModules\Modules\Stub\StubModuleSettings;
use Tests\Unit\CmsModules\Modules\Stub\StubPersistentModule;

/**
 * Unit tests for NavbarModule rendering.
 *
 * Tests cover:
 * - Basic navbar structure
 * - Brand rendering (text, icon, image)
 * - Theme variations (light, dark, auto)
 * - Position variations (static, fixed-top, sticky-top)
 * - Container types
 * - Menu types (collapse, offcanvas)
 * - Nested child modules (links)
 * - Expand breakpoints
 *
 * @group unit
 */
final class NavbarModuleTest extends TestCase
{
    // ==================== Basic Structure ====================

    #[Test]
    public function renderDefault_basicNavbarStructure(): void
    {
        $settings = new StubModuleSettings([
            'position' => 'static',
            'expandBreakpoint' => 'lg',
            'mobileMenuType' => 'collapse',
            'containerType' => 'container',
            'theme' => 'light',
            'brand' => ['text' => 'Brand'],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        // Should have nav element with navbar class
        self::assertStringContainsString('<nav', $html);
        self::assertStringContainsString('class="navbar', $html);

        // Should have container
        self::assertStringContainsString('class="container"', $html);

        // Should have brand
        self::assertStringContainsString('navbar-brand', $html);

        // Should have toggler
        self::assertStringContainsString('navbar-toggler', $html);

        // Should have collapse menu
        self::assertStringContainsString('navbar-collapse', $html);
    }

    // ==================== Theme Variations ====================

    #[Test]
    public function renderDefault_lightTheme(): void
    {
        $settings = new StubModuleSettings([
            'theme' => 'light',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('navbar-light', $html);
        self::assertStringContainsString('bg-light', $html);
        self::assertStringNotContainsString('navbar-dark', $html);
    }

    #[Test]
    public function renderDefault_darkTheme(): void
    {
        $settings = new StubModuleSettings([
            'theme' => 'dark',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('navbar-dark', $html);
        self::assertStringContainsString('bg-dark', $html);
        self::assertStringNotContainsString('navbar-light', $html);
    }

    #[Test]
    public function renderDefault_autoTheme(): void
    {
        $settings = new StubModuleSettings([
            'theme' => 'auto',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        // Auto theme defaults to light for SSR but adds special class
        self::assertStringContainsString('navbar-light', $html);
        self::assertStringContainsString('navbar-theme-auto', $html);
    }

    #[Test]
    public function renderDefault_customBackgroundDisablesDefaultBg(): void
    {
        $settings = new StubModuleSettings([
            'theme' => 'light',
            'background' => '#FF0000',
            'backgroundEnabled' => true,
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        // Should NOT have bg-light/bg-dark when custom background is set
        self::assertStringNotContainsString('bg-light', $html);
        self::assertStringNotContainsString('bg-dark', $html);
    }

    // ==================== Position Variations ====================

    #[Test]
    public function renderDefault_staticPosition(): void
    {
        $settings = new StubModuleSettings([
            'position' => 'static',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        // Static position should not add position class
        self::assertStringNotContainsString('fixed-top', $html);
        self::assertStringNotContainsString('fixed-bottom', $html);
        self::assertStringNotContainsString('sticky-top', $html);
    }

    #[Test]
    public function renderDefault_fixedTopPosition(): void
    {
        $settings = new StubModuleSettings([
            'position' => 'fixed-top',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('fixed-top', $html);
    }

    #[Test]
    public function renderDefault_stickyTopPosition(): void
    {
        $settings = new StubModuleSettings([
            'position' => 'sticky-top',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('sticky-top', $html);
    }

    // ==================== Container Types ====================

    #[Test]
    public function renderDefault_containerFluid(): void
    {
        $settings = new StubModuleSettings([
            'containerType' => 'container-fluid',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('class="container-fluid"', $html);
    }

    // ==================== Expand Breakpoints ====================

    #[Test]
    public function renderDefault_expandLgBreakpoint(): void
    {
        $settings = new StubModuleSettings([
            'expandBreakpoint' => 'lg',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('navbar-expand-lg', $html);
    }

    #[Test]
    public function renderDefault_expandMdBreakpoint(): void
    {
        $settings = new StubModuleSettings([
            'expandBreakpoint' => 'md',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('navbar-expand-md', $html);
    }

    #[Test]
    public function renderDefault_alwaysExpanded(): void
    {
        $settings = new StubModuleSettings([
            'expandBreakpoint' => 'always',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        // Should not have navbar-expand-* class
        self::assertStringNotContainsString('navbar-expand-', $html);
    }

    // ==================== Brand Rendering ====================

    #[Test]
    public function renderDefault_brandWithText(): void
    {
        $settings = new StubModuleSettings([
            'brand' => [
                'text' => 'My Brand',
                'url' => '/',
            ],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('navbar-brand', $html);
        self::assertStringContainsString('navbar-brand-text', $html);
        self::assertStringContainsString('My Brand', $html);
    }

    #[Test]
    public function renderDefault_brandWithIcon(): void
    {
        $settings = new StubModuleSettings([
            'brand' => [
                'icon' => 'house',
                'url' => '/',
            ],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('navbar-brand-icon', $html);
        self::assertStringContainsString('bi bi-house', $html);
    }

    #[Test]
    public function renderDefault_brandWithTextAndIcon(): void
    {
        $settings = new StubModuleSettings([
            'brand' => [
                'text' => 'Acme Corp',
                'icon' => 'building',
                'url' => '/home',
            ],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('Acme Corp', $html);
        self::assertStringContainsString('bi bi-building', $html);
        self::assertStringContainsString('href="/home"', $html);
    }

    #[Test]
    public function renderDefault_brandWithColor(): void
    {
        $settings = new StubModuleSettings([
            'brand' => [
                'text' => 'Colored Brand',
                'color' => '#FF5500',
            ],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('color: #FF5500', $html);
    }

    #[Test]
    public function renderDefault_brandWithImage(): void
    {
        $settings = new StubModuleSettings([
            'brand' => [
                'imageFilename' => 'logo.png',
                'imageHeight' => 40,
                'imageWidth' => 'auto',
            ],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('navbar-brand-image', $html);
        self::assertStringContainsString('src="/uploads/logo.png"', $html);
    }

    #[Test]
    public function renderDefault_emptyBrandIsHidden(): void
    {
        $settings = new StubModuleSettings([
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        // Empty brand should be hidden
        self::assertStringContainsString('display: none', $html);
    }

    // ==================== Menu Types ====================

    #[Test]
    public function renderDefault_collapseMenu(): void
    {
        $settings = new StubModuleSettings([
            'mobileMenuType' => 'collapse',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings, 1);
        $html = $module->renderDefault();

        self::assertStringContainsString('data-bs-toggle="collapse"', $html);
        self::assertStringContainsString('data-bs-target="#navbar-1-collapse"', $html);
        self::assertStringContainsString('id="navbar-1-collapse"', $html);
    }

    #[Test]
    public function renderDefault_offcanvasMenu(): void
    {
        $settings = new StubModuleSettings([
            'mobileMenuType' => 'offcanvas',
            'offcanvasPosition' => 'start',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings, 2);
        $html = $module->renderDefault();

        self::assertStringContainsString('data-bs-toggle="offcanvas"', $html);
        self::assertStringContainsString('data-bs-target="#navbar-2-offcanvas"', $html);
        self::assertStringContainsString('id="navbar-2-offcanvas"', $html);
        self::assertStringContainsString('offcanvas-start', $html);
    }

    #[Test]
    public function renderDefault_offcanvasEndPosition(): void
    {
        $settings = new StubModuleSettings([
            'mobileMenuType' => 'offcanvas',
            'offcanvasPosition' => 'end',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('offcanvas-end', $html);
    }

    // ==================== Nested Child Modules (Links) ====================

    #[Test]
    public function renderDefault_withLeftLinks(): void
    {
        $settings = new StubModuleSettings([
            'mobileMenuType' => 'collapse',
            'brand' => [],
            'leftModules' => [101, 102],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);

        // Add child link modules
        $module->addModule(101, $this->createLinkModule(101, 'Home', '/'));
        $module->addModule(102, $this->createLinkModule(102, 'About', '/about'));

        $html = $module->renderDefault();

        // Should render links in left nav
        self::assertStringContainsString('Home', $html);
        self::assertStringContainsString('About', $html);
        self::assertStringContainsString('href="/"', $html);
        self::assertStringContainsString('href="/about"', $html);

        // Links should be wrapped in nav-item
        self::assertStringContainsString('nav-item', $html);

        // Links should have nav-link class added
        self::assertStringContainsString('nav-link', $html);
    }

    #[Test]
    public function renderDefault_withRightLinks(): void
    {
        $settings = new StubModuleSettings([
            'mobileMenuType' => 'collapse',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [201, 202],
        ]);

        $module = $this->createNavbarModule($settings);

        // Add child link modules
        $module->addModule(201, $this->createLinkModule(201, 'Login', '/login'));
        $module->addModule(202, $this->createLinkModule(202, 'Sign Up', '/signup', '_blank'));

        $html = $module->renderDefault();

        // Should render links in right nav
        self::assertStringContainsString('Login', $html);
        self::assertStringContainsString('Sign Up', $html);
        self::assertStringContainsString('href="/login"', $html);
        self::assertStringContainsString('href="/signup"', $html);
    }

    #[Test]
    public function renderDefault_withLeftAndRightLinks(): void
    {
        $settings = new StubModuleSettings([
            'mobileMenuType' => 'collapse',
            'brand' => ['text' => 'Site'],
            'leftModules' => [101, 102],
            'rightModules' => [201],
        ]);

        $module = $this->createNavbarModule($settings);

        // Add child link modules
        $module->addModule(101, $this->createLinkModule(101, 'Products', '/products'));
        $module->addModule(102, $this->createLinkModule(102, 'Services', '/services'));
        $module->addModule(201, $this->createLinkModule(201, 'Contact', '/contact'));

        $html = $module->renderDefault();

        // All links should be rendered
        self::assertStringContainsString('Products', $html);
        self::assertStringContainsString('Services', $html);
        self::assertStringContainsString('Contact', $html);

        // Should have both left and right navs
        self::assertStringContainsString('me-auto', $html); // left nav
        self::assertStringContainsString('ms-auto', $html); // right nav
    }

    #[Test]
    public function renderDefault_linksWithIconsInNavbar(): void
    {
        $settings = new StubModuleSettings([
            'mobileMenuType' => 'collapse',
            'brand' => [],
            'leftModules' => [101],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);

        // Add link with icon
        $module->addModule(101, $this->createLinkModule(101, 'Dashboard', '/dashboard', '_self', 'speedometer2'));

        $html = $module->renderDefault();

        self::assertStringContainsString('Dashboard', $html);
        self::assertStringContainsString('bi bi-speedometer2', $html);
    }

    #[Test]
    public function renderDefault_offcanvasWithLinks(): void
    {
        $settings = new StubModuleSettings([
            'mobileMenuType' => 'offcanvas',
            'offcanvasPosition' => 'start',
            'brand' => ['text' => 'Menu'],
            'leftModules' => [101, 102],
            'rightModules' => [201],
        ]);

        $module = $this->createNavbarModule($settings);

        $module->addModule(101, $this->createLinkModule(101, 'Home', '/'));
        $module->addModule(102, $this->createLinkModule(102, 'Blog', '/blog'));
        $module->addModule(201, $this->createLinkModule(201, 'Account', '/account'));

        $html = $module->renderDefault();

        // Should have both desktop collapse and offcanvas
        self::assertStringContainsString('navbar-collapse', $html);
        self::assertStringContainsString('offcanvas', $html);
        self::assertStringContainsString('offcanvas-body', $html);

        // Links should appear in both desktop and mobile menu
        // Count occurrences - each link should appear twice (desktop + mobile)
        self::assertEquals(2, \substr_count($html, '>Home<'));
        self::assertEquals(2, \substr_count($html, '>Blog<'));
        self::assertEquals(2, \substr_count($html, '>Account<'));
    }

    // ==================== Toggler Button ====================

    #[Test]
    public function renderDefault_togglerHasAccessibilityAttributes(): void
    {
        $settings = new StubModuleSettings([
            'mobileMenuType' => 'collapse',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringContainsString('aria-label="Toggle navigation"', $html);
        self::assertStringContainsString('navbar-toggler-icon', $html);
    }

    // ==================== Data Attributes for CMS ====================

    #[Test]
    public function renderDefault_hasContainerDataAttributes(): void
    {
        $settings = new StubModuleSettings([
            'mobileMenuType' => 'collapse',
            'brand' => [],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        // Should have data-cms-container and data-cms-slot attributes
        self::assertStringContainsString('data-cms-container="true"', $html);
        self::assertStringContainsString('data-cms-slot="left"', $html);
        self::assertStringContainsString('data-cms-slot="right"', $html);
    }

    // ==================== XSS Prevention ====================

    #[Test]
    public function renderDefault_escapesBrandText(): void
    {
        $settings = new StubModuleSettings([
            'brand' => [
                'text' => '<script>alert("xss")</script>',
            ],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringNotContainsString('<script>alert("xss")</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function renderDefault_escapesBrandIcon(): void
    {
        $settings = new StubModuleSettings([
            'brand' => [
                'icon' => '"><script>alert(1)</script>',
            ],
            'leftModules' => [],
            'rightModules' => [],
        ]);

        $module = $this->createNavbarModule($settings);
        $html = $module->renderDefault();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ==================== Helper Methods ====================

    private function createNavbarModule(StubModuleSettings $settings, int $id = 1): NavbarModule
    {
        $persistentModule = new StubPersistentModule(
            id: $id,
            type: 'navbar',
            settings: $settings->toArray(),
        );

        return new NavbarModule($persistentModule, 'navbar', $settings);
    }

    private function createLinkModule(
        int $id,
        string $text,
        string $url,
        string $target = '_self',
        string $icon = '',
    ): LinkModule
    {
        $linkSettings = new StubModuleSettings([
            'link' => [
                'text' => $text,
                'url' => ['type' => 'url', 'url' => $url],
                'icon' => $icon,
                'iconPosition' => 'left',
                'target' => $target,
            ],
        ]);

        $persistentModule = new StubPersistentModule(
            id: $id,
            type: 'link',
            settings: $linkSettings->toArray(),
        );

        return new LinkModule($persistentModule, 'link', $linkSettings);
    }
}
