<?php declare(strict_types = 1);

namespace Tests\Integration\Pages;

use App\CmsModules\Database\Module;
use App\CmsModules\Database\ModuleRepository;
use App\Pages\Database\Page;
use App\Pages\Database\PageRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use App\Pages\PageContainer;
use App\Pages\PageManager;
use App\Pages\PageNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for PageManager.
 *
 * These tests verify the complete flow from slug to rendered page container.
 * They require a running database and proper DI container setup.
 *
 * @group integration
 * @group database
 */
final class PageManagerIntegrationTest extends IntegrationTestCase
{
    private PageManager $pageManager;
    private PageRepository $pageRepository;
    private PageTranslationRepository $pageTranslationRepository;

    /**
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \Doctrine\DBAL\Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pageManager = $this->getService(PageManager::class);
        $this->pageRepository = $this->getService(PageRepository::class);
        $this->pageTranslationRepository = $this->getService(PageTranslationRepository::class);
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function loadPageBySlug(): void
    {
        // Create a page with translation
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $slug = '/test-page';
        $hash = \sha1(\strtolower($slug));
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            $hash,
            'Test Page Title',
            'Test description',
        );
        $this->pageTranslationRepository->save($translation);

        // Load page by slug
        $container = $this->pageManager->createPageContainer($slug);

        // Verify PageContainer
        self::assertInstanceOf(PageContainer::class, $container);
        self::assertNotNull($container->getPageTranslation());
        self::assertEquals($translation->getId(), $container->getPageTranslation()->getId());
        self::assertEquals('Test Page Title', $container->getPageTranslation()->getTitle());
        self::assertEquals('cs', $container->getLanguage());
        self::assertEmpty($container->getModules());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function handleNonExistentPage(): void
    {
        $this->expectException(PageNotFoundException::class);
        $this->expectExceptionMessage('Page translation not found for slug "/non-existent-slug-12345"');

        $this->pageManager->createPageContainer('/non-existent-slug-12345');
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function loadHomepage(): void
    {
        // Create homepage (first root page)
        $homepage = new Page('Homepage');
        $this->pageRepository->save($homepage);

        $slug = '/';
        $hash = \sha1($slug);
        $translation = new PageTranslation(
            $homepage,
            'cs',
            $slug,
            $hash,
            'Homepage Title',
        );
        $this->pageTranslationRepository->save($translation);

        // Load homepage
        $container = $this->pageManager->createPageContainer('/');

        // Verify
        self::assertInstanceOf(PageContainer::class, $container);
        self::assertNotNull($container->getPageTranslation());
        self::assertEquals('Homepage Title', $container->getPageTranslation()->getTitle());
        self::assertEquals('/', $container->getPageTranslation()->getSlug());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function languageFallback(): void
    {
        // Create homepage first (required for fallback)
        $homepage = new Page('Homepage');
        $this->pageRepository->save($homepage);

        $homepageTranslation = new PageTranslation(
            $homepage,
            'cs',
            '/',
            \sha1('/'),
            'Homepage',
        );
        $this->pageTranslationRepository->save($homepageTranslation);

        // Create page with Czech translation only
        $page = new Page('Czech Only Page');
        $this->pageRepository->save($page);

        $slug = '/ceska-stranka';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Česká stránka',
        );
        $this->pageTranslationRepository->save($translation);

        // Request English version - should fallback to homepage
        $container = $this->pageManager->createPageContainer($slug, 'en');

        // Verify fallback behavior
        self::assertInstanceOf(PageContainer::class, $container);
        self::assertNull($container->getPageTranslation());
        self::assertEquals('en', $container->getLanguage());
        self::assertEmpty($container->getModules());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function caseInsensitiveSlugMatching(): void
    {
        $page = new Page('About Us');
        $this->pageRepository->save($page);

        // Store with original case but hash is lowercase
        $slug = '/About-Us';
        $hash = \sha1(\strtolower($slug)); // sha1('/about-us')
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            $hash,
            'About Us Title',
        );
        $this->pageTranslationRepository->save($translation);

        // Request with different case - should find the page
        $container = $this->pageManager->createPageContainer('/ABOUT-US');

        self::assertInstanceOf(PageContainer::class, $container);
        self::assertNotNull($container->getPageTranslation());
        self::assertEquals('About Us Title', $container->getPageTranslation()->getTitle());

        // Also test lowercase
        $container2 = $this->pageManager->createPageContainer('/about-us');
        self::assertNotNull($container2->getPageTranslation());
        self::assertEquals('About Us Title', $container2->getPageTranslation()->getTitle());
    }

    /**
     * @throws PageNotFoundException
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     */
    #[Test]
    public function moduleTreeLoading(): void
    {
        // Create page
        $page = new Page('Page With Modules');
        $this->pageRepository->save($page);

        $slug = '/page-with-modules';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Page With Modules',
        );
        $translation->setCustom(true); // Enable custom layout so modules are bound to PageTranslation
        $this->pageTranslationRepository->save($translation);

