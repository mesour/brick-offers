<?php declare(strict_types = 1);

namespace Tests\Integration\Pages;

use App\CmsModules\Database\Module;
use App\CmsModules\Database\ModuleRepository;
use App\Pages\Database\Page;
use App\Pages\Database\PageRepository;
use App\Pages\Database\PageTranslation;
use App\Pages\Database\PageTranslationRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\ApiIntegrationTestCase;

/**
 * Integration tests for Page Layout Mode API endpoints.
 *
 * The layout mode determines whether a page translation uses:
 * - inherited: modules from the parent Page entity
 * - custom: its own modules stored in PageTranslation
 *
 * @group integration
 * @group database
 */
final class PageLayoutModeApiTest extends ApiIntegrationTestCase
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

    private function createTestPage(): Page
    {
        $page = new Page('Test Page');
        $this->pageRepository->save($page);

        return $page;
    }

    private function createTestPageWithTranslation(bool $isCustom = false): PageTranslation
    {
        $page = $this->createTestPage();

        $translation = new PageTranslation(
            $page,
            'cs',
            '/test-layout-mode',
            \sha1('/test-layout-mode'),
            'Test Page',
        );
        $translation->setCustom($isCustom);
        $this->pageTranslationRepository->save($translation);

        return $translation;
    }

    // =========================================================================
    // GET /api/page-layout-mode-info
    // =========================================================================

    #[Test]
    public function getLayoutModeInfoForInheritedMode(): void
    {
        $translation = $this->createTestPageWithTranslation(false);

        $result = $this->apiGet('pageLayoutModeInfo', [
            'pageTranslationId' => (string) $translation->getId(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertFalse($data['isCustom']);
        self::assertArrayHasKey('inheritedModuleCount', $data);
        self::assertArrayHasKey('customModuleCount', $data);
    }

    #[Test]
    public function getLayoutModeInfoForCustomMode(): void
    {
        $translation = $this->createTestPageWithTranslation(true);

        $result = $this->apiGet('pageLayoutModeInfo', [
            'pageTranslationId' => (string) $translation->getId(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['isCustom']);
    }

    #[Test]
    public function getLayoutModeInfoWithModules(): void
    {
        $page = $this->createTestPage();

        // Create modules on Page level (inherited)
        $pageModule = Module::create('text', $page, null);
        $pageModule->setSettingsArray(['content' => '<p>Page module</p>']);
        $this->moduleRepository->save($pageModule);

        // Create translation in custom mode
        $translation = new PageTranslation(
            $page,
            'cs',
            '/test-with-modules',
            \sha1('/test-with-modules'),
            'Test Page',
        );
        $translation->setCustom(true);
        $this->pageTranslationRepository->save($translation);

        // Create modules on PageTranslation level (custom)
        $customModule = Module::create('text', null, $translation);
        $customModule->setSettingsArray(['content' => '<p>Custom module</p>']);
        $this->moduleRepository->save($customModule);

        $result = $this->apiGet('pageLayoutModeInfo', [
            'pageTranslationId' => (string) $translation->getId(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['isCustom']);
        self::assertEquals(1, $data['inheritedModuleCount']);
        self::assertEquals(1, $data['customModuleCount']);
    }

    #[Test]
    public function getLayoutModeInfoReturns404ForNonExistent(): void
    {
        $result = $this->apiGet('pageLayoutModeInfo', [
            'pageTranslationId' => '999999',
        ]);

        $this->assertApiStatusCode(404, $result);
    }

    // =========================================================================
    // POST /api/page-layout-mode-switch
    // =========================================================================

    #[Test]
    public function switchToCustomMode(): void
    {
        $translation = $this->createTestPageWithTranslation(false);
        $version = $translation->getVersion();

        $result = $this->apiPost('pageLayoutModeSwitch', [
            'pageTranslationId' => (string) $translation->getId(),
            'mode' => 'custom',
            'version' => (string) $version,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertEquals('custom', $data['mode']);
        self::assertArrayHasKey('newVersion', $data);
    }

    #[Test]
    public function switchToInheritedMode(): void
    {
        $translation = $this->createTestPageWithTranslation(true);
        $version = $translation->getVersion();

        $result = $this->apiPost('pageLayoutModeSwitch', [
            'pageTranslationId' => (string) $translation->getId(),
            'mode' => 'inherited',
            'version' => (string) $version,
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertEquals('inherited', $data['mode']);
    }

    #[Test]
    public function switchToCustomModeWithCopyModules(): void
    {
        $page = $this->createTestPage();

        // Create modules on Page level
        $pageModule1 = Module::create('container', $page, null);
        $this->moduleRepository->save($pageModule1);

        $pageModule2 = Module::create('text', $page, null);
        $pageModule2->setParent($pageModule1);
        $this->moduleRepository->save($pageModule2);

        // Create translation in inherited mode
        $translation = new PageTranslation(
            $page,
            'cs',
            '/test-copy-modules',
            \sha1('/test-copy-modules'),
            'Test Page',
        );
        $this->pageTranslationRepository->save($translation);

        $result = $this->apiPost('pageLayoutModeSwitch', [
            'pageTranslationId' => (string) $translation->getId(),
            'mode' => 'custom',
            'copyModules' => '1',
            'version' => (string) $translation->getVersion(),
        ]);

        $this->assertApiSuccess($result);
        $data = $result->getJsonData();
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertEquals('custom', $data['mode']);
        self::assertEquals(2, $data['moduleCount']); // 2 modules copied
    }

    /**
     * @throws \App\Pages\PageTranslationNotFound
     * @throws \RuntimeException
     */
    #[Test]
    public function switchToInheritedDeletesCustomModules(): void
    {
        $translation = $this->createTestPageWithTranslation(true);

        // Create custom modules
        $customModule = Module::create('text', null, $translation);
        $customModule->setSettingsArray(['content' => '<p>Will be deleted</p>']);
        $this->moduleRepository->save($customModule);

        $result = $this->apiPost('pageLayoutModeSwitch', [
            'pageTranslationId' => (string) $translation->getId(),
            'mode' => 'inherited',
            'version' => (string) $translation->getVersion(),
        ]);

        $this->assertApiSuccess($result);

        // Verify modules were deleted
        $this->getEntityManager()->clear();
        $remainingModules = $this->moduleRepository->findAllActiveByPage(
            $this->pageTranslationRepository->get($translation->getId()),
        );
        self::assertCount(0, $remainingModules);
    }

    #[Test]
    public function switchModeVersionConflict(): void
    {
        $translation = $this->createTestPageWithTranslation(false);
        $initialVersion = $translation->getVersion();

        // First switch should succeed
        $result1 = $this->apiPost('pageLayoutModeSwitch', [
            'pageTranslationId' => (string) $translation->getId(),
            'mode' => 'custom',
            'version' => (string) $initialVersion,
        ]);
        $this->assertApiSuccess($result1);

        // Second switch with old version should fail
        $result2 = $this->apiPost('pageLayoutModeSwitch', [
            'pageTranslationId' => (string) $translation->getId(),
            'mode' => 'inherited',
            'version' => (string) $initialVersion,
        ]);

        $this->assertApiStatusCode(409, $result2);
        $this->assertApiError('VERSION_CONFLICT', $result2);
    }

    #[Test]
    public function switchModeRejectsInvalidMode(): void
    {
        $translation = $this->createTestPageWithTranslation(false);

        $result = $this->apiPost('pageLayoutModeSwitch', [
            'pageTranslationId' => (string) $translation->getId(),
            'mode' => 'invalid_mode',
            'version' => (string) $translation->getVersion(),
        ]);

        $this->assertApiStatusCode(400, $result);
        $this->assertApiError('INVALID_MODE', $result);
    }

    #[Test]
    public function switchModeReturns404ForNonExistent(): void
    {
        $result = $this->apiPost('pageLayoutModeSwitch', [
            'pageTranslationId' => '999999',
            'mode' => 'custom',
            'version' => '1',
        ]);

        $this->assertApiStatusCode(404, $result);
    }
}
