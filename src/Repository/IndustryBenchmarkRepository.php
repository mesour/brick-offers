<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryBenchmark;
use App\Enum\Industry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryBenchmark>
 */
class IndustryBenchmarkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryBenchmark::class);
    }

    /**
     * Find the latest benchmark for an industry.
     */
    public function findLatestByIndustry(Industry $industry): ?IndustryBenchmark
    {
        return $this->createQueryBuilder('b')
            ->where('b.industry = :industry')
            ->setParameter('industry', $industry)
            ->orderBy('b.periodStart', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find benchmark for a specific period.
     */
    public function findByIndustryAndPeriod(
        Industry $industry,
        \DateTimeImmutable $periodStart,
    ): ?IndustryBenchmark {
        return $this->createQueryBuilder('b')
            ->where('b.industry = :industry')
            ->andWhere('b.periodStart = :periodStart')
            ->setParameter('industry', $industry)
            ->setParameter('periodStart', $periodStart)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get benchmark history for an industry.
     *
     * @return array<IndustryBenchmark>
     */
    public function findHistoryByIndustry(Industry $industry, int $periods = 12): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.industry = :industry')
            ->setParameter('industry', $industry)
            ->orderBy('b.periodStart', 'DESC')
            ->setMaxResults($periods)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get latest benchmarks for all industries.
     *
     * @return array<IndustryBenchmark>
     */
    public function findLatestForAllIndustries(): array
    {
        // Get the latest period start for each industry
        $subQuery = $this->createQueryBuilder('b2')
            ->select('b2.industry, MAX(b2.periodStart) as maxPeriod')
            ->groupBy('b2.industry')
            ->getDQL();

        // This is a simplified version - in production you'd use a proper subquery
        $results = [];
        foreach (Industry::cases() as $industry) {
            $benchmark = $this->findLatestByIndustry($industry);
            if ($benchmark !== null) {
                $results[] = $benchmark;
            }
        }

        return $results;
    }

    /**
     * Get trending data across periods.
     *
     * @return array<array{periodStart: string, avgScore: float, sampleSize: int}>
     */
    public function getTrendingData(Industry $industry, int $periods = 12): array
    {
        $results = $this->createQueryBuilder('b')
            ->select('b.periodStart', 'b.avgScore', 'b.sampleSize')
            ->where('b.industry = :industry')
            ->setParameter('industry', $industry)
            ->orderBy('b.periodStart', 'DESC')
            ->setMaxResults($periods)
            ->getQuery()
            ->getArrayResult();

        return array_map(function (array $row): array {
            return [
                'periodStart' => $row['periodStart']->format('Y-m-d'),
                'avgScore' => $row['avgScore'],
                'sampleSize' => $row['sampleSize'],
            ];
        }, $results);
    }
}
