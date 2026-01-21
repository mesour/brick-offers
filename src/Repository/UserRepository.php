<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByCode(string $code): ?User
    {
        return $this->findOneBy(['code' => strtolower($code)]);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Find active users.
     *
     * @return array<User>
     */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['name' => 'ASC']);
    }

    /**
     * Get or create a user by code.
     * Returns existing user or creates a new one with the given name.
     */
    public function findOrCreate(string $code, string $name): User
    {
        $user = $this->findByCode($code);

        if ($user !== null) {
            return $user;
        }

        $user = new User();
        $user->setCode($code);
        $user->setName($name);

        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();

        return $user;
    }

    /**
     * Check if code exists.
     */
    public function codeExists(string $code): bool
    {
        return $this->findByCode($code) !== null;
    }
}