        // Create modules for this page
        $moduleRepository = $this->getService(ModuleRepository::class);

        // Root container module
        $containerModule = Module::create('container', null, $translation);
        $moduleRepository->save($containerModule);

        // Row inside container
        $rowModule = Module::create('row', null, $translation);
        $rowModule->setParent($containerModule);
        $moduleRepository->save($rowModule);

        // Text inside row
        $textModule = Module::create('text', null, $translation);
        $textModule->setParent($rowModule);
        $moduleRepository->save($textModule);

        // Load page container
        $container = $this->pageManager->createPageContainer($slug);

        // Verify module tree
        self::assertNotEmpty($container->getModules());
        // Root modules (without parent) should be returned
        self::assertCount(1, $container->getModules());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function inactivePageHandling(): void
    {
        // Create homepage for fallback
        $homepage = new Page('Homepage');
        $this->pageRepository->save($homepage);

        $homepageTranslation = new PageTranslation(
            $homepage,
            'cs',
            '/',
            \sha1('/'),
            'Homepage',
        );
        $this->pageTranslationRepository->save($homepageTranslation);

        // Create page with inactive translation
        $page = new Page('Inactive Page');
        $this->pageRepository->save($page);

        $slug = '/inactive-page';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Inactive Page Title',
        );
        $translation->setActive(false);
        $this->pageTranslationRepository->save($translation);

        // Should throw exception because inactive pages are not visible
        $this->expectException(PageNotFoundException::class);
        $this->pageManager->createPageContainer($slug);
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function multipleLanguages(): void
    {
        $page = new Page('Multilingual Page');
        $this->pageRepository->save($page);

        // Czech translation
        $csSlug = '/o-nas';
        $csTranslation = new PageTranslation(
            $page,
            'cs',
            $csSlug,
            \sha1(\strtolower($csSlug)),
            'O nás',
        );
        $this->pageTranslationRepository->save($csTranslation);

        // English translation
        $enSlug = '/about-us';
        $enTranslation = new PageTranslation(
            $page,
            'en',
            $enSlug,
            \sha1(\strtolower($enSlug)),
            'About Us',
        );
        $this->pageTranslationRepository->save($enTranslation);

        // Request Czech version
        $csContainer = $this->pageManager->createPageContainer($csSlug, 'cs');
        self::assertNotNull($csContainer->getPageTranslation());
        self::assertEquals('O nás', $csContainer->getPageTranslation()->getTitle());
        self::assertEquals('cs', $csContainer->getLanguage());

        // Request English version
        $enContainer = $this->pageManager->createPageContainer($enSlug, 'en');
        self::assertNotNull($enContainer->getPageTranslation());
        self::assertEquals('About Us', $enContainer->getPageTranslation()->getTitle());
        self::assertEquals('en', $enContainer->getLanguage());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function languageMismatchReturnsHomepageFallback(): void
    {
        // Create homepage
        $homepage = new Page('Homepage');
        $this->pageRepository->save($homepage);

        $homepageTranslation = new PageTranslation(
            $homepage,
            'cs',
            '/',
            \sha1('/'),
            'Homepage',
        );
        $this->pageTranslationRepository->save($homepageTranslation);

        // Create page with Czech translation
        $page = new Page('Czech Page');
        $this->pageRepository->save($page);

        $slug = '/ceska-stranka';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Česká stránka',
        );
        $this->pageTranslationRepository->save($translation);

        // Request with English language - translation exists but language doesn't match
        $container = $this->pageManager->createPageContainer($slug, 'en');

        // Should return homepage fallback
        self::assertInstanceOf(PageContainer::class, $container);
        self::assertNull($container->getPageTranslation());
        self::assertEquals('en', $container->getLanguage());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function pageContainerReturnsCorrectPageId(): void
    {
        $page = new Page('Page For ID Test');
        $this->pageRepository->save($page);

        $slug = '/page-id-test';
        $translation = new PageTranslation(
            $page,
            'cs',
            $slug,
            \sha1(\strtolower($slug)),
            'Page ID Test',
        );
        $this->pageTranslationRepository->save($translation);

        $container = $this->pageManager->createPageContainer($slug);

        self::assertEquals($page->getId(), $container->getPageId());
    }
}
