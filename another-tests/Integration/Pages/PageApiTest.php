<?php declare(strict_types = 1);

namespace Tests\Integration\Pages;

use App\CmsModules\Database\ModuleTranslationDraftRepository;
use App\Pages\Database\Page;
use App\Pages\Database\PageDraftRepository;
use App\Pages\Database\PageRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use App\Pages\PageDraftManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\ApiIntegrationTestCase;

/**
 * Integration tests for Page API endpoints.
 *
 * @group integration
 * @group database
 */
final class PageApiTest extends ApiIntegrationTestCase
{
    private PageRepository $pageRepository;
    private PageTranslationRepository $pageTranslationRepository;

    /**
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     * @throws \Doctrine\DBAL\Exception
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipTests) {
            return;
        }

        $this->pageRepository = $this->getService(PageRepository::class);
        $this->pageTranslationRepository = $this->getService(PageTranslationRepository::class);
    }

    // =========================================================================
    // GET /api/pages
    // =========================================================================

    #[Test]
    public function getPagesReturnsEmptyArrayWhenNoPages(): void
    {
        $result = $this->apiGet('pages', ['language' => 'cs']);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['pages']);
    }

    #[Test]
    public function getPagesReturnsAllPagesWithTranslations(): void
    {
        // Create pages
        $homepage = new Page('Homepage');
        $this->pageRepository->save($homepage);

        $homepageTranslation = new PageTranslation(
            $homepage,
            'cs',
            '/',
            \sha1('/'),
            'Domů',
        );
        $this->pageTranslationRepository->save($homepageTranslation);

        $aboutPage = new Page('About');
        $this->pageRepository->save($aboutPage);

        $aboutTranslation = new PageTranslation(
            $aboutPage,
            'cs',
            '/o-nas',
            \sha1('/o-nas'),
            'O nás',
        );
        $this->pageTranslationRepository->save($aboutTranslation);

        $result = $this->apiGet('pages', ['language' => 'cs']);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['pages']);
        self::assertCount(2, $data['pages']);
    }

    // =========================================================================
    // GET /api/page-translation
    // =========================================================================

    #[Test]
    public function getPageTranslationReturnsTranslationData(): void
    {
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $translation = new PageTranslation(
            $page,
            'cs',
            '/test-page',
            \sha1('/test-page'),
            'Test Title',
            'Test description',
            'keyword1, keyword2',
        );
        $this->pageTranslationRepository->save($translation);

        $result = $this->apiGet('pageTranslation', [
            'pageId' => (string) $page->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['exists']);
        self::assertEquals('Test Title', $data['title']);
        self::assertEquals('/test-page', $data['slug']);
        self::assertEquals('Test description', $data['description']);
    }

    #[Test]
    public function getPageTranslationReturns404ForNonExistentPage(): void
    {
        $result = $this->apiGet('pageTranslation', [
            'pageId' => '999999',
            'language' => 'cs',
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    #[Test]
    public function getPageTranslationReturnsExistsFalseForNonExistentLanguage(): void
    {
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $translation = new PageTranslation(
            $page,
            'cs',
            '/test-page',
            \sha1('/test-page'),
            'Test Title',
        );
        $this->pageTranslationRepository->save($translation);

        $result = $this->apiGet('pageTranslation', [
            'pageId' => (string) $page->getId(),
            'language' => 'en', // No English translation
        ]);

        // Returns 200 with exists=false instead of 404
        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertFalse($data['exists']);
        self::assertEquals($page->getId(), $data['pageId']);
        self::assertEquals('en', $data['language']);
    }

    // =========================================================================
    // POST /api/page-create
    // =========================================================================

    #[Test]
    public function createPageCreatesNewPageWithTranslation(): void
    {
        $result = $this->apiPost('pageCreate', [
            'name' => 'New Page',
            'language' => 'cs',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
        self::assertEquals('New Page', $data['name']);
        self::assertArrayHasKey('translation', $data);
        self::assertIsArray($data['translation']);
        self::assertEquals('New Page', $data['translation']['title']);
        self::assertEquals('/new-page', $data['translation']['slug']);
    }

    #[Test]
    public function createPageWithParent(): void
    {
        // Create parent page
        $parentPage = new Page('Parent');
        $this->pageRepository->save($parentPage);

        $result = $this->apiPost('pageCreate', [
            'name' => 'Child Page',
            'language' => 'cs',
            'parentId' => (string) $parentPage->getId(),
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals($parentPage->getId(), $data['parentId']);
    }

    #[Test]
    public function createPageGeneratesUniqueSlug(): void
    {
        // Create first page
        $page1 = new Page('Test');
        $this->pageRepository->save($page1);

        $translation1 = new PageTranslation(
            $page1,
            'cs',
            '/test',
            \sha1('/test'),
            'Test',
        );
        $this->pageTranslationRepository->save($translation1);

        // Create second page with same name - should get unique slug
        $result = $this->apiPost('pageCreate', [
            'name' => 'Test',
            'language' => 'cs',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('translation', $data);
        self::assertIsArray($data['translation']);
        self::assertArrayHasKey('slug', $data['translation']);
        self::assertIsString($data['translation']['slug']);
        self::assertNotEquals('/test', $data['translation']['slug']);
        self::assertStringStartsWith('/test-', $data['translation']['slug']);
    }

    #[Test]
    public function create404Page(): void
    {
        $result = $this->apiPost('pageCreate', [
            'name' => '404 Not Found',
            'language' => 'cs',
            'is404' => '1',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['is404']);
    }

    #[Test]
    public function cannotCreateSecond404PageForSameParent(): void
    {
        // Create first 404 page
        $page404 = new Page('404 Page');
        $page404->setIs404(true);
        $this->pageRepository->save($page404);

        // Try to create second 404 page at root level
        $result = $this->apiPost('pageCreate', [
            'name' => 'Another 404',
            'language' => 'cs',
            'is404' => '1',
        ]);

        $this->assertApiStatusCode(409, $result);
        $this->assertApiError('404_ALREADY_EXISTS', $result);
    }

    // =========================================================================
    // POST /api/page-translation-create
    // =========================================================================

    #[Test]
    public function createPageTranslationForExistingPage(): void
    {
        $page = new Page('My Page');
        $this->pageRepository->save($page);

        // Create Czech translation
        $csTranslation = new PageTranslation(
            $page,
            'cs',
            '/moje-stranka',
            \sha1('/moje-stranka'),
            'Moje stránka',
        );
        $this->pageTranslationRepository->save($csTranslation);

        // Create English translation via API
        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page->getId(),
            'language' => 'en',
            'title' => 'My Page',
            'slug' => '/my-page',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals('My Page', $data['title']);
        self::assertEquals('/my-page', $data['slug']);
        self::assertEquals('en', $data['language']);
    }

    #[Test]
    public function cannotCreateDuplicateTranslation(): void
    {
        $page = new Page('My Page');
        $this->pageRepository->save($page);

        // Create Czech translation
        $csTranslation = new PageTranslation(
            $page,
            'cs',
            '/moje-stranka',
            \sha1('/moje-stranka'),
            'Moje stránka',
        );
        $this->pageTranslationRepository->save($csTranslation);

        // Try to create another Czech translation
        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page->getId(),
            'language' => 'cs',
            'title' => 'Another Title',
            'slug' => '/jina-stranka',
        ]);

        $this->assertApiStatusCode(409, $result);
        $this->assertApiError('TRANSLATION_ALREADY_EXISTS', $result);
    }

    #[Test]
    public function createTranslationValidatesSlugFormat(): void
    {
        $page = new Page('My Page');
        $this->pageRepository->save($page);

        // Invalid slug with uppercase
        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page->getId(),
            'language' => 'cs',
            'title' => 'My Page',
            'slug' => '/My-Page',
        ]);

        $this->assertApiStatusCode(400, $result);
        $this->assertApiError('INVALID_SLUG', $result);
    }

    #[Test]
    public function createTranslationRejectsConsecutiveHyphens(): void
    {
        $page = new Page('My Page');
        $this->pageRepository->save($page);

        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page->getId(),
            'language' => 'cs',
            'title' => 'My Page',
            'slug' => '/my--page',
        ]);

        $this->assertApiStatusCode(400, $result);
        $this->assertApiError('INVALID_SLUG', $result);
    }

    #[Test]
    public function createTranslationRejectsDuplicateSlug(): void
    {
        // Create first page with slug
        $page1 = new Page('Page 1');
        $this->pageRepository->save($page1);

        $translation1 = new PageTranslation(
            $page1,
            'cs',
            '/test-slug',
            \sha1('/test-slug'),
            'Page 1',
        );
        $this->pageTranslationRepository->save($translation1);

        // Create second page and try to use same slug
        $page2 = new Page('Page 2');
        $this->pageRepository->save($page2);

        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page2->getId(),
            'language' => 'cs',
            'title' => 'Page 2',
            'slug' => '/test-slug',
        ]);

        $this->assertApiStatusCode(409, $result);
        $this->assertApiError('SLUG_EXISTS', $result);
    }

    // =========================================================================
    // POST /api/page-translation-update
    // =========================================================================

    #[Test]
    public function updatePageTranslation(): void
    {
        $page = new Page('My Page');
        $this->pageRepository->save($page);

        $translation = new PageTranslation(
            $page,
            'cs',
            '/original-slug',
            \sha1('/original-slug'),
            'Original Title',
        );
        $this->pageTranslationRepository->save($translation);

        $result = $this->apiPost('pageTranslationUpdate', [
            'id' => (string) $translation->getId(),
            'title' => 'Updated Title',
            'slug' => '/updated-slug',
            'description' => 'New description',
            'keywords' => 'new, keywords',
            'indexable' => '1',
            'version' => (string) $translation->getVersion(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('newVersion', $data);
    }

    #[Test]
    public function updatePageTranslationVersionConflict(): void
    {
        $page = new Page('My Page');
        $this->pageRepository->save($page);

        $translation = new PageTranslation(
            $page,
            'cs',
            '/my-page',
            \sha1('/my-page'),
            'My Page',
        );
        $this->pageTranslationRepository->save($translation);

        // Store initial version (because EntityManager identity map shares objects)
        $initialVersion = $translation->getVersion();

        // First update should succeed
        $result1 = $this->apiPost('pageTranslationUpdate', [
            'id' => (string) $translation->getId(),
            'title' => 'Updated Title',
            'slug' => '/my-page',
            'description' => '',
            'keywords' => '',
            'indexable' => '1',
            'version' => (string) $initialVersion,
        ]);
        $this->assertApiSuccess($result1);

        // Second update with old version should fail
        $result2 = $this->apiPost('pageTranslationUpdate', [
            'id' => (string) $translation->getId(),
            'title' => 'Another Title',
            'slug' => '/my-page',
            'description' => '',
            'keywords' => '',
            'indexable' => '1',
            'version' => (string) $initialVersion, // Old version - should cause conflict
        ]);

        $this->assertApiStatusCode(409, $result2);
        $this->assertApiError('VERSION_CONFLICT', $result2);
    }

    #[Test]
    public function updatePageTranslationForceOverwrite(): void
    {
        $page = new Page('My Page');
        $this->pageRepository->save($page);

        $translation = new PageTranslation(
            $page,
            'cs',
            '/my-page',
            \sha1('/my-page'),
            'My Page',
        );
        $this->pageTranslationRepository->save($translation);

        // Store initial version (because EntityManager identity map shares objects)
        $initialVersion = $translation->getVersion();

        // First update
        $result1 = $this->apiPost('pageTranslationUpdate', [
            'id' => (string) $translation->getId(),
            'title' => 'Updated Title',
            'slug' => '/my-page',
            'description' => '',
            'keywords' => '',
            'indexable' => '1',
            'version' => (string) $initialVersion,
        ]);
        $this->assertApiSuccess($result1);

        // Force update with old version should succeed
        $result2 = $this->apiPost('pageTranslationUpdate', [
            'id' => (string) $translation->getId(),
            'title' => 'Force Updated Title',
            'slug' => '/my-page',
            'description' => '',
            'keywords' => '',
            'indexable' => '1',
            'version' => (string) $initialVersion, // Old version - but force=true
            'force' => '1',
        ]);

        $this->assertApiSuccess($result2);
    }

    // =========================================================================
    // POST /api/page-duplicate
    // =========================================================================

    #[Test]
    public function duplicatePage(): void
    {
        $page = new Page('Original Page');
        $this->pageRepository->save($page);

        $translation = new PageTranslation(
            $page,
            'cs',
            '/original-page',
            \sha1('/original-page'),
            'Original Title',
            'Original description',
        );
        $this->pageTranslationRepository->save($translation);

        $result = $this->apiPost('pageDuplicate', [
            'pageId' => (string) $page->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertNotEquals($page->getId(), $data['id']);
        self::assertIsString($data['name']);
        self::assertStringContainsString('(kopie)', $data['name']);
        self::assertArrayHasKey('translation', $data);
        self::assertIsArray($data['translation']);
        self::assertIsString($data['translation']['slug']);
        self::assertStringContainsString('-copy', $data['translation']['slug']);
    }

    #[Test]
    public function duplicatePageReturns404ForNonExistent(): void
    {
        $result = $this->apiPost('pageDuplicate', [
            'pageId' => '999999',
            'language' => 'cs',
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // POST /api/page-delete
    // =========================================================================

    #[Test]
    public function deletePage(): void
    {
        // First create a homepage (first root page becomes homepage)
        $homepage = new Page('Homepage');
        $this->pageRepository->save($homepage);

        // Now create the page we want to delete
        $page = new Page('Page To Delete');
        $this->pageRepository->save($page);

        $translation = new PageTranslation(
            $page,
            'cs',
            '/page-to-delete',
            \sha1('/page-to-delete'),
            'Page To Delete',
        );
        $this->pageTranslationRepository->save($translation);

        $result = $this->apiPost('pageDelete', [
            'pageId' => (string) $page->getId(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
    }

    #[Test]
    public function cannotDeleteHomepage(): void
    {
        // Create homepage (first root page is considered homepage)
        $homepage = new Page('Homepage');
        $this->pageRepository->save($homepage);

        $translation = new PageTranslation(
            $homepage,
            'cs',
            '/',
            \sha1('/'),
            'Homepage',
        );
        $this->pageTranslationRepository->save($translation);

        $result = $this->apiPost('pageDelete', [
            'pageId' => (string) $homepage->getId(),
        ]);

        $this->assertApiStatusCode(403, $result);
        $this->assertApiError('HOMEPAGE_CANNOT_BE_DELETED', $result);
    }

    #[Test]
    public function cannotDeletePageWithChildren(): void
    {
        // First create a homepage (first root page becomes homepage)
        $homepage = new Page('Homepage');
        $this->pageRepository->save($homepage);

        // Create parent page
        $parent = new Page('Parent');
        $this->pageRepository->save($parent);

        // Create child page
        $child = new Page('Child', $parent->getId());
        $this->pageRepository->save($child);

        $result = $this->apiPost('pageDelete', [
            'pageId' => (string) $parent->getId(),
        ]);

        $this->assertApiStatusCode(409, $result);
        $this->assertApiError('PAGE_HAS_CHILDREN', $result);
    }

    // =========================================================================
    // GET /api/page-parents
    // =========================================================================

    #[Test]
    public function getPageParentsReturnsAvailableParents(): void
    {
        // Create pages
        $homepage = new Page('Homepage');
        $this->pageRepository->save($homepage);

        $aboutPage = new Page('About');
        $this->pageRepository->save($aboutPage);

        $result = $this->apiGet('pageParents');

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertIsArray($data['pages']);
    }

    // =========================================================================
    // GET /api/page-404-exists
    // =========================================================================

    #[Test]
    public function page404ExistsReturnsFalseWhenNo404(): void
    {
        $result = $this->apiGet('page404Exists');

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertFalse($data['exists']);
    }

    #[Test]
    public function page404ExistsReturnsTrueWhen404Exists(): void
    {
        // Create 404 page
        $page404 = new Page('404 Page');
        $page404->setIs404(true);
        $this->pageRepository->save($page404);

        $result = $this->apiGet('page404Exists');

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['exists']);
    }

    #[Test]
    public function page404ExistsChecksSpecificParent(): void
    {
        // Create parent
        $parent = new Page('Parent');
        $this->pageRepository->save($parent);

        // Create 404 at root level only
        $page404 = new Page('404 Page');
        $page404->setIs404(true);
        $this->pageRepository->save($page404);

        // Check root level - should return true
        $result1 = $this->apiGet('page404Exists');
        $data1 = $result1->getJsonData();
        self::assertIsArray($data1);
        self::assertTrue($data1['exists']);

        // Check under parent - should return false
        $result2 = $this->apiGet('page404Exists', [
            'parentId' => (string) $parent->getId(),
        ]);
        $data2 = $result2->getJsonData();
        self::assertIsArray($data2);
        self::assertFalse($data2['exists']);
    }

    // =========================================================================
    // Same slug in different languages (unique constraint fix)
    // =========================================================================

    #[Test]
    public function createTranslationWithSameSlugInDifferentLanguage(): void
    {
        $page = new Page('Homepage');
        $this->pageRepository->save($page);

        // Create Czech translation with root slug
        $csTranslation = new PageTranslation(
            $page,
            'cs',
            '/',
            \sha1('/'),
            'Domů',
        );
        $this->pageTranslationRepository->save($csTranslation);

        // Create English translation with the same slug via API
        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page->getId(),
            'language' => 'en',
            'title' => 'Home',
            'slug' => '/',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertEquals('Home', $data['title']);
        self::assertEquals('/', $data['slug']);
        self::assertEquals('en', $data['language']);
    }

    #[Test]
    public function createMultipleTranslationsWithSameSlug(): void
    {
        $page = new Page('Homepage');
        $this->pageRepository->save($page);

        // Create translations for multiple languages with root slug
        $languages = ['cs', 'en', 'de', 'sk'];

        foreach ($languages as $lang) {
            $result = $this->apiPost('pageTranslationCreate', [
                'pageId' => (string) $page->getId(),
                'language' => $lang,
                'title' => "Home $lang",
                'slug' => '/',
            ]);

            $this->assertApiStatusCode(201, $result);
            $data = $result->getJsonData();
            self::assertIsArray($data);
            self::assertEquals('/', $data['slug']);
            self::assertEquals($lang, $data['language']);
        }

        // Verify all translations exist
        $translations = $this->pageTranslationRepository->findAllByPage($page);
        self::assertCount(4, $translations);
    }

    // =========================================================================
    // Draft creation when creating new page translation
    // =========================================================================

    #[Test]
    public function createTranslationCreatesDraftForUsersWithExistingDrafts(): void
    {
        /** @var PageDraftRepository $pageDraftRepository */
        $pageDraftRepository = $this->getService(PageDraftRepository::class);
        /** @var PageDraftManager $pageDraftManager */
        $pageDraftManager = $this->getService(PageDraftManager::class);

