<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DiscoveryProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscoveryProfile>
 */
class DiscoveryProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscoveryProfile::class);
    }

    /**
     * Find the default profile for a user.
     */
    public function findDefaultForUser(User $user): ?DiscoveryProfile
    {
        return $this->createQueryBuilder('dp')
            ->where('dp.user = :user')
            ->andWhere('dp.isDefault = :isDefault')
            ->setParameter('user', $user)
            ->setParameter('isDefault', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all active profiles for a user (discovery enabled).
     *
     * @return array<DiscoveryProfile>
     */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('dp')
            ->where('dp.user = :user')
            ->andWhere('dp.discoveryEnabled = :enabled')
            ->setParameter('user', $user)
            ->setParameter('enabled', true)
            ->orderBy('dp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all profiles for a user.
     *
     * @return array<DiscoveryProfile>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('dp')
            ->where('dp.user = :user')
            ->setParameter('user', $user)
            ->orderBy('dp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all enabled profiles for batch discovery.
     * Includes profiles from all active users where discoveryEnabled is true.
     *
     * @return array<DiscoveryProfile>
     */
    public function findAllActiveForBatch(): array
    {
        return $this->createQueryBuilder('dp')
            ->join('dp.user', 'u')
            ->where('dp.discoveryEnabled = :enabled')
            ->andWhere('u.isActive = :userActive')
            ->setParameter('enabled', true)
            ->setParameter('userActive', true)
            ->orderBy('dp.priority', 'DESC')
            ->addOrderBy('dp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find profile by name and user.
     */
    public function findByNameAndUser(string $name, User $user): ?DiscoveryProfile
    {
        return $this->createQueryBuilder('dp')
            ->where('dp.user = :user')
            ->andWhere('dp.name = :name')
            ->setParameter('user', $user)
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Clear default flag for all profiles of a user.
     */
    public function clearDefaultForUser(User $user): void
    {
        $this->createQueryBuilder('dp')
            ->update()
            ->set('dp.isDefault', ':isDefault')
            ->where('dp.user = :user')
            ->setParameter('isDefault', false)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Count leads discovered by this profile.
     */
    public function countLeadsByProfile(DiscoveryProfile $profile): int
    {
        return (int) $this->createQueryBuilder('dp')
            ->select('COUNT(l.id)')
            ->leftJoin('dp.leads', 'l')
            ->where('dp.id = :profileId')
            ->setParameter('profileId', $profile->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }
}
