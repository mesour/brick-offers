<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

class PerformanceAnalyzer extends AbstractBrowserAnalyzer
{
    // Core Web Vitals thresholds (in milliseconds, except CLS which is unitless)
    // Based on Google's guidelines: https://web.dev/vitals/
    private const LCP_GOOD = 2500;
    private const LCP_POOR = 4000;

    private const FCP_GOOD = 1800;
    private const FCP_POOR = 3000;

    private const CLS_GOOD = 0.1;
    private const CLS_POOR = 0.25;

    private const TTFB_GOOD = 800;
    private const TTFB_POOR = 1800;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::PERFORMANCE;
    }

    public function getPriority(): int
    {
        return 50; // After basic analyzers, before visual
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
        $rawData = [
            'url' => $url,
            'metrics' => [],
            'thresholds' => [
                'lcp' => ['good' => self::LCP_GOOD, 'poor' => self::LCP_POOR],
                'fcp' => ['good' => self::FCP_GOOD, 'poor' => self::FCP_POOR],
                'cls' => ['good' => self::CLS_GOOD, 'poor' => self::CLS_POOR],
                'ttfb' => ['good' => self::TTFB_GOOD, 'poor' => self::TTFB_POOR],
            ],
            'measuredAt' => date('c'),
        ];

        try {
            $metrics = $this->browser->measureWebVitals($url);
            $rawData['metrics'] = $metrics;

            // Analyze LCP (Largest Contentful Paint)
            if ($metrics['lcp'] !== null) {
                $lcpIssues = $this->analyzeLcp($metrics['lcp']);
                foreach ($lcpIssues as $issue) {
                    $issues[] = $issue;
                }
            }

            // Analyze FCP (First Contentful Paint)
            if ($metrics['fcp'] !== null) {
                $fcpIssues = $this->analyzeFcp($metrics['fcp']);
                foreach ($fcpIssues as $issue) {
                    $issues[] = $issue;
                }
            }

            // Analyze CLS (Cumulative Layout Shift)
            if ($metrics['cls'] !== null) {
                $clsIssues = $this->analyzeCls($metrics['cls']);
                foreach ($clsIssues as $issue) {
                    $issues[] = $issue;
                }
            }

            // Analyze TTFB (Time to First Byte)
            if ($metrics['ttfb'] !== null) {
                $ttfbIssues = $this->analyzeTtfb($metrics['ttfb']);
                foreach ($ttfbIssues as $issue) {
                    $issues[] = $issue;
                }
            }

            return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
        } catch (\Throwable $e) {
            $this->logger->error('Performance analysis failed: {error}', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return AnalyzerResult::failure(
                $this->getCategory(),
                'Performance analysis failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * @return array<Issue>
     */
    private function analyzeLcp(float $lcp): array
    {
        $issues = [];

        if ($lcp > self::LCP_POOR) {
            $issues[] = $this->createIssue('perf_lcp_poor', sprintf('LCP: %.0f ms', $lcp));
        } elseif ($lcp > self::LCP_GOOD) {
            $issues[] = $this->createIssue('perf_lcp_needs_improvement', sprintf('LCP: %.0f ms', $lcp));
        }

        return $issues;
    }

    /**
     * @return array<Issue>
     */
    private function analyzeFcp(float $fcp): array
    {
        $issues = [];

        if ($fcp > self::FCP_POOR) {
            $issues[] = $this->createIssue('perf_fcp_poor', sprintf('FCP: %.0f ms', $fcp));
        } elseif ($fcp > self::FCP_GOOD) {
            $issues[] = $this->createIssue('perf_fcp_needs_improvement', sprintf('FCP: %.0f ms', $fcp));
        }

        return $issues;
    }

    /**
     * @return array<Issue>
     */
    private function analyzeCls(float $cls): array
    {
        $issues = [];

        if ($cls > self::CLS_POOR) {
            $issues[] = $this->createIssue('perf_cls_poor', sprintf('CLS: %.3f', $cls));
        } elseif ($cls > self::CLS_GOOD) {
            $issues[] = $this->createIssue('perf_cls_needs_improvement', sprintf('CLS: %.3f', $cls));
        }

        return $issues;
    }

    /**
     * @return array<Issue>
     */
    private function analyzeTtfb(float $ttfb): array
    {
        $issues = [];

        if ($ttfb > self::TTFB_POOR) {
            $issues[] = $this->createIssue('perf_ttfb_poor', sprintf('TTFB: %.0f ms', $ttfb));
        } elseif ($ttfb > self::TTFB_GOOD) {
            $issues[] = $this->createIssue('perf_ttfb_needs_improvement', sprintf('TTFB: %.0f ms', $ttfb));
        }

        return $issues;
    }
}
