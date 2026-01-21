<?php declare(strict_types = 1);

namespace Tests\Unit\Pages;

use App\CmsModules\CmsModule;
use App\CmsModules\ModuleManager;
use App\Pages\Database\Page;
use App\Pages\Database\PageRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use App\Pages\PageContainer;
use App\Pages\PageManager;
use App\Pages\PageNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PageManagerTest extends TestCase
{
    private PageTranslationRepository&MockObject $pageTranslationRepository;
    private PageRepository&MockObject $pageRepository;
    private ModuleManager&MockObject $moduleManager;
    private PageManager $manager;

    protected function setUp(): void
    {
        $this->pageTranslationRepository = $this->createMock(PageTranslationRepository::class);
        $this->pageRepository = $this->createMock(PageRepository::class);
        $this->moduleManager = $this->createMock(ModuleManager::class);

        $this->manager = new PageManager(
            $this->pageTranslationRepository,
            $this->pageRepository,
            $this->moduleManager,
        );
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function createPageContainer_findsTranslationBySlug(): void
    {
        $page = $this->createMock(Page::class);
        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getLanguage')->willReturn('cs');
        $pageTranslation->method('getPage')->willReturn($page);

        $module = $this->createMock(CmsModule::class);

        // Slug "/about" should be hashed with sha1(strtolower("/about"))
        $expectedHash = \sha1('/about');

        $this->pageTranslationRepository
            ->expects($this->once())
            ->method('findOneActiveBySlug')
            ->with($expectedHash)
            ->willReturn($pageTranslation);

        $this->moduleManager
            ->expects($this->once())
            ->method('createModuleTree')
            ->with($pageTranslation)
            ->willReturn([$module]);

        $result = $this->manager->createPageContainer('/about');

        self::assertInstanceOf(PageContainer::class, $result);
        self::assertSame($pageTranslation, $result->getPageTranslation());
        self::assertCount(1, $result->getModules());
        self::assertEquals('cs', $result->getLanguage());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function createPageContainer_throwsExceptionWhenSlugNotFound(): void
    {
        $this->pageTranslationRepository
            ->expects($this->once())
            ->method('findOneActiveBySlug')
            ->willReturn(null);

        $this->expectException(PageNotFoundException::class);
        $this->expectExceptionMessage('Page translation not found for slug "/non-existent"');

        $this->manager->createPageContainer('/non-existent');
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function createPageContainer_returnsHomepageWhenLanguageProvidedButNoTranslation(): void
    {
        $page = $this->createMock(Page::class);
        $page->method('getId')->willReturn(1);

        $this->pageTranslationRepository
            ->expects($this->once())
            ->method('findOneActiveBySlug')
            ->willReturn(null);

        $this->pageRepository
            ->expects($this->once())
            ->method('getHomepageId')
            ->willReturn(1);

        $this->pageRepository
            ->expects($this->once())
            ->method('get')
            ->with(1)
            ->willReturn($page);

        // Module manager should not be called when no translation found
        $this->moduleManager
            ->expects($this->never())
            ->method('createModuleTree');

        $result = $this->manager->createPageContainer('/non-existent', 'en');

        self::assertInstanceOf(PageContainer::class, $result);
        self::assertNull($result->getPageTranslation());
        self::assertEmpty($result->getModules());
        self::assertEquals('en', $result->getLanguage());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function createPageContainer_returnsHomepageWhenLanguageDoesNotMatch(): void
    {
        $page = $this->createMock(Page::class);
        $page->method('getId')->willReturn(1);

        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getLanguage')->willReturn('cs');

        $this->pageTranslationRepository
            ->expects($this->once())
            ->method('findOneActiveBySlug')
            ->willReturn($pageTranslation);

        $this->pageRepository
            ->expects($this->once())
            ->method('getHomepageId')
            ->willReturn(1);

        $this->pageRepository
            ->expects($this->once())
            ->method('get')
            ->with(1)
            ->willReturn($page);

        // Request English version but translation is Czech
        $result = $this->manager->createPageContainer('/about', 'en');

        self::assertInstanceOf(PageContainer::class, $result);
        self::assertNull($result->getPageTranslation());
        self::assertEmpty($result->getModules());
        self::assertEquals('en', $result->getLanguage());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function createPageContainer_returnsTranslationWhenLanguageMatches(): void
    {
        $page = $this->createMock(Page::class);
        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getLanguage')->willReturn('en');
        $pageTranslation->method('getPage')->willReturn($page);

        $module = $this->createMock(CmsModule::class);

        $this->pageTranslationRepository
            ->expects($this->once())
            ->method('findOneActiveBySlug')
            ->willReturn($pageTranslation);

        $this->moduleManager
            ->expects($this->once())
            ->method('createModuleTree')
            ->with($pageTranslation)
            ->willReturn([$module]);

        // Should not call pageRepository when translation is found and language matches
        $this->pageRepository
            ->expects($this->never())
            ->method('getHomepageId');

        $result = $this->manager->createPageContainer('/about', 'en');

        self::assertInstanceOf(PageContainer::class, $result);
        self::assertSame($pageTranslation, $result->getPageTranslation());
        self::assertCount(1, $result->getModules());
        self::assertEquals('en', $result->getLanguage());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function createPageContainer_normalizesSlugToLowercase(): void
    {
        $page = $this->createMock(Page::class);
        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getLanguage')->willReturn('cs');
        $pageTranslation->method('getPage')->willReturn($page);

        // Both "/About" and "/about" should produce the same hash
        $expectedHash = \sha1('/about');

        $this->pageTranslationRepository
            ->expects($this->once())
            ->method('findOneActiveBySlug')
            ->with($expectedHash)
            ->willReturn($pageTranslation);

        $this->moduleManager
            ->method('createModuleTree')
            ->willReturn([]);

        $result = $this->manager->createPageContainer('/About');

        self::assertInstanceOf(PageContainer::class, $result);
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function createPageContainer_handlesRootSlug(): void
    {
        $page = $this->createMock(Page::class);
        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getLanguage')->willReturn('cs');
        $pageTranslation->method('getPage')->willReturn($page);

        // Root slug "/" should be hashed as sha1("/")
        $expectedHash = \sha1('/');

        $this->pageTranslationRepository
            ->expects($this->once())
            ->method('findOneActiveBySlug')
            ->with($expectedHash)
            ->willReturn($pageTranslation);

        $this->moduleManager
            ->method('createModuleTree')
            ->willReturn([]);

        $result = $this->manager->createPageContainer('/');

        self::assertInstanceOf(PageContainer::class, $result);
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function createPageContainer_returnsEmptyModulesArrayWhenNoModules(): void
    {
        $page = $this->createMock(Page::class);
        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getLanguage')->willReturn('cs');
        $pageTranslation->method('getPage')->willReturn($page);

        $this->pageTranslationRepository
            ->method('findOneActiveBySlug')
            ->willReturn($pageTranslation);

        $this->moduleManager
            ->expects($this->once())
            ->method('createModuleTree')
            ->with($pageTranslation)
            ->willReturn([]);

        $result = $this->manager->createPageContainer('/empty-page');

        self::assertInstanceOf(PageContainer::class, $result);
        self::assertEmpty($result->getModules());
    }
}
