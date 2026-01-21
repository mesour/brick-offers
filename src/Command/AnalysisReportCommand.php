<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Analysis;
use App\Entity\AnalysisResult;
use App\Enum\IssueCategory;
use App\Enum\IssueSeverity;
use App\Repository\AnalysisRepository;
use App\Repository\LeadRepository;
use App\Service\Analyzer\Issue;
use App\Service\Analyzer\IssueRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:analysis:report',
    description: 'Generate a markdown report for an analysis',
)]
class AnalysisReportCommand extends Command
{
    public function __construct(
        private readonly LeadRepository $leadRepository,
        private readonly AnalysisRepository $analysisRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'lead-id',
                'l',
                InputOption::VALUE_REQUIRED,
                'Generate report for latest analysis of this lead'
            )
            ->addOption(
                'analysis-id',
                'a',
                InputOption::VALUE_REQUIRED,
                'Generate report for specific analysis'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file path (default: stdout)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $leadId = $input->getOption('lead-id');
        $analysisId = $input->getOption('analysis-id');
        $outputFile = $input->getOption('output');

        if ($leadId === null && $analysisId === null) {
            $io->error('You must provide either --lead-id or --analysis-id');

            return Command::FAILURE;
        }

        // Get analysis
        $analysis = $this->getAnalysis($leadId, $analysisId);
        if ($analysis === null) {
            $io->error('Analysis not found');

            return Command::FAILURE;
        }

        // Generate report
        $report = $this->generateReport($analysis);

        // Output
        if ($outputFile !== null) {
            file_put_contents($outputFile, $report);
            $io->success("Report saved to {$outputFile}");
        } else {
            $output->writeln($report);
        }

        return Command::SUCCESS;
    }

