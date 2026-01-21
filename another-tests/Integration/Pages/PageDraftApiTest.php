<?php declare(strict_types = 1);

namespace Tests\Integration\Pages;

use App\CmsModules\Database\ModuleRepository;
use App\Pages\Database\Page;
use App\Pages\Database\PageRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use App\Users\Database\User;
use App\Users\Database\UserRepository;
use Nette\Security\SimpleIdentity;
use Nette\Security\User as SecurityUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\ApiIntegrationTestCase;

/**
 * Integration tests for Page Draft API endpoints.
 *
 * These tests verify the complete flow from draft creation to publishing.
 *
 * @group integration
 * @group database
 * @group api
 */
final class PageDraftApiTest extends ApiIntegrationTestCase
{
    private PageRepository $pageRepository;
    private PageTranslationRepository $pageTranslationRepository;
    private ModuleRepository $moduleRepository;

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
    }

    /**
     * Create a test page with translation
     */
    private function createTestPage(string $slug = '/test-page', string $title = 'Test Page'): PageTranslation
    {
        try {
            $page = new Page('Test Page');
            $this->pageRepository->save($page);

            $translation = new PageTranslation(
                $page,
                'cs',
                $slug,
                \sha1(\strtolower($slug)),
                $title,
            );
            $this->pageTranslationRepository->save($translation);

            return $translation;
        } catch (\Throwable $e) {
            throw new \LogicException($e->getMessage(), $e->getCode(), $e);
        }
    }

    #[Test]
    public function getDraftStatusForPageWithoutDraft(): void
    {
        $translation = $this->createTestPage();

        $result = $this->apiGet('pageDraftStatus', [
            'pageTranslationId' => $translation->getId(),
        ]);

        $this->assertApiSuccess($result);
        self::assertFalse($result->get('hasDraft'));
        self::assertFalse($result->get('hasConflict'));
        self::assertEquals($translation->getVersion(), $result->get('masterVersion'));
        self::assertNull($result->get('draft'));
    }

    #[Test]
    public function createDraftForPage(): void
    {
        $translation = $this->createTestPage();

        $result = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);

        $this->assertApiSuccess($result);
        self::assertNotNull($result->get('id'));
        self::assertEquals($translation->getId(), $result->get('pageTranslationId'));
        self::assertEquals($translation->getVersion(), $result->get('baseVersion'));
        self::assertEquals($translation->getTitle(), $result->get('title'));
        self::assertEquals($translation->getSlug(), $result->get('slug'));
        self::assertFalse($result->get('hasConflict'));
    }

    #[Test]
    public function getExistingDraftReturnsSameDraft(): void
    {
        $translation = $this->createTestPage();

        // Create first draft
        $result1 = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $result1->get('id');

        // Request again - should return same draft
        $result2 = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);

        self::assertEquals($draftId, $result2->get('id'));
    }

    #[Test]
    public function updateDraftSettings(): void
    {
        $translation = $this->createTestPage();

        // Create draft
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $createResult->get('id');

        // Update draft
        $result = $this->apiPost('pageDraftUpdate', [
            'draftId' => $draftId,
            'title' => 'Updated Title',
            'description' => 'Updated description',
            'slug' => '/updated-slug',
        ]);

        $this->assertApiSuccess($result);
        self::assertEquals('Updated Title', $result->get('title'));
        self::assertEquals('Updated description', $result->get('description'));
        self::assertEquals('/updated-slug', $result->get('slug'));
    }

    /**
     * @throws \App\Pages\PageTranslationNotFound
     * @throws \RuntimeException
     */
    #[Test]
    public function publishDraftUpdatesTranslation(): void
    {
        $translation = $this->createTestPage('/original-slug', 'Original Title');
        $originalVersion = $translation->getVersion();

        // Create draft
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $createResult->get('id');

        // Update draft
        $this->apiPost('pageDraftUpdate', [
            'draftId' => $draftId,
            'title' => 'Published Title',
        ]);

        // Publish draft
        $result = $this->apiPost('pageDraftPublish', [
            'draftId' => $draftId,
        ]);

        $this->assertApiSuccess($result);
        self::assertTrue($result->get('success'));
        self::assertGreaterThan($originalVersion, $result->get('newVersion'));

        // Verify translation was updated
        $this->getEntityManager()->clear();
        $updatedTranslation = $this->pageTranslationRepository->get($translation->getId());
        self::assertEquals('Published Title', $updatedTranslation->getTitle());
    }

    #[Test]
    public function discardDraftDeletesDraft(): void
    {
        $translation = $this->createTestPage();

        // Create draft
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $createResult->get('id');

        // Discard draft
        $result = $this->apiPost('pageDraftDiscard', [
            'draftId' => $draftId,
        ]);

        $this->assertApiSuccess($result);
        self::assertTrue($result->get('success'));

        // Verify draft was deleted
        $statusResult = $this->apiGet('pageDraftStatus', [
            'pageTranslationId' => $translation->getId(),
        ]);
        self::assertFalse($statusResult->get('hasDraft'));
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function conflictDetectedWhenMasterChanges(): void
    {
        $translation = $this->createTestPage();

        // Create draft
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $createResult->get('id');

        // Simulate another user updating master (direct database change)
        $translation->setTitle('Changed by another user');
        $translation->incrementVersion();
        $this->pageTranslationRepository->save($translation);
        $this->getEntityManager()->flush();

        // Try to publish - should get conflict
        $result = $this->apiPost('pageDraftPublish', [
            'draftId' => $draftId,
        ]);

        $this->assertApiStatusCode(409, $result);
        $this->assertApiError('VERSION_CONFLICT', $result);
        self::assertEquals(1, $result->get('draftBaseVersion'));
        self::assertEquals(2, $result->get('currentMasterVersion'));
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function rebaseDraftUpdatesBaseVersion(): void
    {
        $translation = $this->createTestPage();

        // Create draft
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $createResult->get('id');

        // Simulate master change
        $translation->incrementVersion();
        $this->pageTranslationRepository->save($translation);
        $this->getEntityManager()->flush();

        // Rebase draft
        $result = $this->apiPost('pageDraftRebase', [
            'draftId' => $draftId,
        ]);

        $this->assertApiSuccess($result);
        self::assertTrue($result->get('success'));
        self::assertEquals(2, $result->get('newBaseVersion'));
    }

    /**
     * @throws \App\Pages\PageTranslationNotFound
     * @throws \RuntimeException
     */
    #[Test]
    public function forcePublishBypassesConflict(): void
    {
        $translation = $this->createTestPage();

        // Create draft
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $createResult->get('id');

        // Update draft with new title
        $this->apiPost('pageDraftUpdate', [
            'draftId' => $draftId,
            'title' => 'Force Published Title',
        ]);

        // Simulate master change
        $translation->setTitle('Other user title');
        $translation->incrementVersion();
        $this->pageTranslationRepository->save($translation);
        $this->getEntityManager()->flush();

        // Force publish
        $result = $this->apiPost('pageDraftPublish', [
            'draftId' => $draftId,
            'force' => true,
        ]);

        $this->assertApiSuccess($result);
        self::assertTrue($result->get('success'));

        // Verify our changes won
        $this->getEntityManager()->clear();
        $updatedTranslation = $this->pageTranslationRepository->get($translation->getId());
        self::assertEquals('Force Published Title', $updatedTranslation->getTitle());
    }

    /**
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     * @throws \RuntimeException
     */
    #[Test]
    public function cannotAccessOtherUsersDraft(): void
    {
        $translation = $this->createTestPage();

        // Create draft as user A
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $createResult->get('id');

        // Login as different user
        $userRepository = $this->getService(UserRepository::class);
        $otherUser = new User(
            'other@example.com',
            \password_hash('password', \PASSWORD_DEFAULT),
        );
        $otherUser->setAdmin(true);
        $userRepository->save($otherUser);

        $securityUser = $this->getService(SecurityUser::class);
        $securityUser->login(new SimpleIdentity($otherUser->getId(), ['admin']));

        // Reset AuthorizationChecker to clear cached user A
        $authChecker = $this->getService(\App\Security\AuthorizationChecker::class);
        $refClass = new \ReflectionClass(\App\Security\AuthorizationChecker::class);
        $prop = $refClass->getProperty('currentUser');
        $prop->setAccessible(true);
        $prop->setValue($authChecker, null);

        // Try to publish other user's draft
        $result = $this->apiPost('pageDraftPublish', [
            'draftId' => $draftId,
        ]);

        $this->assertApiStatusCode(403, $result);
        $this->assertApiError('ACCESS_DENIED', $result);
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function draftStatusShowsConflictAfterMasterChange(): void
    {
        $translation = $this->createTestPage();

        // Create draft
        $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);

        // Simulate master change
        $translation->incrementVersion();
        $this->pageTranslationRepository->save($translation);
        $this->getEntityManager()->flush();

        // Check status
        $result = $this->apiGet('pageDraftStatus', [
            'pageTranslationId' => $translation->getId(),
        ]);

        $this->assertApiSuccess($result);
        self::assertTrue($result->get('hasDraft'));
        self::assertTrue($result->get('hasConflict'));
        self::assertEquals(1, $result->get('baseVersion'));
        self::assertEquals(2, $result->get('masterVersion'));
    }

    #[Test]
    public function pageNotFoundReturns404(): void
    {
        $result = $this->apiGet('pageDraftStatus', [
            'pageTranslationId' => 99999,
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    #[Test]
    public function draftNotFoundReturns404(): void
    {
        $result = $this->apiPost('pageDraftPublish', [
            'draftId' => 99999,
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    #[Test]
    public function bulkSaveModulesToDraft(): void
    {
        $translation = $this->createTestPage();
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create draft
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $createResult->get('id');

        // Save modules in bulk
        $result = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'temp-1',
                    'type' => 'container',
                    'settings' => [],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'temp-2',
                    'type' => 'text',
                    'settings' => ['text' => 'Hello World'],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => 'temp-1',
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);

        $this->assertApiSuccess($result);
        $modules = $result->get('modules');
        self::assertIsArray($modules);
        self::assertNotEmpty($modules);
        self::assertCount(2, $modules);
        $tempKeyMapping = $result->get('tempKeyMapping');
        self::assertIsArray($tempKeyMapping);
        self::assertNotEmpty($tempKeyMapping);
    }

    // ==========================================
    // COMPLEX SCENARIO TESTS
    // ==========================================

    /**
     * @throws \App\Pages\PageTranslationNotFound
     * @throws \RuntimeException
     */
    #[Test]
    public function complexScenarioLinkWithTextInsertTabsAndPublish(): void
    {
        $translation = $this->createTestPage('/complex-test', 'Complex Test');
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Step 1: Create draft
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $this->assertApiSuccess($createResult);
        $draftId = $createResult->get('id');

        // Step 2: Save initial modules - link with text inside
        $result1 = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'link-1',
                    'type' => 'link',
                    'settings' => ['url' => '/test-link'],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Hello World'],
                    'parentDraftId' => null,
                    'parentTempKey' => 'link-1',
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);

        $this->assertApiSuccess($result1);
        $modules1 = $result1->get('modules');
        self::assertIsArray($modules1);
        self::assertCount(2, $modules1);

        $tempKeyMapping1 = $result1->get('tempKeyMapping');
        self::assertIsArray($tempKeyMapping1);
        $linkDraftId = $tempKeyMapping1['link-1'];
        $textDraftId = $tempKeyMapping1['text-1'];

        // Step 3: Insert tabs module after text (as sibling to link)
        $result2 = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => $linkDraftId,
                    'originalModuleId' => null,
                    'tempKey' => null,
                    'type' => 'link',
                    'settings' => ['url' => '/test-link'],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'unchanged',
                ],
                [
                    'draftId' => $textDraftId,
                    'originalModuleId' => null,
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Hello World'],
                    'parentDraftId' => $linkDraftId,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'unchanged',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'tabs-1',
                    'type' => 'tabs',
                    'settings' => ['tabs' => []],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 1,
                    'status' => 'created',
                ],
            ],
        ]);

        $this->assertApiSuccess($result2);
        $modules2 = $result2->get('modules');
        self::assertIsArray($modules2);
        self::assertCount(3, $modules2);

        $tempKeyMapping2 = $result2->get('tempKeyMapping');
        self::assertIsArray($tempKeyMapping2);

        // Step 4: Publish draft
        $publishResult = $this->apiPost('pageDraftPublish', [
            'draftId' => $draftId,
        ]);

        $this->assertApiSuccess($publishResult);
        self::assertTrue($publishResult->get('success'));

        // Step 5: Verify published modules - should have exactly 3 modules
        $this->getEntityManager()->clear();
        $updatedTranslation = $this->pageTranslationRepository->get($translation->getId());

        $masterModules = $this->moduleRepository->findAllActiveByPage($updatedTranslation);
        self::assertCount(3, $masterModules, 'Should have exactly 3 modules after publish');

        // Verify module types
        $moduleTypes = \array_map(static fn($m) => $m->getType(), $masterModules);
        \sort($moduleTypes);
        self::assertEquals(['link', 'tabs', 'text'], $moduleTypes);
    }

    /**
     * @throws \App\Pages\PageTranslationNotFound
     * @throws \RuntimeException
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     * @throws \Nette\DI\MissingServiceException
     * @throws \App\Users\UserNotFound
     */
    #[Test]
    public function complexScenarioMoveModuleAfterTabsNoDuplicates(): void
    {
        $translation = $this->createTestPage('/move-test', 'Move Test');
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Step 1: Create and publish initial structure with link, text, tabs
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $createResult->get('id');

        // Create link (sort 0), text inside link, tabs (sort 1)
        $result1 = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'link-1',
                    'type' => 'link',
                    'settings' => ['url' => '/link'],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Text in link'],
                    'parentDraftId' => null,
                    'parentTempKey' => 'link-1',
                    'sort' => 0,
                    'status' => 'created',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'tabs-1',
                    'type' => 'tabs',
                    'settings' => ['tabs' => []],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 1,
                    'status' => 'created',
                ],
            ],
        ]);
        $this->assertApiSuccess($result1);

        // Publish initial structure
        $this->apiPost('pageDraftPublish', ['draftId' => $draftId]);

        // Clear and get master modules
        $this->getEntityManager()->clear();

        // Re-login after EM clear to refresh the user entity in AuthorizationChecker
        $this->resetAuthorizationCheckerAndRelogin();

        $updatedTranslation = $this->pageTranslationRepository->get($translation->getId());
        $masterModules = $this->moduleRepository->findAllActiveByPage($updatedTranslation);

        self::assertCount(3, $masterModules, 'Should have 3 modules after first publish');

        // Get module IDs for reference
        $linkModule = null;
        $textModule = null;
        $tabsModule = null;
        foreach ($masterModules as $m) {
            if ($m->getType() === 'link') {
                $linkModule = $m;
            }

            if ($m->getType() === 'text') {
                $textModule = $m;
            }

            if ($m->getType() === 'tabs') {
                $tabsModule = $m;
            }
        }

        self::assertNotNull($linkModule);
        self::assertNotNull($textModule);
        self::assertNotNull($tabsModule);

        // Step 2: Create new draft to move link from before tabs (sort 0) to after tabs (sort 2)
        $createResult2 = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $this->assertApiSuccess($createResult2, 'Failed to create second draft');
        $draftId2 = $createResult2->get('id');

        // Move link after tabs by changing sort order
        // Original: link (0), tabs (1) -> New: tabs (0), link (1)
        $result2 = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId2,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $tabsModule->getId(),
                    'tempKey' => null,
                    'type' => 'tabs',
                    'settings' => ['tabs' => []],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0, // tabs now first
                    'status' => 'modified',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => $linkModule->getId(),
                    'tempKey' => null,
                    'type' => 'link',
                    'settings' => ['url' => '/link'],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 1, // link now after tabs
                    'status' => 'modified',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => $textModule->getId(),
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Text in link'],
                    'parentDraftId' => null, // Will be resolved to link
                    'parentOriginalModuleId' => $linkModule->getId(),
                    'sort' => 0,
                    'status' => 'unchanged',
                ],
            ],
        ]);

        $this->assertApiSuccess($result2);
        $savedModules = $result2->get('modules');
        self::assertIsArray($savedModules);
        self::assertCount(3, $savedModules, 'Should still have 3 module drafts after move');

        // Step 3: Publish the move
        $publishResult2 = $this->apiPost('pageDraftPublish', ['draftId' => $draftId2]);
        $this->assertApiSuccess($publishResult2);

        // Step 4: Verify no duplicates - CRITICAL CHECK
        $this->getEntityManager()->clear();
        $finalTranslation = $this->pageTranslationRepository->get($translation->getId());
        $finalModules = $this->moduleRepository->findAllActiveByPage($finalTranslation);

        self::assertCount(3, $finalModules, 'CRITICAL: Should still have exactly 3 modules, no duplicates!');

        // Verify each type appears exactly once
        $typeCounts = [];
        foreach ($finalModules as $m) {
            $type = $m->getType();
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }

        self::assertEquals(1, $typeCounts['link'] ?? 0, 'Should have exactly 1 link module');
        self::assertEquals(1, $typeCounts['text'] ?? 0, 'Should have exactly 1 text module');
        self::assertEquals(1, $typeCounts['tabs'] ?? 0, 'Should have exactly 1 tabs module');

        // Verify sort order changed - only check root modules (no parent)
        $rootModules = \array_filter($finalModules, static fn($m) => $m->getParent() === null);
        self::assertCount(2, $rootModules, 'Should have 2 root modules (link and tabs)');

        $sortedRoots = \array_values($rootModules);
        \usort($sortedRoots, static fn($a, $b) => $a->getSort() <=> $b->getSort());

        self::assertEquals('tabs', $sortedRoots[0]->getType(), 'Tabs should now be first');
        self::assertEquals('link', $sortedRoots[1]->getType(), 'Link should now be second');
    }

    /**
     * @throws \App\Pages\PageTranslationNotFound
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \RuntimeException
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     * @throws \Nette\DI\MissingServiceException
     * @throws \App\Users\UserNotFound
     */
    #[Test]
    public function complexScenarioEditExistingModulesNoDuplicates(): void
    {
        $translation = $this->createTestPage('/edit-test', 'Edit Test');
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create and publish initial page with container > row > text
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $createResult->get('id');

        $result1 = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'container-1',
                    'type' => 'container',
                    'settings' => [],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'row-1',
                    'type' => 'row',
                    'settings' => [],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => 'container-1',
                    'sort' => 0,
                    'status' => 'created',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Original text'],
                    'parentDraftId' => null,
                    'parentTempKey' => 'row-1',
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);
        $this->assertApiSuccess($result1);

        // Publish
        $this->apiPost('pageDraftPublish', ['draftId' => $draftId]);

        // Get master module IDs
        $this->getEntityManager()->clear();

        // Re-login after EM clear to refresh the user entity
        $this->resetAuthorizationCheckerAndRelogin();

        $updatedTranslation = $this->pageTranslationRepository->get($translation->getId());
        $masterModules = $this->moduleRepository->findAllActiveByPage($updatedTranslation);

        self::assertCount(3, $masterModules);

        $containerModule = null;
        $rowModule = null;
        $textModule = null;
        foreach ($masterModules as $m) {
            if ($m->getType() === 'container') {
                $containerModule = $m;
            }

            if ($m->getType() === 'row') {
                $rowModule = $m;
            }

            if ($m->getType() === 'text') {
                $textModule = $m;
            }
        }

        self::assertNotNull($containerModule);
        self::assertNotNull($rowModule);
        self::assertNotNull($textModule);

        // Create new draft to edit text
        $createResult2 = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $this->assertApiSuccess($createResult2, 'Failed to create edit draft');
        $draftId2 = $createResult2->get('id');

        // Edit only the text module
        $result2 = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId2,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $containerModule->getId(),
                    'tempKey' => null,
                    'type' => 'container',
                    'settings' => [],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'unchanged',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => $rowModule->getId(),
                    'tempKey' => null,
                    'type' => 'row',
                    'settings' => [],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentOriginalModuleId' => $containerModule->getId(),
                    'sort' => 0,
                    'status' => 'unchanged',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => $textModule->getId(),
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'EDITED text'],
                    'parentDraftId' => null,
                    'parentOriginalModuleId' => $rowModule->getId(),
                    'sort' => 0,
                    'status' => 'modified',
                ],
            ],
        ]);
        $this->assertApiSuccess($result2);

        // Publish edit
        $publishResult2 = $this->apiPost('pageDraftPublish', ['draftId' => $draftId2]);
        $this->assertApiSuccess($publishResult2);

        // Verify no duplicates
        $this->getEntityManager()->clear();
        $finalTranslation = $this->pageTranslationRepository->get($translation->getId());
        $finalModules = $this->moduleRepository->findAllActiveByPage($finalTranslation);

        self::assertCount(3, $finalModules, 'Should still have exactly 3 modules after edit');

        // Verify text was updated
        $finalTextModule = null;
        foreach ($finalModules as $m) {
            if ($m->getType() === 'text') {
                $finalTextModule = $m;
            }
        }

        self::assertNotNull($finalTextModule);
        // Check translation settings were updated
        $textTranslation = $this->getService(\App\CmsModules\Database\ModuleTranslationRepository::class)
            ->findByModule($finalTextModule, 'cs');
        self::assertNotNull($textTranslation);
        self::assertEquals('EDITED text', $textTranslation->getSettings()['text'] ?? null);
    }

    // ==========================================
    // BUG REPRODUCTION TESTS
    // ==========================================

    /**
     * Test that unsaved modules (sort=-1) are cleaned up when loading draft.
     *
     * When a user creates a module via moduleContent but doesn't save, and then reloads the page,
     * the unsaved modules should be deleted to prevent orphaned drafts from accumulating.
     *
     * @throws \RuntimeException
     */
    #[Test]
    public function unsavedModulesAreCleanedUpWhenLoadingDraft(): void
    {
        $translation = $this->createTestPage('/unsaved-cleanup-test', 'Unsaved Cleanup Test');
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Step 1: Create a module via /api/module-content
        // This implicitly creates a draft and a ModuleDraft with sort=-1
        $moduleContentResult = $this->apiPost('moduleContent', [
            'pageId' => $translation->getPage()->getId(),
            'pageTranslationId' => $translation->getId(),
            'language' => 'cs',
            'id' => null, // New module
            'type' => 'text',
            'settings' => \json_encode(['text' => 'Test content']),
            'parentId' => null,
        ]);

        $this->assertApiSuccess($moduleContentResult, 'Failed to create module via moduleContent');
        $unsavedModuleId = (int) $moduleContentResult->get('id');
        self::assertGreaterThan(0, $unsavedModuleId, 'Module ID should be returned');

        // Verify the module exists with sort=-1
        $moduleDraftRepository = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);
        $unsavedModule = $moduleDraftRepository->find($unsavedModuleId);
        self::assertNotNull($unsavedModule, 'Module should exist after creation');
        self::assertEquals(-1, $unsavedModule->getSort(), 'Module should have sort=-1');

        // Step 2: Simulate page reload - call getOrCreatePageDraft
        // This should clean up unsaved modules (sort=-1)
        $draftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);

        $this->assertApiSuccess($draftResult, 'Failed to get/create draft');

        // Step 3: Verify the unsaved module was deleted
        $this->getEntityManager()->clear();
        $deletedModule = $moduleDraftRepository->find($unsavedModuleId);
        self::assertNull($deletedModule, 'Unsaved module (sort=-1) should be deleted when loading draft');
    }

    /**
     * Test that saving a draft without prior draft creation works correctly.
     *
     * Scenario: User opens page without draft, makes changes, then bulk saves.
     * The frontend sends master module IDs with draftId=null and originalModuleId=masterModuleId.
     *
     * @throws \RuntimeException
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     */
    #[Test]
    public function bulkSaveWithMasterModulesCreatesDraftModulesCorrectly(): void
    {
        // Create page with existing master modules
        $translation = $this->createTestPage('/master-modules-test', 'Master Modules Test');
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // First, create and publish some master modules
        $createDraftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $initialDraftId = $createDraftResult->get('id');

        $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $initialDraftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'container-1',
                    'type' => 'container',
                    'settings' => [],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Original text'],
                    'parentDraftId' => null,
                    'parentTempKey' => 'container-1',
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);

        // Publish to create master modules
        $this->apiPost('pageDraftPublish', ['draftId' => $initialDraftId]);

        // Clear entity manager and re-login
        $this->getEntityManager()->clear();
        $this->resetAuthorizationCheckerAndRelogin();

        // Get master module IDs
        $updatedTranslation = $this->pageTranslationRepository->get($translation->getId());
        $masterModules = $this->moduleRepository->findAllActiveByPage($updatedTranslation);
        self::assertCount(2, $masterModules);

        $containerModuleId = null;
        $textModuleId = null;
        foreach ($masterModules as $m) {
            if ($m->getType() === 'container') {
                $containerModuleId = $m->getId();
            }

            if ($m->getType() === 'text') {
                $textModuleId = $m->getId();
            }
        }

        self::assertNotNull($containerModuleId);
        self::assertNotNull($textModuleId);

        // NOW simulate the user scenario:
        // User opens page (no draft), edits modules, and saves
        // Frontend sends draftId=null, originalModuleId=masterModuleId

        // Create new draft for editing
        $editDraftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $this->assertApiSuccess($editDraftResult, 'Failed to create edit draft');
        $editDraftId = $editDraftResult->get('id');

        // Save with master module references - this should create new draft modules
        $saveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $editDraftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $containerModuleId,
                    'tempKey' => null,
                    'type' => 'container',
                    'settings' => [],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'status' => 'unchanged',
                ],
                [
                    'draftId' => null,
                    'originalModuleId' => $textModuleId,
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'MODIFIED text'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => $containerModuleId,
                    'sort' => 0,
                    'status' => 'modified',
                ],
            ],
        ]);

        $this->assertApiSuccess($saveResult, 'Bulk save with master module references should succeed');
        $savedModules = $saveResult->get('modules');
        self::assertIsArray($savedModules);
        self::assertCount(2, $savedModules);

        // Verify originalIdMapping is returned for modules that were converted from master
        $originalIdMapping = $saveResult->get('originalIdMapping');
        self::assertIsArray($originalIdMapping);
        // Should have mappings for both container and text
        self::assertNotEmpty($originalIdMapping);
    }

    // ==========================================
    // TRANSLATION STATUS TESTS
    // ==========================================

    /**
     * Create a test page with multiple language translations (CZ and EN).
     *
     * @return array{page: Page, cs: PageTranslation, en: PageTranslation}
     */
    private function createMultiLanguagePage(string $baseSlug = '/multi-lang'): array
    {
        $page = new Page('Multi-language Page');
        $this->pageRepository->save($page);

        // CZ translation
        $czTranslation = new PageTranslation(
            $page,
            'cs',
            $baseSlug . '-cs',
            \sha1(\strtolower($baseSlug . '-cs')),
            'Czech Page',
        );
        $this->pageTranslationRepository->save($czTranslation);

        // EN translation (shared layout - custom=false by default)
        $enTranslation = new PageTranslation(
            $page,
            'en',
            $baseSlug . '-en',
            \sha1(\strtolower($baseSlug . '-en')),
            'English Page',
        );
        $this->pageTranslationRepository->save($enTranslation);

        return [
            'page' => $page,
            'cs' => $czTranslation,
            'en' => $enTranslation,
        ];
    }

    /**
     * Test that publishing a new module in one language creates pending translations
     * for other languages with shared layout.
     *
     * @throws \App\Pages\PageTranslationNotFound
     * @throws \RuntimeException
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     * @throws \Nette\DI\MissingServiceException
     * @throws \App\Users\UserNotFound
     */
    #[Test]
    public function publishingNewModuleCreatesPendingTranslationsForOtherLanguages(): void
    {
        // Create page with CZ and EN translations (shared layout)
        $translations = $this->createMultiLanguagePage('/pending-test');
        $czTranslation = $translations['cs'];

        // Create draft for CZ and add a new module
        $createResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $czTranslation->getId(),
        ]);
        $this->assertApiSuccess($createResult);
        $draftId = $createResult->get('id');

        // Add a text module with CZ content
        $saveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Český text'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);
        $this->assertApiSuccess($saveResult);

        // Publish the CZ draft
        $publishResult = $this->apiPost('pageDraftPublish', ['draftId' => $draftId]);
        $this->assertApiSuccess($publishResult);

        // Clear entity manager to reload fresh data
        $this->getEntityManager()->clear();
        $this->resetAuthorizationCheckerAndRelogin();

        // Get the master module
        $page = $this->pageRepository->find($translations['page']->getId());
        self::assertNotNull($page);

        $masterModules = $this->moduleRepository->findAllActiveByPage($page);
        self::assertCount(1, $masterModules);
        $textModule = $masterModules[0];

        // Get translation repository
        $translationRepo = $this->getService(\App\CmsModules\Database\ModuleTranslationRepository::class);

        // Check CZ translation - should be 'translated'
        $czModuleTranslation = $translationRepo->findByModule($textModule, 'cs');
        self::assertNotNull($czModuleTranslation, 'CZ translation should exist');
        self::assertEquals('translated', $czModuleTranslation->getStatus()->value);
        self::assertEquals('Český text', $czModuleTranslation->getSettings()['text'] ?? null);

        // Check EN translation - should be 'pending' with CZ content as fallback
        $enModuleTranslation = $translationRepo->findByModule($textModule, 'en');
        self::assertNotNull($enModuleTranslation, 'EN translation should be created');
        self::assertEquals('pending', $enModuleTranslation->getStatus()->value);
        // EN should have the CZ text as fallback content
        self::assertEquals('Český text', $enModuleTranslation->getSettings()['text'] ?? null);
    }

    /**
     * Test that editing a module in a language with pending status
     * changes it to translated status.
     *
     * @throws \App\Pages\PageTranslationNotFound
     * @throws \RuntimeException
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     * @throws \Nette\DI\MissingServiceException
     * @throws \App\Users\UserNotFound
     * @throws \Doctrine\ORM\Exception\ORMException
     */
    #[Test]
    public function editingModuleInPendingLanguageChangesStatusToTranslated(): void
    {
        // First, create a module in CZ and publish it (creates pending EN translation)
        $translations = $this->createMultiLanguagePage('/translate-test');
        $czTranslation = $translations['cs'];
        $enTranslation = $translations['en'];

        // Create and publish module in CZ
        $czDraftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $czTranslation->getId(),
        ]);
        $czDraftId = $czDraftResult->get('id');

        $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $czDraftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Český obsah'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);

        $this->apiPost('pageDraftPublish', ['draftId' => $czDraftId]);

        // Clear and refresh
        $this->getEntityManager()->clear();
        $this->resetAuthorizationCheckerAndRelogin();

        // Get the master module
        $page = $this->pageRepository->find($translations['page']->getId());
        self::assertNotNull($page);
        $masterModules = $this->moduleRepository->findAllActiveByPage($page);
        self::assertCount(1, $masterModules);
        $textModuleId = $masterModules[0]->getId();

        // Verify EN is pending before editing
        $translationRepo = $this->getService(\App\CmsModules\Database\ModuleTranslationRepository::class);
        $enModuleTranslation = $translationRepo->findByModule($masterModules[0], 'en');
        self::assertNotNull($enModuleTranslation);
        self::assertEquals('pending', $enModuleTranslation->getStatus()->value);

        // Now edit the module in EN
        $enDraftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $enTranslation->getId(),
        ]);
        $this->assertApiSuccess($enDraftResult);
        $enDraftId = $enDraftResult->get('id');

        // Save with English content
        $enSaveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $enDraftId,
            'language' => 'en',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $textModuleId,
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'English content'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'modified',
                ],
            ],
        ]);
        $this->assertApiSuccess($enSaveResult);

        // Publish EN translation
        $enPublishResult = $this->apiPost('pageDraftPublish', ['draftId' => $enDraftId]);
        $this->assertApiSuccess($enPublishResult);

        // Clear and verify final state
        $this->getEntityManager()->clear();

        $finalModule = $this->moduleRepository->find($textModuleId);
        self::assertNotNull($finalModule);

        // EN should now be 'translated'
        $finalEnTranslation = $translationRepo->findByModule($finalModule, 'en');
        self::assertNotNull($finalEnTranslation);
        self::assertEquals('translated', $finalEnTranslation->getStatus()->value);
        self::assertEquals('English content', $finalEnTranslation->getSettings()['text'] ?? null);

        // CZ should still be 'translated'
        $finalCzTranslation = $translationRepo->findByModule($finalModule, 'cs');
        self::assertNotNull($finalCzTranslation);
        self::assertEquals('translated', $finalCzTranslation->getStatus()->value);
    }

    /**
     * Test that custom layout (custom=true) does NOT create pending translations
     * for other languages because modules are not shared.
     *
     * @throws \RuntimeException
     */
    #[Test]
    public function customLayoutDoesNotCreatePendingTranslations(): void
    {
        // Create page with custom layout for CZ
        $translations = $this->createMultiLanguagePage('/custom-layout');
        $czTranslation = $translations['cs'];
        $czTranslation->setCustom(true); // Custom layout - modules not shared
        $this->pageTranslationRepository->save($czTranslation);

        // Create and publish module in CZ
        $draftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $czTranslation->getId(),
        ]);
        $draftId = $draftResult->get('id');

        $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Only in Czech'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);

        $this->apiPost('pageDraftPublish', ['draftId' => $draftId]);

        // Clear and check
        $this->getEntityManager()->clear();

        // Module should only exist for CZ translation (custom layout)
        $updatedCzTranslation = $this->pageTranslationRepository->get($czTranslation->getId());
        $masterModules = $this->moduleRepository->findAllActiveByPage($updatedCzTranslation);
        self::assertCount(1, $masterModules);
        $textModule = $masterModules[0];

        // Check translations - only CZ should exist
        $translationRepo = $this->getService(\App\CmsModules\Database\ModuleTranslationRepository::class);

        $czModuleTranslation = $translationRepo->findByModule($textModule, 'cs');
        self::assertNotNull($czModuleTranslation);

        // EN translation should NOT exist (custom layout = modules not shared)
        $enModuleTranslation = $translationRepo->findByModule($textModule, 'en');
        self::assertNull($enModuleTranslation, 'EN translation should NOT be created for custom layout');
    }

    /**
     * Test that API returns translation status in module draft data.
     *
     * @throws \RuntimeException
     */
    #[Test]
    public function apiReturnsTranslationStatusInModuleDraftData(): void
    {
        $translations = $this->createMultiLanguagePage('/api-status');
        $czTranslation = $translations['cs'];

        // Create draft and module
        $draftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $czTranslation->getId(),
        ]);
        $draftId = $draftResult->get('id');

        // Save module
        $saveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Test'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);

        $this->assertApiSuccess($saveResult);
        $modules = $saveResult->get('modules');
        self::assertIsArray($modules);
        self::assertCount(1, $modules);

        // Verify translationStatus is returned
        /** @var array<string, mixed> $module */
        $module = $modules[0];
        self::assertIsArray($module);
        self::assertArrayHasKey('translationStatus', $module);
        self::assertEquals('translated', $module['translationStatus']);
    }

    // ==========================================
    // UNSAVED DRAFT (sort=-1) HANDLING TESTS
    // ==========================================

    /**
     * Test that saving modules with originalModuleId pointing to an unsaved draft (sort=-1)
     * updates the existing draft instead of creating a duplicate.
     *
     * This reproduces the bug where:
     * 1. User creates a module via /api/module-content (creates draft with sort=-1)
     * 2. User reloads the page (FE doesn't know about sort=-1 drafts)
     * 3. User edits the module and saves
     * 4. FE sends originalModuleId = unsaved draft ID (because it thinks it's a master module)
     * 5. Before fix: Backend created DUPLICATE draft with originalModuleId pointing to another draft
     * 6. After fix: Backend recognizes the unsaved draft and updates it instead
     *
     * @throws \RuntimeException
     */
    #[Test]
    public function savingWithOriginalModuleIdPointingToUnsavedDraftUpdatesThatDraft(): void
    {
        $translation = $this->createTestPage('/unsaved-draft-test', 'Unsaved Draft Test');
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Get the page draft FIRST (this cleans up any unsaved modules)
        $draftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $this->assertApiSuccess($draftResult);
        $draftId = (int) $draftResult->get('id');

        // Step 1: Create a module via /api/module-content (creates draft with sort=-1)
        $moduleContentResult = $this->apiPost('moduleContent', [
            'pageId' => $translation->getPage()->getId(),
            'pageTranslationId' => $translation->getId(),
            'language' => 'cs',
            'id' => null,
            'type' => 'link',
            'settings' => \json_encode(
                ['link' => ['text' => 'Original text', 'url' => ['type' => 'url', 'url' => '#']]],
            ),
            'parentId' => null,
        ]);

        $this->assertApiSuccess($moduleContentResult, 'Failed to create module via moduleContent');
        $unsavedDraftId = (int) $moduleContentResult->get('id');
        self::assertGreaterThan(0, $unsavedDraftId, 'Module ID should be returned');

        // Verify the draft has sort=-1
        $moduleDraftRepository = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);
        $unsavedDraft = $moduleDraftRepository->find($unsavedDraftId);
        self::assertNotNull($unsavedDraft);
        self::assertEquals(-1, $unsavedDraft->getSort(), 'Newly created draft should have sort=-1');

        // Step 2: Simulate what happens after page reload
        // FE doesn't know about unsaved drafts (sort=-1), so it sends originalModuleId instead of draftId
        // This is what caused the duplicate bug - FE thinks unsavedDraftId is a master module ID
        $saveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null, // FE doesn't know the draft ID after reload
                    'originalModuleId' => $unsavedDraftId, // FE mistakenly thinks this is master module ID
                    'tempKey' => null,
                    'type' => 'link',
                    'settings' => ['link' => ['text' => 'Updated text', 'url' => ['type' => 'url', 'url' => '#']]],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'status' => 'modified',
                ],
            ],
        ]);

        $this->assertApiSuccess($saveResult, 'Save should succeed');
        /** @var array<int, array<string, mixed>> $savedModules */
        $savedModules = $saveResult->get('modules');
        self::assertIsArray($savedModules);
        self::assertCount(1, $savedModules, 'Should have exactly 1 module (no duplicates)');

        // Verify the existing draft was updated, not a new one created
        $savedModule = $savedModules[0];
        self::assertEquals($unsavedDraftId, $savedModule['id'], 'Should update the existing unsaved draft');
        self::assertEquals(0, $savedModule['sort'], 'Sort should be updated from -1 to 0');

        // Verify no duplicates in database
        $this->getEntityManager()->clear();
        $pageDraftRepository = $this->getService(\App\Pages\Database\PageDraftRepository::class);
        $draft = $pageDraftRepository->get($draftId);
        $allModuleDrafts = $moduleDraftRepository->findAllSavedByPageDraft($draft);
        self::assertCount(1, $allModuleDrafts, 'Database should have exactly 1 module draft (no duplicates)');
    }

    /**
     * Test that multiple saves of a newly created module don't create duplicates.
     *
     * Scenario:
     * 1. Create module via moduleContent (sort=-1)
     * 2. Save draft (module gets proper sort)
     * 3. Edit module
     * 4. Save draft again
     * 5. Should still have only 1 module
     *
     * @throws \RuntimeException
     */
    #[Test]
    public function multipleSavesOfNewModuleDoNotCreateDuplicates(): void
    {
        $translation = $this->createTestPage('/multi-save-test', 'Multi Save Test');
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Get draft FIRST (this cleans up any unsaved modules)
        $draftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = (int) $draftResult->get('id');

        // Create module via moduleContent
        $moduleContentResult = $this->apiPost('moduleContent', [
            'pageId' => $translation->getPage()->getId(),
            'pageTranslationId' => $translation->getId(),
            'language' => 'cs',
            'id' => null,
            'type' => 'text',
            'settings' => \json_encode(['text' => 'Initial text']),
            'parentId' => null,
        ]);

        $this->assertApiSuccess($moduleContentResult);
        $moduleId = (int) $moduleContentResult->get('id');

        // First save - with draftId (normal case, FE knows the ID)
        $saveResult1 = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => $moduleId,
                    'originalModuleId' => null,
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'First save'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);

        $this->assertApiSuccess($saveResult1, 'First save should succeed');
        /** @var array<int, array<string, mixed>> $modules1 */
        $modules1 = $saveResult1->get('modules');
        self::assertIsArray($modules1);
        self::assertCount(1, $modules1);

        // Second save - same module with updated content
        $saveResult2 = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => $moduleId,
                    'originalModuleId' => null,
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Second save'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'status' => 'modified',
                ],
            ],
        ]);

        $this->assertApiSuccess($saveResult2, 'Second save should succeed');
        /** @var array<int, array<string, mixed>> $modules2 */
        $modules2 = $saveResult2->get('modules');
        self::assertIsArray($modules2);
        self::assertCount(1, $modules2, 'Should still have exactly 1 module after second save');

        // Verify in database
        $this->getEntityManager()->clear();
        $moduleDraftRepository = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);
        $pageDraftRepository = $this->getService(\App\Pages\Database\PageDraftRepository::class);
        $draft = $pageDraftRepository->get($draftId);
        $allModuleDrafts = $moduleDraftRepository->findAllSavedByPageDraft($draft);
        self::assertCount(1, $allModuleDrafts, 'Database should have exactly 1 module draft');
    }

    /**
     * Test that translatable settings are correctly split and saved for link module.
     *
     * @throws \RuntimeException
     */
    #[Test]
    public function linkModuleTranslatableSettingsAreSavedCorrectly(): void
    {
        $translation = $this->createTestPage('/link-translatable-test', 'Link Translatable Test');
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Get draft
        $draftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $draftId = $draftResult->get('id');

        // Save link module with translatable text
        $saveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $draftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'link-1',
                    'type' => 'link',
                    'settings' => [
                        'link' => [
                            'text' => 'Click here',
                            'url' => ['type' => 'url', 'url' => 'https://example.com'],
                            'icon' => '',
                            'iconPosition' => 'left',
                            'target' => '_self',
                        ],
                    ],
                    'translationSettings' => [],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'parentOriginalModuleId' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);

        $this->assertApiSuccess($saveResult, 'Save should succeed');
        /** @var array<int, array<string, mixed>> $modules */
        $modules = $saveResult->get('modules');
        self::assertIsArray($modules);
        self::assertCount(1, $modules);

        $savedModuleId = (int) $modules[0]['id'];

        // Verify in database that settings were split correctly
        $this->getEntityManager()->clear();
        $moduleDraftRepository = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);
        $moduleTranslationDraftRepository = $this->getService(
            \App\CmsModules\Database\ModuleTranslationDraftRepository::class,
        );

        $moduleDraft = $moduleDraftRepository->get($savedModuleId);
        $moduleSettings = $moduleDraft->getSettings();

        // link.text should be in translation, not in module settings
        // Module settings should have url, icon, iconPosition, target but NOT text
        self::assertArrayHasKey('link', $moduleSettings);
        /** @var array<string, mixed> $linkSettings */
        $linkSettings = $moduleSettings['link'];
        self::assertIsArray($linkSettings);
        self::assertArrayNotHasKey(
            'text',
            $linkSettings,
            'text should be in translation settings, not module settings',
        );
        self::assertArrayHasKey('url', $linkSettings);

        // Check translation settings
        $translationDraft = $moduleTranslationDraftRepository->findByModuleDraftAndLanguage($moduleDraft, 'cs');
        self::assertNotNull($translationDraft, 'Translation draft should exist');
        $translationSettings = $translationDraft->getSettings();
        self::assertArrayHasKey('link', $translationSettings);
        /** @var array<string, mixed> $translationLinkSettings */
        $translationLinkSettings = $translationSettings['link'];
        self::assertIsArray($translationLinkSettings);
        self::assertArrayHasKey('text', $translationLinkSettings, 'text should be in translation settings');
        self::assertEquals('Click here', $translationLinkSettings['text']);
    }

    /**
     * Test that unsaved modules (sort=-1) are cleaned up when calling page-draft-status.
     *
     * This is a regression test for the bug where:
     * 1. User creates a module via /api/module-content (creates draft with sort=-1)
     * 2. User reloads the page (calls page-draft-status, NOT page-draft-get-or-create)
     * 3. The unsaved modules with sort=-1 should be cleaned up
     *
     * Before the fix: Cleanup only happened in page-draft-get-or-create, not in page-draft-status
     * After the fix: Cleanup happens in both endpoints
     *
     * @throws \RuntimeException
     */
    #[Test]
    public function unsavedModulesAreCleanedUpWhenCallingPageDraftStatus(): void
    {
        $translation = $this->createTestPage('/unsaved-status-cleanup-test', 'Unsaved Status Cleanup Test');
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Step 1: Create draft first
        $draftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $translation->getId(),
        ]);
        $this->assertApiSuccess($draftResult, 'Failed to create draft');

        // Step 2: Create a module via /api/module-content (creates draft with sort=-1)
        $moduleContentResult = $this->apiPost('moduleContent', [
            'pageId' => $translation->getPage()->getId(),
            'pageTranslationId' => $translation->getId(),
            'language' => 'cs',
            'id' => null,
            'type' => 'text',
            'settings' => \json_encode(['text' => 'Test content']),
            'parentId' => null,
        ]);

        $this->assertApiSuccess($moduleContentResult, 'Failed to create module via moduleContent');
        $unsavedModuleId = (int) $moduleContentResult->get('id');
        self::assertGreaterThan(0, $unsavedModuleId, 'Module ID should be returned');

        // Verify the module exists with sort=-1
        $moduleDraftRepository = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);
        $unsavedModule = $moduleDraftRepository->find($unsavedModuleId);
        self::assertNotNull($unsavedModule, 'Module should exist after creation');
        self::assertEquals(-1, $unsavedModule->getSort(), 'Module should have sort=-1');

        // Step 3: Simulate page reload by calling page-draft-status (NOT page-draft-get-or-create)
        // This should also clean up unsaved modules (sort=-1)
        $statusResult = $this->apiGet('pageDraftStatus', [
            'pageTranslationId' => $translation->getId(),
        ]);

        $this->assertApiSuccess($statusResult, 'Failed to get draft status');
        self::assertTrue($statusResult->get('hasDraft'), 'Draft should exist');

        // Step 4: Verify the unsaved module was deleted
        $this->getEntityManager()->clear();
        $deletedModule = $moduleDraftRepository->find($unsavedModuleId);
        self::assertNull($deletedModule, 'Unsaved module (sort=-1) should be deleted when calling page-draft-status');
    }

    /**
     * Test that saving translation settings in a language with pending status
     * changes the draft translation status to translated (before publishing).
     *
     * @throws \RuntimeException
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     * @throws \Nette\DI\MissingServiceException
     * @throws \App\Users\UserNotFound
     * @throws \App\Pages\PageTranslationNotFound
     */
    #[Test]
    public function savingDraftWithPendingStatusChangesToTranslated(): void
    {
        // Create page with CZ and EN translations (shared layout)
        $translations = $this->createMultiLanguagePage('/pending-save-test');
        $czTranslation = $translations['cs'];
        $enTranslation = $translations['en'];

        // Create and publish module in CZ (creates pending EN translation)
        $czDraftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $czTranslation->getId(),
        ]);
        $czDraftId = $czDraftResult->get('id');

        $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $czDraftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Český text'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);

        $this->apiPost('pageDraftPublish', ['draftId' => $czDraftId]);

        // Clear and refresh
        $this->getEntityManager()->clear();
        $this->resetAuthorizationCheckerAndRelogin();

        // Get the master module
        $page = $this->pageRepository->find($translations['page']->getId());
        self::assertNotNull($page);
        $masterModules = $this->moduleRepository->findAllActiveByPage($page);
        self::assertCount(1, $masterModules);
        $textModuleId = $masterModules[0]->getId();

        // Verify EN master translation is pending before editing
        $translationRepo = $this->getService(\App\CmsModules\Database\ModuleTranslationRepository::class);
        $enModuleTranslation = $translationRepo->findByModule($masterModules[0], 'en');
        self::assertNotNull($enModuleTranslation);
        self::assertEquals('pending', $enModuleTranslation->getStatus()->value, 'EN should be pending before edit');

        // Create draft for EN
        $enDraftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $enTranslation->getId(),
        ]);
        $this->assertApiSuccess($enDraftResult);
        $enDraftId = (int) $enDraftResult->get('id');

        // Save with English content (without publishing yet)
        $enSaveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $enDraftId,
            'language' => 'en',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $textModuleId,
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'English text'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'modified',
                ],
            ],
        ]);
        $this->assertApiSuccess($enSaveResult);

        // Get the module draft ID from response
        /** @var array<int, array<string, mixed>> $savedModules */
        $savedModules = $enSaveResult->get('modules');
        self::assertIsArray($savedModules);
        self::assertCount(1, $savedModules);
        $moduleDraftId = (int) $savedModules[0]['id'];

        // Verify the draft translation status is now TRANSLATED (not pending)
        $moduleTranslationDraftRepository = $this->getService(
            \App\CmsModules\Database\ModuleTranslationDraftRepository::class,
        );
        $moduleDraftRepository = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);

        $moduleDraft = $moduleDraftRepository->get($moduleDraftId);
        $translationDraft = $moduleTranslationDraftRepository->findByModuleDraftAndLanguage($moduleDraft, 'en');
        self::assertNotNull($translationDraft, 'Translation draft should exist');
        self::assertEquals(
            'translated',
            $translationDraft->getStatus()->value,
            'Draft translation status should be TRANSLATED after saving (was PENDING)',
        );
    }

    /**
     * Test that originalModuleId is updated in sibling drafts after publishing.
     *
     * This is a regression test for the bug where:
     * 1. CZ draft saves a new module (originalModuleId=null)
     * 2. Sync creates EN draft copy (originalModuleId=null)
     * 3. CZ draft is published → module goes to master with new ID (e.g., 100)
     * 4. EN draft still has originalModuleId=null (BUG!)
     * 5. User opens EN → FE sends originalModuleId=100 (from master)
     * 6. BE can't find draft with originalModuleId=100 → creates duplicate!
     *
     * After fix: When CZ is published, EN draft's originalModuleId is updated
     * to point to the new master module ID.
     *
     * @throws \RuntimeException
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     * @throws \Nette\DI\MissingServiceException
     * @throws \App\Users\UserNotFound
     * @throws \App\Pages\PageTranslationNotFound
     */
    #[Test]
    public function publishingDraftUpdatesOriginalModuleIdInSiblingDrafts(): void
    {
        // Create shared layout page with CZ and EN translations
        $translations = $this->createMultiLanguagePage('/sibling-update-test');
        $czTranslation = $translations['cs'];
        $enTranslation = $translations['en'];

        // Step 1: Create CZ draft and add a new module
        $czDraftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $czTranslation->getId(),
        ]);
        $this->assertApiSuccess($czDraftResult);
        $czDraftId = (int) $czDraftResult->get('id');

        // Save a new module in CZ draft (originalModuleId=null means new module)
        $czSaveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $czDraftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Český text'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);
        $this->assertApiSuccess($czSaveResult);

        // Step 2: Verify EN draft was created with synced module (originalModuleId=null)
        $pageDraftRepository = $this->getService(\App\Pages\Database\PageDraftRepository::class);
        $moduleDraftRepository = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);

        // Find EN draft (should have been created by sync)
        $enDraft = $pageDraftRepository->findByUserAndPageTranslation(
            $this->testUser,
            $enTranslation,
        );
        self::assertNotNull($enDraft, 'EN draft should have been created by sync');

        $enModulesBeforePublish = $moduleDraftRepository->findAllSavedByPageDraft($enDraft);
        self::assertCount(1, $enModulesBeforePublish, 'EN draft should have 1 synced module');
        $enModuleBefore = $enModulesBeforePublish[0];
        self::assertNull(
            $enModuleBefore->getOriginalModuleId(),
            'EN module should have originalModuleId=null before CZ publish',
        );
        $enModuleDraftId = $enModuleBefore->getId();

        // Step 3: Publish CZ draft
        $czPublishResult = $this->apiPost('pageDraftPublish', ['draftId' => $czDraftId]);
        $this->assertApiSuccess($czPublishResult);

        // Step 4: Get the new master module ID
        $this->getEntityManager()->clear();
        $this->resetAuthorizationCheckerAndRelogin();

        $page = $this->pageRepository->find($translations['page']->getId());
        self::assertNotNull($page);
        $masterModules = $this->moduleRepository->findAllActiveByPage($page);
        self::assertCount(1, $masterModules);
        $masterModuleId = $masterModules[0]->getId();

        // Step 5: Verify EN draft module now has originalModuleId updated
        $enModuleAfter = $moduleDraftRepository->find($enModuleDraftId);
        self::assertNotNull($enModuleAfter, 'EN module draft should still exist');
        self::assertEquals(
            $masterModuleId,
            $enModuleAfter->getOriginalModuleId(),
            'EN module originalModuleId should be updated to master module ID after CZ publish',
        );

        // Step 6: Verify saving EN draft doesn't create duplicate
        // Reload EN draft since entity manager was cleared
        $enDraftReloaded = $pageDraftRepository->findByUserAndPageTranslation(
            $this->testUser,
            $this->pageTranslationRepository->get($enTranslation->getId()),
        );
        self::assertNotNull($enDraftReloaded);
        $enDraftId = $enDraftReloaded->getId();

        // Save EN draft with the master module reference (simulating what FE would do)
        $enSaveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $enDraftId,
            'language' => 'en',
            'modules' => [
                [
                    'draftId' => $enModuleDraftId, // Now we have the correct draft ID
                    'originalModuleId' => $masterModuleId, // FE sends the master ID
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'English text'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'modified',
                ],
            ],
        ]);
        $this->assertApiSuccess($enSaveResult);

        // Step 7: Verify no duplicates
        /** @var array<int, array<string, mixed>> $enSavedModules */
        $enSavedModules = $enSaveResult->get('modules');
        self::assertIsArray($enSavedModules);
        self::assertCount(1, $enSavedModules, 'EN draft should still have exactly 1 module (no duplicates)');

        // Verify in database
        $this->getEntityManager()->clear();
        $finalEnDraft = $pageDraftRepository->get($enDraftId);
        $finalEnModules = $moduleDraftRepository->findAllSavedByPageDraft($finalEnDraft);
        self::assertCount(1, $finalEnModules, 'Database: EN draft should have exactly 1 module (no duplicates)');
    }

    /**
     * Test the scenario where FE doesn't know the EN draft ID and sends only originalModuleId.
     *
     * This is the exact bug scenario:
     * 1. CZ creates module, saves, publishes
     * 2. EN draft exists with updated originalModuleId
     * 3. User opens EN page - FE loads master modules, doesn't know about draft module IDs
     * 4. User saves - FE sends originalModuleId only (no draftId)
     * 5. Should find existing draft by originalModuleId (not create duplicate)
     *
     * @throws \RuntimeException
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     * @throws \Nette\DI\MissingServiceException
     * @throws \App\Users\UserNotFound
     * @throws \App\Pages\PageTranslationNotFound
     */
    #[Test]
    public function savingEnDraftWithOnlyOriginalModuleIdDoesNotCreateDuplicate(): void
    {
        // Create shared layout page with CZ and EN translations
        $translations = $this->createMultiLanguagePage('/sibling-no-draftid-test');
        $czTranslation = $translations['cs'];
        $enTranslation = $translations['en'];

        // Step 1: Create CZ draft and add a new module
        $czDraftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $czTranslation->getId(),
        ]);
        $this->assertApiSuccess($czDraftResult);
        $czDraftId = (int) $czDraftResult->get('id');

        // Save a new module in CZ draft
        $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $czDraftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Český text'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);

        // Step 2: Publish CZ draft
        $this->apiPost('pageDraftPublish', ['draftId' => $czDraftId]);

        // Clear and get master module ID
        $this->getEntityManager()->clear();
        $this->resetAuthorizationCheckerAndRelogin();

        $page = $this->pageRepository->find($translations['page']->getId());
        self::assertNotNull($page);
        $masterModules = $this->moduleRepository->findAllActiveByPage($page);
        self::assertCount(1, $masterModules);
        $masterModuleId = $masterModules[0]->getId();

        // Step 3: Find EN draft (exists from sync)
        $pageDraftRepository = $this->getService(\App\Pages\Database\PageDraftRepository::class);
        $moduleDraftRepository = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);

        $enDraft = $pageDraftRepository->findByUserAndPageTranslation(
            $this->testUser,
            $this->pageTranslationRepository->get($enTranslation->getId()),
        );
        self::assertNotNull($enDraft, 'EN draft should exist after sync');
        $enDraftId = $enDraft->getId();

        // Verify EN draft has 1 module with correct originalModuleId
        $enModulesBefore = $moduleDraftRepository->findAllSavedByPageDraft($enDraft);
        self::assertCount(1, $enModulesBefore);
        self::assertEquals(
            $masterModuleId,
            $enModulesBefore[0]->getOriginalModuleId(),
            'EN module should have originalModuleId pointing to master',
        );

        // Step 4: Simulate FE saving EN with only originalModuleId (no draftId)
        // This is what happens when user opens EN page after CZ was published
        $enSaveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $enDraftId,
            'language' => 'en',
            'modules' => [
                [
                    'draftId' => null, // FE doesn't know the draft ID
                    'originalModuleId' => $masterModuleId, // FE sends master module ID
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'English translation'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'modified',
                ],
            ],
        ]);
        $this->assertApiSuccess($enSaveResult);

        // Step 5: Verify no duplicates
        /** @var array<int, array<string, mixed>> $enSavedModules */
        $enSavedModules = $enSaveResult->get('modules');
        self::assertIsArray($enSavedModules);
        self::assertCount(1, $enSavedModules, 'Should have exactly 1 module (no duplicates)');

        // Verify the existing draft was updated (same ID)
        $this->getEntityManager()->clear();
        $finalEnDraft = $pageDraftRepository->get($enDraftId);
        $finalEnModules = $moduleDraftRepository->findAllSavedByPageDraft($finalEnDraft);
        self::assertCount(1, $finalEnModules, 'Database should have exactly 1 module draft (no duplicates)');

        // The module should still have the correct originalModuleId
        self::assertEquals(
            $masterModuleId,
            $finalEnModules[0]->getOriginalModuleId(),
            'Module should still have correct originalModuleId',
        );
    }

    /**
     * Test that saving draft WITHOUT changing translation content
     * does NOT change pending status to translated.
     *
     * Bug scenario:
     * 1. CZ creates module, publishes → EN is pending
     * 2. User opens EN draft
     * 3. User saves EN draft WITHOUT changing the translation content
     * 4. Expected: EN translation should REMAIN pending
     * 5. Actual (bug): EN translation becomes translated
     *
     * @throws \App\Pages\PageTranslationNotFound
     * @throws \RuntimeException
     * @throws \Nette\Security\AuthenticationException
     * @throws \ReflectionException
     * @throws \Nette\DI\MissingServiceException
     * @throws \App\Users\UserNotFound
     */
    #[Test]
    public function savingDraftWithoutChangingContentDoesNotChangePendingStatus(): void
    {
        // 1. Create shared layout page with CZ and EN
        $translations = $this->createMultiLanguagePage('/pending-status-test');
        $czTranslation = $translations['cs'];
        $enTranslation = $translations['en'];

        // 2. Create module in CZ, save and publish
        $czDraftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $czTranslation->getId(),
        ]);
        $this->assertApiSuccess($czDraftResult);
        $czDraftId = $czDraftResult->get('id');

        $czSaveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $czDraftId,
            'language' => 'cs',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => null,
                    'tempKey' => 'text-1',
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => ['text' => 'Český obsah'],
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'created',
                ],
            ],
        ]);
        $this->assertApiSuccess($czSaveResult);

        $this->apiPost('pageDraftPublish', ['draftId' => $czDraftId]);

        // Clear and get master module
        $this->getEntityManager()->clear();
        $this->resetAuthorizationCheckerAndRelogin();

        $page = $this->pageRepository->find($translations['page']->getId());
        self::assertNotNull($page);
        $masterModules = $this->moduleRepository->findAllActiveByPage($page);
        self::assertCount(1, $masterModules);
        $textModuleId = $masterModules[0]->getId();

        // 3. Verify EN master translation is PENDING
        /** @var \App\CmsModules\Database\ModuleTranslationRepository $translationRepo */
        $translationRepo = $this->getService(\App\CmsModules\Database\ModuleTranslationRepository::class);
        $enMasterTranslation = $translationRepo->findByModule($masterModules[0], 'en');
        self::assertNotNull($enMasterTranslation, 'EN translation should exist');
        self::assertEquals('pending', $enMasterTranslation->getStatus()->value, 'EN should be pending before save');

        // Get the original EN settings (which is the fallback CZ content)
        $originalEnSettings = $enMasterTranslation->getSettings();

        // 4. Open EN draft
        $enDraftResult = $this->apiPost('pageDraftGetOrCreate', [
            'pageTranslationId' => $enTranslation->getId(),
        ]);
        $this->assertApiSuccess($enDraftResult);
        $enDraftId = $enDraftResult->get('id');

        // 5. Save EN draft with SAME content (no actual changes)
        // This simulates user opening the editor and saving without changing anything
        $enSaveResult = $this->apiPostJson('pageDraftSaveModules', [
            'draftId' => $enDraftId,
            'language' => 'en',
            'modules' => [
                [
                    'draftId' => null,
                    'originalModuleId' => $textModuleId,
                    'tempKey' => null,
                    'type' => 'text',
                    'settings' => [],
                    'translationSettings' => $originalEnSettings, // SAME content - no change!
                    'parentDraftId' => null,
                    'parentTempKey' => null,
                    'sort' => 0,
                    'status' => 'unchanged', // Module is unchanged
                ],
            ],
        ]);
        $this->assertApiSuccess($enSaveResult);

        // 6. Verify EN draft translation is STILL PENDING (not translated!)
        /** @var \App\CmsModules\Database\ModuleDraftRepository $moduleDraftRepo */
        $moduleDraftRepo = $this->getService(\App\CmsModules\Database\ModuleDraftRepository::class);
        /** @var \App\CmsModules\Database\ModuleTranslationDraftRepository $translationDraftRepo */
        $translationDraftRepo = $this->getService(\App\CmsModules\Database\ModuleTranslationDraftRepository::class);
        /** @var \App\Pages\Database\PageDraftRepository $pageDraftRepo */
        $pageDraftRepo = $this->getService(\App\Pages\Database\PageDraftRepository::class);

        $enDraft = $pageDraftRepo->get((int) $enDraftId);
        $moduleDrafts = $moduleDraftRepo->findAllSavedByPageDraft($enDraft);
        self::assertCount(1, $moduleDrafts);

        $enTranslationDraft = $translationDraftRepo->findByModuleDraftAndLanguage($moduleDrafts[0], 'en');
        self::assertNotNull($enTranslationDraft, 'EN translation draft should exist');

        // THIS IS THE KEY ASSERTION - status should remain PENDING
        self::assertEquals(
            'pending',
            $enTranslationDraft->getStatus()->value,
            'EN translation should REMAIN pending when content was not changed',
        );
    }
}
