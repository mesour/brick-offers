<?php

declare(strict_types=1);

namespace App\Service\Browser;

interface BrowserInterface
{
    /**
     * Take a screenshot of a URL.
     *
     * @param string $url The URL to screenshot
     * @param array{width?: int, height?: int, fullPage?: bool} $options Screenshot options
     * @return string Binary PNG image data
     */
    public function screenshot(string $url, array $options = []): string;

    /**
     * Get the rendered page source (after JavaScript execution).
     *
     * @param string $url The URL to load
     * @param int $waitMs Time to wait for JavaScript to execute (milliseconds)
     */
    public function getPageSource(string $url, int $waitMs = 1000): string;

    /**
     * Get console logs from page load.
     *
     * @param string $url The URL to load
     * @return array<array{level: string, message: string, timestamp: int}>
     */
    public function getConsoleLogs(string $url): array;

    /**
     * Measure Core Web Vitals.
     *
     * @param string $url The URL to measure
     * @return array{lcp: ?float, fid: ?float, cls: ?float, ttfb: ?float, fcp: ?float}
     */
    public function measureWebVitals(string $url): array;

    /**
     * Check if the browser service is available.
     */
    public function isAvailable(): bool;
}
