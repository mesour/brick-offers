<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Company;
use App\Entity\User;
use App\Entity\UserCompanyNote;
use App\Enum\RelationshipStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserCompanyNote>
 *
 * @method UserCompanyNote|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserCompanyNote|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserCompanyNote[]    findAll()
 * @method UserCompanyNote[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserCompanyNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserCompanyNote::class);
    }

    /**
     * Get note for a specific user-company pair.
     */
    public function findByUserAndCompany(User $user, Company $company): ?UserCompanyNote
    {
        return $this->findOneBy([
            'user' => $user,
            'company' => $company,
        ]);
    }

    /**
     * Get or create note for a user-company pair.
     */
    public function findOrCreate(User $user, Company $company): UserCompanyNote
    {
        $note = $this->findByUserAndCompany($user, $company);

        if ($note === null) {
            $note = new UserCompanyNote();
            $note->setUser($user);
            $note->setCompany($company);
        }

        return $note;
    }

    /**
     * Get all notes for a user with a specific relationship status.
     *
     * @return UserCompanyNote[]
     */
    public function findByUserAndStatus(User $user, RelationshipStatus $status): array
    {
        return $this->findBy([
            'user' => $user,
            'relationshipStatus' => $status,
        ]);
    }

    /**
     * Get all notes for a user with a specific tag.
     *
     * @return UserCompanyNote[]
     */
    public function findByUserAndTag(User $user, string $tag): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('JSON_CONTAINS(n.tags, :tag) = 1')
            ->setParameter('user', $user)
            ->setParameter('tag', json_encode($tag))
            ->getQuery()
            ->getResult();
    }

    /**
     * Get companies that a user has blacklisted.
     *
     * @return Company[]
     */
    public function findBlacklistedCompaniesForUser(User $user): array
    {
        $notes = $this->findByUserAndStatus($user, RelationshipStatus::BLACKLISTED);

        return array_map(fn (UserCompanyNote $n) => $n->getCompany(), $notes);
    }

    /**
     * Check if a company is blacklisted by a user.
     */
    public function isBlacklisted(User $user, Company $company): bool
    {
        $note = $this->findByUserAndCompany($user, $company);

        return $note !== null && $note->getRelationshipStatus() === RelationshipStatus::BLACKLISTED;
    }
}
