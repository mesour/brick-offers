<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Analysis;
use App\Entity\AnalysisResult;
use App\Enum\AnalysisStatus;
use App\Enum\IssueCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalysisResult>
 */
class AnalysisResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalysisResult::class);
    }

    /**
     * Find result by analysis and category.
     */
    public function findByAnalysisAndCategory(Analysis $analysis, IssueCategory $category): ?AnalysisResult
    {
        return $this->findOneBy([
            'analysis' => $analysis,
            'category' => $category,
        ]);
    }

    /**
     * Find all results for an analysis.
     *
     * @return array<AnalysisResult>
     */
    public function findByAnalysis(Analysis $analysis): array
    {
        return $this->findBy(['analysis' => $analysis], ['category' => 'ASC']);
    }

    /**
     * Find results by status.
     *
     * @return array<AnalysisResult>
     */
    public function findByStatus(AnalysisStatus $status, int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', $status)
            ->orderBy('r.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count results by category across all analyses.
     *
     * @return array<string, int>
     */
    public function countByCategory(): array
    {
        $result = $this->createQueryBuilder('r')
            ->select('r.category, COUNT(r.id) as count')
            ->groupBy('r.category')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($result as $row) {
            $counts[$row['category']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Get average score by category.
     *
     * @return array<string, float>
     */
    public function getAverageScoreByCategory(): array
    {
        $result = $this->createQueryBuilder('r')
            ->select('r.category, AVG(r.score) as avgScore')
            ->where('r.status = :status')
            ->setParameter('status', AnalysisStatus::COMPLETED)
            ->groupBy('r.category')
            ->getQuery()
            ->getArrayResult();

        $scores = [];
        foreach ($result as $row) {
            $scores[$row['category']->value] = round((float) $row['avgScore'], 2);
        }

        return $scores;
    }

    /**
     * Find results for compression (with non-empty, non-compressed rawData).
     *
     * @return array<AnalysisResult>
     */
    public function findForCompression(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
    ): array {
        $connection = $this->getEntityManager()->getConnection();

        // Use native SQL because DQL doesn't support JSONB text casting
        $sql = '
            SELECT ar.id
            FROM analysis_results ar
            JOIN analyses a ON ar.analysis_id = a.id
            WHERE a.created_at >= :from
              AND a.created_at < :to
              AND ar.raw_data::text != :empty
              AND ar.raw_data::text NOT LIKE :compressed
            LIMIT :limit
        ';

        $ids = $connection->fetchFirstColumn($sql, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'empty' => '[]',
            'compressed' => '%"_compressed"%',
            'limit' => $limit,
        ]);

        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count results that can be compressed.
     */
    public function countForCompression(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $connection = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT COUNT(ar.id)
            FROM analysis_results ar
            JOIN analyses a ON ar.analysis_id = a.id
            WHERE a.created_at >= :from
              AND a.created_at < :to
              AND ar.raw_data::text != :empty
              AND ar.raw_data::text NOT LIKE :compressed
        ';

        return (int) $connection->fetchOne($sql, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'empty' => '[]',
            'compressed' => '%"_compressed"%',
        ]);
    }

    /**
     * Count results that can be cleared (have non-empty rawData).
     */
    public function countForClearing(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $connection = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT COUNT(ar.id)
            FROM analysis_results ar
            JOIN analyses a ON ar.analysis_id = a.id
            WHERE a.created_at >= :from
              AND a.created_at < :to
              AND ar.raw_data::text != :empty
        ';

        return (int) $connection->fetchOne($sql, [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'empty' => '[]',
        ]);
    }

    /**
     * Clear rawData for results in date range.
     *
     * @return int Number of affected rows
     */
    public function clearRawDataInRange(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit,
    ): int {
        $connection = $this->getEntityManager()->getConnection();

        // PostgreSQL doesn't support LIMIT in UPDATE, use subquery
        $sql = '
            UPDATE analysis_results
            SET raw_data = :empty
            WHERE id IN (
                SELECT ar.id
                FROM analysis_results ar
                JOIN analyses a ON ar.analysis_id = a.id
                WHERE a.created_at >= :from
                  AND a.created_at < :to
                  AND ar.raw_data::text != :empty_check
                LIMIT :limit
            )
        ';

        return $connection->executeStatement($sql, [
            'empty' => '[]',
            'empty_check' => '[]',
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ]);
    }

    /**
     * Count results older than specified date.
     */
    public function countOlderThan(\DateTimeImmutable $date): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.analysis', 'a')
            ->where('a.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Delete results older than specified date.
     *
     * @return int Number of deleted rows
     */
    public function deleteOlderThan(\DateTimeImmutable $date, int $limit): int
    {
        $connection = $this->getEntityManager()->getConnection();

        // PostgreSQL doesn't support LIMIT in DELETE, use subquery
        $sql = '
            DELETE FROM analysis_results
            WHERE id IN (
                SELECT ar.id
                FROM analysis_results ar
                JOIN analyses a ON ar.analysis_id = a.id
                WHERE a.created_at < :date
                LIMIT :limit
            )
        ';

        return $connection->executeStatement($sql, [
            'date' => $date->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ]);
    }
}