    private function getAnalysis(?string $leadId, ?string $analysisId): ?Analysis
    {
        if ($analysisId !== null) {
            try {
                $uuid = Uuid::fromString($analysisId);

                return $this->analysisRepository->find($uuid);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        if ($leadId !== null) {
            try {
                $uuid = Uuid::fromString($leadId);
                $lead = $this->leadRepository->find($uuid);

                if ($lead === null) {
                    return null;
                }

                return $this->analysisRepository->findLatestByLead($lead);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return null;
    }

    private function generateReport(Analysis $analysis): string
    {
        $lead = $analysis->getLead();
        $domain = $lead?->getDomain() ?? 'Unknown';
        $url = $lead?->getUrl() ?? 'Unknown';

        $lines = [];
        $lines[] = "# Analýza webu {$domain}";
        $lines[] = '';
        $lines[] = "**URL:** {$url}";
        $lines[] = sprintf('**Datum:** %s', $analysis->getCreatedAt()?->format('Y-m-d H:i:s') ?? 'N/A');
        $lines[] = sprintf('**Celkové skóre:** %d/100', $analysis->getTotalScore());
        $lines[] = sprintf('**Stav:** %s', $this->getStatusLabel($analysis));
        $lines[] = '';

        // Summary table
        $lines[] = '## Shrnutí';
        $lines[] = '';
        $lines[] = '| Kategorie | Skóre | Problémů |';
        $lines[] = '|-----------|-------|----------|';

        $categoryStats = $this->getCategoryStats($analysis);
        foreach ($categoryStats as $category => $stats) {
            $lines[] = sprintf('| %s | %d | %d |', $this->getCategoryLabel($category), $stats['score'], $stats['issues']);
        }
        $lines[] = '';

        // Issues by severity
        $allIssues = $this->collectAllIssues($analysis);

        // Critical issues
        $criticalIssues = array_filter($allIssues, fn (Issue $i) => $i->severity === IssueSeverity::CRITICAL);
        if (count($criticalIssues) > 0) {
            $lines[] = '## Kritické problémy';
            $lines[] = '';
            foreach ($criticalIssues as $issue) {
                $lines = array_merge($lines, $this->formatIssue($issue, 'red'));
            }
        }

        // Recommended issues
        $recommendedIssues = array_filter($allIssues, fn (Issue $i) => $i->severity === IssueSeverity::RECOMMENDED);
        if (count($recommendedIssues) > 0) {
            $lines[] = '## Doporučení';
            $lines[] = '';
            foreach ($recommendedIssues as $issue) {
                $lines = array_merge($lines, $this->formatIssue($issue, 'yellow'));
            }
        }

        // Optimization issues
        $optimizationIssues = array_filter($allIssues, fn (Issue $i) => $i->severity === IssueSeverity::OPTIMIZATION);
        if (count($optimizationIssues) > 0) {
            $lines[] = '## Optimalizace';
            $lines[] = '';
            foreach ($optimizationIssues as $issue) {
                $lines = array_merge($lines, $this->formatIssue($issue, 'white'));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, array{score: int, issues: int}>
     */
    private function getCategoryStats(Analysis $analysis): array
    {
        $stats = [];

        foreach ($analysis->getResults() as $result) {
            $category = $result->getCategory()?->value ?? 'unknown';
            $stats[$category] = [
                'score' => $result->getScore() ?? 0,
                'issues' => count($result->getIssues() ?? []),
            ];
        }

        return $stats;
    }

    /**
     * @return array<Issue>
     */
    private function collectAllIssues(Analysis $analysis): array
    {
        $issues = [];

        foreach ($analysis->getResults() as $result) {
            $issuesData = $result->getIssues() ?? [];
            foreach ($issuesData as $issueData) {
                $issues[] = Issue::fromStorageArray($issueData);
            }
        }

        // Sort by severity (critical first)
        usort($issues, function (Issue $a, Issue $b) {
            $severityOrder = [
                IssueSeverity::CRITICAL->value => 0,
                IssueSeverity::RECOMMENDED->value => 1,
                IssueSeverity::OPTIMIZATION->value => 2,
            ];

            return $severityOrder[$a->severity->value] <=> $severityOrder[$b->severity->value];
        });

        return $issues;
    }

    /**
     * @return array<string>
     */
    private function formatIssue(Issue $issue, string $color): array
    {
        $emoji = match ($color) {
            'red' => "\u{1F534}",
            'yellow' => "\u{1F7E1}",
            'white' => "\u{26AA}",
            default => '',
        };

        $lines = [];
        $lines[] = "### {$emoji} {$issue->title}";
        $lines[] = sprintf('**Kategorie:** %s | **Závažnost:** %s', $this->getCategoryLabel($issue->category->value), $this->getSeverityLabel($issue->severity));
        $lines[] = '';
        $lines[] = $issue->description;
        $lines[] = '';

        if ($issue->evidence !== null && $issue->evidence !== '') {
            $lines[] = "**Evidence:** {$issue->evidence}";
            $lines[] = '';
        }

        if ($issue->impact !== null && $issue->impact !== '') {
            $lines[] = "**Dopad:** {$issue->impact}";
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';

        return $lines;
    }

    private function getStatusLabel(Analysis $analysis): string
    {
        $score = $analysis->getTotalScore();

        if ($score >= 80) {
            return 'Vynikající';
        }

        if ($score >= 60) {
            return 'Dobrý';
        }

        if ($score >= 40) {
            return 'Doporučeno zlepšení';
        }

        return 'Nutná náprava';
    }

    private function getCategoryLabel(string $category): string
    {
        return match ($category) {
            'http' => 'HTTP/SSL',
            'security' => 'Bezpečnost',
            'seo' => 'SEO',
            'libraries' => 'Knihovny',
            'performance' => 'Výkon',
            'responsiveness' => 'Responzivita',
            'visual' => 'Vizuální',
            'accessibility' => 'Přístupnost',
            'eshop_detection' => 'E-shop detekce',
            'outdated_code' => 'Zastaralý kód',
            'design_modernity' => 'Modernost designu',
            default => ucfirst($category),
        };
    }

    private function getSeverityLabel(IssueSeverity $severity): string
    {
        return match ($severity) {
            IssueSeverity::CRITICAL => 'Kritická',
            IssueSeverity::RECOMMENDED => 'Doporučená',
            IssueSeverity::OPTIMIZATION => 'Optimalizace',
        };
    }
}