        $page = new Page('My Page');
        $this->pageRepository->save($page);

        // Create Czech translation
        $csTranslation = new PageTranslation(
            $page,
            'cs',
            '/moje-stranka',
            \sha1('/moje-stranka'),
            'Moje stránka',
        );
        $this->pageTranslationRepository->save($csTranslation);

        // Create draft for Czech translation (simulating user editing the page)
        $csDraft = $pageDraftManager->getOrCreateDraft($this->testUser, $csTranslation);
        self::assertNotNull($csDraft);

        // Now create English translation via API
        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page->getId(),
            'language' => 'en',
            'title' => 'My Page',
            'slug' => '/my-page',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        $enTranslationId = $data['id'];

        // Find the English translation
        $enTranslation = $this->pageTranslationRepository->find($enTranslationId);
        self::assertNotNull($enTranslation);

        // Verify a draft was created for the English translation
        $enDraft = $pageDraftRepository->findByUserAndPageTranslation($this->testUser, $enTranslation);
        self::assertNotNull(
            $enDraft,
            'Draft should be created for new translation when user has draft in another language',
        );
    }

    #[Test]
    public function newTranslationDraftHasModulesWithPendingStatus(): void
    {
        /** @var PageDraftRepository $pageDraftRepository */
        $pageDraftRepository = $this->getService(PageDraftRepository::class);
        /** @var PageDraftManager $pageDraftManager */
        $pageDraftManager = $this->getService(PageDraftManager::class);
        /** @var ModuleTranslationDraftRepository $moduleTranslationDraftRepository */
        $moduleTranslationDraftRepository = $this->getService(ModuleTranslationDraftRepository::class);
        /** @var \App\CmsModules\Database\ModuleDraftRepository $moduleDraftRepository */
        $moduleDraftRepository = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);

        $page = new Page('My Page');
        $this->pageRepository->save($page);

        // Create Czech translation
        $csTranslation = new PageTranslation(
            $page,
            'cs',
            '/moje-stranka',
            \sha1('/moje-stranka'),
            'Moje stránka',
        );
        $this->pageTranslationRepository->save($csTranslation);

        // Create draft for Czech translation
        $csDraft = $pageDraftManager->getOrCreateDraft($this->testUser, $csTranslation);

        // Add a module to the draft
        $modulesData = [
            [
                'tempKey' => 'temp-1',
                'type' => 'text',
                'settings' => ['content' => 'Test content'],
                'translationSettings' => ['content' => 'Czech content'],
                'sort' => 0,
                'status' => 'created',
            ],
        ];
        $pageDraftManager->saveModules($csDraft, 'cs', $modulesData);

        // Now create English translation via API
        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page->getId(),
            'language' => 'en',
            'title' => 'My Page',
            'slug' => '/my-page',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        $enTranslationId = $data['id'];

        // Find the English translation and its draft
        $enTranslation = $this->pageTranslationRepository->find($enTranslationId);
        $enDraft = $pageDraftRepository->findByUserAndPageTranslation($this->testUser, $enTranslation);
        self::assertNotNull($enDraft);

        // Verify modules exist in English draft
        $enModules = $moduleDraftRepository->findAllSavedByPageDraft($enDraft);
        self::assertCount(1, $enModules, 'English draft should have the same modules as Czech draft');

        // Verify translation has PENDING status
        $enModuleDraft = $enModules[0];
        $enTranslationDraft = $moduleTranslationDraftRepository->findByModuleDraftAndLanguage($enModuleDraft, 'en');
        self::assertNotNull($enTranslationDraft);
        self::assertTrue($enTranslationDraft->isPending(), 'Translation should have PENDING status');
    }

    #[Test]
    public function noExistingDraftsNoNewDraftCreated(): void
    {
        /** @var PageDraftRepository $pageDraftRepository */
        $pageDraftRepository = $this->getService(PageDraftRepository::class);

        $page = new Page('My Page');
        $this->pageRepository->save($page);

        // Create Czech translation (no draft!)
        $csTranslation = new PageTranslation(
            $page,
            'cs',
            '/moje-stranka',
            \sha1('/moje-stranka'),
            'Moje stránka',
        );
        $this->pageTranslationRepository->save($csTranslation);

        // Create English translation via API without any existing drafts
        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page->getId(),
            'language' => 'en',
            'title' => 'My Page',
            'slug' => '/my-page',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        $enTranslationId = $data['id'];

        // Find the English translation
        $enTranslation = $this->pageTranslationRepository->find($enTranslationId);
        self::assertNotNull($enTranslation);

        // Verify no draft was created (since no existing drafts)
        $enDraft = $pageDraftRepository->findByUserAndPageTranslation($this->testUser, $enTranslation);
        self::assertNull($enDraft, 'No draft should be created if user has no existing drafts');
    }

    #[Test]
    public function customLayoutDraftNotUsedAsSourceForNewTranslation(): void
    {
        /** @var PageDraftRepository $pageDraftRepository */
        $pageDraftRepository = $this->getService(PageDraftRepository::class);
        /** @var PageDraftManager $pageDraftManager */
        $pageDraftManager = $this->getService(PageDraftManager::class);

        $page = new Page('My Page');
        $this->pageRepository->save($page);

        // Create Czech translation with custom layout
        $csTranslation = new PageTranslation(
            $page,
            'cs',
            '/moje-stranka',
            \sha1('/moje-stranka'),
            'Moje stránka',
        );
        $csTranslation->setCustom(true); // Custom layout - modules are bound to this translation only
        $this->pageTranslationRepository->save($csTranslation);

        // Create draft for Czech translation (but it's custom layout!)
        $csDraft = $pageDraftManager->getOrCreateDraft($this->testUser, $csTranslation);
        self::assertNotNull($csDraft);

        // Now create English translation via API (non-custom)
        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page->getId(),
            'language' => 'en',
            'title' => 'My Page',
            'slug' => '/my-page',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        $enTranslationId = $data['id'];

        // Find the English translation
        $enTranslation = $this->pageTranslationRepository->find($enTranslationId);
        self::assertNotNull($enTranslation);

        // Verify NO draft was created for English translation
        // because the only existing draft belongs to a custom layout translation
        $enDraft = $pageDraftRepository->findByUserAndPageTranslation($this->testUser, $enTranslation);
        self::assertNull(
            $enDraft,
            'No draft should be created for new translation when existing drafts are from custom layout translations only',
        );
    }

    #[Test]
    public function sharedLayoutDraftUsedAsSourceWhenMixedWithCustomLayout(): void
    {
        /** @var PageDraftRepository $pageDraftRepository */
        $pageDraftRepository = $this->getService(PageDraftRepository::class);
        /** @var PageDraftManager $pageDraftManager */
        $pageDraftManager = $this->getService(PageDraftManager::class);
        /** @var \App\CmsModules\Database\ModuleDraftRepository $moduleDraftRepository */
        $moduleDraftRepository = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);

        $page = new Page('My Page');
        $this->pageRepository->save($page);

        // Create Czech translation with custom layout
        $csTranslation = new PageTranslation(
            $page,
            'cs',
            '/moje-stranka',
            \sha1('/moje-stranka'),
            'Moje stránka',
        );
        $csTranslation->setCustom(true); // Custom layout
        $this->pageTranslationRepository->save($csTranslation);

        // Create draft for Czech (custom layout)
        $csDraft = $pageDraftManager->getOrCreateDraft($this->testUser, $csTranslation);

        // Create Slovak translation with shared layout
        $skTranslation = new PageTranslation(
            $page,
            'sk',
            '/moja-stranka',
            \sha1('/moja-stranka'),
            'Moja stránka',
        );
        // Not setting custom = shared layout (default)
        $this->pageTranslationRepository->save($skTranslation);

        // Create draft for Slovak (shared layout) with a module
        $skDraft = $pageDraftManager->getOrCreateDraft($this->testUser, $skTranslation);
        $modulesData = [
            [
                'tempKey' => 'temp-1',
                'type' => 'text',
                'settings' => ['content' => 'Test content'],
                'translationSettings' => ['content' => 'Slovak content'],
                'sort' => 0,
                'status' => 'created',
            ],
        ];
        $pageDraftManager->saveModules($skDraft, 'sk', $modulesData);

        // Now create English translation via API (non-custom)
        $result = $this->apiPost('pageTranslationCreate', [
            'pageId' => (string) $page->getId(),
            'language' => 'en',
            'title' => 'My Page',
            'slug' => '/my-page',
        ]);

        $this->assertApiStatusCode(201, $result);
        $data = $result->getJsonData();
        $enTranslationId = $data['id'];

        // Find the English translation
        $enTranslation = $this->pageTranslationRepository->find($enTranslationId);
        self::assertNotNull($enTranslation);

        // Verify draft WAS created for English translation
        // because there's a draft from shared layout translation (Slovak)
        $enDraft = $pageDraftRepository->findByUserAndPageTranslation($this->testUser, $enTranslation);
        self::assertNotNull(
            $enDraft,
            'Draft should be created for new translation when there is a draft from shared layout translation',
        );

        // Verify the modules were synced from the Slovak draft (not Czech custom draft)
        $enModules = $moduleDraftRepository->findAllSavedByPageDraft($enDraft);
        self::assertCount(1, $enModules, 'English draft should have modules from Slovak shared layout draft');
    }
}
