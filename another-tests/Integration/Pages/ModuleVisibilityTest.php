<?php declare(strict_types = 1);

namespace Tests\Integration\Pages;

use App\CmsModules\Database\Module;
use App\CmsModules\Database\ModuleRepository;
use App\CmsModules\Database\ModuleTranslation;
use App\CmsModules\Database\ModuleTranslationRepository;
use App\CmsModules\Database\TranslationStatus;
use App\Pages\Database\Page;
use App\Pages\Database\PageRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use App\Pages\PageManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for module visibility based on TranslationStatus.
 *
 * These tests verify that:
 * - HIDDEN modules are not visible to public (forPublic=true)
 * - PENDING modules are not visible to public (forPublic=true)
 * - TRANSLATED modules are visible to all
 * - Modules without translation are visible (with base settings)
 * - Admin users (forPublic=false) see all modules including HIDDEN/PENDING
 *
 * @group integration
 * @group database
 */
final class ModuleVisibilityTest extends IntegrationTestCase
{
    private PageManager $pageManager;
    private PageRepository $pageRepository;
    private PageTranslationRepository $pageTranslationRepository;
    private ModuleRepository $moduleRepository;
    private ModuleTranslationRepository $moduleTranslationRepository;

    /**
     * @throws \RuntimeException
     * @throws \Doctrine\DBAL\Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pageManager = $this->getService(PageManager::class);
        $this->pageRepository = $this->getService(PageRepository::class);
        $this->pageTranslationRepository = $this->getService(PageTranslationRepository::class);
        $this->moduleRepository = $this->getService(ModuleRepository::class);
        $this->moduleTranslationRepository = $this->getService(ModuleTranslationRepository::class);
    }

    /**
     * @throws \App\Pages\PageNotFoundException
     */
    #[Test]
    public function hiddenModuleNotVisibleForPublic(): void
    {
        // Create page with custom layout
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $slug = '/test-hidden';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create module with HIDDEN status
        $module = Module::create('text', null, $translation);
        $this->moduleRepository->save($module);

        $moduleTranslation = new ModuleTranslation($module, 'cs');
        $moduleTranslation->setStatus(TranslationStatus::HIDDEN);
        $this->moduleTranslationRepository->save($moduleTranslation);

        // Load page for public - module should NOT be visible
        $container = $this->pageManager->createPageContainer($slug, null, forPublic: true);

        self::assertEmpty($container->getModules(), 'HIDDEN module should not be visible to public');
    }

    /**
     * @throws \App\Pages\PageNotFoundException
     */
    #[Test]
    public function hiddenModuleVisibleForAdmin(): void
    {
        // Create page with custom layout
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $slug = '/test-hidden-admin';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create module with HIDDEN status
        $module = Module::create('text', null, $translation);
        $this->moduleRepository->save($module);

        $moduleTranslation = new ModuleTranslation($module, 'cs');
        $moduleTranslation->setStatus(TranslationStatus::HIDDEN);
        $this->moduleTranslationRepository->save($moduleTranslation);

        // Load page for admin - module SHOULD be visible
        $container = $this->pageManager->createPageContainer($slug, null, forPublic: false);

        self::assertCount(1, $container->getModules(), 'HIDDEN module should be visible to admin');
    }

    /**
     * @throws \App\Pages\PageNotFoundException
     */
    #[Test]
    public function pendingModuleNotVisibleForPublic(): void
    {
        // Create page with custom layout
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $slug = '/test-pending';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create module with PENDING status
        $module = Module::create('text', null, $translation);
        $this->moduleRepository->save($module);

        $moduleTranslation = new ModuleTranslation($module, 'cs');
        $moduleTranslation->setStatus(TranslationStatus::PENDING);
        $this->moduleTranslationRepository->save($moduleTranslation);

        // Load page for public - module should NOT be visible
        $container = $this->pageManager->createPageContainer($slug, null, forPublic: true);

        self::assertEmpty($container->getModules(), 'PENDING module should not be visible to public');
    }

    /**
     * @throws \App\Pages\PageNotFoundException
     */
    #[Test]
    public function pendingModuleVisibleForAdmin(): void
    {
        // Create page with custom layout
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $slug = '/test-pending-admin';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create module with PENDING status
        $module = Module::create('text', null, $translation);
        $this->moduleRepository->save($module);

        $moduleTranslation = new ModuleTranslation($module, 'cs');
        $moduleTranslation->setStatus(TranslationStatus::PENDING);
        $this->moduleTranslationRepository->save($moduleTranslation);

        // Load page for admin - module SHOULD be visible
        $container = $this->pageManager->createPageContainer($slug, null, forPublic: false);

        self::assertCount(1, $container->getModules(), 'PENDING module should be visible to admin');
    }

    /**
     * @throws \App\Pages\PageNotFoundException
     */
    #[Test]
    public function translatedModuleVisibleForAll(): void
    {
        // Create page with custom layout
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $slug = '/test-translated';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create module with TRANSLATED status (default)
        $module = Module::create('text', null, $translation);
        $this->moduleRepository->save($module);

        $moduleTranslation = new ModuleTranslation($module, 'cs');
        $moduleTranslation->setStatus(TranslationStatus::TRANSLATED);
        $this->moduleTranslationRepository->save($moduleTranslation);

        // Load page for public - module SHOULD be visible
        $containerPublic = $this->pageManager->createPageContainer($slug, null, forPublic: true);
        self::assertCount(1, $containerPublic->getModules(), 'TRANSLATED module should be visible to public');

        // Load page for admin - module SHOULD be visible
        $containerAdmin = $this->pageManager->createPageContainer($slug, null, forPublic: false);
        self::assertCount(1, $containerAdmin->getModules(), 'TRANSLATED module should be visible to admin');
    }

