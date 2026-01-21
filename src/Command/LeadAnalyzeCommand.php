<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Analysis;
use App\Entity\AnalysisResult;
use App\Entity\Lead;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use App\Enum\IssueCategory;
use App\Enum\LeadStatus;
use App\Repository\AnalysisRepository;
use App\Repository\LeadRepository;
use App\Service\Analyzer\Issue;
use App\Service\Analyzer\LeadAnalyzerInterface;
use App\Service\Scoring\ScoringServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:lead:analyze',
    description: 'Analyze leads for technical issues',
)]
class LeadAnalyzeCommand extends Command
{
    /** @var array<LeadAnalyzerInterface> */
    private array $analyzers = [];

    /**
     * @param iterable<LeadAnalyzerInterface> $analyzers
     */
    public function __construct(
        #[TaggedIterator('app.lead_analyzer')]
        iterable $analyzers,
        private readonly LeadRepository $leadRepository,
        private readonly AnalysisRepository $analysisRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ScoringServiceInterface $scoringService,
    ) {
        parent::__construct();

        // Sort analyzers by priority
        $analyzerArray = iterator_to_array($analyzers);
        usort($analyzerArray, fn (LeadAnalyzerInterface $a, LeadAnalyzerInterface $b) => $a->getPriority() <=> $b->getPriority());
        $this->analyzers = $analyzerArray;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of leads to analyze',
                50
            )
            ->addOption(
                'offset',
                'o',
                InputOption::VALUE_REQUIRED,
                'Offset for pagination',
                0
            )
            ->addOption(
                'lead-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Analyze specific lead by UUID'
            )
            ->addOption(
                'category',
                'c',
                InputOption::VALUE_REQUIRED,
                'Run only specific analyzer category (http, security, seo, libraries)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate without saving to database'
            )
            ->addOption(
                'reanalyze',
                null,
                InputOption::VALUE_NONE,
                'Re-analyze leads that already have an analysis'
            )
            ->addOption(
                'verbose-issues',
                null,
                InputOption::VALUE_NONE,
                'Show detailed issue information'
            )
            ->addOption(
                'industry',
                'i',
                InputOption::VALUE_REQUIRED,
                'Industry type for analysis (webdesign, eshop, real_estate, automobile, restaurant, medical, legal, finance, education, other)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');
        $offset = (int) $input->getOption('offset');
        $leadId = $input->getOption('lead-id');
        $categoryFilter = $input->getOption('category');
        $dryRun = $input->getOption('dry-run');
        $reanalyze = $input->getOption('reanalyze');
        $verboseIssues = $input->getOption('verbose-issues');
        $industryFilter = $input->getOption('industry');

        // Parse industry option
        $industry = null;
        if ($industryFilter !== null) {
            $industry = Industry::tryFrom($industryFilter);
            if ($industry === null) {
                $io->error(sprintf('Invalid industry "%s". Available: %s', $industryFilter, implode(', ', array_map(
                    fn (Industry $i) => $i->value,
                    Industry::cases()
                ))));

                return Command::FAILURE;
            }
        }

