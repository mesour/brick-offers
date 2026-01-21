<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnalysisSnapshot;
use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\SnapshotPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalysisSnapshot>
 */
class AnalysisSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalysisSnapshot::class);
    }

    /**
     * Find snapshots for a lead ordered by period.
     *
     * @return array<AnalysisSnapshot>
     */
    public function findByLead(Lead $lead, ?SnapshotPeriod $periodType = null, int $limit = 52): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('s.periodStart', 'DESC')
            ->setMaxResults($limit);

        if ($periodType !== null) {
            $qb->andWhere('s.periodType = :periodType')
                ->setParameter('periodType', $periodType);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find the latest snapshot for a lead and period type.
     */
    public function findLatestByLead(Lead $lead, SnapshotPeriod $periodType): ?AnalysisSnapshot
    {
        return $this->createQueryBuilder('s')
            ->where('s.lead = :lead')
            ->andWhere('s.periodType = :periodType')
            ->setParameter('lead', $lead)
            ->setParameter('periodType', $periodType)
            ->orderBy('s.periodStart', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find snapshot for specific period.
     */
    public function findByLeadAndPeriod(
        Lead $lead,
        SnapshotPeriod $periodType,
        \DateTimeImmutable $periodStart,
    ): ?AnalysisSnapshot {
        return $this->createQueryBuilder('s')
            ->where('s.lead = :lead')
            ->andWhere('s.periodType = :periodType')
            ->andWhere('s.periodStart = :periodStart')
            ->setParameter('lead', $lead)
            ->setParameter('periodType', $periodType)
            ->setParameter('periodStart', $periodStart)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get trending data for a lead.
     *
     * @return array<array{periodStart: string, totalScore: int, scoreDelta: ?int, issueCount: int}>
     */
    public function getTrendingData(Lead $lead, SnapshotPeriod $periodType, int $periods = 12): array
    {
        $results = $this->createQueryBuilder('s')
            ->select('s.periodStart', 's.totalScore', 's.scoreDelta', 's.issueCount', 's.criticalIssueCount')
            ->where('s.lead = :lead')
            ->andWhere('s.periodType = :periodType')
            ->setParameter('lead', $lead)
            ->setParameter('periodType', $periodType)
            ->orderBy('s.periodStart', 'DESC')
            ->setMaxResults($periods)
            ->getQuery()
            ->getArrayResult();

        return array_map(function (array $row): array {
            return [
                'periodStart' => $row['periodStart']->format('Y-m-d'),
                'totalScore' => $row['totalScore'],
                'scoreDelta' => $row['scoreDelta'],
                'issueCount' => $row['issueCount'],
                'criticalIssueCount' => $row['criticalIssueCount'],
            ];
        }, $results);
    }

    /**
     * Get snapshots for an industry in a period.
     *
     * @return array<AnalysisSnapshot>
     */
    public function findByIndustryAndPeriod(
        Industry $industry,
        SnapshotPeriod $periodType,
        \DateTimeImmutable $periodStart,
    ): array {
        return $this->createQueryBuilder('s')
            ->where('s.industry = :industry')
            ->andWhere('s.periodType = :periodType')
            ->andWhere('s.periodStart = :periodStart')
            ->setParameter('industry', $industry)
            ->setParameter('periodType', $periodType)
            ->setParameter('periodStart', $periodStart)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get average scores for an industry over time.
     *
     * @return array<array{periodStart: string, avgScore: float, count: int}>
     */
    public function getIndustryTrending(Industry $industry, SnapshotPeriod $periodType, int $periods = 12): array
    {
        $results = $this->createQueryBuilder('s')
            ->select('s.periodStart', 'AVG(s.totalScore) as avgScore', 'COUNT(s.id) as cnt')
            ->where('s.industry = :industry')
            ->andWhere('s.periodType = :periodType')
            ->setParameter('industry', $industry)
            ->setParameter('periodType', $periodType)
            ->groupBy('s.periodStart')
            ->orderBy('s.periodStart', 'DESC')
            ->setMaxResults($periods)
            ->getQuery()
            ->getArrayResult();

        return array_map(function (array $row): array {
            return [
                'periodStart' => $row['periodStart']->format('Y-m-d'),
                'avgScore' => (float) $row['avgScore'],
                'count' => (int) $row['cnt'],
            ];
        }, $results);
    }
}
