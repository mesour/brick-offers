<?php declare(strict_types = 1);

namespace Tests\Unit\Pages;

use App\CmsModules\CmsModuleProviderRegistry;
use App\CmsModules\Database\ModuleDraft;
use App\CmsModules\Database\ModuleDraftRepository;
use App\CmsModules\Database\ModuleRepository;
use App\CmsModules\Database\ModuleTranslationDraft;
use App\CmsModules\Database\ModuleTranslationDraftRepository;
use App\CmsModules\Database\ModuleTranslationRepository;
use App\CmsModules\Database\TranslationStatus;
use App\CmsModules\DraftModuleStatus;
use App\CmsModules\ModuleManager;
use App\CmsModules\Settings\CmsModuleSettingsResolver;
use App\Pages\Database\Page;
use App\Pages\Database\PageDraft;
use App\Pages\Database\PageDraftRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use App\Pages\PageDraftManager;
use App\Users\Database\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for shared layout draft synchronization.
 * When pages have shared layout (custom=false), drafts should sync structure across languages.
 */
final class PageDraftManagerSharedLayoutTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private PageDraftRepository&MockObject $pageDraftRepository;
    private ModuleDraftRepository&MockObject $moduleDraftRepository;
    private ModuleRepository&MockObject $moduleRepository;
    private ModuleTranslationDraftRepository&MockObject $moduleTranslationDraftRepository;
    private ModuleTranslationRepository&MockObject $moduleTranslationRepository;
    private ModuleManager&MockObject $moduleManager;
    private CmsModuleProviderRegistry $cmsModuleProviderRegistry;
    private CmsModuleSettingsResolver $cmsModuleSettingsResolver;
    private PageTranslationRepository&MockObject $pageTranslationRepository;
    private PageDraftManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->pageDraftRepository = $this->createMock(PageDraftRepository::class);
        $this->moduleDraftRepository = $this->createMock(ModuleDraftRepository::class);
        $this->moduleRepository = $this->createMock(ModuleRepository::class);
        $this->moduleTranslationDraftRepository = $this->createMock(ModuleTranslationDraftRepository::class);
        $this->moduleTranslationRepository = $this->createMock(ModuleTranslationRepository::class);
        $this->moduleManager = $this->createMock(ModuleManager::class);
        $this->cmsModuleProviderRegistry = new CmsModuleProviderRegistry();
        $this->cmsModuleSettingsResolver = new CmsModuleSettingsResolver();
        $this->pageTranslationRepository = $this->createMock(PageTranslationRepository::class);

        $this->manager = new PageDraftManager(
            $this->entityManager,
            $this->pageDraftRepository,
            $this->moduleDraftRepository,
            $this->moduleRepository,
            $this->moduleTranslationDraftRepository,
            $this->moduleTranslationRepository,
            $this->moduleManager,
            $this->cmsModuleProviderRegistry,
            $this->cmsModuleSettingsResolver,
            $this->pageTranslationRepository,
        );
    }

    /**
     * When creating first draft for shared layout, drafts for ALL languages should be created.
     *
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function getOrCreateDraft_createsAllLanguageDrafts_forSharedLayout(): void
    {
        $user = $this->createMock(User::class);
        $page = $this->createMock(Page::class);

        // CZ translation (source - the one being opened)
        $czTranslation = $this->createMock(PageTranslation::class);
        $czTranslation->method('getId')->willReturn(1);
        $czTranslation->method('getPage')->willReturn($page);
        $czTranslation->method('getLanguage')->willReturn('cs');
        $czTranslation->method('isCustom')->willReturn(false);
        $czTranslation->method('getVersion')->willReturn(1);

        // EN translation (should get draft created automatically)
        $enTranslation = $this->createMock(PageTranslation::class);
        $enTranslation->method('getId')->willReturn(2);
        $enTranslation->method('getPage')->willReturn($page);
        $enTranslation->method('getLanguage')->willReturn('en');
        $enTranslation->method('isCustom')->willReturn(false);
        $enTranslation->method('getVersion')->willReturn(1);

        // No existing draft
        $this->pageDraftRepository
            ->method('findByUserAndPageTranslation')
            ->willReturn(null);

        // No existing drafts for page
        $this->pageDraftRepository
            ->method('findByPageAndUser')
            ->willReturn([]);

        // All translations for this page
        $this->pageTranslationRepository
            ->method('findAllByPage')
            ->with($page)
            ->willReturn([$czTranslation, $enTranslation]);

        // No modules in master
        $this->moduleRepository
            ->method('findAllActiveByPage')
            ->willReturn([]);

        // Should save at least TWO drafts (one for CZ, one for EN)
        // Additional saves happen when syncing modules
        $savedDrafts = [];
        $this->pageDraftRepository
            ->expects($this->atLeast(2))
            ->method('save')
            ->willReturnCallback(static function (PageDraft $draft) use (&$savedDrafts): void {
                $savedDrafts[] = $draft;
            });

        $result = $this->manager->getOrCreateDraft($user, $czTranslation);

        self::assertInstanceOf(PageDraft::class, $result);
        // At least 2 unique drafts should be saved (CZ and EN)
        self::assertGreaterThanOrEqual(2, \count($savedDrafts));
    }

    /**
     * When creating draft for EN and CZ draft already exists, EN should sync from CZ.
     *
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function getOrCreateDraft_syncsFromExistingDraft_forOtherLanguage(): void
    {
        $user = $this->createMock(User::class);
        $page = $this->createMock(Page::class);

        // CZ translation with existing draft
        $czTranslation = $this->createMock(PageTranslation::class);
        $czTranslation->method('getId')->willReturn(1);
        $czTranslation->method('getPage')->willReturn($page);
        $czTranslation->method('getLanguage')->willReturn('cs');
        $czTranslation->method('isCustom')->willReturn(false);

        // EN translation (being opened)
        $enTranslation = $this->createMock(PageTranslation::class);
        $enTranslation->method('getId')->willReturn(2);
        $enTranslation->method('getPage')->willReturn($page);
        $enTranslation->method('getLanguage')->willReturn('en');
        $enTranslation->method('isCustom')->willReturn(false);
        $enTranslation->method('getVersion')->willReturn(1);

        // Existing CZ draft
        $czDraft = $this->createMock(PageDraft::class);
        $czDraft->method('getPageTranslation')->willReturn($czTranslation);
        $czDraft->method('getDeletedModuleIds')->willReturn([]);

        // No EN draft yet
        $this->pageDraftRepository
            ->method('findByUserAndPageTranslation')
            ->with($user, $enTranslation)
            ->willReturn(null);

        // CZ draft exists for this page
        $this->pageDraftRepository
            ->method('findByPageAndUser')
            ->with($page, $user)
            ->willReturn([$czDraft]);

        // CZ draft has one module
        $czModule = $this->createMock(ModuleDraft::class);
        $czModule->method('getId')->willReturn(100);
        $czModule->method('getType')->willReturn('text');
        $czModule->method('getSettings')->willReturn(['layout' => 'full']);
        $czModule->method('getSort')->willReturn(1);
        $czModule->method('getOriginalModuleId')->willReturn(10);
        $czModule->method('getParent')->willReturn(null);
        $czModule->method('getStatus')->willReturn(DraftModuleStatus::UNCHANGED);

        $this->moduleDraftRepository
            ->method('findAllSavedByPageDraft')
            ->willReturn([$czModule]);

        // Module translation for EN exists
        $czTranslationDraft = $this->createMock(ModuleTranslationDraft::class);
        $czTranslationDraft->method('getSettings')->willReturn(['text' => 'Hello']);
        $czTranslationDraft->method('getStatus')->willReturn(TranslationStatus::TRANSLATED);

        $this->moduleTranslationDraftRepository
            ->method('findByModuleDraftAndLanguage')
            ->willReturn($czTranslationDraft);

        // Should save at least one draft (EN) - additional saves happen during sync
        $this->pageDraftRepository
            ->expects($this->atLeastOnce())
            ->method('save');

        // Should create one module draft for EN
        $this->moduleDraftRepository
            ->expects($this->atLeastOnce())
            ->method('save');

        // Should create translation draft for EN
        $this->moduleTranslationDraftRepository
            ->expects($this->atLeastOnce())
            ->method('save');

        $result = $this->manager->getOrCreateDraft($user, $enTranslation);

        self::assertInstanceOf(PageDraft::class, $result);
    }

    /**
     * Custom layout pages should NOT sync across languages.
     *
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function getOrCreateDraft_doesNotSync_forCustomLayout(): void
    {
        $user = $this->createMock(User::class);
        $page = $this->createMock(Page::class);

        // Custom layout translation (custom=true)
        $customTranslation = $this->createMock(PageTranslation::class);
        $customTranslation->method('getId')->willReturn(1);
        $customTranslation->method('getPage')->willReturn($page);
        $customTranslation->method('getLanguage')->willReturn('cs');
        $customTranslation->method('isCustom')->willReturn(true);
        $customTranslation->method('getVersion')->willReturn(1);

        // No existing draft
        $this->pageDraftRepository
            ->method('findByUserAndPageTranslation')
            ->willReturn(null);

        // Should NOT query for other language drafts (custom layout doesn't sync)
        $this->pageDraftRepository
            ->expects($this->never())
            ->method('findByPageAndUser');

        // No modules
        $this->moduleRepository
            ->method('findAllActiveByPage')
            ->willReturn([]);

        // Should save only ONE draft
        $this->pageDraftRepository
            ->expects($this->once())
            ->method('save');

        $result = $this->manager->getOrCreateDraft($user, $customTranslation);

        self::assertInstanceOf(PageDraft::class, $result);
    }

    /**
     * When module is deleted in one language draft, it should be deleted in others on sync.
     *
     * @throws \ReflectionException
     */
    #[Test]
    public function mergeStructureIntoDraft_deletesRemovedModules(): void
    {
        $user = $this->createMock(User::class);
        $page = $this->createMock(Page::class);

        // CZ translation
        $czTranslation = $this->createMock(PageTranslation::class);
        $czTranslation->method('getId')->willReturn(1);
        $czTranslation->method('getPage')->willReturn($page);
        $czTranslation->method('getLanguage')->willReturn('cs');
        $czTranslation->method('isCustom')->willReturn(false);
        $czTranslation->method('getVersion')->willReturn(1);

        // EN translation
        $enTranslation = $this->createMock(PageTranslation::class);
        $enTranslation->method('getId')->willReturn(2);
        $enTranslation->method('getPage')->willReturn($page);
        $enTranslation->method('getLanguage')->willReturn('en');
        $enTranslation->method('isCustom')->willReturn(false);

        // CZ draft (source - module was deleted here)
        $czDraft = $this->createMock(PageDraft::class);
        $czDraft->method('getId')->willReturn(1);
        $czDraft->method('getPageTranslation')->willReturn($czTranslation);
        $czDraft->method('getUser')->willReturn($user);
        $czDraft->method('getDeletedModuleIds')->willReturn([10]); // Module 10 was deleted

        // EN draft (target)
        $enDraft = $this->createMock(PageDraft::class);
        $enDraft->method('getId')->willReturn(2);
        $enDraft->method('getPageTranslation')->willReturn($enTranslation);
        $enDraft->method('getUser')->willReturn($user);

        // All translations for this page (needed by new syncStructureToOtherLanguages)
        $this->pageTranslationRepository
            ->method('findAllByPage')
            ->with($page)
            ->willReturn([$czTranslation, $enTranslation]);

        // Both drafts exist
        $this->pageDraftRepository
            ->method('findByPageAndUser')
            ->willReturn([$czDraft, $enDraft]);

        // CZ has no modules (one was deleted)
        // EN still has the module that should be deleted
        $enModule = $this->createMock(ModuleDraft::class);
        $enModule->method('getId')->willReturn(200);
        $enModule->method('getOriginalModuleId')->willReturn(10); // Same as deleted in CZ

        $this->moduleDraftRepository
            ->method('findAllSavedByPageDraft')
            ->willReturnCallback(static function ($draft) use ($czDraft, $enModule) {
                if ($draft === $czDraft) {
                    return []; // CZ has no modules
                }

                return [$enModule]; // EN still has the module
            });

        // Should delete the module from EN
        $this->moduleDraftRepository
            ->expects($this->once())
            ->method('delete')
            ->with($enModule);

        // Simulate saveModules call by directly testing syncStructureToOtherLanguages
        // We need to use reflection to test private method
        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('syncStructureToOtherLanguages');
        $method->invoke($this->manager, $czDraft);
    }

    /**
     * When syncing structure, missing drafts for other languages should be created.
     *
     * @throws \ReflectionException
     */
    #[Test]
    public function syncStructureToOtherLanguages_createsMissingDrafts(): void
    {
        $user = $this->createMock(User::class);
        $page = $this->createMock(Page::class);

        // CZ translation (has draft)
        $czTranslation = $this->createMock(PageTranslation::class);
        $czTranslation->method('getId')->willReturn(1);
        $czTranslation->method('getPage')->willReturn($page);
        $czTranslation->method('getLanguage')->willReturn('cs');
        $czTranslation->method('isCustom')->willReturn(false);
        $czTranslation->method('getVersion')->willReturn(1);

        // EN translation (no draft yet)
        $enTranslation = $this->createMock(PageTranslation::class);
        $enTranslation->method('getId')->willReturn(2);
        $enTranslation->method('getPage')->willReturn($page);
        $enTranslation->method('getLanguage')->willReturn('en');
        $enTranslation->method('isCustom')->willReturn(false);
        $enTranslation->method('getVersion')->willReturn(1);

        // CZ draft (source)
        $czDraft = $this->createMock(PageDraft::class);
        $czDraft->method('getId')->willReturn(1);
        $czDraft->method('getPageTranslation')->willReturn($czTranslation);
        $czDraft->method('getUser')->willReturn($user);
        $czDraft->method('getDeletedModuleIds')->willReturn([]);

        // All translations for this page
        $this->pageTranslationRepository
            ->method('findAllByPage')
            ->with($page)
            ->willReturn([$czTranslation, $enTranslation]);

        // Only CZ draft exists (EN is missing)
        $this->pageDraftRepository
            ->method('findByPageAndUser')
            ->willReturn([$czDraft]);

        // CZ has one module
        $czModule = $this->createMock(ModuleDraft::class);
        $czModule->method('getId')->willReturn(100);
        $czModule->method('getType')->willReturn('text');
        $czModule->method('getSettings')->willReturn(['layout' => 'full']);
        $czModule->method('getSort')->willReturn(1);
        $czModule->method('getOriginalModuleId')->willReturn(10);
        $czModule->method('getParent')->willReturn(null);
        $czModule->method('getStatus')->willReturn(DraftModuleStatus::UNCHANGED);

        $this->moduleDraftRepository
            ->method('findAllSavedByPageDraft')
            ->willReturn([$czModule]);

        $this->moduleTranslationDraftRepository
            ->method('findByModuleDraftAndLanguage')
            ->willReturn(null);

        $this->moduleTranslationDraftRepository
            ->method('findFirstByModuleDraft')
            ->willReturn(null);

        // Should create EN draft (save is called at least once for the new draft)
        $this->pageDraftRepository
            ->expects($this->atLeastOnce())
            ->method('save');

        // Should create module drafts for EN
        $this->moduleDraftRepository
            ->expects($this->atLeastOnce())
            ->method('save');

        // Simulate saveModules call by directly testing syncStructureToOtherLanguages
        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('syncStructureToOtherLanguages');
        $method->invoke($this->manager, $czDraft);
    }

    /**
     * When syncing modules with originalModuleId=null, they should be matched by type and sort.
     * This prevents duplication of newly created modules when syncing between languages.
     *
     * @throws \ReflectionException
     */
    #[Test]
    public function mergeStructureIntoDraft_matchesNewModulesByTypeAndSort(): void
    {
        $user = $this->createMock(User::class);
        $page = $this->createMock(Page::class);

        // CZ translation
        $czTranslation = $this->createMock(PageTranslation::class);
        $czTranslation->method('getId')->willReturn(1);
        $czTranslation->method('getPage')->willReturn($page);
        $czTranslation->method('getLanguage')->willReturn('cs');
        $czTranslation->method('isCustom')->willReturn(false);
        $czTranslation->method('getVersion')->willReturn(1);

        // EN translation
        $enTranslation = $this->createMock(PageTranslation::class);
        $enTranslation->method('getId')->willReturn(2);
        $enTranslation->method('getPage')->willReturn($page);
        $enTranslation->method('getLanguage')->willReturn('en');
        $enTranslation->method('isCustom')->willReturn(false);

        // EN draft (source - user added moduleB here)
        $enDraft = $this->createMock(PageDraft::class);
        $enDraft->method('getId')->willReturn(2);
        $enDraft->method('getPageTranslation')->willReturn($enTranslation);
        $enDraft->method('getUser')->willReturn($user);
        $enDraft->method('getDeletedModuleIds')->willReturn([]);

        // CZ draft (target - already has moduleA synced)
        $czDraft = $this->createMock(PageDraft::class);
        $czDraft->method('getId')->willReturn(1);
        $czDraft->method('getPageTranslation')->willReturn($czTranslation);
        $czDraft->method('getUser')->willReturn($user);

        // Module A - created in CZ, synced to EN (originalModuleId=null)
        $enModuleA = $this->createMock(ModuleDraft::class);
        $enModuleA->method('getId')->willReturn(200);
        $enModuleA->method('getType')->willReturn('text');
        $enModuleA->method('getSettings')->willReturn(['layout' => 'full']);
        $enModuleA->method('getSort')->willReturn(1);
        $enModuleA->method('getOriginalModuleId')->willReturn(null); // Created in draft
        $enModuleA->method('getParent')->willReturn(null);
        $enModuleA->method('getStatus')->willReturn(DraftModuleStatus::CREATED);

        // Module B - created in EN (originalModuleId=null)
        $enModuleB = $this->createMock(ModuleDraft::class);
        $enModuleB->method('getId')->willReturn(201);
        $enModuleB->method('getType')->willReturn('image');
        $enModuleB->method('getSettings')->willReturn(['layout' => 'wide']);
        $enModuleB->method('getSort')->willReturn(2);
        $enModuleB->method('getOriginalModuleId')->willReturn(null); // Created in draft
        $enModuleB->method('getParent')->willReturn(null);
        $enModuleB->method('getStatus')->willReturn(DraftModuleStatus::CREATED);

        // CZ already has moduleA (synced earlier)
        $czModuleA = $this->createMock(ModuleDraft::class);
        $czModuleA->method('getId')->willReturn(100);
        $czModuleA->method('getType')->willReturn('text');
        $czModuleA->method('getSettings')->willReturn(['layout' => 'full']);
        $czModuleA->method('getSort')->willReturn(1);
        $czModuleA->method('getOriginalModuleId')->willReturn(null); // Created in draft
        $czModuleA->method('getParent')->willReturn(null);
        $czModuleA->method('getStatus')->willReturn(DraftModuleStatus::CREATED);

        $this->moduleDraftRepository
            ->method('findAllSavedByPageDraft')
            ->willReturnCallback(static function ($draft) use ($enDraft, $czDraft, $enModuleA, $enModuleB, $czModuleA) {
                if ($draft === $enDraft) {
                    return [$enModuleA, $enModuleB]; // EN has both modules
                }

                if ($draft === $czDraft) {
                    return [$czModuleA]; // CZ only has moduleA
                }

                return [];
            });

        $this->moduleTranslationDraftRepository
            ->method('findFirstByModuleDraft')
            ->willReturn(null);

        // Track newly created modules (not the mocks - real new instances)
        $knownMocks = [$enModuleA, $enModuleB, $czModuleA];
        $newModulesCreated = [];
        $this->moduleDraftRepository
            ->method('save')
            ->willReturnCallback(static function (ModuleDraft $module) use ($knownMocks, &$newModulesCreated): void {
                // If this is not one of our mocks and not already tracked, it's a newly created module
                if (!\in_array($module, $knownMocks, true) && !\in_array($module, $newModulesCreated, true)) {
                    $newModulesCreated[] = $module;
                }
            });

        // Directly call mergeStructureIntoDraft (EN -> CZ)
        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('mergeStructureIntoDraft');
        $method->invoke($this->manager, $enDraft, $czDraft, 'cs');

        // Should only create 1 new module (moduleB for CZ), not 2 (would include duplicate moduleA)
        // With the fallback matching, enModuleA matches czModuleA by type+sort, so no duplicate is created
        self::assertCount(1, $newModulesCreated, 'Should only create moduleB, not duplicate moduleA');
    }
}
