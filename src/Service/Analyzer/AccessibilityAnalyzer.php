<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\IssueCategory;
use App\Enum\IssueSeverity;

class AccessibilityAnalyzer extends AbstractBrowserAnalyzer
{
    // Axe-core violation ID to Czech description mapping
    private const VIOLATION_DESCRIPTIONS = [
        'color-contrast' => [
            'title' => 'Nedostatečný barevný kontrast',
            'impact' => 'Text je špatně čitelný, zejména pro lidi se zhoršeným zrakem.',
        ],
        'image-alt' => [
            'title' => 'Obrázky bez alt textu',
            'impact' => 'Čtečky obrazovky nemohou popsat obsah obrázků nevidomým uživatelům.',
        ],
        'label' => [
            'title' => 'Formulářové prvky bez popisků',
            'impact' => 'Uživatelé nevidí, co mají do formulářového pole zadat.',
        ],
        'link-name' => [
            'title' => 'Odkazy bez textu',
            'impact' => 'Čtečky obrazovky nedokážou popsat, kam odkaz vede.',
        ],
        'html-has-lang' => [
            'title' => 'Chybí atribut lang na HTML',
            'impact' => 'Čtečky obrazovky neví, v jakém jazyce má obsah číst.',
        ],
        'duplicate-id' => [
            'title' => 'Duplicitní ID atributy',
            'impact' => 'Může způsobit problémy s navigací pomocí klávesnice a čteček.',
        ],
        'button-name' => [
            'title' => 'Tlačítka bez názvu',
            'impact' => 'Čtečky obrazovky nedokážou popsat, co tlačítko dělá.',
        ],
        'document-title' => [
            'title' => 'Chybí title stránky',
            'impact' => 'Uživatelé nevidí název stránky v záložce prohlížeče.',
        ],
        'frame-title' => [
            'title' => 'Iframe bez title',
            'impact' => 'Čtečky nedokážou popsat obsah vloženého rámce.',
        ],
        'heading-order' => [
            'title' => 'Nesprávné pořadí nadpisů',
            'impact' => 'Struktura stránky není logická pro čtečky obrazovky.',
        ],
        'meta-viewport' => [
            'title' => 'Viewport blokuje zoom',
            'impact' => 'Uživatelé nemohou přiblížit obsah, což je problém pro slabozraké.',
        ],
        'list' => [
            'title' => 'Nesprávná struktura seznamů',
            'impact' => 'Čtečky obrazovky špatně interpretují seznam položek.',
        ],
    ];

    public function getCategory(): IssueCategory
    {
        return IssueCategory::ACCESSIBILITY;
    }

    public function getPriority(): int
    {
        return 80; // After visual
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
            'analyzedAt' => date('c'),
        ];

        try {
            $axeResults = $this->browser->runAxeAnalysis($url);

            $rawData['axeResults'] = [
                'violations' => $axeResults['violations'],
                'passCount' => $axeResults['passes'],
                'incompleteCount' => $axeResults['incomplete'],
            ];

            // Check for Axe-core error
            if (isset($axeResults['error']) && $axeResults['error'] !== null) {
                $this->logger->warning('Axe-core analysis returned error: {error}', [
                    'error' => $axeResults['error'],
                    'url' => $url,
                ]);
            }

            // Calculate summary
            $summary = [
                'critical' => 0,
                'serious' => 0,
                'moderate' => 0,
                'minor' => 0,
            ];

            foreach ($axeResults['violations'] as $violation) {
                $impact = $violation['impact'] ?? 'minor';
                if (isset($summary[$impact])) {
                    $summary[$impact]++;
                }
            }
            $rawData['summary'] = $summary;

            // Convert violations to issues
            foreach ($axeResults['violations'] as $violation) {
                $issue = $this->violationToIssue($violation);
                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }

            return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
        } catch (\Throwable $e) {
            $this->logger->error('Accessibility analysis failed: {error}', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return AnalyzerResult::failure(
                $this->getCategory(),
                'Accessibility analysis failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array{id: string, impact: string, description: string, help?: string, helpUrl?: string, nodes: array} $violation
     */
    private function violationToIssue(array $violation): ?Issue
    {
        $id = $violation['id'];
        $impact = $violation['impact'] ?? 'minor';
        $description = $violation['description'] ?? '';
        $help = $violation['help'] ?? '';
        $nodeCount = count($violation['nodes'] ?? []);

        // Build evidence from affected nodes
        $evidence = sprintf('Počet problémů: %d', $nodeCount);
        if ($nodeCount > 0 && !empty($violation['nodes'])) {
            $firstNode = $violation['nodes'][0];
            if (isset($firstNode['html'])) {
                $evidence .= "\nPříklad: " . substr($firstNode['html'], 0, 100);
            }
        }

        $issueCode = 'a11y_' . str_replace('-', '_', $id);

        // Check if issue code exists in registry
        if (IssueRegistry::has($issueCode)) {
            return $this->createIssue($issueCode, $evidence);
        }

        // Map Axe impact to IssueSeverity for dynamic issues
        $severity = match ($impact) {
            'critical', 'serious' => IssueSeverity::CRITICAL,
            'moderate' => IssueSeverity::RECOMMENDED,
            default => IssueSeverity::OPTIMIZATION,
        };

        // Get Czech description if available
        $czechInfo = self::VIOLATION_DESCRIPTIONS[$id] ?? null;
        $title = $czechInfo['title'] ?? $this->translateTitle($help);
        $impactText = $czechInfo['impact'] ?? $description;

        return $this->createCustomIssue(
            $severity,
            $issueCode,
            $title,
            $description,
            $evidence,
            $impactText
        );
    }

    /**
     * Simple translation of common Axe help messages.
     */
    private function translateTitle(string $help): string
    {
        $translations = [
            'Elements must have sufficient color contrast' => 'Nedostatečný barevný kontrast',
            'Images must have alternate text' => 'Obrázky bez alt textu',
            'Form elements must have labels' => 'Formulářové prvky bez popisků',
            'Links must have discernible text' => 'Odkazy bez textu',
            '<html> element must have a lang attribute' => 'Chybí atribut lang',
            'id attribute value must be unique' => 'Duplicitní ID atributy',
            'Buttons must have discernible text' => 'Tlačítka bez názvu',
            'Documents must have <title> element' => 'Chybí title stránky',
        ];

        return $translations[$help] ?? $help;
    }
}