        $io->title('Lead Analysis');

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be saved');
        }

        if ($industry !== null) {
            $io->note(sprintf('Industry filter: %s', $industry->getLabel()));
        }

        // Filter analyzers by category if specified
        $analyzersToRun = $this->analyzers;
        if ($categoryFilter !== null) {
            $category = IssueCategory::tryFrom($categoryFilter);
            if ($category === null) {
                $io->error(sprintf('Invalid category "%s". Available: %s', $categoryFilter, implode(', ', array_map(
                    fn (IssueCategory $c) => $c->value,
                    IssueCategory::cases()
                ))));

                return Command::FAILURE;
            }
            $analyzersToRun = array_filter($this->analyzers, fn (LeadAnalyzerInterface $a) => $a->getCategory() === $category);
        }

        // Filter analyzers by industry - keep universal analyzers and industry-specific ones
        if ($industry !== null) {
            $analyzersToRun = array_filter($analyzersToRun, function (LeadAnalyzerInterface $analyzer) use ($industry): bool {
                $category = $analyzer->getCategory();

                // Universal analyzers run for all industries
                if ($category->isUniversal()) {
                    return true;
                }

                // Industry-specific analyzers only run for their industry
                return $category->getIndustry() === $industry;
            });
        } else {
            // Without industry filter, only run universal analyzers
            $analyzersToRun = array_filter($analyzersToRun, fn (LeadAnalyzerInterface $a) => $a->getCategory()->isUniversal());
        }

        // Show available analyzers
        $io->section('Analyzers to run');
        $analyzerInfo = [];
        foreach ($analyzersToRun as $analyzer) {
            $analyzerInfo[] = [
                $analyzer->getCategory()->value,
                $analyzer::class,
                $analyzer->getPriority(),
            ];
        }
        $io->table(['Category', 'Class', 'Priority'], $analyzerInfo);

        // Get leads to analyze
        $leads = $this->getLeadsToAnalyze($leadId, $limit, $offset, $reanalyze);

        if (empty($leads)) {
            $io->warning('No leads to analyze');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Analyzing %d lead(s)', count($leads)));

        // Statistics
        $stats = [
            'analyzed' => 0,
            'failed' => 0,
            'totalIssues' => 0,
            'criticalIssues' => 0,
            'statusCounts' => [],
            'categoryStats' => [],
            'improved' => 0,
            'worsened' => 0,
            'newAnalyses' => 0,
        ];

        $io->progressStart(count($leads));

        foreach ($leads as $lead) {
            try {
                $result = $this->analyzeLead($lead, $analyzersToRun, $dryRun, $industry);

                $stats['analyzed']++;
                $stats['totalIssues'] += $result['issueCount'];
                $stats['criticalIssues'] += $result['criticalCount'];

                $status = $result['status']->value;
                $stats['statusCounts'][$status] = ($stats['statusCounts'][$status] ?? 0) + 1;

                // Track delta statistics
                if ($result['isFirstAnalysis']) {
                    $stats['newAnalyses']++;
                } elseif ($result['isImproved']) {
                    $stats['improved']++;
                } elseif ($result['scoreDelta'] !== null && $result['scoreDelta'] < 0) {
                    $stats['worsened']++;
                }

                // Track per-category stats
                foreach ($result['categoryResults'] as $category => $catResult) {
                    if (!isset($stats['categoryStats'][$category])) {
                        $stats['categoryStats'][$category] = ['issues' => 0, 'score' => 0, 'count' => 0];
                    }
                    $stats['categoryStats'][$category]['issues'] += $catResult['issueCount'];
                    $stats['categoryStats'][$category]['score'] += $catResult['score'];
                    $stats['categoryStats'][$category]['count']++;
                }

                if ($verboseIssues && $result['issueCount'] > 0) {
                    $io->progressAdvance();
                    $io->newLine();
                    $this->displayIssues($io, $lead, $result['issues'], $result['scoreDelta']);
                }
            } catch (\Throwable $e) {
                $stats['failed']++;

                if ($output->isVerbose()) {
                    $io->progressAdvance();
                    $io->newLine();
                    $io->error(sprintf('Failed to analyze %s: %s', $lead->getDomain(), $e->getMessage()));
                }
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Display statistics
        $this->displayStatistics($io, $stats);

        return Command::SUCCESS;
    }

    /**
     * @return array<Lead>
     */
    private function getLeadsToAnalyze(?string $leadId, int $limit, int $offset, bool $reanalyze): array
    {
        if ($leadId !== null) {
            try {
                $uuid = Uuid::fromString($leadId);
                $lead = $this->leadRepository->find($uuid);

                if ($lead === null) {
                    return [];
                }

                // Check if already analyzed
                if (!$reanalyze) {
                    $existingAnalysis = $this->analysisRepository->findLatestByLead($lead);
                    if ($existingAnalysis !== null && $existingAnalysis->getStatus() === AnalysisStatus::COMPLETED) {
                        return [];
                    }
                }

                return [$lead];
            } catch (\InvalidArgumentException) {
                return [];
            }
        }

        // Find NEW leads
        $qb = $this->leadRepository->createQueryBuilder('l')
            ->where('l.status = :status')
            ->setParameter('status', LeadStatus::NEW)
            ->orderBy('l.priority', 'DESC')
            ->addOrderBy('l.createdAt', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array<LeadAnalyzerInterface> $analyzers
     * @return array{issueCount: int, criticalCount: int, status: LeadStatus, issues: array<Issue>, categoryResults: array<string, array{issueCount: int, score: int}>, isFirstAnalysis: bool, isImproved: bool, scoreDelta: ?int}
     */
    private function analyzeLead(Lead $lead, array $analyzers, bool $dryRun, ?Industry $industry): array
    {
        // Get previous analysis for delta calculation
        $previousAnalysis = $this->analysisRepository->findLatestByLead($lead);
        $isFirstAnalysis = $previousAnalysis === null;

        // Calculate sequence number
        $sequenceNumber = 1;
        if ($previousAnalysis !== null) {
            $sequenceNumber = $previousAnalysis->getSequenceNumber() + 1;
        }

        // Create new analysis
        $analysis = new Analysis();
        $analysis->setLead($lead);
        $analysis->setIndustry($industry ?? $lead->getIndustry());
        $analysis->setSequenceNumber($sequenceNumber);
        $analysis->setPreviousAnalysis($previousAnalysis);
        $analysis->markAsRunning();

        if (!$dryRun) {
            $this->entityManager->persist($analysis);
            $this->entityManager->flush();
        }

        $allIssues = [];
        $categoryResults = [];

        // Run each analyzer
        foreach ($analyzers as $analyzer) {
            $category = $analyzer->getCategory();

            // Create result for this analyzer
            $analysisResult = new AnalysisResult();
            $analysisResult->setCategory($category);
            $analysisResult->markAsRunning();
            $analysis->addResult($analysisResult);

            try {
                $result = $analyzer->analyze($lead);

                if ($result->success) {
                    // Convert issues to arrays for storage (only code + evidence)
                    $issuesArray = array_map(fn (Issue $issue) => $issue->toStorageArray(), $result->issues);

                    $analysisResult->setRawData($result->rawData);
                    $analysisResult->setIssues($issuesArray);
                    $analysisResult->setScore($result->getScore());
                    $analysisResult->markAsCompleted();

                    foreach ($result->issues as $issue) {
                        $allIssues[] = $issue;
                    }

                    $categoryResults[$category->value] = [
                        'issueCount' => count($result->issues),
                        'score' => $result->getScore(),
                    ];

                    // Handle e-shop detection result
                    if ($category === IssueCategory::ESHOP_DETECTION && isset($result->rawData['isEshop'])) {
                        $analysis->setIsEshop((bool) $result->rawData['isEshop']);
                    }
                } else {
                    $analysisResult->markAsFailed($result->errorMessage ?? 'Unknown error');
                    $categoryResults[$category->value] = [
                        'issueCount' => 0,
                        'score' => 0,
                    ];
                }
            } catch (\Throwable $e) {
                $analysisResult->markAsFailed($e->getMessage());
                $categoryResults[$category->value] = [
                    'issueCount' => 0,
                    'score' => 0,
                ];
            }
        }

        // Calculate total score and finalize analysis
        $analysis->calculateTotalScore();

        if ($analysis->getFailedResultsCount() === count($analyzers)) {
            $analysis->markAsFailed();
        } else {
            $analysis->markAsCompleted();
        }

        // Calculate delta compared to previous analysis
        $analysis->calculateDelta();

        // Determine lead status
        $newStatus = $this->scoringService->determineLeadStatus($analysis);

        // Update lead
        $lead->setStatus($newStatus);
        $lead->setAnalyzedAt(new \DateTimeImmutable());
        $lead->setLastAnalyzedAt(new \DateTimeImmutable());
        $lead->setLatestAnalysis($analysis);
        $lead->incrementAnalysisCount();

        // Set industry on lead if provided and not already set
        if ($industry !== null && $lead->getIndustry() === null) {
            $lead->setIndustry($industry);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return [
            'issueCount' => count($allIssues),
            'criticalCount' => $analysis->getCriticalIssueCount(),
            'status' => $newStatus,
            'issues' => $allIssues,
            'categoryResults' => $categoryResults,
            'isFirstAnalysis' => $isFirstAnalysis,
            'isImproved' => $analysis->isImproved(),
            'scoreDelta' => $analysis->getScoreDelta(),
        ];
    }

    /**
     * @param array<Issue> $issues
     */
    private function displayIssues(SymfonyStyle $io, Lead $lead, array $issues, ?int $scoreDelta = null): void
    {
        $deltaInfo = '';
        if ($scoreDelta !== null) {
            $deltaColor = $scoreDelta >= 0 ? 'green' : 'red';
            $deltaSign = $scoreDelta >= 0 ? '+' : '';
            $deltaInfo = sprintf(' [<%s>%s%d</>]', $deltaColor, $deltaSign, $scoreDelta);
        }

        $io->text(sprintf('<info>%s</info> - %d issue(s)%s:', $lead->getDomain(), count($issues), $deltaInfo));

        $rows = [];
        foreach ($issues as $issue) {
            $severityColor = match ($issue->severity->value) {
                'critical' => 'red',
                'recommended' => 'yellow',
                default => 'gray',
            };

            $rows[] = [
                sprintf('<%s>%s</>', $severityColor, strtoupper($issue->severity->value)),
                $issue->category->value,
                $issue->title,
            ];
        }

        $io->table(['Severity', 'Category', 'Title'], $rows);
    }

    /**
     * @param array{analyzed: int, failed: int, totalIssues: int, criticalIssues: int, statusCounts: array<string, int>, categoryStats: array<string, array{issues: int, score: int, count: int}>, improved: int, worsened: int, newAnalyses: int} $stats
     */
    private function displayStatistics(SymfonyStyle $io, array $stats): void
    {
        $io->section('Statistics');

        $io->definitionList(
            ['Analyzed' => $stats['analyzed']],
            ['Failed' => $stats['failed']],
            ['Total Issues' => $stats['totalIssues']],
            ['Critical Issues' => $stats['criticalIssues']],
        );

        // Show delta statistics if there are re-analyses
        $reanalyzed = $stats['analyzed'] - $stats['newAnalyses'];
        if ($reanalyzed > 0) {
            $io->section('Delta Statistics');
            $io->definitionList(
                ['New Analyses' => $stats['newAnalyses']],
                ['Re-Analyses' => $reanalyzed],
                ['Improved' => sprintf('<fg=green>%d</>', $stats['improved'])],
                ['Worsened' => sprintf('<fg=red>%d</>', $stats['worsened'])],
                ['Unchanged' => $reanalyzed - $stats['improved'] - $stats['worsened']],
            );
        }

        if (!empty($stats['categoryStats'])) {
            $io->section('Category Statistics');
            $categoryRows = [];
            foreach ($stats['categoryStats'] as $category => $catStats) {
                $avgScore = $catStats['count'] > 0 ? round($catStats['score'] / $catStats['count'], 1) : 0;
                $categoryRows[] = [$category, $catStats['issues'], $catStats['score'], $avgScore];
            }
            $io->table(['Category', 'Total Issues', 'Total Score', 'Avg Score'], $categoryRows);
        }

        if (!empty($stats['statusCounts'])) {
            $io->section('Status Distribution');
            $statusRows = [];
            foreach ($stats['statusCounts'] as $status => $count) {
                $statusRows[] = [$status, $count];
            }
            $io->table(['Status', 'Count'], $statusRows);
        }

        if ($stats['failed'] > 0) {
            $io->warning(sprintf('%d lead(s) failed to analyze', $stats['failed']));
        } else {
            $io->success('Analysis complete');
        }
    }
}
