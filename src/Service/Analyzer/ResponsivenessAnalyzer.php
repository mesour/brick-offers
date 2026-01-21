<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

class ResponsivenessAnalyzer extends AbstractBrowserAnalyzer
{
    // Minimum touch target size per WCAG 2.1 guidelines
    private const MIN_TOUCH_TARGET_SIZE = 48;

    // Maximum number of small touch targets to report before it's a critical issue
    private const SMALL_TOUCH_TARGETS_CRITICAL_THRESHOLD = 10;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::RESPONSIVENESS;
    }

    public function getPriority(): int
    {
        return 60; // After performance
    }

    public function analyze(Lead $lead): AnalyzerResult
    {
        $url = $lead->getUrl();
        if ($url === null) {
            return AnalyzerResult::failure($this->getCategory(), 'Lead URL is null');
        }

        // Check browser availability
        $browserCheck = $this->ensureBrowserAvailable();
        if ($browserCheck !== null) {
            return $browserCheck;
        }

        $issues = [];
        $analysisId = $this->generateAnalysisId($lead);
        $rawData = [
            'url' => $url,
            'viewports' => [],
            'analyzedAt' => date('c'),
        ];

        try {
            // Test each viewport
            foreach (self::VIEWPORTS as $viewportName => $viewport) {
                $viewportData = $this->analyzeViewport($url, $viewportName, $viewport, $analysisId);
                $rawData['viewports'][$viewportName] = $viewportData;

                // Collect issues from this viewport
                foreach ($viewportData['issues'] as $issue) {
                    $issues[] = $issue;
                }
            }

            // Check viewport meta tag from page source
            $viewportMetaResult = $this->checkViewportMetaTag($url);
            $rawData['hasViewportMeta'] = $viewportMetaResult['hasViewportMeta'];
            if (!$viewportMetaResult['hasViewportMeta']) {
                $issues[] = $this->createIssue('resp_missing_viewport_meta', 'Viewport meta tag nebyl nalezen v HTML');
            }

            return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
        } catch (\Throwable $e) {
            $this->logger->error('Responsiveness analysis failed: {error}', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return AnalyzerResult::failure(
                $this->getCategory(),
                'Responsiveness analysis failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array{width: int, height: int} $viewport
     * @return array{screenshot: ?string, horizontalOverflow: bool, overflowAmount: int, smallTouchTargets: array, issues: array<Issue>}
     */
    private function analyzeViewport(string $url, string $viewportName, array $viewport, string $analysisId): array
    {
        $data = [
            'screenshot' => null,
            'horizontalOverflow' => false,
            'overflowAmount' => 0,
            'smallTouchTargets' => [],
            'issues' => [],
        ];

        // Capture screenshot
        $screenshotPath = $this->captureAndStoreScreenshot($url, $analysisId, $viewportName, [
            'fullPage' => true,
        ]);
        $data['screenshot'] = $screenshotPath;

        // Analyze responsiveness at this viewport
        $responsivenessData = $this->browser->analyzeResponsiveness($url, $viewport);
        $data['horizontalOverflow'] = $responsivenessData['horizontalOverflow'];
        $data['overflowAmount'] = $responsivenessData['overflowAmount'];
        $data['smallTouchTargets'] = $responsivenessData['smallTouchTargets'];

        // Create issues for this viewport
        $issues = [];

        // Horizontal overflow is critical on mobile
        if ($responsivenessData['horizontalOverflow'] && $viewportName === 'mobile') {
            $issues[] = $this->createIssue(
                'resp_horizontal_overflow_mobile',
                sprintf('Přetečení: %d px na viewportu %s', $responsivenessData['overflowAmount'], $viewportName)
            );
        } elseif ($responsivenessData['horizontalOverflow'] && $viewportName === 'tablet') {
            $issues[] = $this->createIssue(
                'resp_horizontal_overflow_tablet',
                sprintf('Přetečení: %d px na viewportu %s', $responsivenessData['overflowAmount'], $viewportName)
            );
        }

        // Small touch targets on mobile/tablet
        $smallTargetCount = count($responsivenessData['smallTouchTargets']);
        if ($smallTargetCount > 0 && in_array($viewportName, ['mobile', 'tablet'], true)) {
            $targetExamples = array_slice($responsivenessData['smallTouchTargets'], 0, 3);
            $examplesText = implode(', ', array_map(
                fn ($t) => sprintf('%s (%dx%d px)', $t['tag'], $t['width'], $t['height']),
                $targetExamples
            ));

            $issueCode = 'resp_small_touch_targets_' . $viewportName;
            $issues[] = $this->createIssue($issueCode, sprintf('Příklady: %s', $examplesText));
        }

        $data['issues'] = $issues;

        return $data;
    }

    /**
     * @return array{hasViewportMeta: bool, viewportContent: ?string}
     */
    private function checkViewportMetaTag(string $url): array
    {
        try {
            $pageSource = $this->browser->getPageSource($url, 500);

            // Look for viewport meta tag
            $hasViewportMeta = (bool) preg_match(
                '/<meta[^>]+name=["\']viewport["\'][^>]*>/i',
                $pageSource
            );

            $viewportContent = null;
            if (preg_match('/<meta[^>]+name=["\']viewport["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $pageSource, $matches)) {
                $viewportContent = $matches[1];
            } elseif (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']viewport["\'][^>]*>/i', $pageSource, $matches)) {
                $viewportContent = $matches[1];
            }

            return [
                'hasViewportMeta' => $hasViewportMeta || $viewportContent !== null,
                'viewportContent' => $viewportContent,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to check viewport meta tag: {error}', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            // Assume it exists if we can't check
            return [
                'hasViewportMeta' => true,
                'viewportContent' => null,
            ];
        }
    }
}
