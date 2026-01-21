<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DemandSignal;
use App\Entity\User;
use App\Enum\DemandSignalSource;
use App\Enum\DemandSignalStatus;
use App\Enum\DemandSignalType;
use App\Enum\Industry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandSignal>
 */
class DemandSignalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandSignal::class);
    }

    /**
     * Find by external ID and source (for deduplication).
     */
    public function findByExternalId(DemandSignalSource $source, string $externalId): ?DemandSignal
    {
        return $this->findOneBy([
            'source' => $source,
            'externalId' => $externalId,
        ]);
    }

    /**
     * Find active signals (new or qualified, not expired).
     *
     * @return DemandSignal[]
     */
    public function findActive(User $user, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.deadline IS NULL OR d.deadline > :now')
            ->setParameter('user', $user)
            ->setParameter('statuses', [DemandSignalStatus::NEW, DemandSignalStatus::QUALIFIED])
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('d.publishedAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find signals by industry.
     *
     * @return DemandSignal[]
     */
    public function findByIndustry(User $user, Industry $industry, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.industry = :industry')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('industry', $industry)
            ->setParameter('statuses', [DemandSignalStatus::NEW, DemandSignalStatus::QUALIFIED])
            ->orderBy('d.publishedAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find signals by source.
     *
     * @return DemandSignal[]
     */
    public function findBySource(User $user, DemandSignalSource $source, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.source = :source')
            ->setParameter('user', $user)
            ->setParameter('source', $source)
            ->orderBy('d.publishedAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find signals by type.
     *
     * @return DemandSignal[]
     */
    public function findByType(User $user, DemandSignalType $type, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.signalType = :type')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->orderBy('d.publishedAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find signals with upcoming deadlines.
     *
     * @return DemandSignal[]
     */
    public function findWithUpcomingDeadlines(User $user, int $daysAhead = 7): array
    {
        $now = new \DateTimeImmutable();
        $futureDate = $now->modify("+{$daysAhead} days");

        return $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.deadline IS NOT NULL')
            ->andWhere('d.deadline BETWEEN :now AND :future')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->setParameter('future', $futureDate)
            ->setParameter('statuses', [DemandSignalStatus::NEW, DemandSignalStatus::QUALIFIED])
            ->orderBy('d.deadline', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find high-value signals above a threshold.
     *
     * @return DemandSignal[]
     */
    public function findHighValue(User $user, float $minValue, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.value >= :minValue')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('minValue', $minValue)
            ->setParameter('statuses', [DemandSignalStatus::NEW, DemandSignalStatus::QUALIFIED])
            ->orderBy('d.value', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count signals by source for statistics.
     *
     * @return array<string, int>
     */
    public function countBySource(User $user): array
    {
        $results = $this->createQueryBuilder('d')
            ->select('d.source, COUNT(d.id) as count')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->groupBy('d.source')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['source']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Expire signals with passed deadlines.
     */
    public function expireOldSignals(): int
    {
        return $this->createQueryBuilder('d')
            ->update()
            ->set('d.status', ':expiredStatus')
            ->where('d.deadline IS NOT NULL')
            ->andWhere('d.deadline < :now')
            ->andWhere('d.status IN (:activeStatuses)')
            ->setParameter('expiredStatus', DemandSignalStatus::EXPIRED)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('activeStatuses', [DemandSignalStatus::NEW, DemandSignalStatus::QUALIFIED])
            ->getQuery()
            ->execute();
    }
}
