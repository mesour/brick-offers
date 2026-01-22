<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Lead;
use App\Message\TakeScreenshotMessage;
use App\MessageHandler\TakeScreenshotMessageHandler;
use App\Repository\LeadRepository;
use App\Service\Screenshot\ScreenshotService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class TakeScreenshotMessageHandlerTest extends TestCase
{
    private LeadRepository&MockObject $leadRepository;
    private ScreenshotService&MockObject $screenshotService;
    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    private TakeScreenshotMessageHandler $handler;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->leadRepository = $this->createMock(LeadRepository::class);
        $this->screenshotService = $this->createMock(ScreenshotService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storagePath = sys_get_temp_dir() . '/test-screenshots';

        $this->handler = new TakeScreenshotMessageHandler(
            $this->leadRepository,
            $this->screenshotService,
            $this->em,
            $this->logger,
            $this->storagePath,
        );
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (is_dir($this->storagePath)) {
            array_map('unlink', glob($this->storagePath . '/*') ?: []);
            rmdir($this->storagePath);
        }
    }

    public function testInvoke_leadNotFound_logsErrorAndReturns(): void
    {
        $leadId = Uuid::v4();
        $message = new TakeScreenshotMessage($leadId);

        $this->leadRepository->expects(self::once())
            ->method('find')
            ->with($leadId)
            ->willReturn(null);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Lead not found for screenshot', self::anything());

        $this->screenshotService->expects(self::never())
            ->method('captureFromUrl');

        ($this->handler)($message);
    }

    public function testInvoke_leadNoUrl_logsErrorAndReturns(): void
    {
        $leadId = Uuid::v4();
        $message = new TakeScreenshotMessage($leadId);

        $lead = $this->createMock(Lead::class);
        $lead->method('getUrl')->willReturn(null);

        $this->leadRepository->method('find')->willReturn($lead);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Lead has no URL for screenshot', self::anything());

        $this->screenshotService->expects(self::never())
            ->method('captureFromUrl');

        ($this->handler)($message);
    }

    public function testInvoke_serviceNotAvailable_throwsException(): void
    {
        $leadId = Uuid::v4();
        $message = new TakeScreenshotMessage($leadId);

        $lead = $this->createMock(Lead::class);
        $lead->method('getUrl')->willReturn('https://example.com');

        $this->leadRepository->method('find')->willReturn($lead);

        $this->screenshotService->method('isAvailable')->willReturn(false);
        $this->screenshotService->method('getEndpoint')->willReturn('http://localhost:3000');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Screenshot service not available');

        ($this->handler)($message);
    }

    public function testInvoke_success_savesScreenshot(): void
    {
        $leadId = Uuid::v4();
        $message = new TakeScreenshotMessage($leadId);

        $lead = $this->createMock(Lead::class);
        $lead->method('getUrl')->willReturn('https://example.com');
        $lead->method('getMetadata')->willReturn([]);

        $lead->expects(self::once())
            ->method('setMetadata')
            ->with(self::callback(function (array $metadata) use ($leadId) {
                return $metadata['screenshot_path'] === $leadId->toRfc4122() . '.png'
                    && isset($metadata['screenshot_taken_at']);
            }));

        $this->leadRepository->method('find')->willReturn($lead);

        $this->screenshotService->method('isAvailable')->willReturn(true);
        $this->screenshotService->expects(self::once())
            ->method('captureFromUrl')
            ->with('https://example.com', [])
            ->willReturn('binary-screenshot-data');

        $this->em->expects(self::once())->method('flush');

        ($this->handler)($message);

        // Verify file was created
        $expectedPath = $this->storagePath . '/' . $leadId->toRfc4122() . '.png';
        self::assertFileExists($expectedPath);
        self::assertSame('binary-screenshot-data', file_get_contents($expectedPath));
    }

    public function testInvoke_captureException_logsErrorAndRethrows(): void
    {
        $leadId = Uuid::v4();
        $message = new TakeScreenshotMessage($leadId);

        $lead = $this->createMock(Lead::class);
        $lead->method('getUrl')->willReturn('https://example.com');

        $this->leadRepository->method('find')->willReturn($lead);

        $this->screenshotService->method('isAvailable')->willReturn(true);

        $exception = new \RuntimeException('Capture failed');
        $this->screenshotService->expects(self::once())
            ->method('captureFromUrl')
            ->willThrowException($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Screenshot capture failed', self::anything());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Capture failed');

        ($this->handler)($message);
    }
}