    /**
     * @throws \App\Pages\PageNotFoundException
     */
    #[Test]
    public function moduleWithoutTranslationVisibleForPublic(): void
    {
        // Create page with custom layout
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $slug = '/test-no-translation';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create module WITHOUT translation (null ModuleTranslation)
        $module = Module::create('text', null, $translation);
        $this->moduleRepository->save($module);

        // NO ModuleTranslation created - module should still be visible with base settings

        // Load page for public - module SHOULD be visible
        $container = $this->pageManager->createPageContainer($slug, null, forPublic: true);

        self::assertCount(1, $container->getModules(), 'Module without translation should be visible to public');
    }

    /**
     * @throws \App\Pages\PageNotFoundException
     */
    #[Test]
    public function mixedStatusModulesFilteredCorrectly(): void
    {
        // Create page with custom layout
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $slug = '/test-mixed';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create 4 modules with different statuses
        // 1. TRANSLATED - should be visible
        $moduleTranslated = Module::create('text', null, $translation);
        $this->moduleRepository->save($moduleTranslated);
        $mtTranslated = new ModuleTranslation($moduleTranslated, 'cs');
        $mtTranslated->setStatus(TranslationStatus::TRANSLATED);
        $this->moduleTranslationRepository->save($mtTranslated);

        // 2. HIDDEN - should NOT be visible
        $moduleHidden = Module::create('text', null, $translation);
        $this->moduleRepository->save($moduleHidden);
        $mtHidden = new ModuleTranslation($moduleHidden, 'cs');
        $mtHidden->setStatus(TranslationStatus::HIDDEN);
        $this->moduleTranslationRepository->save($mtHidden);

        // 3. PENDING - should NOT be visible
        $modulePending = Module::create('text', null, $translation);
        $this->moduleRepository->save($modulePending);
        $mtPending = new ModuleTranslation($modulePending, 'cs');
        $mtPending->setStatus(TranslationStatus::PENDING);
        $this->moduleTranslationRepository->save($mtPending);

        // 4. No translation - should be visible
        $moduleNoTranslation = Module::create('text', null, $translation);
        $this->moduleRepository->save($moduleNoTranslation);

        // Load page for public - only 2 modules should be visible (TRANSLATED + no translation)
        $containerPublic = $this->pageManager->createPageContainer($slug, null, forPublic: true);
        self::assertCount(
            2,
            $containerPublic->getModules(),
            'Only TRANSLATED and no-translation modules should be visible to public',
        );

        // Load page for admin - all 4 modules should be visible
        $containerAdmin = $this->pageManager->createPageContainer($slug, null, forPublic: false);
        self::assertCount(4, $containerAdmin->getModules(), 'All modules should be visible to admin');
    }

    /**
     * @throws \App\Pages\PageNotFoundException
     */
    #[Test]
    public function nestedModulesWithHiddenParent(): void
    {
        // Create page with custom layout
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $slug = '/test-nested-hidden';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create container (HIDDEN)
        $container = Module::create('container', null, $translation);
        $this->moduleRepository->save($container);
        $mtContainer = new ModuleTranslation($container, 'cs');
        $mtContainer->setStatus(TranslationStatus::HIDDEN);
        $this->moduleTranslationRepository->save($mtContainer);

        // Create child inside hidden container (TRANSLATED)
        $child = Module::create('text', null, $translation);
        $child->setParent($container);
        $this->moduleRepository->save($child);
        $mtChild = new ModuleTranslation($child, 'cs');
        $mtChild->setStatus(TranslationStatus::TRANSLATED);
        $this->moduleTranslationRepository->save($mtChild);

        // Load page for public - container is hidden, so no root modules
        $containerPublic = $this->pageManager->createPageContainer($slug, null, forPublic: true);
        self::assertEmpty($containerPublic->getModules(), 'Hidden container should not be visible');

        // Load page for admin - container should be visible with child
        $containerAdmin = $this->pageManager->createPageContainer($slug, null, forPublic: false);
        self::assertCount(1, $containerAdmin->getModules(), 'Hidden container should be visible to admin');
    }

    /**
     * @throws \App\Pages\PageNotFoundException
     */
    #[Test]
    public function differentLanguageTranslationStatus(): void
    {
        // Create page with custom layout
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $slug = '/test-lang-status';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create module
        $module = Module::create('text', null, $translation);
        $this->moduleRepository->save($module);

        // Czech translation is HIDDEN
        $mtCs = new ModuleTranslation($module, 'cs');
        $mtCs->setStatus(TranslationStatus::HIDDEN);
        $this->moduleTranslationRepository->save($mtCs);

        // English translation is TRANSLATED (but page is in Czech)
        $mtEn = new ModuleTranslation($module, 'en');
        $mtEn->setStatus(TranslationStatus::TRANSLATED);
        $this->moduleTranslationRepository->save($mtEn);

        // Load page for public in Czech - module should NOT be visible (Czech translation is HIDDEN)
        $containerPublic = $this->pageManager->createPageContainer($slug, 'cs', forPublic: true);
        self::assertEmpty($containerPublic->getModules(), 'Module with HIDDEN Czech translation should not be visible');
    }
}
