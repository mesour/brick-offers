<?php

declare(strict_types=1);

namespace App\Service\Screenshot;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for capturing screenshots using headless Chrome.
 */
class ScreenshotService
{
    private const DEFAULT_WIDTH = 1920;
    private const DEFAULT_HEIGHT = 1080;
    private const DEFAULT_FORMAT = 'png';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $chromeEndpoint = 'http://host.docker.internal:7290',
    ) {
    }

    /**
     * Capture a screenshot from HTML content.
     *
     * @param array<string, mixed> $options
     * @return string Binary screenshot data
     */
    public function captureFromHtml(string $html, array $options = []): string
    {
        $width = $options['width'] ?? self::DEFAULT_WIDTH;
        $height = $options['height'] ?? self::DEFAULT_HEIGHT;
        $format = $options['format'] ?? self::DEFAULT_FORMAT;
        $fullPage = $options['fullPage'] ?? false;

        $this->logger->debug('Capturing screenshot from HTML', [
            'html_length' => strlen($html),
            'width' => $width,
            'height' => $height,
            'format' => $format,
        ]);

        try {
            $response = $this->httpClient->request('POST', $this->chromeEndpoint . '/screenshot', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'html' => $html,
                    'options' => [
                        'type' => $format,
                        'fullPage' => $fullPage,
                        'viewport' => [
                            'width' => $width,
                            'height' => $height,
                        ],
                    ],
                ],
                'timeout' => 60,
            ]);

            return $response->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('Screenshot capture failed', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to capture screenshot: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Capture a screenshot from a URL.
     *
     * @param array<string, mixed> $options
     * @return string Binary screenshot data
     */
    public function captureFromUrl(string $url, array $options = []): string
    {
        $width = $options['width'] ?? self::DEFAULT_WIDTH;
        $height = $options['height'] ?? self::DEFAULT_HEIGHT;
        $format = $options['format'] ?? self::DEFAULT_FORMAT;
        $fullPage = $options['fullPage'] ?? false;
        $waitUntil = $options['waitUntil'] ?? 'networkidle0';

        $this->logger->debug('Capturing screenshot from URL', [
            'url' => $url,
            'width' => $width,
            'height' => $height,
            'format' => $format,
        ]);

        try {
            $response = $this->httpClient->request('POST', $this->chromeEndpoint . '/screenshot', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'url' => $url,
                    'options' => [
                        'type' => $format,
                        'fullPage' => $fullPage,
                        'viewport' => [
                            'width' => $width,
                            'height' => $height,
                        ],
                    ],
                    'gotoOptions' => [
                        'waitUntil' => $waitUntil,
                        'timeout' => 30000,
                    ],
                ],
                'timeout' => 60,
            ]);

            return $response->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('Screenshot capture from URL failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to capture screenshot: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if the screenshot service is available.
     */
    public function isAvailable(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->chromeEndpoint . '/health', [
                'timeout' => 5,
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the Chrome endpoint URL.
     */
    public function getEndpoint(): string
    {
        return $this->chromeEndpoint;
    }
}
