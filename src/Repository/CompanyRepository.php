<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Company>
 *
 * @method Company|null find($id, $lockMode = null, $lockVersion = null)
 * @method Company|null findOneBy(array $criteria, array $orderBy = null)
 * @method Company[]    findAll()
 * @method Company[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    /**
     * Find a company by IČO (unique identifier).
     */
    public function findByIco(string $ico): ?Company
    {
        return $this->findOneBy(['ico' => $ico]);
    }

    /**
     * Find or create a company by IČO.
     * If the company doesn't exist, creates a new one with the given name.
     */
    public function findOrCreateByIco(string $ico, ?string $name = null): Company
    {
        $company = $this->findByIco($ico);

        if ($company === null) {
            $company = new Company();
            $company->setIco($ico);
            $company->setName($name ?? 'Unknown Company');
        }

        return $company;
    }

    /**
     * Find companies that need ARES data refresh.
     *
     * @return array<Company>
     */
    public function findNeedingAresUpdate(int $limit = 100): array
    {
        $thirtyDaysAgo = new \DateTimeImmutable('-30 days');

        return $this->createQueryBuilder('c')
            ->where('c.aresUpdatedAt IS NULL')
            ->orWhere('c.aresUpdatedAt < :cutoff')
            ->setParameter('cutoff', $thirtyDaysAgo)
            ->orderBy('c.aresUpdatedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find existing IČOs from a list for bulk deduplication.
     *
     * @param array<string> $icos
     * @return array<string> Array of IČOs that already exist
     */
    public function findExistingIcos(array $icos): array
    {
        if (empty($icos)) {
            return [];
        }

        $qb = $this->createQueryBuilder('c')
            ->select('c.ico')
            ->where('c.ico IN (:icos)')
            ->setParameter('icos', $icos);

        $result = $qb->getQuery()->getArrayResult();

        return array_column($result, 'ico');
    }

    /**
     * Find companies by IČOs.
     *
     * @param array<string> $icos
     * @return array<string, Company> Array indexed by IČO
     */
    public function findByIcos(array $icos): array
    {
        if (empty($icos)) {
            return [];
        }

        $companies = $this->createQueryBuilder('c')
            ->where('c.ico IN (:icos)')
            ->setParameter('icos', $icos)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($companies as $company) {
            $indexed[$company->getIco()] = $company;
        }

        return $indexed;
    }

    /**
     * Count companies by business status.
     *
     * @return array<string, int>
     */
    public function countByBusinessStatus(): array
    {
        $result = $this->createQueryBuilder('c')
            ->select('c.businessStatus, COUNT(c.id) as count')
            ->groupBy('c.businessStatus')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($result as $row) {
            $status = $row['businessStatus'] ?? 'unknown';
            $counts[$status] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Get statistics about companies.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        $total = $this->count([]);

        $withAres = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.aresData IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $withLeads = $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.id)')
            ->innerJoin('c.leads', 'l')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'with_ares_data' => (int) $withAres,
            'with_leads' => (int) $withLeads,
            'by_status' => $this->countByBusinessStatus(),
        ];
    }
}
