<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailBlacklist;
use App\Entity\User;
use App\Enum\EmailBounceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailBlacklist>
 */
class EmailBlacklistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailBlacklist::class);
    }

    /**
     * Check if email is blacklisted (global or per-user).
     */
    public function isBlacklisted(string $email, ?User $user = null): bool
    {
        $email = strtolower($email);

        // Check global blacklist first
        $globalEntry = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.email = :email')
            ->andWhere('b.user IS NULL')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult();

        if ((int) $globalEntry > 0) {
            return true;
        }

        // Check user-specific blacklist
        if ($user !== null) {
            $userEntry = $this->createQueryBuilder('b')
                ->select('COUNT(b.id)')
                ->where('b.email = :email')
                ->andWhere('b.user = :user')
                ->setParameter('email', $email)
                ->setParameter('user', $user)
                ->getQuery()
                ->getSingleScalarResult();

            return (int) $userEntry > 0;
        }

        return false;
    }

    /**
     * Find blacklist entry for email (global or per-user).
     */
    public function findEntry(string $email, ?User $user = null): ?EmailBlacklist
    {
        $email = strtolower($email);

        // First check global
        $globalEntry = $this->createQueryBuilder('b')
            ->where('b.email = :email')
            ->andWhere('b.user IS NULL')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();

        if ($globalEntry !== null) {
            return $globalEntry;
        }

        // Then check per-user
        if ($user !== null) {
            return $this->createQueryBuilder('b')
                ->where('b.email = :email')
                ->andWhere('b.user = :user')
                ->setParameter('email', $email)
                ->setParameter('user', $user)
                ->getQuery()
                ->getOneOrNullResult();
        }

        return null;
    }

    /**
     * Find global bounce entries.
     *
     * @return EmailBlacklist[]
     */
    public function findGlobalBounces(int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.user IS NULL')
            ->andWhere('b.type IN (:types)')
            ->setParameter('types', [EmailBounceType::HARD_BOUNCE, EmailBounceType::COMPLAINT])
            ->orderBy('b.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find user's unsubscribes.
     *
     * @return EmailBlacklist[]
     */
    public function findUserUnsubscribes(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.user = :user')
            ->andWhere('b.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', EmailBounceType::UNSUBSCRIBE)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all entries for a user (including unsubscribes).
     *
     * @return EmailBlacklist[]
     */
    public function findByUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find by type.
     *
     * @return EmailBlacklist[]
     */
    public function findByType(EmailBounceType $type, int $limit = 100): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.type = :type')
            ->setParameter('type', $type)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count global blacklist entries.
     */
    public function countGlobal(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.user IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count user's blacklist entries.
     */
    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Remove entry by email and user.
     */
    public function removeEntry(string $email, ?User $user = null): bool
    {
        $entry = $this->findEntry($email, $user);

        if ($entry === null) {
            return false;
        }

        $this->getEntityManager()->remove($entry);
        $this->getEntityManager()->flush();

        return true;
    }
}
