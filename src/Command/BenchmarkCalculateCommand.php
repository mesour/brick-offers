<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Analysis;
use App\Entity\User;
use App\Enum\Industry;
use App\Repository\AnalysisRepository;
use App\Repository\IndustryBenchmarkRepository;
use App\Repository\UserRepository;
use App\Service\Benchmark\BenchmarkCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:benchmark:calculate',
    description: 'Calculate industry benchmarks from analysis data',
)]
class BenchmarkCalculateCommand extends Command
{
    public function __construct(
        private readonly BenchmarkCalculator $benchmarkCalculator,
        private readonly IndustryBenchmarkRepository $benchmarkRepository,
        private readonly AnalysisRepository $analysisRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'user',
                null,
                InputOption::VALUE_REQUIRED,
                'User code (required) - calculate benchmarks for this user'
            )
            ->addOption(
                'industry',
                'i',
                InputOption::VALUE_REQUIRED,
                'Calculate benchmark for specific industry only'
            )
            ->addOption(
                'compare',
                null,
                InputOption::VALUE_REQUIRED,
                'Compare specific analysis (UUID) against its industry benchmark'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate without saving to database'
            )
            ->addOption(
                'show-stats',
                null,
                InputOption::VALUE_NONE,
                'Show current benchmark statistics'
            )
            ->addOption(
                'show-top-issues',
                null,
                InputOption::VALUE_NONE,
                'Show top issues per industry'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userCode = $input->getOption('user');
        $industryFilter = $input->getOption('industry');
        $compareAnalysisId = $input->getOption('compare');
        $dryRun = $input->getOption('dry-run');
        $showStats = $input->getOption('show-stats');
        $showTopIssues = $input->getOption('show-top-issues');

        // Get user (required)
        if ($userCode === null) {
            $io->error('User is required. Use --user=<code>');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findByCode($userCode);
        if ($user === null) {
            $io->error(sprintf('User with code "%s" not found', $userCode));

            return Command::FAILURE;
        }

        $io->title('Industry Benchmark Calculator');
        $io->note(sprintf('Using user: %s (%s)', $user->getName(), $user->getCode()));

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be saved');
        }

        // Show statistics
        if ($showStats) {
            $this->displayStatistics($io, $user, $showTopIssues);

            return Command::SUCCESS;
        }

        // Compare analysis against benchmark
        if ($compareAnalysisId !== null) {
            return $this->executeComparison($io, $compareAnalysisId, $user);
        }

        // Parse industry filter
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

        // Calculate benchmarks
        if ($industry !== null) {
            return $this->executeForIndustry($io, $industry, $user, $dryRun);
        }

        return $this->executeForAllIndustries($io, $user, $dryRun);
    }

    private function executeForIndustry(SymfonyStyle $io, Industry $industry, User $user, bool $dryRun): int
    {
        $io->section(sprintf('Calculating benchmark for: %s', $industry->getLabel()));

        if ($dryRun) {
            // Show what would be calculated
            $count = $this->analysisRepository->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->innerJoin('a.lead', 'l')
                ->where('a.industry = :industry')
                ->andWhere('l.user = :user')
                ->setParameter('industry', $industry)
                ->setParameter('user', $user)
                ->getQuery()
                ->getSingleScalarResult();

            $io->note(sprintf('Would calculate benchmark from %d analyses (dry-run)', $count));

            return Command::SUCCESS;
        }

        $benchmark = $this->benchmarkCalculator->calculateForIndustry($industry, $user);

        if ($benchmark === null) {
            $io->warning('No analyses found for this industry');

            return Command::SUCCESS;
        }

        $this->entityManager->flush();

        $io->success('Benchmark calculated successfully');
        $this->displayBenchmarkDetails($io, $benchmark);

        return Command::SUCCESS;
    }

    private function executeForAllIndustries(SymfonyStyle $io, User $user, bool $dryRun): int
    {
        $io->section('Calculating benchmarks for all industries');

        if ($dryRun) {
            // Count analyses per industry for this user
            $counts = $this->analysisRepository->countByIndustryForUser($user);

            $io->table(
                ['Industry', 'Analyses'],
                array_map(fn ($k, $v) => [$k, $v], array_keys($counts), $counts)
            );

            $io->note('Would calculate benchmarks for industries with analyses (dry-run)');

            return Command::SUCCESS;
        }

        $stats = $this->benchmarkCalculator->calculateForAllIndustries($user);

        $io->section('Results');
        $io->definitionList(
            ['Created' => $stats['created']],
            ['Updated' => $stats['updated']],
            ['Skipped (no data)' => $stats['skipped']],
        );

        $io->success('Benchmark calculation complete');

        return Command::SUCCESS;
    }

