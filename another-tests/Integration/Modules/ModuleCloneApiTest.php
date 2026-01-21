<?php declare(strict_types = 1);

namespace Tests\Integration\Modules;

use App\CmsModules\Database\ModuleDraft;
use App\CmsModules\Database\ModuleDraftRepository;
use App\Pages\Database\Page;
use App\Pages\Database\PageDraft;
use App\Pages\Database\PageDraftRepository;
use App\Pages\Database\PageRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\ApiIntegrationTestCase;

/**
 * Integration tests for Module Clone API endpoint.
 *
 * The moduleClone API atomically clones a module and all its descendants.
 * Used for cloning container modules (Row, Tabs, Container) with their children.
 *
 * @group integration
 * @group database
 */
final class ModuleCloneApiTest extends ApiIntegrationTestCase
{
    private PageRepository $pageRepository;
    private PageTranslationRepository $pageTranslationRepository;
    private PageDraftRepository $pageDraftRepository;
    private ModuleDraftRepository $moduleDraftRepository;

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
        $this->pageDraftRepository = $this->getService(PageDraftRepository::class);
        $this->moduleDraftRepository = $this->getService(ModuleDraftRepository::class);
    }

    private function createTestPageWithTranslation(): PageTranslation
    {
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $translation = new PageTranslation(
            $page,
            'cs',
            '/test-module-clone',
            \sha1('/test-module-clone'),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        return $translation;
    }

    private function createPageDraft(PageTranslation $translation): PageDraft
    {
        self::assertNotNull($this->testUser);
        $draft = PageDraft::createFromMaster($this->testUser, $translation);
        $this->pageDraftRepository->save($draft);

        return $draft;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createModuleDraft(
        PageDraft $pageDraft,
        string $type,
        array $settings = [],
        ModuleDraft|null $parent = null,
    ): ModuleDraft {
        $module = ModuleDraft::createNew($pageDraft, $type);
        $module->setSettingsArray($settings);
        $module->setSort(0); // Mark as saved
        $module->markAsCreated();

        if ($parent !== null) {
            $module->setParent($parent);
        }

        $this->moduleDraftRepository->save($module);

        return $module;
    }

    /**
     * Extract typed clone result from API response.
     *
     * @return array{
     *     rootModule: array{id: int, type: string, settings: array<string, mixed>},
     *     modules: array<int, array{id: int, type: string, settings: array<string, mixed>, parentId: int|null, sort: int, status: string, content: string}>,
     *     idMapping: array<string, int>,
     *     styles: string
     * }
     */
    private function getCloneResult(mixed $data): array
    {
        self::assertIsArray($data);
        self::assertArrayHasKey('rootModule', $data);
        self::assertArrayHasKey('modules', $data);
        self::assertArrayHasKey('idMapping', $data);

        /** @var array{rootModule: array{id: int, type: string, settings: array<string, mixed>}, modules: array<int, array{id: int, type: string, settings: array<string, mixed>, parentId: int|null, sort: int, status: string, content: string}>, idMapping: array<string, int>, styles: string} */
        return $data;
    }

    // =========================================================================
    // Basic cloning - simple module without children
    // =========================================================================

    #[Test]
    public function cloneSimpleModuleReturnsClonedData(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        $originalModule = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Hello World</p>',
        ]);

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $originalModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Root module should have new ID
        self::assertNotEquals($originalModule->getId(), $data['rootModule']['id']);
        self::assertEquals('text', $data['rootModule']['type']);

        // Modules array should contain 1 module (the cloned one)
        self::assertCount(1, $data['modules']);
        self::assertEquals($data['rootModule']['id'], $data['modules'][0]['id']);

        // ID mapping should map old ID to new ID
        self::assertArrayHasKey((string) $originalModule->getId(), $data['idMapping']);
    }

    #[Test]
    public function clonedModuleHasSortMinusOne(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        $originalModule = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Test</p>',
        ]);

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $originalModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Cloned module should have sort=-1 (unsaved state)
        self::assertEquals(-1, $data['modules'][0]['sort']);
    }

    #[Test]
    public function clonedModuleHasCreatedStatus(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        $originalModule = $this->createModuleDraft($pageDraft, 'text');

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $originalModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Cloned module should have CREATED status
        self::assertEquals('created', $data['modules'][0]['status']);
    }

    // =========================================================================
    // Cloning container with children - Row module
    // =========================================================================

    #[Test]
    public function cloneRowModuleWithChildren(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        // Create row module
        $rowModule = $this->createModuleDraft($pageDraft, 'row', [
            'columns' => [],
        ]);

        // Create child text modules
        $child1 = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Child 1</p>',
        ], $rowModule);

        $child2 = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Child 2</p>',
        ], $rowModule);

        // Update row settings with child IDs
        $rowModule->setSettingsArray([
            'columns' => [
                ['modules' => [$child1->getId(), $child2->getId()]],
            ],
        ]);
        $this->moduleDraftRepository->save($rowModule);

        // Clone the row
        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $rowModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Should have 3 modules: row + 2 children
        self::assertCount(3, $data['modules']);

        // Verify ID mapping has all 3 modules
        self::assertCount(3, $data['idMapping']);
        self::assertArrayHasKey((string) $rowModule->getId(), $data['idMapping']);
        self::assertArrayHasKey((string) $child1->getId(), $data['idMapping']);
        self::assertArrayHasKey((string) $child2->getId(), $data['idMapping']);
    }

    #[Test]
    public function clonedRowSettingsContainRemappedChildIds(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        // Create row with children
        $rowModule = $this->createModuleDraft($pageDraft, 'row');
        $child1 = $this->createModuleDraft($pageDraft, 'text', [], $rowModule);
        $child2 = $this->createModuleDraft($pageDraft, 'text', [], $rowModule);

        $rowModule->setSettingsArray([
            'columns' => [
                ['modules' => [$child1->getId()]],
                ['modules' => [$child2->getId()]],
            ],
        ]);
        $this->moduleDraftRepository->save($rowModule);

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $rowModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Find the cloned row in modules
        $clonedRow = null;
        foreach ($data['modules'] as $module) {
            if ($module['type'] === 'row') {
                $clonedRow = $module;

                break;
            }
        }
        self::assertNotNull($clonedRow);

        // Get the new child IDs from mapping
        $newChild1Id = $data['idMapping'][(string) $child1->getId()];
        $newChild2Id = $data['idMapping'][(string) $child2->getId()];

        // Verify row settings contain new IDs, not old ones
        self::assertArrayHasKey('columns', $clonedRow['settings']);
        /** @var array<int, array{modules: array<int>}> $columns */
        $columns = $clonedRow['settings']['columns'];

        self::assertEquals([$newChild1Id], $columns[0]['modules']);
        self::assertEquals([$newChild2Id], $columns[1]['modules']);

        // Verify old IDs are NOT in settings
        self::assertNotContains($child1->getId(), $columns[0]['modules']);
        self::assertNotContains($child2->getId(), $columns[1]['modules']);
    }

    #[Test]
    public function clonedChildrenHaveCorrectParentId(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        $rowModule = $this->createModuleDraft($pageDraft, 'row');
        $child = $this->createModuleDraft($pageDraft, 'text', [], $rowModule);

        $rowModule->setSettingsArray([
            'columns' => [['modules' => [$child->getId()]]],
        ]);
        $this->moduleDraftRepository->save($rowModule);

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $rowModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        $newRowId = $data['idMapping'][(string) $rowModule->getId()];

        // Find the cloned child
        $clonedChild = null;
        foreach ($data['modules'] as $module) {
            if ($module['type'] === 'text') {
                $clonedChild = $module;

                break;
            }
        }
        self::assertNotNull($clonedChild);

        // Verify parent ID points to new row, not old row
        self::assertEquals($newRowId, $clonedChild['parentId']);
        self::assertNotEquals($rowModule->getId(), $clonedChild['parentId']);
    }

    // =========================================================================
    // Cloning nested containers (Row in Row)
    // =========================================================================

    #[Test]
    public function cloneNestedContainers(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        // Create outer row
        $outerRow = $this->createModuleDraft($pageDraft, 'row');

        // Create inner row inside outer
        $innerRow = $this->createModuleDraft($pageDraft, 'row', [], $outerRow);

        // Create text inside inner row
        $textModule = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Deeply nested</p>',
        ], $innerRow);

        // Set up settings
        $innerRow->setSettingsArray([
            'columns' => [['modules' => [$textModule->getId()]]],
        ]);
        $this->moduleDraftRepository->save($innerRow);

        $outerRow->setSettingsArray([
            'columns' => [['modules' => [$innerRow->getId()]]],
        ]);
        $this->moduleDraftRepository->save($outerRow);

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $outerRow->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Should have 3 modules: outer row, inner row, text
        self::assertCount(3, $data['modules']);

        // Verify all 3 are in ID mapping
        self::assertArrayHasKey((string) $outerRow->getId(), $data['idMapping']);
        self::assertArrayHasKey((string) $innerRow->getId(), $data['idMapping']);
        self::assertArrayHasKey((string) $textModule->getId(), $data['idMapping']);
    }

    // =========================================================================
    // Cloning Tabs module
    // =========================================================================

    #[Test]
    public function cloneTabsModuleWithChildren(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        // Create tabs module
        $tabsModule = $this->createModuleDraft($pageDraft, 'tabs');

        // Create child modules for each tab
        $tab1Child = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Tab 1 content</p>',
        ], $tabsModule);

        $tab2Child = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Tab 2 content</p>',
        ], $tabsModule);

        // Set up tabs settings
        $tabsModule->setSettingsArray([
            'tabs' => [
                ['id' => 'tab1', 'label' => 'Tab 1', 'panelModules' => [$tab1Child->getId()]],
                ['id' => 'tab2', 'label' => 'Tab 2', 'panelModules' => [$tab2Child->getId()]],
            ],
        ]);
        $this->moduleDraftRepository->save($tabsModule);

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $tabsModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Should have 3 modules: tabs + 2 children
        self::assertCount(3, $data['modules']);

        // Find cloned tabs module
        $clonedTabs = null;
        foreach ($data['modules'] as $module) {
            if ($module['type'] === 'tabs') {
                $clonedTabs = $module;

                break;
            }
        }
        self::assertNotNull($clonedTabs);

        // Verify settings have remapped IDs
        $newTab1ChildId = $data['idMapping'][(string) $tab1Child->getId()];
        $newTab2ChildId = $data['idMapping'][(string) $tab2Child->getId()];

        /** @var array<int, array{panelModules: array<int>}> $tabs */
        $tabs = $clonedTabs['settings']['tabs'];
        self::assertEquals([$newTab1ChildId], $tabs[0]['panelModules']);
        self::assertEquals([$newTab2ChildId], $tabs[1]['panelModules']);
    }

    // =========================================================================
    // Database verification
    // =========================================================================

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function clonedModulesArePersisted(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        $rowModule = $this->createModuleDraft($pageDraft, 'row');
        $child = $this->createModuleDraft($pageDraft, 'text', [], $rowModule);

        $rowModule->setSettingsArray([
            'columns' => [['modules' => [$child->getId()]]],
        ]);
        $this->moduleDraftRepository->save($rowModule);

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $rowModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Clear EntityManager and verify modules exist in DB
        $this->getEntityManager()->clear();

        $newRowId = $data['idMapping'][(string) $rowModule->getId()];
        $newChildId = $data['idMapping'][(string) $child->getId()];

        $clonedRow = $this->moduleDraftRepository->find($newRowId);
        $clonedChild = $this->moduleDraftRepository->find($newChildId);

        self::assertNotNull($clonedRow);
        self::assertNotNull($clonedChild);

        // Verify parent relationship in DB
        self::assertEquals($newRowId, $clonedChild->getParentId());

        // Verify settings in DB have remapped IDs
        /** @var array{columns: array<int, array{modules: array<int>}>} $settings */
        $settings = $clonedRow->getSettings();
        self::assertEquals([$newChildId], $settings['columns'][0]['modules']);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    #[Test]
    public function returns404ForNonExistentModule(): void
    {
        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => '999999',
            'language' => 'cs',
        ]);

        $this->assertApiStatusCode(404, $result);
        $this->assertApiError('MODULE_DRAFT_NOT_FOUND', $result);
    }

    /**
     * @throws \RuntimeException
     * @throws \Nette\DI\MissingServiceException
     */
    #[Test]
    public function returns403ForOtherUsersDraft(): void
    {
        $translation = $this->createTestPageWithTranslation();

        // Create a draft owned by a different user
        $otherUser = new \App\Users\Database\User(
            'other@example.com',
            \password_hash('password', \PASSWORD_DEFAULT),
        );
        $this->getService(\App\Users\Database\UserRepository::class)->save($otherUser);

        $otherUserDraft = PageDraft::createFromMaster($otherUser, $translation);
        $this->pageDraftRepository->save($otherUserDraft);

        $module = ModuleDraft::createNew($otherUserDraft, 'text');
        $this->moduleDraftRepository->save($module);

        // Try to clone as current test user
        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $module->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiStatusCode(403, $result);
        $this->assertApiError('ACCESS_DENIED', $result);
    }

    // =========================================================================
    // Rendered content
    // =========================================================================

    #[Test]
    public function cloneReturnsRenderedContent(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        $module = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Test content</p>',
        ]);

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $module->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Each module should have rendered content
        self::assertArrayHasKey('content', $data['modules'][0]);
        self::assertNotEmpty($data['modules'][0]['content']);
        self::assertStringContainsString('<', $data['modules'][0]['content']);
    }

    #[Test]
    public function rootModuleSettingsMatchFirstModuleSettings(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        // Create row with children to have meaningful settings
        $rowModule = $this->createModuleDraft($pageDraft, 'row');
        $child = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Child</p>',
        ], $rowModule);

        $rowModule->setSettingsArray([
            'columns' => [
                ['modules' => [$child->getId()], 'xs' => '6'],
                ['modules' => [], 'xs' => '6'],
            ],
        ]);
        $this->moduleDraftRepository->save($rowModule);

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $rowModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // rootModule.settings should match modules[0].settings
        $rootModuleSettings = $data['rootModule']['settings'];
        $firstModuleSettings = $data['modules'][0]['settings'];

        self::assertEquals($rootModuleSettings, $firstModuleSettings);

        // Verify settings have the expected structure
        self::assertArrayHasKey('columns', $rootModuleSettings);
        self::assertIsArray($rootModuleSettings['columns']);
        self::assertCount(2, $rootModuleSettings['columns']);
    }

    #[Test]
    public function cloneReturnsStyles(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        $module = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Test</p>',
        ]);

        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $module->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Response should have styles key (may be empty if no CSS needed)
        self::assertArrayHasKey('styles', $data);
    }

    // =========================================================================
    // FE settings override (for unsaved modules)
    // =========================================================================

    #[Test]
    public function cloneUsesFESettingsWhenProvided(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        // Create row module with empty settings in DB
        $rowModule = $this->createModuleDraft($pageDraft, 'row', [
            'columns' => [],
        ]);

        // Create child that we'll reference in FE settings
        $child = $this->createModuleDraft($pageDraft, 'text', [
            'content' => '<p>Child</p>',
        ], $rowModule);

        // FE settings (simulating unsaved changes with child module)
        $feSettings = [
            'columns' => [
                ['modules' => [$child->getId()]],
            ],
        ];

        // Clone with FE settings override
        $result = $this->apiPostJson('moduleClone', ['settings' => $feSettings], [
            'moduleDraftId' => (string) $rowModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Should have 2 modules: row + 1 child (from FE settings)
        self::assertCount(2, $data['modules']);

        // Verify ID mapping has both modules
        self::assertArrayHasKey((string) $rowModule->getId(), $data['idMapping']);
        self::assertArrayHasKey((string) $child->getId(), $data['idMapping']);

        // Verify cloned row settings contain remapped child ID
        $newChildId = $data['idMapping'][(string) $child->getId()];
        /** @var array{columns: array<int, array{modules: array<int>}>} $clonedSettings */
        $clonedSettings = $data['rootModule']['settings'];
        self::assertEquals([$newChildId], $clonedSettings['columns'][0]['modules']);
    }

    #[Test]
    public function cloneFallsBackToDBSettingsWhenNoFESettingsProvided(): void
    {
        $translation = $this->createTestPageWithTranslation();
        $pageDraft = $this->createPageDraft($translation);

        // Create row module with child in DB settings
        $rowModule = $this->createModuleDraft($pageDraft, 'row');
        $child = $this->createModuleDraft($pageDraft, 'text', [], $rowModule);

        $rowModule->setSettingsArray([
            'columns' => [['modules' => [$child->getId()]]],
        ]);
        $this->moduleDraftRepository->save($rowModule);

        // Clone WITHOUT FE settings (should use DB settings)
        $result = $this->apiPost('moduleClone', [
            'moduleDraftId' => (string) $rowModule->getId(),
            'language' => 'cs',
        ]);

        $this->assertApiSuccess($result);

        $data = $this->getCloneResult($result->getJsonData());

        // Should have 2 modules: row + 1 child (from DB settings)
        self::assertCount(2, $data['modules']);
        self::assertArrayHasKey((string) $child->getId(), $data['idMapping']);
    }
}
