<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Analysis;
use App\Entity\Lead;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Analysis>
 */
class AnalysisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Analysis::class);
    }

    public function findByLead(Lead $lead): ?Analysis
    {
        return $this->findOneBy(['lead' => $lead]);
    }

    public function findLatestByLead(Lead $lead): ?Analysis
    {
        return $this->createQueryBuilder('a')
            ->where('a.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find analyses by status with optional limit.
     *
     * @return array<Analysis>
     */
    public function findByStatus(AnalysisStatus $status, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', $status)
            ->orderBy('a.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count analyses by status.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $result = $this->createQueryBuilder('a')
            ->select('a.status, COUNT(a.id) as count')
            ->groupBy('a.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($result as $row) {
            $counts[$row['status']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Get average scores across all completed analyses.
     *
     * @return array{avgScore: float, totalAnalyses: int, criticalCount: int}
     */
    public function getAnalysisStats(): array
    {
        $result = $this->createQueryBuilder('a')
            ->select('AVG(a.totalScore) as avgScore, COUNT(a.id) as totalAnalyses')
            ->where('a.status = :status')
            ->setParameter('status', AnalysisStatus::COMPLETED)
            ->getQuery()
            ->getSingleResult();

        return [
            'avgScore' => (float) ($result['avgScore'] ?? 0),
            'totalAnalyses' => (int) $result['totalAnalyses'],
            'criticalCount' => 0, // Will be calculated from JSONB in advanced version
        ];
    }

    /**
     * Find all analyses for a lead, ordered by sequence number.
     *
     * @return array<Analysis>
     */
    public function findHistoryByLead(Lead $lead, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('a.sequenceNumber', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find analyses by industry.
     *
     * @return array<Analysis>
     */
    public function findByIndustry(Industry $industry, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.industry = :industry')
            ->andWhere('a.status = :status')
            ->setParameter('industry', $industry)
            ->setParameter('status', AnalysisStatus::COMPLETED)
            ->orderBy('a.completedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get improved/worsened statistics for a time period.
     *
     * @return array{improved: int, worsened: int, unchanged: int}
     */
    public function getDeltaStats(\DateTimeImmutable $since): array
    {
        $improved = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.isImproved = true')
            ->andWhere('a.completedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        $worsened = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.isImproved = false')
            ->andWhere('a.scoreDelta < 0')
            ->andWhere('a.completedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        $unchanged = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.isImproved = false')
            ->andWhere('(a.scoreDelta = 0 OR a.scoreDelta IS NULL)')
            ->andWhere('a.previousAnalysis IS NOT NULL')
            ->andWhere('a.completedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'improved' => $improved,
            'worsened' => $worsened,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * Find analyses with new critical issues (for alerting).
     *
     * @return array<Analysis>
     */
    public function findWithNewCriticalIssues(\DateTimeImmutable $since): array
    {
        // This is a simplified version - in production you'd query the JSONB
        return $this->createQueryBuilder('a')
            ->where('a.completedAt >= :since')
            ->andWhere('a.status = :status')
            ->andWhere('a.previousAnalysis IS NOT NULL')
            ->setParameter('since', $since)
            ->setParameter('status', AnalysisStatus::COMPLETED)
            ->orderBy('a.completedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count analyses per industry.
     *
     * @return array<string, int>
     */
    public function countByIndustry(): array
    {
        $result = $this->createQueryBuilder('a')
            ->select('a.industry, COUNT(a.id) as cnt')
            ->where('a.status = :status')
            ->andWhere('a.industry IS NOT NULL')
            ->setParameter('status', AnalysisStatus::COMPLETED)
            ->groupBy('a.industry')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($result as $row) {
            if ($row['industry'] instanceof Industry) {
                $counts[$row['industry']->value] = (int) $row['cnt'];
            }
        }

        return $counts;
    }
}
