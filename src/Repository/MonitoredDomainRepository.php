<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredDomain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoredDomain>
 *
 * @method MonitoredDomain|null find($id, $lockMode = null, $lockVersion = null)
 * @method MonitoredDomain|null findOneBy(array $criteria, array $orderBy = null)
 * @method MonitoredDomain[]    findAll()
 * @method MonitoredDomain[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MonitoredDomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoredDomain::class);
    }

    /**
     * Find a monitored domain by its domain name.
     */
    public function findByDomain(string $domain): ?MonitoredDomain
    {
        return $this->findOneBy(['domain' => strtolower($domain)]);
    }

    /**
     * Find or create a monitored domain.
     */
    public function findOrCreate(string $domain, string $url): MonitoredDomain
    {
        $monitored = $this->findByDomain($domain);

        if ($monitored === null) {
            $monitored = new MonitoredDomain();
            $monitored->setDomain($domain);
            $monitored->setUrl($url);
        }

        return $monitored;
    }

    /**
     * Find domains that need to be crawled based on their frequency.
     *
     * @return MonitoredDomain[]
     */
    public function findNeedingCrawl(int $limit = 100): array
    {
        $domains = $this->createQueryBuilder('d')
            ->where('d.active = true')
            ->orderBy('d.lastCrawledAt', 'ASC')
            ->setMaxResults($limit * 2) // Fetch more to filter by frequency
            ->getQuery()
            ->getResult();

        // Filter by crawl frequency
        $result = [];
        foreach ($domains as $domain) {
            if ($domain->shouldCrawl() && count($result) < $limit) {
                $result[] = $domain;
            }
        }

        return $result;
    }

    /**
     * Find domains that have never been crawled.
     *
     * @return MonitoredDomain[]
     */
    public function findNeverCrawled(int $limit = 100): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.active = true')
            ->andWhere('d.lastCrawledAt IS NULL')
            ->orderBy('d.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find domains with active subscriptions.
     *
     * @return MonitoredDomain[]
     */
    public function findWithSubscriptions(): array
    {
        return $this->createQueryBuilder('d')
            ->innerJoin('d.subscriptions', 's')
            ->where('d.active = true')
            ->groupBy('d.id')
            ->orderBy('d.lastCrawledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count active monitored domains.
     */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.active = true')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
