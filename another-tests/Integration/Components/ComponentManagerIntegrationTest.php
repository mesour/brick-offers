<?php declare(strict_types = 1);

namespace Tests\Integration\Components;

use App\Components\ComponentManager;
use App\Components\Database\ComponentModuleRepository;
use App\Components\Database\ComponentRepository;
use App\Components\Database\ComponentTranslationRepository;
use App\Pages\Database\Page;
use App\Pages\Database\PageRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for ComponentManager.
 *
 * @group integration
 * @group database
 */
final class ComponentManagerIntegrationTest extends IntegrationTestCase
{
    private ComponentManager $componentManager;
    private ComponentRepository $componentRepository;
    private ComponentTranslationRepository $translationRepository;
    private ComponentModuleRepository $moduleRepository;
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

        $this->componentManager = $this->getService(ComponentManager::class);
        $this->componentRepository = $this->getService(ComponentRepository::class);
        $this->translationRepository = $this->getService(ComponentTranslationRepository::class);
        $this->moduleRepository = $this->getService(ComponentModuleRepository::class);
        $this->pageRepository = $this->getService(PageRepository::class);
        $this->pageTranslationRepository = $this->getService(PageTranslationRepository::class);
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function createComponent(): void
    {
        $component = $this->componentManager->create(
            'test-hero',
            'hero',
            'cs',
            'Testovací Hero',
        );

        self::assertGreaterThan(0, $component->getId());
        self::assertEquals('test-hero', $component->getName());
        self::assertEquals('hero', $component->getType());
        self::assertEquals(1, $component->getVersion());
        self::assertNull($component->getPublishedVersion());
        self::assertFalse($component->isPublished());

        // Verify translation was created
        $translation = $this->translationRepository->findByComponentAndLanguage(
            $component->getId(),
            'cs',
        );
        self::assertNotNull($translation);
        self::assertEquals('Testovací Hero', $translation->getDisplayName());
    }

    /**
     * @throws \RuntimeException
     * @throws \App\Components\ComponentNotFound
     */
    #[Test]
    public function updateComponent(): void
    {
        $component = $this->componentManager->create(
            'original-name',
            'button',
            'cs',
            'Tlačítko',
        );

        $this->componentManager->update($component, [
            'name' => 'updated-name',
            'type' => 'card',
            'canvasDesktop' => ['width' => 1400, 'height' => 900],
            'canvasTablet' => ['width' => 800, 'height' => 700],
            'canvasMobile' => ['width' => 400, 'height' => 500],
        ]);

        // Reload from database
        $updated = $this->componentRepository->get($component->getId());

        self::assertEquals('updated-name', $updated->getName());
        self::assertEquals('card', $updated->getType());
        self::assertEquals(['width' => 1400, 'height' => 900], $updated->getCanvasDesktop());
        self::assertEquals(['width' => 800, 'height' => 700], $updated->getCanvasTablet());
        self::assertEquals(['width' => 400, 'height' => 500], $updated->getCanvasMobile());
    }

