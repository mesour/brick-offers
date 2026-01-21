<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Service\Browser\BrowserInterface;
use App\Service\Browser\BrowserlessClient;
use App\Service\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractBrowserAnalyzer extends AbstractLeadAnalyzer
{
    public const VIEWPORT_MOBILE = ['width' => 375, 'height' => 812];
    public const VIEWPORT_TABLET = ['width' => 768, 'height' => 1024];
    public const VIEWPORT_DESKTOP = ['width' => 1920, 'height' => 1080];

    public const VIEWPORTS = [
        'mobile' => self::VIEWPORT_MOBILE,
        'tablet' => self::VIEWPORT_TABLET,
        'desktop' => self::VIEWPORT_DESKTOP,
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        protected readonly BrowserlessClient $browser,
        protected readonly StorageInterface $storage,
    ) {
        parent::__construct($httpClient, $logger);
    }

    /**
     * Check if browser is available before analysis.
     * Returns failure result if browser is not available, null otherwise.
     */
    protected function ensureBrowserAvailable(): ?AnalyzerResult
    {
        if (!$this->browser->isAvailable()) {
            $this->logger->warning('Browser service unavailable for {analyzer}', [
                'analyzer' => static::class,
            ]);

            return AnalyzerResult::failure(
                $this->getCategory(),
                'Browser service unavailable. Please ensure Chromium container is running.'
            );
        }

        return null;
    }

    /**
     * Take a screenshot and store it, returning the storage path.
     */
    protected function captureAndStoreScreenshot(
        string $url,
        string $analysisId,
        string $viewportName,
        array $options = [],
    ): ?string {
        try {
            $viewport = self::VIEWPORTS[$viewportName] ?? self::VIEWPORT_DESKTOP;
            $screenshotOptions = array_merge([
                'width' => $viewport['width'],
                'height' => $viewport['height'],
                'fullPage' => $options['fullPage'] ?? false,
            ], $options);

            $imageData = $this->browser->screenshot($url, $screenshotOptions);

            $path = sprintf(
                'screenshots/%s/%s/%d_%s.png',
                $analysisId,
                $this->getCategory()->value,
                time(),
                $viewportName
            );

            $this->storage->upload($path, $imageData, 'image/png');

            return $path;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to capture screenshot: {error}', [
                'error' => $e->getMessage(),
                'url' => $url,
                'viewport' => $viewportName,
            ]);

            return null;
        }
    }

    /**
     * Get public URL for a stored screenshot.
     */
    protected function getScreenshotUrl(string $path): string
    {
        return $this->storage->getUrl($path);
    }

    /**
     * Generate a temporary analysis ID for screenshot storage.
     */
    protected function generateAnalysisId(Lead $lead): string
    {
        return sprintf('%s_%d', $lead->getId() ?? 'unknown', time());
    }
}
