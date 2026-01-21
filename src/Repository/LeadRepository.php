<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Lead;
use App\Enum\LeadStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Lead>
 */
class LeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lead::class);
    }

    public function findByDomain(string $domain): ?Lead
    {
        return $this->findOneBy(['domain' => $domain]);
    }

    public function domainExists(string $domain): bool
    {
        return $this->findByDomain($domain) !== null;
    }

    /**
     * Find existing domains from a list for bulk deduplication.
     *
     * @param array<string> $domains
     * @return array<string> Array of domains that already exist
     * @deprecated Use findExistingDomainsForUser instead
     */
    public function findExistingDomains(array $domains): array
    {
        if (empty($domains)) {
            return [];
        }

        $qb = $this->createQueryBuilder('l')
            ->select('l.domain')
            ->where('l.domain IN (:domains)')
            ->setParameter('domains', $domains);

        $result = $qb->getQuery()->getArrayResult();

        return array_column($result, 'domain');
    }

    /**
     * Find existing domains from a list for a specific user.
     *
     * @param array<string> $domains
     * @return array<string> Array of domains that already exist for this user
     */
    public function findExistingDomainsForUser(array $domains, \App\Entity\User $user): array
    {
        if (empty($domains)) {
            return [];
        }

        $qb = $this->createQueryBuilder('l')
            ->select('l.domain')
            ->where('l.domain IN (:domains)')
            ->andWhere('l.user = :user')
            ->setParameter('domains', $domains)
            ->setParameter('user', $user);

        $result = $qb->getQuery()->getArrayResult();

        return array_column($result, 'domain');
    }

    /**
     * Find leads by status with optional limit.
     *
     * @return array<Lead>
     */
    public function findByStatus(LeadStatus $status, int $limit = 100): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.status = :status')
            ->setParameter('status', $status)
            ->orderBy('l.priority', 'DESC')
            ->addOrderBy('l.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find new leads ready for qualification.
     *
     * @return array<Lead>
     */
    public function findNewForQualification(int $limit = 100): array
    {
        return $this->findByStatus(LeadStatus::NEW, $limit);
    }

    /**
     * Count leads by status.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $result = $this->createQueryBuilder('l')
            ->select('l.status, COUNT(l.id) as count')
            ->groupBy('l.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($result as $row) {
            $counts[$row['status']->value] = (int) $row['count'];
        }

        return $counts;
    }
}