    private function executeComparison(SymfonyStyle $io, string $analysisId, User $user): int
    {
        try {
            $uuid = Uuid::fromString($analysisId);
            $analysis = $this->analysisRepository->find($uuid);

            if ($analysis === null) {
                $io->error(sprintf('Analysis with ID "%s" not found', $analysisId));

                return Command::FAILURE;
            }

            // Verify analysis belongs to user
            if ($analysis->getLead()?->getUser() !== $user) {
                $io->error('Analysis does not belong to this user');

                return Command::FAILURE;
            }

            $io->section(sprintf('Comparing analysis for: %s', $analysis->getLead()?->getDomain() ?? 'Unknown'));

            $comparison = $this->benchmarkCalculator->compareWithBenchmark($analysis, $user);

            if ($comparison['ranking'] === 'unknown') {
                $io->warning('Analysis has no industry assigned');

                return Command::SUCCESS;
            }

            if ($comparison['ranking'] === 'no_benchmark') {
                $io->warning('No benchmark available for this industry');

                return Command::SUCCESS;
            }

            $io->section('Comparison Results');

            $rankingColor = match ($comparison['ranking']) {
                'top10' => 'green',
                'top25' => 'cyan',
                'above_average' => 'white',
                'below_average' => 'yellow',
                'bottom25' => 'red',
                default => 'white',
            };

            $io->definitionList(
                ['Ranking' => sprintf('<%s>%s</>', $rankingColor, strtoupper($comparison['ranking']))],
                ['Percentile' => $comparison['percentile'] !== null ? sprintf('%.1f%%', $comparison['percentile']) : 'N/A'],
            );

            $io->section('Score Comparison');
            $data = $comparison['comparison'];
            $diffColor = $data['diffFromAvg'] >= 0 ? 'green' : 'red';

            $io->table(
                ['Metric', 'This Analysis', 'Industry Average', 'Difference'],
                [
                    ['Score', $data['score'], round($data['industryAvg'], 1), sprintf('<%s>%+.1f</>', $diffColor, $data['diffFromAvg'])],
                    ['Issue Count', $data['issueCount'], $data['industryAvgIssues'], ''],
                ]
            );

            $io->note(sprintf('Based on %d analyses in this industry', $data['sampleSize']));

            return Command::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $io->error(sprintf('Invalid UUID: %s', $analysisId));

            return Command::FAILURE;
        }
    }

    private function displayStatistics(SymfonyStyle $io, User $user, bool $showTopIssues): void
    {
        $io->section('Current Benchmark Statistics');

        $benchmarks = $this->benchmarkRepository->findLatestForAllIndustriesForUser($user);

        if (empty($benchmarks)) {
            $io->warning('No benchmarks found. Run the command without --show-stats to calculate them.');

            return;
        }

        $rows = [];
        foreach ($benchmarks as $benchmark) {
            $rows[] = [
                $benchmark->getIndustry()?->getLabel() ?? 'N/A',
                $benchmark->getSampleSize(),
                round($benchmark->getAvgScore(), 1),
                round($benchmark->getMedianScore(), 1),
                round($benchmark->getPercentiles()['p25'] ?? 0, 1),
                round($benchmark->getPercentiles()['p75'] ?? 0, 1),
                $benchmark->getPeriodStart()->format('Y-m-d'),
            ];
        }

        $io->table(
            ['Industry', 'Samples', 'Avg Score', 'Median', 'P25', 'P75', 'Period'],
            $rows
        );

        if ($showTopIssues) {
            foreach ($benchmarks as $benchmark) {
                $topIssues = $benchmark->getTopIssues();
                if (empty($topIssues)) {
                    continue;
                }

                $io->section(sprintf('Top Issues: %s', $benchmark->getIndustry()?->getLabel() ?? 'N/A'));

                $issueRows = [];
                foreach (array_slice($topIssues, 0, 10) as $issue) {
                    $issueRows[] = [
                        $issue['code'],
                        $issue['count'],
                        sprintf('%.1f%%', $issue['percentage']),
                    ];
                }

                $io->table(['Issue Code', 'Count', 'Occurrence'], $issueRows);
            }
        }
    }

    private function displayBenchmarkDetails($io, $benchmark): void
    {
        $io->section('Benchmark Details');

        $io->definitionList(
            ['Industry' => $benchmark->getIndustry()?->getLabel()],
            ['Sample Size' => $benchmark->getSampleSize()],
            ['Average Score' => round($benchmark->getAvgScore(), 1)],
            ['Median Score' => round($benchmark->getMedianScore(), 1)],
            ['Avg Issues' => round($benchmark->getAvgIssueCount(), 1)],
            ['Avg Critical Issues' => round($benchmark->getAvgCriticalIssueCount(), 1)],
        );

        $percentiles = $benchmark->getPercentiles();
        $io->section('Percentiles');
        $io->table(
            ['P10', 'P25', 'P50', 'P75', 'P90'],
            [[
                round($percentiles['p10'] ?? 0, 1),
                round($percentiles['p25'] ?? 0, 1),
                round($percentiles['p50'] ?? 0, 1),
                round($percentiles['p75'] ?? 0, 1),
                round($percentiles['p90'] ?? 0, 1),
            ]]
        );

        $categoryScores = $benchmark->getAvgCategoryScores();
        if (!empty($categoryScores)) {
            $io->section('Category Averages');
            $io->table(
                ['Category', 'Avg Score'],
                array_map(fn ($k, $v) => [$k, round($v, 1)], array_keys($categoryScores), $categoryScores)
            );
        }

        $topIssues = array_slice($benchmark->getTopIssues(), 0, 5);
        if (!empty($topIssues)) {
            $io->section('Top 5 Issues');
            $io->table(
                ['Issue Code', 'Count', 'Occurrence'],
                array_map(fn ($i) => [$i['code'], $i['count'], sprintf('%.1f%%', $i['percentage'])], $topIssues)
            );
        }
    }
}
