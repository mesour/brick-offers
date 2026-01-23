<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;

class DesignModernityAnalyzer extends AbstractBrowserAnalyzer
{
    private const SCORE_THRESHOLD_EXCELLENT = 15;
    private const SCORE_THRESHOLD_GOOD = 10;
    private const SCORE_THRESHOLD_FAIR = 5;

    public function getCategory(): IssueCategory
    {
        return IssueCategory::DESIGN_MODERNITY;
    }

    public function getPriority(): int
    {
        return 65;
    }

    public function getDescription(): string
    {
        return 'Hodnotí modernost designu: CSS Grid, Flexbox, proměnné, animace, efekty.';
    }

    public function analyze(Lead $lead): AnalyzerResult
    {
        $url = $lead->getUrl();
        if ($url === null) {
            return AnalyzerResult::failure($this->getCategory(), 'Lead URL is null');
        }

        $browserCheck = $this->ensureBrowserAvailable();
        if ($browserCheck !== null) {
            return $browserCheck;
        }

        $issues = [];

        try {
            $data = $this->browser->analyzeDesignModernity($url);

            $modernScore = $data['summary']['modernScore'] ?? 0;
            $outdatedScore = $data['summary']['outdatedScore'] ?? 0;
            $finalScore = $modernScore + $outdatedScore;

            $rawData = [
                'url' => $url,
                'analyzedAt' => date('c'),
                'modern' => $data['modern'] ?? [],
                'outdated' => $data['outdated'] ?? [],
                'stylesheetInfo' => $data['stylesheetInfo'] ?? [],
                'scores' => [
                    'modern' => $modernScore,
                    'outdated' => $outdatedScore,
                    'final' => $finalScore,
                ],
                'modernityLevel' => $this->calculateLevel($finalScore),
                'totalElements' => $data['summary']['totalElements'] ?? 0,
            ];

            // Generate issues based on findings
            $issues = $this->generateIssues($data, $finalScore);

            $this->logger->info('Design modernity analysis completed', [
                'url' => $url,
                'finalScore' => $finalScore,
                'level' => $rawData['modernityLevel'],
                'issueCount' => count($issues),
            ]);

            return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
        } catch (\Throwable $e) {
            $this->logger->error('Design modernity analysis failed: {error}', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return AnalyzerResult::failure(
                $this->getCategory(),
                'Design modernity analysis failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * @return array<Issue>
     */
    private function generateIssues(array $data, int $finalScore): array
    {
        $issues = [];
        $modern = $data['modern'] ?? [];
        $outdated = $data['outdated'] ?? [];
        $stylesheetInfo = $data['stylesheetInfo'] ?? [];

        // Very outdated design
        if ($finalScore < 3) {
            $issues[] = $this->createIssue('design_very_outdated', sprintf('Skóre modernity: %d/23', max(0, $finalScore)));
        } elseif ($finalScore < self::SCORE_THRESHOLD_FAIR) {
            $issues[] = $this->createIssue('design_outdated', sprintf('Skóre modernity: %d/23', $finalScore));
        }

        // No modern layout (no flex, no grid)
        $hasModernLayout = ($modern['grid'] ?? 0) > 0 || ($modern['flex'] ?? 0) > 0;
        if (!$hasModernLayout) {
            $issues[] = $this->createIssue('design_no_modern_layout', 'Nedetekován display: grid ani display: flex');
        }

        // Float-based layout without modern alternatives
        $floatCount = $outdated['float'] ?? 0;
        if ($floatCount > 10 && !$hasModernLayout) {
            $issues[] = $this->createIssue('design_float_layout', sprintf('Nalezeno %d elementů s float: left/right', $floatCount));
        }

        // Table-based layout
        $tableDisplayCount = $outdated['tableDisplay'] ?? 0;
        if ($tableDisplayCount > 5) {
            $issues[] = $this->createIssue('design_table_display', sprintf('Nalezeno %d elementů s display: table*', $tableDisplayCount));
        }

        // No CSS variables
        $cssVariables = $modern['cssVariables'] ?? 0;
        $cssVariableDefinitions = $stylesheetInfo['cssVariableDefinitions'] ?? 0;
        if ($cssVariables === 0 && $cssVariableDefinitions === 0) {
            $issues[] = $this->createIssue('design_no_css_variables', 'Nedetekováno var(--) ani definice --proměnných');
        }

        // No gradients
        $gradients = $modern['gradients'] ?? 0;
        $gradientRules = $stylesheetInfo['gradientRules'] ?? 0;
        if ($gradients === 0 && $gradientRules === 0 && $finalScore < self::SCORE_THRESHOLD_GOOD) {
            $issues[] = $this->createIssue('design_no_gradients', 'Nedetekováno linear-gradient, radial-gradient ani conic-gradient');
        }

        // No transitions or animations
        $transitions = $modern['transitions'] ?? 0;
        $animations = $modern['animations'] ?? 0;
        if ($transitions === 0 && $animations === 0) {
            $issues[] = $this->createIssue('design_no_animations', 'Nedetekováno transition ani animation');
        }

        // No visual effects (shadows, border-radius)
        $boxShadow = $modern['boxShadow'] ?? 0;
        $borderRadius = $modern['borderRadius'] ?? 0;
        if ($boxShadow === 0 && $borderRadius < 3 && $finalScore < self::SCORE_THRESHOLD_GOOD) {
            $issues[] = $this->createIssue('design_no_visual_effects', sprintf('box-shadow: %d, border-radius: %d', $boxShadow, $borderRadius));
        }

        // Excessive !important
        $importantCount = $stylesheetInfo['importantCount'] ?? 0;
        if ($importantCount > 20) {
            $issues[] = $this->createIssue('design_excessive_important', sprintf('Nalezeno %d výskytů !important', $importantCount));
        }

        return $issues;
    }

    private function calculateLevel(int $score): string
    {
        if ($score >= self::SCORE_THRESHOLD_EXCELLENT) {
            return 'excellent';
        }

        if ($score >= self::SCORE_THRESHOLD_GOOD) {
            return 'good';
        }

        if ($score >= self::SCORE_THRESHOLD_FAIR) {
            return 'fair';
        }

        return 'poor';
    }
}
