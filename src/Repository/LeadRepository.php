<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Analysis;
use App\Entity\AnalysisSnapshot;
use App\Entity\Lead;
use App\Entity\User;
use App\Enum\LeadStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

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

    /**
     * Find lead with all detail page data pre-fetched to avoid N+1 queries.
     *
     * Uses 3 optimized queries:
     * - Query 1: Lead + single-value associations (latestAnalysis, company, user, discoveryProfile)
     * - Query 2: Analyses with results (limit 10 newest)
     * - Query 3: Snapshots (limit 20 newest)
     */
    public function findOneWithDetailData(Uuid $id, ?User $user = null): ?Lead
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.latestAnalysis', 'la')->addSelect('la')
            ->leftJoin('l.company', 'c')->addSelect('c')
            ->leftJoin('l.user', 'u')->addSelect('u')
            ->leftJoin('l.discoveryProfile', 'dp')->addSelect('dp')
            ->where('l.id = :id')
            ->setParameter('id', $id, UuidType::NAME);

        if ($user !== null) {
            $qb->andWhere('l.user = :user')->setParameter('user', $user);
        }

        $lead = $qb->getQuery()->getOneOrNullResult();

        if ($lead === null) {
            return null;
        }

        // Pre-fetch analyses with results (Doctrine will cache them in identity map)
        $this->getEntityManager()->createQueryBuilder()
            ->select('a', 'r')
            ->from(Analysis::class, 'a')
            ->leftJoin('a.results', 'r')
            ->where('a.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        // Pre-fetch snapshots
        $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(AnalysisSnapshot::class, 's')
            ->where('s.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('s.periodStart', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $lead;
    }
}
