<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CompetitorSnapshot;
use App\Entity\MonitoredDomain;
use App\Enum\ChangeSignificance;
use App\Enum\CompetitorSnapshotType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitorSnapshot>
 *
 * @method CompetitorSnapshot|null find($id, $lockMode = null, $lockVersion = null)
 * @method CompetitorSnapshot|null findOneBy(array $criteria, array $orderBy = null)
 * @method CompetitorSnapshot[]    findAll()
 * @method CompetitorSnapshot[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CompetitorSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitorSnapshot::class);
    }

    /**
     * Find the latest snapshot for a monitored domain and type.
     */
    public function findLatest(MonitoredDomain $monitoredDomain, CompetitorSnapshotType $type): ?CompetitorSnapshot
    {
        return $this->createQueryBuilder('s')
            ->where('s.monitoredDomain = :domain')
            ->andWhere('s.snapshotType = :type')
            ->setParameter('domain', $monitoredDomain)
            ->setParameter('type', $type)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all snapshots for a monitored domain and type.
     *
     * @return CompetitorSnapshot[]
     */
    public function findByDomainAndType(MonitoredDomain $monitoredDomain, CompetitorSnapshotType $type, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.monitoredDomain = :domain')
            ->andWhere('s.snapshotType = :type')
            ->setParameter('domain', $monitoredDomain)
            ->setParameter('type', $type)
            ->orderBy('s.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find snapshots with changes.
     *
     * @return CompetitorSnapshot[]
     */
    public function findWithChanges(
        ?MonitoredDomain $monitoredDomain = null,
        ?CompetitorSnapshotType $type = null,
        ?ChangeSignificance $minSignificance = null,
        ?int $limit = null
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->where('s.hasChanges = :hasChanges')
            ->setParameter('hasChanges', true)
            ->orderBy('s.createdAt', 'DESC');

        if ($monitoredDomain !== null) {
            $qb->andWhere('s.monitoredDomain = :domain')
                ->setParameter('domain', $monitoredDomain);
        }

        if ($type !== null) {
            $qb->andWhere('s.snapshotType = :type')
                ->setParameter('type', $type);
        }

        if ($minSignificance !== null) {
            $qb->andWhere('s.significance IN (:significances)');
            // Get all significance levels >= minSignificance
            $significances = array_filter(
                ChangeSignificance::cases(),
                fn (ChangeSignificance $s) => $s->getWeight() >= $minSignificance->getWeight()
            );
            $qb->setParameter('significances', $significances);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find snapshots requiring alerts (significant changes).
     *
     * @return CompetitorSnapshot[]
     */
    public function findRequiringAlerts(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.hasChanges = :hasChanges')
            ->andWhere('s.significance IN (:significances)')
            ->andWhere('s.createdAt >= :since')
            ->setParameter('hasChanges', true)
            ->setParameter('significances', [ChangeSignificance::CRITICAL, ChangeSignificance::HIGH])
            ->setParameter('since', $since)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get snapshot history for a monitored domain (all types).
     *
     * @return CompetitorSnapshot[]
     */
    public function getHistory(MonitoredDomain $monitoredDomain, ?int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.monitoredDomain = :domain')
            ->setParameter('domain', $monitoredDomain)
            ->orderBy('s.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find monitored domains that need a new snapshot (based on type's check frequency).
     *
     * @return MonitoredDomain[]
     */
    public function findDomainsNeedingSnapshot(CompetitorSnapshotType $type, int $maxResults = 100): array
    {
        $checkFrequencyDays = $type->getCheckFrequencyDays();
        $cutoffDate = (new \DateTimeImmutable())->modify("-{$checkFrequencyDays} days");

        // Find domains that either:
        // 1. Have no snapshot of this type
        // 2. Have a snapshot older than the check frequency

        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(sub.monitoredDomain)')
            ->from(CompetitorSnapshot::class, 'sub')
            ->where('sub.snapshotType = :type')
            ->andWhere('sub.createdAt >= :cutoff')
            ->getDQL();

        return $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(MonitoredDomain::class, 'd')
            ->where("d.id NOT IN ({$subQuery})")
            ->andWhere('d.active = :active')
            ->setParameter('type', $type)
            ->setParameter('cutoff', $cutoffDate)
            ->setParameter('active', true)
            ->setMaxResults($maxResults)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count snapshots by type.
     *
     * @return array<string, int>
     */
    public function countByType(): array
    {
        $results = $this->createQueryBuilder('s')
            ->select('s.snapshotType, COUNT(s.id) as count')
            ->groupBy('s.snapshotType')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['snapshotType']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Clean up old snapshots, keeping only the latest N per domain/type.
     */
    public function cleanupOldSnapshots(int $keepLatest = 10): int
    {
        // This is a complex query - we need to find snapshots to delete
        // For each domain/type combination, keep only the latest N

        $conn = $this->getEntityManager()->getConnection();

        // Subquery to find IDs to keep
        $keepQuery = "
            SELECT id FROM (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY monitored_domain_id, snapshot_type
                    ORDER BY created_at DESC
                ) as rn
                FROM competitor_snapshots
            ) ranked
            WHERE rn <= :keepLatest
        ";

        // Delete everything not in the keep list
        $deleteQuery = "
            DELETE FROM competitor_snapshots
            WHERE id NOT IN ({$keepQuery})
        ";

        return $conn->executeStatement($deleteQuery, ['keepLatest' => $keepLatest]);
    }
}
