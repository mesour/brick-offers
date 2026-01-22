<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TakeScreenshotMessage;
use App\Repository\LeadRepository;
use App\Service\Screenshot\ScreenshotService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for taking website screenshots asynchronously.
 */
#[AsMessageHandler]
final readonly class TakeScreenshotMessageHandler
{
    public function __construct(
        private LeadRepository $leadRepository,
        private ScreenshotService $screenshotService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $screenshotStoragePath = '/var/www/html/var/screenshots',
    ) {}

    public function __invoke(TakeScreenshotMessage $message): void
    {
        $lead = $this->leadRepository->find($message->leadId);
        if ($lead === null) {
            $this->logger->error('Lead not found for screenshot', [
                'lead_id' => $message->leadId->toRfc4122(),
            ]);

            return;
        }

        $url = $lead->getUrl();
        if (empty($url)) {
            $this->logger->error('Lead has no URL for screenshot', [
                'lead_id' => $message->leadId->toRfc4122(),
            ]);

            return;
        }

        // Check if screenshot service is available
        if (!$this->screenshotService->isAvailable()) {
            $this->logger->warning('Screenshot service not available', [
                'lead_id' => $message->leadId->toRfc4122(),
                'endpoint' => $this->screenshotService->getEndpoint(),
            ]);

            throw new \RuntimeException('Screenshot service not available');
        }

        $this->logger->info('Taking screenshot', [
            'lead_id' => $message->leadId->toRfc4122(),
            'url' => $url,
        ]);

        try {
            // Capture screenshot
            $screenshotData = $this->screenshotService->captureFromUrl($url, $message->options);

            // Save to storage
            $filename = sprintf('%s.png', $message->leadId->toRfc4122());
            $filepath = $this->screenshotStoragePath . '/' . $filename;

            // Ensure directory exists
            if (!is_dir($this->screenshotStoragePath)) {
                mkdir($this->screenshotStoragePath, 0755, true);
            }

            file_put_contents($filepath, $screenshotData);

            // Update lead metadata with screenshot path
            $metadata = $lead->getMetadata();
            $metadata['screenshot_path'] = $filename;
            $metadata['screenshot_taken_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $lead->setMetadata($metadata);
            $this->em->flush();

            $this->logger->info('Screenshot saved', [
                'lead_id' => $message->leadId->toRfc4122(),
                'filepath' => $filepath,
                'size' => strlen($screenshotData),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Screenshot capture failed', [
                'lead_id' => $message->leadId->toRfc4122(),
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
