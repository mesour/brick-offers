<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserEmailTemplate;
use App\Enum\Industry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserEmailTemplate>
 */
class UserEmailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserEmailTemplate::class);
    }

    /**
     * Find all templates for a user.
     *
     * @return UserEmailTemplate[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active templates for a user.
     *
     * @return UserEmailTemplate[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.isActive = true')
            ->setParameter('user', $user)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active template by user and industry.
     */
    public function findActiveByUserAndIndustry(User $user, Industry $industry): ?UserEmailTemplate
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.industry = :industry')
            ->andWhere('t.isActive = true')
            ->setParameter('user', $user)
            ->setParameter('industry', $industry)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find template by user and name.
     */
    public function findByUserAndName(User $user, string $name): ?UserEmailTemplate
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.name = :name')
            ->setParameter('user', $user)
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find best matching template for user.
     *
     * Resolution order:
     * 1. User template matching name (if provided)
     * 2. User template matching industry
     * 3. Any active user template
     */
    public function findBestMatch(User $user, ?Industry $industry = null, ?string $name = null): ?UserEmailTemplate
    {
        // Try to find by name first
        if ($name !== null) {
            $template = $this->createQueryBuilder('t')
                ->where('t.user = :user')
                ->andWhere('t.name = :name')
                ->andWhere('t.isActive = true')
                ->setParameter('user', $user)
                ->setParameter('name', $name)
                ->getQuery()
                ->getOneOrNullResult();

            if ($template !== null) {
                return $template;
            }
        }

        // Try to find by industry
        if ($industry !== null) {
            $template = $this->findActiveByUserAndIndustry($user, $industry);

            if ($template !== null) {
                return $template;
            }
        }

        // Return any active template
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.isActive = true')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
