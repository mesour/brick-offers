<?php declare(strict_types = 1);

namespace Tests\Unit\Pages;

use App\CmsModules\CmsModuleProviderRegistry;
use App\CmsModules\Database\Module;
use App\CmsModules\Database\ModuleDraft;
use App\CmsModules\Database\ModuleDraftRepository;
use App\CmsModules\Database\ModuleRepository;
use App\CmsModules\Database\ModuleTranslationDraftRepository;
use App\CmsModules\Database\ModuleTranslationRepository;
use App\CmsModules\DraftModuleStatus;
use App\CmsModules\ModuleManager;
use App\CmsModules\Settings\CmsModuleSettingsResolver;
use App\Pages\Database\Page;
use App\Pages\Database\PageDraft;
use App\Pages\Database\PageDraftRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use App\Pages\PageDraftConflictException;
use App\Pages\PageDraftManager;
use App\Users\Database\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PageDraftManagerTest extends TestCase
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

    #[Test]
    public function hasDraft_returnsTrueWhenDraftExists(): void
    {
        $user = $this->createMock(User::class);
        $pageTranslation = $this->createMock(PageTranslation::class);

        $this->pageDraftRepository
            ->expects($this->once())
            ->method('exists')
            ->with($user, $pageTranslation)
            ->willReturn(true);

        $result = $this->manager->hasDraft($user, $pageTranslation);

        self::assertTrue($result);
    }

    #[Test]
    public function hasDraft_returnsFalseWhenNoDraft(): void
    {
        $user = $this->createMock(User::class);
        $pageTranslation = $this->createMock(PageTranslation::class);

        $this->pageDraftRepository
            ->expects($this->once())
            ->method('exists')
            ->with($user, $pageTranslation)
            ->willReturn(false);

        $result = $this->manager->hasDraft($user, $pageTranslation);

        self::assertFalse($result);
    }

    /**
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function getDraft_returnsDraftWhenExists(): void
    {
        $user = $this->createMock(User::class);
        $pageTranslation = $this->createMock(PageTranslation::class);
        $draft = $this->createMock(PageDraft::class);

        $this->pageDraftRepository
            ->expects($this->once())
            ->method('findByUserAndPageTranslation')
            ->with($user, $pageTranslation)
            ->willReturn($draft);

        $result = $this->manager->getDraft($user, $pageTranslation);

        self::assertSame($draft, $result);
    }

    /**
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function getDraft_returnsNullWhenNoDraft(): void
    {
        $user = $this->createMock(User::class);
        $pageTranslation = $this->createMock(PageTranslation::class);

        $this->pageDraftRepository
            ->expects($this->once())
            ->method('findByUserAndPageTranslation')
            ->with($user, $pageTranslation)
            ->willReturn(null);

        $result = $this->manager->getDraft($user, $pageTranslation);

        self::assertNull($result);
    }

    /**
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function getOrCreateDraft_returnsExistingDraft(): void
    {
        $user = $this->createMock(User::class);
        $pageTranslation = $this->createMock(PageTranslation::class);
        $existingDraft = $this->createMock(PageDraft::class);

        $this->pageDraftRepository
            ->expects($this->once())
            ->method('findByUserAndPageTranslation')
            ->with($user, $pageTranslation)
            ->willReturn($existingDraft);

        // Should not create a new draft
        $this->pageDraftRepository
            ->expects($this->never())
            ->method('save');

        $result = $this->manager->getOrCreateDraft($user, $pageTranslation);

        self::assertSame($existingDraft, $result);
    }

    #[Test]
    public function hasConflict_delegatesToDraft(): void
    {
        $draft = $this->createMock(PageDraft::class);
        $draft->expects($this->once())
            ->method('hasConflict')
            ->willReturn(true);

        $result = $this->manager->hasConflict($draft);

        self::assertTrue($result);
    }

    /**
     * @throws PageDraftConflictException
     */
    #[Test]
    public function publishDraft_throwsExceptionOnConflict(): void
    {
        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getVersion')->willReturn(5);

        $draft = $this->createMock(PageDraft::class);
        $draft->method('hasConflict')->willReturn(true);
        $draft->method('getPageTranslation')->willReturn($pageTranslation);

        $this->expectException(PageDraftConflictException::class);

        $this->manager->publishDraft($draft, false);
    }

    /**
     * @throws PageDraftConflictException
     */
    #[Test]
    public function publishDraft_succeedsWithForceOnConflict(): void
    {
        $page = $this->createMock(Page::class);

        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getVersion')->willReturn(5);
        $pageTranslation->method('getPage')->willReturn($page);
        $pageTranslation->method('isCustom')->willReturn(false);

        $draft = $this->createMock(PageDraft::class);
        $draft->method('hasConflict')->willReturn(true);
        $draft->method('getPageTranslation')->willReturn($pageTranslation);

        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('commit');
        $this->entityManager->expects($this->never())->method('rollback');

        $this->moduleDraftRepository
            ->method('findAllByPageDraft')
            ->willReturn([]);

        $this->moduleRepository
            ->method('findAllActiveByPage')
            ->willReturn([]);

        $this->pageDraftRepository
            ->expects($this->once())
            ->method('delete')
            ->with($draft);

        // Should not throw with force=true
        $this->manager->publishDraft($draft, true);
    }

    #[Test]
    public function discardDraft_deletesDraft(): void
    {
        $draft = $this->createMock(PageDraft::class);

        $this->pageDraftRepository
            ->expects($this->once())
            ->method('delete')
            ->with($draft);

        $this->manager->discardDraft($draft);
    }

    /**
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function rebaseDraft_updatesBaseVersion(): void
    {
        $page = $this->createMock(Page::class);

        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getVersion')->willReturn(5);
        $pageTranslation->method('getPage')->willReturn($page);
        $pageTranslation->method('isCustom')->willReturn(false);

        $draft = $this->createMock(PageDraft::class);
        $draft->method('getPageTranslation')->willReturn($pageTranslation);
        $draft->expects($this->once())
            ->method('setBaseVersion')
            ->with(5);
        $draft->expects($this->once())
            ->method('touch');

        $this->moduleDraftRepository
            ->method('findAllByPageDraft')
            ->willReturn([]);

        $this->moduleRepository
            ->method('findAllActiveByPage')
            ->willReturn([]);

        $this->pageDraftRepository
            ->expects($this->once())
            ->method('save')
            ->with($draft);

        $result = $this->manager->rebaseDraft($draft);

        self::assertArrayHasKey('modulesUpdated', $result);
        self::assertArrayHasKey('modulesDeleted', $result);
        self::assertArrayHasKey('modulesAdded', $result);
    }

    /**
     * @throws PageDraftConflictException
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function rebaseDraft_updatesUnchangedModules(): void
    {
        $page = $this->createMock(Page::class);

        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getVersion')->willReturn(5);
        $pageTranslation->method('getPage')->willReturn($page);
        $pageTranslation->method('isCustom')->willReturn(false);

        $draft = $this->createMock(PageDraft::class);
        $draft->method('getPageTranslation')->willReturn($pageTranslation);

        // Master module
        $masterModule = $this->createMock(Module::class);
        $masterModule->method('getId')->willReturn(100);
        $masterModule->method('getSettings')->willReturn(['text' => 'Updated']);
        $masterModule->method('getSort')->willReturn(2);

        // Draft module (UNCHANGED, should be updated)
        $moduleDraft = $this->createMock(ModuleDraft::class);
        $moduleDraft->method('getOriginalModuleId')->willReturn(100);
        $moduleDraft->method('getStatus')->willReturn(DraftModuleStatus::UNCHANGED);
        $moduleDraft->expects($this->once())
            ->method('setSettingsArray')
            ->with(['text' => 'Updated']);
        $moduleDraft->expects($this->once())
            ->method('setSort')
            ->with(2);

        $this->moduleDraftRepository
            ->method('findAllByPageDraft')
            ->willReturn([$moduleDraft]);

        $this->moduleRepository
            ->method('findAllActiveByPage')
            ->willReturn([$masterModule]);

        $result = $this->manager->rebaseDraft($draft);

        self::assertEquals(1, $result['modulesUpdated']);
    }

    /**
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function rebaseDraft_deletesUnchangedModulesNotInMaster(): void
    {
        $page = $this->createMock(Page::class);

        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getVersion')->willReturn(5);
        $pageTranslation->method('getPage')->willReturn($page);
        $pageTranslation->method('isCustom')->willReturn(false);

        $draft = $this->createMock(PageDraft::class);
        $draft->method('getPageTranslation')->willReturn($pageTranslation);

        // Draft module that was deleted in master (UNCHANGED)
        $moduleDraft = $this->createMock(ModuleDraft::class);
        $moduleDraft->method('getOriginalModuleId')->willReturn(100);
        $moduleDraft->method('getStatus')->willReturn(DraftModuleStatus::UNCHANGED);

        $this->moduleDraftRepository
            ->method('findAllByPageDraft')
            ->willReturn([$moduleDraft]);

        $this->moduleRepository
            ->method('findAllActiveByPage')
            ->willReturn([]); // No modules in master

        $this->moduleDraftRepository
            ->expects($this->once())
            ->method('delete')
            ->with($moduleDraft);

        $result = $this->manager->rebaseDraft($draft);

        self::assertEquals(1, $result['modulesDeleted']);
    }

    /**
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function rebaseDraft_marksModifiedAsCreatedWhenDeletedInMaster(): void
    {
        $page = $this->createMock(Page::class);

        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getVersion')->willReturn(5);
        $pageTranslation->method('getPage')->willReturn($page);
        $pageTranslation->method('isCustom')->willReturn(false);

        $draft = $this->createMock(PageDraft::class);
        $draft->method('getPageTranslation')->willReturn($pageTranslation);

        // Draft module that was deleted in master (MODIFIED)
        $moduleDraft = $this->createMock(ModuleDraft::class);
        $moduleDraft->method('getOriginalModuleId')->willReturn(100);
        $moduleDraft->method('getStatus')->willReturn(DraftModuleStatus::MODIFIED);
        $moduleDraft->expects($this->once())
            ->method('setStatus')
            ->with(DraftModuleStatus::CREATED);

        $this->moduleDraftRepository
            ->method('findAllByPageDraft')
            ->willReturn([$moduleDraft]);

        $this->moduleRepository
            ->method('findAllActiveByPage')
            ->willReturn([]); // No modules in master

        $this->manager->rebaseDraft($draft);
    }
}
