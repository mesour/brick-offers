<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CalculateBenchmarksMessage;
use App\Service\Benchmark\BenchmarkCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for calculating industry benchmarks asynchronously.
 */
#[AsMessageHandler]
final readonly class CalculateBenchmarksMessageHandler
{
    public function __construct(
        private BenchmarkCalculator $benchmarkCalculator,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(CalculateBenchmarksMessage $message): void
    {
        $this->logger->info('Starting benchmark calculation', [
            'industry' => $message->industry?->value,
            'recalculate_all' => $message->recalculateAll,
        ]);

        try {
            if ($message->industry !== null) {
                // Calculate for specific industry
                $benchmark = $this->benchmarkCalculator->calculateForIndustry($message->industry);

                if ($benchmark !== null) {
                    $this->logger->info('Benchmark calculated for industry', [
                        'industry' => $message->industry->value,
                        'sample_size' => $benchmark->getSampleSize(),
                        'avg_score' => $benchmark->getAvgScore(),
                    ]);
                } else {
                    $this->logger->warning('No data available for benchmark', [
                        'industry' => $message->industry->value,
                    ]);
                }
            } else {
                // Calculate for all industries
                $stats = $this->benchmarkCalculator->calculateForAllIndustries();

                $this->logger->info('Benchmarks calculated for all industries', [
                    'created' => $stats['created'],
                    'updated' => $stats['updated'],
                    'skipped' => $stats['skipped'],
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Benchmark calculation failed', [
                'industry' => $message->industry?->value,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
