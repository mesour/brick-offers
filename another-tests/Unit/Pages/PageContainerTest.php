<?php declare(strict_types = 1);

namespace Tests\Unit\Pages;

use App\CmsModules\CmsModule;
use App\Pages\Database\Page;
use App\Pages\Database\PageTranslation;
use App\Pages\PageContainer;
use App\Pages\PageNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageContainerTest extends TestCase
{
    #[Test]
    public function getPageTranslation_returnsTranslation(): void
    {
        $pageTranslation = $this->createMock(PageTranslation::class);

        $container = new PageContainer($pageTranslation, null, [], null);

        self::assertSame($pageTranslation, $container->getPageTranslation());
    }

    #[Test]
    public function getPageTranslation_returnsNullWhenNoTranslation(): void
    {
        $page = $this->createMock(Page::class);

        $container = new PageContainer(null, $page, [], 'en');

        self::assertNull($container->getPageTranslation());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function getPageId_returnsPageIdFromPage(): void
    {
        $page = $this->createMock(Page::class);
        $page->method('getId')->willReturn(42);

        $container = new PageContainer(null, $page, [], 'en');

        self::assertEquals(42, $container->getPageId());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function getPageId_returnsPageIdFromTranslation(): void
    {
        $page = $this->createMock(Page::class);
        $page->method('getId')->willReturn(99);

        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getPage')->willReturn($page);

        $container = new PageContainer($pageTranslation, null, [], null);

        self::assertEquals(99, $container->getPageId());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function getPageId_prefersPageOverTranslation(): void
    {
        $page = $this->createMock(Page::class);
        $page->method('getId')->willReturn(1);

        $translationPage = $this->createMock(Page::class);
        $translationPage->method('getId')->willReturn(2);

        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getPage')->willReturn($translationPage);

        $container = new PageContainer($pageTranslation, $page, [], null);

        // Should prefer direct page over translation's page
        self::assertEquals(1, $container->getPageId());
    }

    /**
     * @throws PageNotFoundException
     */
    #[Test]
    public function getPageId_throwsExceptionWhenNoPageAvailable(): void
    {
        $container = new PageContainer(null, null, [], 'en');

        $this->expectException(PageNotFoundException::class);
        $this->expectExceptionMessage('Homepage not exist.');

        $container->getPageId();
    }

    #[Test]
    public function getModules_returnsModulesArray(): void
    {
        $module1 = $this->createMock(CmsModule::class);
        $module2 = $this->createMock(CmsModule::class);

        $container = new PageContainer(null, null, [$module1, $module2], 'en');

        $modules = $container->getModules();

        self::assertCount(2, $modules);
        self::assertSame($module1, $modules[0]);
        self::assertSame($module2, $modules[1]);
    }

    #[Test]
    public function getModules_returnsEmptyArrayWhenNoModules(): void
    {
        $container = new PageContainer(null, null, [], 'en');

        self::assertEmpty($container->getModules());
    }

    #[Test]
    public function getLanguage_returnsLanguageFromTranslation(): void
    {
        $pageTranslation = $this->createMock(PageTranslation::class);
        $pageTranslation->method('getLanguage')->willReturn('de');

        $container = new PageContainer($pageTranslation, null, [], 'en');

        // Should prefer translation's language over provided language
        self::assertEquals('de', $container->getLanguage());
    }

    #[Test]
    public function getLanguage_returnsProvidedLanguageWhenNoTranslation(): void
    {
        $page = $this->createMock(Page::class);

        $container = new PageContainer(null, $page, [], 'fr');

        self::assertEquals('fr', $container->getLanguage());
    }

    #[Test]
    public function getLanguage_returnsDefaultWhenNothingProvided(): void
    {
        $container = new PageContainer(null, null, [], null);

        // Default fallback is 'cz'
        self::assertEquals('cz', $container->getLanguage());
    }

    #[Test]
    public function getModulesArray_returnsSerializedModules(): void
    {
        $module1 = $this->createMock(CmsModule::class);
        $module1->method('toArray')->willReturn(['type' => 'Text', 'id' => 1]);

        $module2 = $this->createMock(CmsModule::class);
        $module2->method('toArray')->willReturn(['type' => 'Container', 'id' => 2]);

        $container = new PageContainer(null, null, [$module1, $module2], 'en');

        $result = $container->getModulesArray();

        self::assertCount(2, $result);
        self::assertEquals(['type' => 'Text', 'id' => 1], $result[0]);
        self::assertEquals(['type' => 'Container', 'id' => 2], $result[1]);
    }

    #[Test]
    public function getModulesArray_returnsEmptyArrayWhenNoModules(): void
    {
        $container = new PageContainer(null, null, [], 'en');

        self::assertEquals([], $container->getModulesArray());
    }
}
