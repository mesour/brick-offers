<?php

declare(strict_types=1);

namespace App\Service\Browser;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BrowserlessClient implements BrowserInterface
{
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY_MS = 500;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $browserlessUrl = 'http://host.docker.internal:7290',
        private readonly int $timeout = 30,
    ) {}

    public function screenshot(string $url, array $options = []): string
    {
        $width = $options['width'] ?? 1920;
        $height = $options['height'] ?? 1080;
        $fullPage = $options['fullPage'] ?? false;

        // Browserless v2 API format
        $payload = [
            'url' => $url,
            'options' => [
                'type' => 'png',
                'fullPage' => $fullPage,
            ],
            'gotoOptions' => [
                'waitUntil' => 'networkidle2',
                'timeout' => $this->timeout * 1000,
            ],
            'viewport' => [
                'width' => $width,
                'height' => $height,
            ],
        ];

        $this->logger->info('Taking screenshot', ['url' => $url, 'viewport' => "{$width}x{$height}"]);

        $response = $this->requestWithRetry('POST', '/screenshot', [
            'json' => $payload,
            'timeout' => $this->timeout + 10,
        ]);

        return $response->getContent();
    }

    public function getPageSource(string $url, int $waitMs = 1000): string
    {
        // Browserless v2 API format
        $payload = [
            'url' => $url,
            'gotoOptions' => [
                'waitUntil' => 'networkidle2',
                'timeout' => $this->timeout * 1000,
            ],
        ];

        $this->logger->info('Getting page source', ['url' => $url]);

        $response = $this->requestWithRetry('POST', '/content', [
            'json' => $payload,
            'timeout' => $this->timeout + 10,
        ]);

        return $response->getContent();
    }

    public function getConsoleLogs(string $url): array
    {
        // For console logs, we need to use /function endpoint
        $jsCode = $this->buildFunctionCode($url, <<<'JS'
            const logs = [];

            page.on('console', msg => {
                logs.push({
                    level: msg.type(),
                    message: msg.text(),
                    timestamp: Date.now()
                });
            });

            page.on('pageerror', error => {
                logs.push({
                    level: 'error',
                    message: error.message,
                    timestamp: Date.now()
                });
            });

            await page.goto(url, { waitUntil: 'networkidle2', timeout: timeout });
            await new Promise(r => setTimeout(r, 2000));

            return { data: logs, type: 'application/json' };
        JS);

        $result = $this->executeFunction($jsCode);

        return $result['data'] ?? [];
    }

    public function measureWebVitals(string $url): array
    {
        $jsCode = $this->buildFunctionCode($url, <<<'JS'
            await page.goto(url, { waitUntil: 'networkidle2', timeout: timeout });

            const metrics = await page.evaluate(() => {
                return new Promise((resolve) => {
                    const results = {
                        lcp: null,
                        fid: null,
                        cls: null,
                        ttfb: null,
                        fcp: null
                    };

                    // Get TTFB from Performance API
                    const navEntry = performance.getEntriesByType('navigation')[0];
                    if (navEntry) {
                        results.ttfb = navEntry.responseStart - navEntry.requestStart;
                    }

                    // Get FCP from paint entries
                    const paintEntries = performance.getEntriesByType('paint');
                    const fcpEntry = paintEntries.find(e => e.name === 'first-contentful-paint');
                    if (fcpEntry) {
                        results.fcp = fcpEntry.startTime;
                    }

                    // LCP observer
                    let lcpValue = null;
                    try {
                        new PerformanceObserver((list) => {
                            const entries = list.getEntries();
                            if (entries.length > 0) {
                                lcpValue = entries[entries.length - 1].startTime;
                            }
                        }).observe({ type: 'largest-contentful-paint', buffered: true });
                    } catch (e) {}

                    // CLS observer
                    let clsValue = 0;
                    try {
                        new PerformanceObserver((list) => {
                            for (const entry of list.getEntries()) {
                                if (!entry.hadRecentInput) {
                                    clsValue += entry.value;
                                }
                            }
                        }).observe({ type: 'layout-shift', buffered: true });
                    } catch (e) {}

                    // Wait for metrics to stabilize
                    setTimeout(() => {
                        results.lcp = lcpValue;
                        results.cls = clsValue;
                        results.fid = null;
                        resolve(results);
                    }, 3000);
                });
            });

            return { data: metrics, type: 'application/json' };
        JS);

        $this->logger->info('Measuring Web Vitals', ['url' => $url]);

        $result = $this->executeFunction($jsCode);

        $data = $result['data'] ?? [];

        return [
            'lcp' => $data['lcp'] ?? null,
            'fid' => $data['fid'] ?? null,
            'cls' => $data['cls'] ?? null,
            'ttfb' => $data['ttfb'] ?? null,
            'fcp' => $data['fcp'] ?? null,
        ];
    }

    public function isAvailable(): bool
    {
        try {
            // Browserless v1 uses /pressure endpoint
            $response = $this->httpClient->request('GET', $this->browserlessUrl . '/pressure', [
                'timeout' => 5,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $this->logger->warning('Browser availability check returned non-200', ['status' => $statusCode]);
                return false;
            }

            // Parse response to check isAvailable flag
            $content = $response->getContent();
            $data = json_decode($content, true);

            $isAvailable = $data['pressure']['isAvailable'] ?? false;
            $this->logger->info('Browser availability check', [
                'isAvailable' => $isAvailable,
                'running' => $data['pressure']['running'] ?? 0,
                'maxConcurrent' => $data['pressure']['maxConcurrent'] ?? 0,
            ]);

            return $isAvailable;
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Browser service availability check failed: {error}', [
                'error' => $e->getMessage(),
                'url' => $this->browserlessUrl,
            ]);

            return false;
        }
    }

    /**
     * Execute custom JavaScript function via Browserless /function endpoint.
     *
     * @return array<string, mixed>
     */
    public function executeFunction(string $jsCode): array
    {
        $response = $this->requestWithRetry('POST', '/function', [
            'body' => $jsCode,
            'headers' => [
                'Content-Type' => 'application/javascript',
            ],
            'timeout' => $this->timeout + 15,
        ]);

        $content = $response->getContent();

        // Try to parse as JSON
        $decoded = json_decode($content, true);
        if ($decoded !== null) {
            return $decoded;
        }

        // If not JSON, return as raw data
        return ['raw' => $content];
    }

    /**
     * Analyze responsiveness by testing different viewports.
     *
     * @param array{width: int, height: int} $viewport
     * @return array{horizontalOverflow: bool, overflowAmount: int, smallTouchTargets: array<array{tag: string, text: string, width: int, height: int}>}
     */
    public function analyzeResponsiveness(string $url, array $viewport): array
    {
        $jsCode = $this->buildFunctionCode($url, <<<JS
            await page.setViewport({ width: {$viewport['width']}, height: {$viewport['height']} });
            await page.goto(url, { waitUntil: 'networkidle2', timeout: timeout });

            const results = await page.evaluate(() => {
                const data = {
                    horizontalOverflow: document.body.scrollWidth > window.innerWidth,
                    overflowAmount: Math.max(0, document.body.scrollWidth - window.innerWidth),
                    smallTouchTargets: []
                };

                const interactive = document.querySelectorAll('a, button, input, select, textarea, [onclick], [role="button"]');
                interactive.forEach(el => {
                    const rect = el.getBoundingClientRect();
                    if (rect.width > 0 && rect.height > 0 && (rect.width < 48 || rect.height < 48)) {
                        data.smallTouchTargets.push({
                            tag: el.tagName.toLowerCase(),
                            text: (el.textContent || '').substring(0, 50).trim(),
                            width: Math.round(rect.width),
                            height: Math.round(rect.height)
                        });
                    }
                });

                data.smallTouchTargets = data.smallTouchTargets.slice(0, 20);
                return data;
            });

            return { data: results, type: 'application/json' };
        JS);

        $this->logger->info('Analyzing responsiveness', ['url' => $url, 'viewport' => $viewport]);

        $result = $this->executeFunction($jsCode);
        $data = $result['data'] ?? [];

        return [
            'horizontalOverflow' => $data['horizontalOverflow'] ?? false,
            'overflowAmount' => $data['overflowAmount'] ?? 0,
            'smallTouchTargets' => $data['smallTouchTargets'] ?? [],
        ];
    }

    /**
     * Analyze visual consistency (padding, typography, images).
     *
     * @return array{paddingValues: array<string, int>, fontFamilies: array<string, int>, fontSizes: array<string, int>, upscaledImages: array<array{src: string, naturalWidth: int, displayWidth: int, ratio: float}>}
     */
    public function analyzeVisualConsistency(string $url): array
    {
        $jsCode = $this->buildFunctionCode($url, <<<'JS'
            await page.goto(url, { waitUntil: 'networkidle2', timeout: timeout });

            const results = await page.evaluate(() => {
                const data = {
                    paddingValues: {},
                    fontFamilies: {},
                    fontSizes: {},
                    upscaledImages: []
                };

                const containers = document.querySelectorAll('section, article, div, main, aside, header, footer, nav');
                containers.forEach(el => {
                    const style = getComputedStyle(el);
                    ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach(prop => {
                        const val = style[prop];
                        if (val && val !== '0px') {
                            data.paddingValues[val] = (data.paddingValues[val] || 0) + 1;
                        }
                    });
                });

                const textElements = document.querySelectorAll('p, h1, h2, h3, h4, h5, h6, span, a, li, td, th');
                textElements.forEach(el => {
                    const style = getComputedStyle(el);
                    const font = style.fontFamily.split(',')[0].trim().replace(/['"]/g, '');
                    const size = style.fontSize;
                    if (font) {
                        data.fontFamilies[font] = (data.fontFamilies[font] || 0) + 1;
                    }
                    if (size) {
                        data.fontSizes[size] = (data.fontSizes[size] || 0) + 1;
                    }
                });

                document.querySelectorAll('img').forEach(img => {
                    if (img.naturalWidth > 0 && img.width > 0) {
                        const ratio = img.naturalWidth / img.width;
                        if (ratio < 0.9) {
                            data.upscaledImages.push({
                                src: img.src.substring(0, 100),
                                naturalWidth: img.naturalWidth,
                                displayWidth: img.width,
                                ratio: parseFloat(ratio.toFixed(2))
                            });
                        }
                    }
                });

                data.upscaledImages = data.upscaledImages.slice(0, 10);
                return data;
            });

            return { data: results, type: 'application/json' };
        JS);

        $this->logger->info('Analyzing visual consistency', ['url' => $url]);

        $result = $this->executeFunction($jsCode);
        $data = $result['data'] ?? [];

        return [
            'paddingValues' => $data['paddingValues'] ?? [],
            'fontFamilies' => $data['fontFamilies'] ?? [],
            'fontSizes' => $data['fontSizes'] ?? [],
            'upscaledImages' => $data['upscaledImages'] ?? [],
        ];
    }

    /**
     * Analyze design modernity by checking CSS properties and techniques.
     *
     * @return array{
     *     modern: array{grid: int, flex: int, cssVariables: int, borderRadius: int, boxShadow: int, gradients: int, transitions: int, animations: int, modernUnits: int, gap: int, objectFit: int, backdropFilter: int, filter: int, aspectRatio: int},
     *     outdated: array{float: int, clearfix: int, tableDisplay: int},
     *     stylesheetInfo: array{totalRules: int, cssVariableDefinitions: int, importantCount: int, gradientRules: int},
     *     summary: array{modernScore: int, outdatedScore: int, totalElements: int}
     * }
     */
    public function analyzeDesignModernity(string $url): array
    {
        $jsCode = $this->buildFunctionCode($url, <<<'JS'
            await page.goto(url, { waitUntil: 'networkidle2', timeout: timeout });

            const results = await page.evaluate(() => {
                const data = {
                    modern: {
                        grid: 0,
                        flex: 0,
                        cssVariables: 0,
                        borderRadius: 0,
                        boxShadow: 0,
                        gradients: 0,
                        transitions: 0,
                        animations: 0,
                        modernUnits: 0,
                        gap: 0,
                        objectFit: 0,
                        backdropFilter: 0,
                        filter: 0,
                        aspectRatio: 0
                    },
                    outdated: {
                        float: 0,
                        clearfix: 0,
                        tableDisplay: 0
                    },
                    stylesheetInfo: {
                        totalRules: 0,
                        cssVariableDefinitions: 0,
                        importantCount: 0,
                        gradientRules: 0
                    },
                    summary: {
                        modernScore: 0,
                        outdatedScore: 0,
                        totalElements: 0
                    }
                };

                // Analyze computed styles of all elements
                const elements = document.querySelectorAll('body *');
                data.summary.totalElements = elements.length;

                // Track if modern units are used anywhere
                let hasModernUnits = false;

                elements.forEach(el => {
                    try {
                        const style = getComputedStyle(el);

                        // Modern layout
                        if (style.display === 'grid') data.modern.grid++;
                        if (style.display === 'flex' || style.display === 'inline-flex') data.modern.flex++;

                        // Modern visual effects
                        if (style.borderRadius && style.borderRadius !== '0px') data.modern.borderRadius++;
                        if (style.boxShadow && style.boxShadow !== 'none') data.modern.boxShadow++;
                        if (style.backgroundImage && style.backgroundImage.includes('gradient')) data.modern.gradients++;
                        if (style.transition && style.transition !== 'none' && style.transition !== 'all 0s ease 0s') data.modern.transitions++;
                        if (style.animation && style.animation !== 'none' && style.animation !== '') data.modern.animations++;
                        if (style.gap && style.gap !== 'normal') data.modern.gap++;
                        if (style.objectFit && style.objectFit !== 'fill') data.modern.objectFit++;
                        if (style.backdropFilter && style.backdropFilter !== 'none') data.modern.backdropFilter++;
                        if (style.filter && style.filter !== 'none') data.modern.filter++;
                        if (style.aspectRatio && style.aspectRatio !== 'auto') data.modern.aspectRatio++;

                        // Outdated techniques
                        if (style.float === 'left' || style.float === 'right') data.outdated.float++;
                        if (style.clear === 'both' || style.clear === 'left' || style.clear === 'right') data.outdated.clearfix++;
                        if (style.display === 'table' || style.display === 'table-cell' || style.display === 'table-row') data.outdated.tableDisplay++;

                    } catch (e) {
                        // Skip elements that cause errors
                    }
                });

                // Analyze stylesheets for CSS variables, gradients, !important
                try {
                    for (const sheet of document.styleSheets) {
                        try {
                            if (!sheet.cssRules) continue;

                            for (const rule of sheet.cssRules) {
                                data.stylesheetInfo.totalRules++;
                                const cssText = rule.cssText || '';

                                // CSS variable definitions
                                if (cssText.includes('--') && cssText.includes(':')) {
                                    const varMatches = cssText.match(/--[\w-]+\s*:/g);
                                    if (varMatches) data.stylesheetInfo.cssVariableDefinitions += varMatches.length;
                                }

                                // CSS variable usage
                                if (cssText.includes('var(--')) {
                                    const usageMatches = cssText.match(/var\(--/g);
                                    if (usageMatches) data.modern.cssVariables += usageMatches.length;
                                }

                                // Gradients in stylesheets
                                if (cssText.includes('gradient')) {
                                    data.stylesheetInfo.gradientRules++;
                                }

                                // !important usage
                                if (cssText.includes('!important')) {
                                    const importantMatches = cssText.match(/!important/g);
                                    if (importantMatches) data.stylesheetInfo.importantCount += importantMatches.length;
                                }

                                // Modern units (rem, em, vh, vw, %)
                                if (cssText.match(/\d+(\.\d+)?(rem|em|vh|vw|vmin|vmax|ch)/)) {
                                    hasModernUnits = true;
                                }
                            }
                        } catch (e) {
                            // Cross-origin stylesheet, skip
                        }
                    }
                } catch (e) {
                    // Stylesheet access error
                }

                if (hasModernUnits) data.modern.modernUnits = 1;

                // Calculate scores
                data.summary.modernScore =
                    (data.modern.grid > 0 ? 3 : 0) +
                    (data.modern.flex > 0 ? 2 : 0) +
                    (data.modern.cssVariables > 0 ? 3 : 0) +
                    (data.modern.borderRadius > 3 ? 1 : 0) +
                    (data.modern.boxShadow > 0 ? 1 : 0) +
                    (data.modern.gradients > 0 ? 2 : 0) +
                    (data.modern.transitions > 0 ? 1 : 0) +
                    (data.modern.animations > 0 ? 1 : 0) +
                    (data.modern.modernUnits > 0 ? 2 : 0) +
                    (data.modern.gap > 0 ? 2 : 0) +
                    (data.modern.objectFit > 0 ? 1 : 0) +
                    (data.modern.backdropFilter > 0 ? 2 : 0) +
                    (data.modern.filter > 0 ? 1 : 0) +
                    (data.modern.aspectRatio > 0 ? 1 : 0);

                data.summary.outdatedScore =
                    (data.outdated.float > 10 && data.modern.flex === 0 && data.modern.grid === 0 ? -3 : 0) +
                    (data.outdated.clearfix > 3 ? -2 : 0) +
                    (data.outdated.tableDisplay > 5 ? -3 : 0) +
                    (data.stylesheetInfo.importantCount > 20 ? -1 : 0);

                return data;
            });

            return { data: results, type: 'application/json' };
        JS);

        $this->logger->info('Analyzing design modernity', ['url' => $url]);

        $result = $this->executeFunction($jsCode);
        $data = $result['data'] ?? [];

        return [
            'modern' => $data['modern'] ?? [],
            'outdated' => $data['outdated'] ?? [],
            'stylesheetInfo' => $data['stylesheetInfo'] ?? [],
            'summary' => $data['summary'] ?? [],
        ];
    }

    /**
     * Run Axe-core accessibility analysis.
     *
     * @return array{violations: array<array{id: string, impact: string, description: string, nodes: array}>, passes: int, incomplete: int, error: ?string}
     */
    public function runAxeAnalysis(string $url): array
    {
        $jsCode = $this->buildFunctionCode($url, <<<'JS'
            await page.goto(url, { waitUntil: 'networkidle2', timeout: timeout });

            // Inject axe-core from CDN
            await page.addScriptTag({
                url: 'https://cdnjs.cloudflare.com/ajax/libs/axe-core/4.8.4/axe.min.js'
            });

            // Wait for axe to load
            await page.waitForFunction('typeof axe !== "undefined"', { timeout: 10000 });

            const results = await page.evaluate(async () => {
                try {
                    const axeResults = await axe.run(document, {
                        runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
                    });

                    return {
                        violations: axeResults.violations.map(v => ({
                            id: v.id,
                            impact: v.impact,
                            description: v.description,
                            help: v.help,
                            helpUrl: v.helpUrl,
                            nodes: v.nodes.slice(0, 5).map(n => ({
                                html: n.html.substring(0, 200),
                                target: n.target
                            }))
                        })),
                        passes: axeResults.passes.length,
                        incomplete: axeResults.incomplete.length,
                        error: null
                    };
                } catch (e) {
                    return {
                        error: e.message,
                        violations: [],
                        passes: 0,
                        incomplete: 0
                    };
                }
            });

            return { data: results, type: 'application/json' };
        JS);

        $this->logger->info('Running Axe-core accessibility analysis', ['url' => $url]);

        $result = $this->executeFunction($jsCode);
        $data = $result['data'] ?? [];

        return [
            'violations' => $data['violations'] ?? [],
            'passes' => $data['passes'] ?? 0,
            'incomplete' => $data['incomplete'] ?? 0,
            'error' => $data['error'] ?? null,
        ];
    }

    /**
     * Build function code for Browserless v1 /function endpoint (CommonJS).
     */
    private function buildFunctionCode(string $url, string $functionBody): string
    {
        $escapedUrl = addslashes($url);
        $timeoutMs = $this->timeout * 1000;

        return <<<JS
        module.exports = async ({ page }) => {
            const url = '{$escapedUrl}';
            const timeout = {$timeoutMs};

            {$functionBody}
        };
        JS;
    }

    /**
     * @return \Symfony\Contracts\HttpClient\ResponseInterface
     */
    private function requestWithRetry(string $method, string $endpoint, array $options): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $lastException = null;
        $fullUrl = $this->browserlessUrl . $endpoint;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $this->logger->debug('Browser request attempt', [
                    'attempt' => $attempt,
                    'method' => $method,
                    'url' => $fullUrl,
                ]);

                $response = $this->httpClient->request($method, $fullUrl, $options);
                $statusCode = $response->getStatusCode();

                if ($statusCode >= 400) {
                    $errorContent = $response->getContent(false);
                    $this->logger->error('Browser request returned error', [
                        'status' => $statusCode,
                        'endpoint' => $endpoint,
                        'error' => $errorContent,
                    ]);
                    throw new \RuntimeException("Browser request failed with status {$statusCode}: {$errorContent}");
                }

                return $response;
            } catch (ExceptionInterface $e) {
                $lastException = $e;
                $this->logger->warning('Browser request failed, attempt {attempt}/{max}: {error}', [
                    'attempt' => $attempt,
                    'max' => self::MAX_RETRIES,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY_MS * 1000);
                }
            } catch (\RuntimeException $e) {
                $lastException = $e;
                if ($attempt < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY_MS * 1000);
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Browser request failed after all retries');
    }
}
