<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Lead;
use App\Message\TakeScreenshotMessage;
use App\MessageHandler\TakeScreenshotMessageHandler;
use App\Repository\LeadRepository;
use App\Service\Screenshot\ScreenshotService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for TakeScreenshotMessageHandler.
 */
final class TakeScreenshotMessageHandlerTest extends MessageHandlerTestCase
{
    private string $testStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testStoragePath = sys_get_temp_dir() . '/screenshot-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (is_dir($this->testStoragePath)) {
            array_map('unlink', glob($this->testStoragePath . '/*'));
            rmdir($this->testStoragePath);
        }

        parent::tearDown();
    }

    // ==================== Success Cases ====================

    #[Test]
    public function invoke_validLead_capturesAndSavesScreenshot(): void
    {
        $user = $this->createUser('screenshot-user');
        $lead = $this->createLead($user, 'screenshot-test.com');
        $leadId = $lead->getId();

        $screenshotData = 'PNG binary data here';

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('isAvailable')->willReturn(true);
        $screenshotService->method('captureFromUrl')
            ->with('https://' . $lead->getDomain(), [])
            ->willReturn($screenshotData);

        $handler = new TakeScreenshotMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            $screenshotService,
            self::$em,
            new NullLogger(),
            $this->testStoragePath,
        );

        $message = new TakeScreenshotMessage($leadId);
        $handler($message);

        // Verify file was created
        $expectedPath = $this->testStoragePath . '/' . $leadId->toRfc4122() . '.png';
        self::assertFileExists($expectedPath);
        self::assertSame($screenshotData, file_get_contents($expectedPath));

        // Verify lead metadata was updated
        self::$em->clear();
        $updatedLead = $this->findEntity(Lead::class, $leadId);
        $metadata = $updatedLead->getMetadata();

        self::assertArrayHasKey('screenshot_path', $metadata);
        self::assertArrayHasKey('screenshot_taken_at', $metadata);
        self::assertSame($leadId->toRfc4122() . '.png', $metadata['screenshot_path']);
    }

    #[Test]
    public function invoke_withOptions_passesOptionsToService(): void
    {
        $user = $this->createUser('options-user');
        $lead = $this->createLead($user, 'options-test.com');
        $leadId = $lead->getId();

        $options = [
            'width' => 1280,
            'height' => 720,
            'fullPage' => true,
        ];

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('isAvailable')->willReturn(true);
        $screenshotService->expects($this->once())
            ->method('captureFromUrl')
            ->with($this->anything(), $options)
            ->willReturn('screenshot data');

        $handler = new TakeScreenshotMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            $screenshotService,
            self::$em,
            new NullLogger(),
            $this->testStoragePath,
        );

        $message = new TakeScreenshotMessage($leadId, $options);
        $handler($message);
    }

    // ==================== Not Found Cases ====================

    #[Test]
    public function invoke_leadNotFound_returnsEarlyWithoutError(): void
    {
        $nonExistentId = Uuid::v4();

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->expects($this->never())
            ->method('captureFromUrl');

        $handler = new TakeScreenshotMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            $screenshotService,
            self::$em,
            new NullLogger(),
            $this->testStoragePath,
        );

        $message = new TakeScreenshotMessage($nonExistentId);

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== No URL Cases ====================

    #[Test]
    public function invoke_leadWithNoUrl_returnsEarlyWithoutError(): void
    {
        $user = $this->createUser('no-url-user');
        $lead = $this->createLead($user, 'no-url.com');

        // Set URL to empty
        $lead->setUrl('');
        self::$em->flush();

        $leadId = $lead->getId();

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->expects($this->never())
            ->method('captureFromUrl');

        $handler = new TakeScreenshotMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            $screenshotService,
            self::$em,
            new NullLogger(),
            $this->testStoragePath,
        );

        $message = new TakeScreenshotMessage($leadId);

        // Should not throw
        $handler($message);

        $this->addToAssertionCount(1);
    }

    // ==================== Service Unavailable Cases ====================

    #[Test]
    public function invoke_serviceUnavailable_throwsException(): void
    {
        $user = $this->createUser('unavailable-user');
        $lead = $this->createLead($user, 'unavailable-test.com');
        $leadId = $lead->getId();

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('isAvailable')->willReturn(false);
        $screenshotService->method('getEndpoint')->willReturn('http://localhost:9222');
        $screenshotService->expects($this->never())
            ->method('captureFromUrl');

        $handler = new TakeScreenshotMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            $screenshotService,
            self::$em,
            new NullLogger(),
            $this->testStoragePath,
        );

        $message = new TakeScreenshotMessage($leadId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Screenshot service not available');

        $handler($message);
    }

    // ==================== Capture Failure Cases ====================

    #[Test]
    public function invoke_captureFailure_throwsException(): void
    {
        $user = $this->createUser('capture-fail-user');
        $lead = $this->createLead($user, 'capture-fail.com');
        $leadId = $lead->getId();

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('isAvailable')->willReturn(true);
        $screenshotService->method('captureFromUrl')
            ->willThrowException(new \RuntimeException('Timeout'));

        $handler = new TakeScreenshotMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            $screenshotService,
            self::$em,
            new NullLogger(),
            $this->testStoragePath,
        );

        $message = new TakeScreenshotMessage($leadId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Timeout');

        $handler($message);
    }

    #[Test]
    public function invoke_captureFailure_doesNotUpdateMetadata(): void
    {
        $user = $this->createUser('no-meta-update-user');
        $lead = $this->createLead($user, 'no-meta-update.com');
        $leadId = $lead->getId();

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('isAvailable')->willReturn(true);
        $screenshotService->method('captureFromUrl')
            ->willThrowException(new \RuntimeException('Network error'));

        $handler = new TakeScreenshotMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            $screenshotService,
            self::$em,
            new NullLogger(),
            $this->testStoragePath,
        );

        $message = new TakeScreenshotMessage($leadId);

        try {
            $handler($message);
        } catch (\RuntimeException) {
            // Expected
        }

        // Verify metadata was NOT updated
        self::$em->clear();
        $updatedLead = $this->findEntity(Lead::class, $leadId);
        $metadata = $updatedLead->getMetadata();

        self::assertArrayNotHasKey('screenshot_path', $metadata);
        self::assertArrayNotHasKey('screenshot_taken_at', $metadata);
    }

    // ==================== Directory Creation Cases ====================

    #[Test]
    public function invoke_directoryDoesNotExist_createsDirectory(): void
    {
        $user = $this->createUser('mkdir-user');
        $lead = $this->createLead($user, 'mkdir-test.com');
        $leadId = $lead->getId();

        // Ensure directory does not exist
        self::assertDirectoryDoesNotExist($this->testStoragePath);

        $screenshotService = $this->createMock(ScreenshotService::class);
        $screenshotService->method('isAvailable')->willReturn(true);
        $screenshotService->method('captureFromUrl')->willReturn('screenshot data');

        $handler = new TakeScreenshotMessageHandler(
            self::getContainer()->get(LeadRepository::class),
            $screenshotService,
            self::$em,
            new NullLogger(),
            $this->testStoragePath,
        );

        $message = new TakeScreenshotMessage($leadId);
        $handler($message);

        // Directory should now exist
        self::assertDirectoryExists($this->testStoragePath);
    }
}
