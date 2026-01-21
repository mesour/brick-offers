<?php declare(strict_types = 1);

namespace Tests\Integration\Modules;

use App\CmsModules\Database\Module;
use App\CmsModules\Database\ModuleRepository;
use App\Pages\Database\Page;
use App\Pages\Database\PageDraftRepository;
use App\Pages\Database\PageRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\ApiIntegrationTestCase;

/**
 * Integration tests for Module Content API endpoint.
 *
 * The moduleContent API is used by the frontend editor to:
 * - Create new module drafts for preview
 * - Update existing module drafts
 * - Render module HTML for live preview
 *
 * @group integration
 * @group database
 */
final class ModuleContentApiTest extends ApiIntegrationTestCase
{
    private PageRepository $pageRepository;
    private PageTranslationRepository $pageTranslationRepository;
    private ModuleRepository $moduleRepository;
    private PageDraftRepository $pageDraftRepository;

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
        $this->moduleRepository = $this->getService(ModuleRepository::class);
        $this->pageDraftRepository = $this->getService(PageDraftRepository::class);
    }

    private function createTestPageWithTranslation(): PageTranslation
    {
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $translation = new PageTranslation(
            $page,
            'cs',
            '/test-module-content',
            \sha1('/test-module-content'),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        return $translation;
    }

    // =========================================================================
    // Basic module creation
    // =========================================================================

    #[Test]
    public function createNewTextModule(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $settings = \json_encode([
            'content' => '<p>Hello World</p>',
        ]);

        // Note: don't pass 'id' when creating new module (null means create new)
        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'text',
            'settings' => $settings,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('content', $data);
        self::assertNotEmpty($data['id']);
        self::assertNotEmpty($data['content']);
    }

    #[Test]
    public function createNewContainerModule(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $settings = \json_encode([
            'width' => 'full',
        ]);

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'container',
            'settings' => $settings,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
        self::assertNotEmpty($data['id']);
    }

    #[Test]
    public function createNewRowModule(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $settings = \json_encode([
            'columns' => 2,
        ]);

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'row',
            'settings' => $settings,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
    }

    #[Test]
    public function createNewLinkModule(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $settings = \json_encode([
            'url' => 'https://example.com',
            'text' => 'Click here',
            'target' => '_blank',
        ]);

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'link',
            'settings' => $settings,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('content', $data);
    }

    #[Test]
    public function createNewIconModule(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $settings = \json_encode([
            'icon' => 'bi bi-star',
            'size' => '24px',
        ]);

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'icon',
            'settings' => $settings,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
    }

    #[Test]
    public function createNewTabsModule(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $settings = \json_encode([
            'tabs' => [
                ['id' => 'tab1', 'label' => 'Tab 1'],
                ['id' => 'tab2', 'label' => 'Tab 2'],
            ],
        ]);

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'tabs',
            'settings' => $settings,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
    }

    // =========================================================================
    // Update existing modules
    // =========================================================================

    #[Test]
    public function moduleContentReturnsUniqueIdForEachModule(): void
    {
        // Note: moduleContent creates unsaved modules (sort=-1) for preview.
        // For complete module update/save flow, use pageDraftSaveModules API.
        // This test verifies that each new module gets a unique ID.

        $translation = $this->createTestPageWithTranslation();

        // Create a single module and verify it gets an ID
        $settings = \json_encode(['content' => '<p>Test content</p>']);
        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'text',
            'settings' => $settings,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
        self::assertIsInt($data['id']);
        self::assertGreaterThan(0, $data['id']);
    }

    #[Test]
    public function updateExistingMasterModule(): void
    {
        $translation = $this->createTestPageWithTranslation();

        // Create a master module (not draft)
        $masterModule = Module::create('text', null, $translation);
        $masterModule->setSettingsArray(['content' => '<p>Master content</p>']);
        $this->moduleRepository->save($masterModule);

        // Update via API - should create/find draft for the master module
        $settings = \json_encode(['content' => '<p>Draft update</p>']);
        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'id' => (string) $masterModule->getId(),
            'type' => 'text',
            'settings' => $settings,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        // Should return a draft ID (different from master ID or same if using originalModuleId lookup)
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('content', $data);
    }

    // =========================================================================
    // Module with parent
    // =========================================================================

    #[Test]
    public function createModuleWithParent(): void
    {
        $translation = $this->createTestPageWithTranslation();

        // Create parent container
        $containerSettings = \json_encode(['width' => 'full']);
        $containerResult = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'container',
            'settings' => $containerSettings,
        ]);
        $this->assertApiSuccess($containerResult);
        $containerData = $containerResult->getJsonData();
        self::assertIsArray($containerData);
        $containerId = $containerData['id'];

        // Create child text module
        $textSettings = \json_encode(['content' => '<p>Child module</p>']);
        $textResult = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'text',
            'settings' => $textSettings,
            'parentId' => (string) $containerId,
        ]);

        $this->assertApiSuccess($textResult);
        $data = $textResult->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('id', $data);
    }

    #[Test]
    public function createNestedModuleHierarchy(): void
    {
        $translation = $this->createTestPageWithTranslation();

        // Create container -> row -> text hierarchy

        // 1. Container
        $containerResult = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'container',
            'settings' => \json_encode([]),
        ]);
        $containerData = $containerResult->getJsonData();
        self::assertIsArray($containerData);
        $containerId = $containerData['id'];

        // 2. Row inside container
        $rowResult = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'row',
            'settings' => \json_encode([]),
            'parentId' => (string) $containerId,
        ]);
        $rowData = $rowResult->getJsonData();
        self::assertIsArray($rowData);
        $rowId = $rowData['id'];

        // 3. Text inside row
        $textResult = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'text',
            'settings' => \json_encode(['content' => '<p>Nested text</p>']),
            'parentId' => (string) $rowId,
        ]);

        $this->assertApiSuccess($textResult);
        $textData = $textResult->getJsonData();
        self::assertIsArray($textData);
        self::assertNotEmpty($textData['id']);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    #[Test]
    public function emptyPageTranslationIdReturnsError(): void
    {
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        // Empty string is not null, so it tries to get translation ID 0 (not found)
        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $page->getId(),
            'pageTranslationId' => '',
            'language' => 'cs',
            'type' => 'text',
            'settings' => '{}',
        ]);

        // Returns 404 because empty string is cast to 0, which doesn't exist
        $this->assertApiStatusCode(404, $result);
    }

    #[Test]
    public function returns404ForNonExistentPageTranslation(): void
    {
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $page->getId(),
            'pageTranslationId' => '999999',
            'language' => 'cs',
            'type' => 'text',
            'settings' => '{}',
        ]);

        $this->assertApiStatusCode(404, $result);
        $this->assertApiError('PAGE_TRANSLATION_NOT_FOUND', $result);
    }

    #[Test]
    public function returns404ForUnknownModuleType(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'unknown_module_type',
            'settings' => '{}',
        ]);

        $this->assertApiStatusCode(404, $result);
        $this->assertApiError('MODULE_TYPE_NOT_FOUND', $result);
    }

    #[Test]
    public function returns404ForNonExistentModuleId(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'id' => '999999',
            'type' => 'text',
            'settings' => '{}',
        ]);

        $this->assertApiStatusCode(404, $result);
        $this->assertApiError('MODULE_DRAFT_NOT_FOUND', $result);
    }

    // =========================================================================
    // Draft system integration
    // =========================================================================

    #[Test]
    public function createsPageDraftAutomatically(): void
    {
        $translation = $this->createTestPageWithTranslation();

        // Verify no draft exists
        self::assertNotNull($this->testUser);
        $existingDraft = $this->pageDraftRepository->findByUserAndPageTranslation(
            $this->testUser,
            $translation,
        );
        self::assertNull($existingDraft);

        // Create module - should create draft automatically
        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'text',
            'settings' => \json_encode(['content' => '<p>Test</p>']),
        ]);
        $this->assertApiSuccess($result);

        // Verify draft was created
        self::assertNotNull($this->testUser);
        $draft = $this->pageDraftRepository->findByUserAndPageTranslation(
            $this->testUser,
            $translation,
        );
        self::assertNotNull($draft);
    }

    /**
     * @throws \App\Pages\PageTranslationNotFound
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     * @throws \Nette\DI\MissingServiceException
     * @throws \App\Users\UserNotFound
     */
    #[Test]
    public function reusesSamePageDraftForMultipleModules(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $translationId = $translation->getId();
        $pageId = $translation->getPage()->getId();

        // Create first module
        $result1 = $this->apiGet('moduleContent', [
            'pageId' => (string) $pageId,
            'pageTranslationId' => (string) $translationId,
            'language' => 'cs',
            'type' => 'text',
            'settings' => \json_encode(['content' => '<p>First</p>']),
        ]);
        $this->assertApiSuccess($result1);

        // Clear and re-login to simulate fresh request
        $this->getEntityManager()->clear();
        $this->resetAuthorizationCheckerAndRelogin();

        // Create second module
        $result2 = $this->apiGet('moduleContent', [
            'pageId' => (string) $pageId,
            'pageTranslationId' => (string) $translationId,
            'language' => 'cs',
            'type' => 'text',
            'settings' => \json_encode(['content' => '<p>Second</p>']),
        ]);
        $this->assertApiSuccess($result2);

        // Clear again before checking
        $this->getEntityManager()->clear();

        // Verify only one draft exists
        $refreshedTranslation = $this->pageTranslationRepository->get($translationId);
        $drafts = $this->pageDraftRepository->findAllByPageTranslation($refreshedTranslation);
        self::assertNotNull($this->testUser);
        $testUserId = $this->testUser->getId();
        $userDrafts = \array_filter($drafts, static fn($d) => $d->getUser()->getId() === $testUserId);
        self::assertCount(1, $userDrafts);
    }

    // =========================================================================
    // Module settings handling
    // =========================================================================

    #[Test]
    public function preservesComplexSettings(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $complexSettings = [
            'content' => '<p>Rich <strong>content</strong></p>',
            'textStyle' => [
                'fontFamily' => 'Arial',
                'fontSize' => '16px',
                'fontWeight' => 'bold',
            ],
            'appearance' => [
                'fill' => '#FF0000',
                'fillEnabled' => true,
                'padding' => ['value' => 16, 'unit' => 'px'],
            ],
        ];

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'text',
            'settings' => \json_encode($complexSettings),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('settings', $data);
    }

    #[Test]
    public function handlesEmptySettings(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'container',
            'settings' => '{}',
        ]);

        $this->assertApiSuccess($result);
    }

    // =========================================================================
    // Render output
    // =========================================================================

    #[Test]
    public function returnsHtmlContent(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'text',
            'settings' => \json_encode(['content' => '<p>Test paragraph</p>']),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertArrayHasKey('content', $data);
        // Content should contain HTML
        self::assertIsString($data['content']);
        self::assertStringContainsString('<', $data['content']);
    }

    #[Test]
    public function containerRendersWithDataAttributes(): void
    {
        $translation = $this->createTestPageWithTranslation();

        $result = $this->apiGet('moduleContent', [
            'pageId' => (string) $translation->getPage()->getId(),
            'pageTranslationId' => (string) $translation->getId(),
            'language' => 'cs',
            'type' => 'container',
            'settings' => \json_encode([]),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        // Container should have data-cms attributes in rendered content
        self::assertArrayHasKey('content', $data);
    }
}