    /**
     * @throws \RuntimeException
     * @throws \App\Components\ComponentNotFound
     */
    #[Test]
    public function deleteComponentWithoutInstances(): void
    {
        $component = $this->componentManager->create(
            'to-delete',
            'hero',
            'cs',
            'K smazání',
        );
        $componentId = $component->getId();

        $this->componentManager->delete($component);

        $this->expectException(\App\Components\ComponentNotFound::class);
        $this->componentRepository->get($componentId);
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function deleteComponentWithInstancesThrowsException(): void
    {
        $component = $this->componentManager->create(
            'component-with-instance',
            'hero',
            'cs',
            'Komponenta s instancí',
        );

        // Publish component first (required for inserting)
        $this->componentManager->publish($component);

        // Create a page to insert component into
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $pageTranslation = new PageTranslation(
            $page,
            'cs',
            '/test-delete',
            \sha1('/test-delete'),
            'Test Page',
        );
        $this->pageTranslationRepository->save($pageTranslation);

        // Insert component into page
        $this->componentManager->insertIntoPage($component, $pageTranslation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete component');
        $this->componentManager->delete($component);
    }

    /**
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function duplicateComponent(): void
    {
        $original = $this->componentManager->create(
            'original-component',
            'pricing',
            'cs',
            'Ceník',
        );

        // Set custom canvas sizes
        $this->componentManager->update($original, [
            'canvasDesktop' => ['width' => 1600, 'height' => 1000],
        ]);

        // Add a module
        $this->componentManager->addModule(
            $original,
            'text',
            ['content' => 'Hello World'],
            0,
            null, // parent
            ['content'],
            ['color'],
        );

        $duplicate = $this->componentManager->duplicate($original, 'copied-component');

        self::assertNotEquals($original->getId(), $duplicate->getId());
        self::assertEquals('copied-component', $duplicate->getName());
        self::assertEquals('pricing', $duplicate->getType());
        self::assertEquals(['width' => 1600, 'height' => 1000], $duplicate->getCanvasDesktop());

        // Verify translation was copied
        $translation = $this->translationRepository->findByComponentAndLanguage(
            $duplicate->getId(),
            'cs',
        );
        self::assertNotNull($translation);
        self::assertStringContainsString('copy', $translation->getDisplayName());

        // Verify modules were copied
        $modules = $this->moduleRepository->findByComponent($duplicate->getId());
        self::assertCount(1, $modules);
        self::assertEquals('text', $modules[0]->getType());
        self::assertEquals(['content' => 'Hello World'], $modules[0]->getSettings());
        self::assertEquals(['content'], $modules[0]->getEditableFields());
        self::assertEquals(['color'], $modules[0]->getLockedFields());
    }

    #[Test]
    public function publishComponent(): void
    {
        $component = $this->componentManager->create(
            'to-publish',
            'hero',
            'cs',
            'K publikaci',
        );

        self::assertEquals(1, $component->getVersion());
        self::assertNull($component->getPublishedVersion());
        self::assertFalse($component->isPublished());

        $this->componentManager->publish($component);

        self::assertEquals(2, $component->getVersion());
        self::assertEquals(2, $component->getPublishedVersion());
        self::assertTrue($component->isPublished());
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function insertComponentIntoPage(): void
    {
        $component = $this->componentManager->create(
            'insertable',
            'card',
            'cs',
            'Vložitelná',
        );

        // Add modules
        $container = $this->componentManager->addModule(
            $component,
            'container',
            ['padding' => '20px'],
        );
        $this->componentManager->addModule(
            $component,
            'text',
            ['content' => 'Card content'],
            0,
            $container, // parent
            ['content'],
            ['color'],
        );

        // Publish
        $this->componentManager->publish($component);

        // Create page
        $page = new Page('Card Page');
        $this->pageRepository->save($page);

        $pageTranslation = new PageTranslation(
            $page,
            'cs',
            '/card-page',
            \sha1('/card-page'),
            'Card Page',
        );
        $this->pageTranslationRepository->save($pageTranslation);

        // Insert component
        $result = $this->componentManager->insertIntoPage($component, $pageTranslation);

        self::assertArrayHasKey('instance', $result);
        self::assertArrayHasKey('modules', $result);
        self::assertCount(2, $result['modules']);

        $instance = $result['instance'];
        self::assertEquals($component->getId(), $instance->getComponent()->getId());
        self::assertEquals($pageTranslation->getId(), $instance->getPageTranslation()->getId());
        self::assertEquals($component->getPublishedVersion(), $instance->getVersionSnapshot());
        self::assertFalse($instance->isUpdateAvailable());
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function cannotInsertUnpublishedComponent(): void
    {
        $component = $this->componentManager->create(
            'unpublished',
            'hero',
            'cs',
            'Nepublikovaná',
        );

        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        $pageTranslation = new PageTranslation(
            $page,
            'cs',
            '/test-unpublished',
            \sha1('/test-unpublished'),
            'Test Page',
        );
        $this->pageTranslationRepository->save($pageTranslation);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot insert unpublished component');
        $this->componentManager->insertIntoPage($component, $pageTranslation);
    }

    #[Test]
    public function addModuleToComponent(): void
    {
        $component = $this->componentManager->create(
            'with-modules',
            'layout',
            'cs',
            'S moduly',
        );

        $module = $this->componentManager->addModule(
            $component,
            'row',
            ['gap' => '16px'],
            5,
            null, // parent
            ['content', 'url'],
            ['backgroundColor', 'color'],
        );

        self::assertGreaterThan(0, $module->getId());
        self::assertEquals('row', $module->getType());
        self::assertEquals(['gap' => '16px'], $module->getSettings());
        self::assertEquals(5, $module->getSort());
        self::assertNull($module->getParentId());
        self::assertEquals(['content', 'url'], $module->getEditableFields());
        self::assertEquals(['backgroundColor', 'color'], $module->getLockedFields());
    }

    #[Test]
    public function addNestedModules(): void
    {
        $component = $this->componentManager->create(
            'nested',
            'card',
            'cs',
            'Vnořený',
        );

        $container = $this->componentManager->addModule($component, 'container');
        $row = $this->componentManager->addModule($component, 'row', [], 0, $container);
        $text = $this->componentManager->addModule($component, 'text', [], 0, $row);

        self::assertNull($container->getParentId());
        self::assertEquals($container->getId(), $row->getParentId());
        self::assertEquals($row->getId(), $text->getParentId());
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function updateModule(): void
    {
        $component = $this->componentManager->create(
            'module-update',
            'hero',
            'cs',
            'Aktualizace modulu',
        );

        $module = $this->componentManager->addModule(
            $component,
            'text',
            ['content' => 'Original'],
            0,
            null, // parent
            ['content'],
            [],
        );

        $this->componentManager->updateModule(
            $module,
            ['content' => 'Updated'],
            10,
            ['content', 'url'],
            ['color'],
        );

        // Reload from database
        $updated = $this->moduleRepository->get($module->getId());

        self::assertEquals(['content' => 'Updated'], $updated->getSettings());
        self::assertEquals(10, $updated->getSort());
        self::assertEquals(['content', 'url'], $updated->getEditableFields());
        self::assertEquals(['color'], $updated->getLockedFields());
    }

    /**
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function deleteModule(): void
    {
        $component = $this->componentManager->create(
            'module-delete',
            'hero',
            'cs',
            'Smazání modulu',
        );

        $module = $this->componentManager->addModule($component, 'text');

        $this->componentManager->deleteModule($module);

        $modules = $this->moduleRepository->findByComponent($component->getId());
        self::assertCount(0, $modules);
    }

    /**
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function getComponentForEditor(): void
    {
        $component = $this->componentManager->create(
            'editor-test',
            'hero',
            'cs',
            'Editor Test',
        );

        $this->componentManager->addModule($component, 'container');
        $this->componentManager->addModule($component, 'text');

        $data = $this->componentManager->getComponentForEditor($component, 'cs');

        self::assertArrayHasKey('component', $data);
        self::assertArrayHasKey('translation', $data);
        self::assertArrayHasKey('modules', $data);

        /** @var array{name: string} $componentData */
        $componentData = $data['component'];
        /** @var array{displayName: string} $translationData */
        $translationData = $data['translation'];
        /** @var array<mixed> $modulesData */
        $modulesData = $data['modules'];

        self::assertEquals('editor-test', $componentData['name']);
        self::assertEquals('Editor Test', $translationData['displayName']);
        self::assertCount(2, $modulesData);
    }

    /**
     * @throws \RuntimeException
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     * @throws \UnexpectedValueException
     */
    #[Test]
    public function getAllForList(): void
    {
        // Create multiple components
        $this->componentManager->create('list-1', 'hero', 'cs', 'List 1');
        $this->componentManager->create('list-2', 'card', 'cs', 'List 2');
        $this->componentManager->create('list-3', 'button', 'cs', 'List 3');

        $list = $this->componentManager->getAllForList();

        self::assertGreaterThanOrEqual(3, \count($list));

        // Each item should have instanceCount
        foreach ($list as $item) {
            self::assertArrayHasKey('instanceCount', $item);
            self::assertArrayHasKey('name', $item);
            self::assertArrayHasKey('type', $item);
        }
    }

    #[Test]
    public function componentHasCorrectDefaultCanvasSizes(): void
    {
        $component = $this->componentManager->create(
            'default-canvas',
            'hero',
            'cs',
            'Výchozí canvas',
        );

        self::assertEquals(['width' => 1200, 'height' => 800], $component->getCanvasDesktop());
        self::assertEquals(['width' => 768, 'height' => 600], $component->getCanvasTablet());
        self::assertEquals(['width' => 375, 'height' => 400], $component->getCanvasMobile());
    }

    #[Test]
    public function componentTimestampsAreSet(): void
    {
        $before = new \DateTimeImmutable();
        $component = $this->componentManager->create(
            'timestamps',
            'hero',
            'cs',
            'Časové značky',
        );
        $after = new \DateTimeImmutable();

        self::assertInstanceOf(\DateTimeImmutable::class, $component->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $component->getUpdatedAt());
        self::assertGreaterThanOrEqual($before, $component->getCreatedAt());
        self::assertLessThanOrEqual($after, $component->getCreatedAt());
    }
}
