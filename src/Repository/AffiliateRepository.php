<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Affiliate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Affiliate>
 */
class AffiliateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Affiliate::class);
    }

    public function findByHash(string $hash): ?Affiliate
    {
        return $this->findOneBy(['hash' => $hash]);
    }

    /**
     * Find all active affiliates.
     *
     * @return array<Affiliate>
     */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['name' => 'ASC']);
    }
}
