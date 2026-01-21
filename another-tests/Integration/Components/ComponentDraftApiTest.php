<?php declare(strict_types = 1);

namespace Tests\Integration\Components;

use App\Components\ComponentManager;
use App\Components\Database\ComponentModuleRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\ApiIntegrationTestCase;

/**
 * Integration tests for Component Draft API endpoints.
 *
 * @group integration
 * @group database
 * @group api
 */
final class ComponentDraftApiTest extends ApiIntegrationTestCase
{
    private ComponentManager $componentManager;
    private ComponentModuleRepository $moduleRepository;

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

        $this->componentManager = $this->getService(ComponentManager::class);
        $this->moduleRepository = $this->getService(ComponentModuleRepository::class);
    }

    #[Test]
    public function getDraftStatusForComponentWithoutDraft(): void
    {
        $component = $this->componentManager->create(
            'test-component',
            'hero',
            'cs',
            'Test Component',
        );

        $result = $this->apiGet('componentDraftStatus', [
            'componentId' => $component->getId(),
        ]);

        $this->assertApiSuccess($result);
        self::assertFalse($result->get('hasDraft'));
        self::assertFalse($result->get('hasConflict'));
    }

    #[Test]
    public function saveComponentDraftCreatesNewDraft(): void
    {
        $component = $this->componentManager->create(
            'save-draft-test',
            'hero',
            'cs',
            'Save Draft Test',
        );

        // Add a master module
        $module = $this->componentManager->addModule(
            $component,
            'text',
            ['content' => 'Original content'],
        );

        // Save draft with modified module
        $result = $this->apiPostJson('componentDraftSave', [
            'componentId' => $component->getId(),
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $module->getId(),
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => ['content' => 'Modified content'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'editableFields' => [],
                    'lockedFields' => [],
                    'status' => 'modified',
                ],
            ],
        ]);

        $this->assertApiSuccess($result);
        self::assertNotNull($result->get('draftId'));
        self::assertIsArray($result->get('tempKeyMapping'));
        self::assertIsArray($result->get('originalIdMapping'));
    }

    #[Test]
    public function deleteModuleFromComponentDraft(): void
    {
        // 1. Create component with module
        $component = $this->componentManager->create(
            'delete-module-test',
            'hero',
            'cs',
            'Delete Module Test',
        );

        $module = $this->componentManager->addModule(
            $component,
            'text',
            ['content' => 'To be deleted'],
        );

        $moduleId = $module->getId();

        // 2. Save draft with module marked as deleted
        $result = $this->apiPostJson('componentDraftSave', [
            'componentId' => $component->getId(),
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $moduleId,
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'editableFields' => [],
                    'lockedFields' => [],
                    'status' => 'deleted',
                ],
            ],
        ]);

        $this->assertApiSuccess($result);

        // 3. Get draft modules via API and verify deleted module is not returned
        $modulesResult = $this->apiGet('componentDraftModules', [
            'componentId' => $component->getId(),
        ]);

        $this->assertApiSuccess($modulesResult);

        /** @var array<array{originalModuleId: int|null}> $modules */
        $modules = $modulesResult->get('modules') ?? [];

        // Module should not be in the response
        $foundDeletedModule = false;

        foreach ($modules as $m) {
            if ($m['originalModuleId'] === $moduleId) {
                $foundDeletedModule = true;

                break;
            }
        }

        self::assertFalse($foundDeletedModule, 'Deleted module should not appear in draft modules response');
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function publishComponentDraftAppliesChanges(): void
    {
        $component = $this->componentManager->create(
            'publish-draft-test',
            'hero',
            'cs',
            'Publish Draft Test',
        );

        // Add master module
        $module = $this->componentManager->addModule(
            $component,
            'text',
            ['content' => 'Original'],
        );

        // Save draft with modified module
        $this->apiPostJson('componentDraftSave', [
            'componentId' => $component->getId(),
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $module->getId(),
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => ['content' => 'Published content'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'editableFields' => [],
                    'lockedFields' => [],
                    'status' => 'modified',
                ],
            ],
        ]);

        // Publish draft
        $result = $this->apiPost('componentDraftPublish', [
            'componentId' => $component->getId(),
        ]);

        $this->assertApiSuccess($result);
        self::assertTrue($result->get('success'));

        // Verify master module was updated
        $this->getEntityManager()->clear();
        $updatedModule = $this->moduleRepository->get($module->getId());
        self::assertEquals(['content' => 'Published content'], $updatedModule->getSettings());
    }

    #[Test]
    public function discardComponentDraftDeletesDraft(): void
    {
        $component = $this->componentManager->create(
            'discard-draft-test',
            'hero',
            'cs',
            'Discard Draft Test',
        );

        // Add master module
        $module = $this->componentManager->addModule(
            $component,
            'text',
            ['content' => 'Original'],
        );

        // Save draft
        $this->apiPostJson('componentDraftSave', [
            'componentId' => $component->getId(),
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $module->getId(),
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => ['content' => 'Draft content'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'editableFields' => [],
                    'lockedFields' => [],
                    'status' => 'modified',
                ],
            ],
        ]);

        // Discard draft
        $result = $this->apiPost('componentDraftDiscard', [
            'componentId' => $component->getId(),
        ]);

        $this->assertApiSuccess($result);
        self::assertTrue($result->get('success'));

        // Verify draft was deleted
        $statusResult = $this->apiGet('componentDraftStatus', [
            'componentId' => $component->getId(),
        ]);
        self::assertFalse($statusResult->get('hasDraft'));
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function deleteModuleAndSaveAgainDoesNotResurrectModule(): void
    {
        // This test verifies the fix for the bug where deleted modules
        // would reappear after save (frontend would send them again)

        $component = $this->componentManager->create(
            'no-resurrect-test',
            'hero',
            'cs',
            'No Resurrect Test',
        );

        // Add two modules
        $module1 = $this->componentManager->addModule(
            $component,
            'text',
            ['content' => 'Module 1'],
            0,
        );
        $module2 = $this->componentManager->addModule(
            $component,
            'text',
            ['content' => 'Module 2'],
            1,
        );

        // First save: delete module1, keep module2
        $result1 = $this->apiPostJson('componentDraftSave', [
            'componentId' => $component->getId(),
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $module1->getId(),
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'editableFields' => [],
                    'lockedFields' => [],
                    'status' => 'deleted',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => $module2->getId(),
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => ['content' => 'Module 2 modified'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'editableFields' => [],
                    'lockedFields' => [],
                    'status' => 'modified',
                ],
            ],
        ]);

        $this->assertApiSuccess($result1);

        // Get the draft ID for module2 from the mapping
        /** @var array<int, int> $originalIdMapping */
        $originalIdMapping = $result1->get('originalIdMapping');
        $module2DraftId = $originalIdMapping[$module2->getId()] ?? null;

        // Second save: only send module2 (simulating frontend behavior after fix)
        $result2 = $this->apiPostJson('componentDraftSave', [
            'componentId' => $component->getId(),
            'modules' => [
                [
                    'draftId' => $module2DraftId,
                    'originalModuleId' => $module2->getId(),
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => ['content' => 'Module 2 further modified'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'editableFields' => [],
                    'lockedFields' => [],
                    'status' => 'modified',
                ],
            ],
        ]);

        $this->assertApiSuccess($result2);

        // Verify only module2 exists in draft
        $this->getEntityManager()->clear();

        $modulesResult = $this->apiGet('componentDraftModules', [
            'componentId' => $component->getId(),
        ]);

        /** @var array<array{originalModuleId: int|null}> $modules */
        $modules = $modulesResult->get('modules') ?? [];

        self::assertCount(1, $modules, 'Only one module should exist after deleting the other');
        self::assertEquals($module2->getId(), $modules[0]['originalModuleId']);
    }
}
