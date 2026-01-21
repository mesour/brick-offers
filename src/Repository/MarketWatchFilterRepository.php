<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DemandSignal;
use App\Entity\MarketWatchFilter;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketWatchFilter>
 *
 * @method MarketWatchFilter|null find($id, $lockMode = null, $lockVersion = null)
 * @method MarketWatchFilter|null findOneBy(array $criteria, array $orderBy = null)
 * @method MarketWatchFilter[]    findAll()
 * @method MarketWatchFilter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MarketWatchFilterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketWatchFilter::class);
    }

    /**
     * Get all filters for a user.
     *
     * @return MarketWatchFilter[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    /**
     * Get active filters for a user.
     *
     * @return MarketWatchFilter[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'active' => true], ['name' => 'ASC']);
    }

    /**
     * Get all active filters.
     *
     * @return MarketWatchFilter[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['active' => true]);
    }

    /**
     * Find all users whose filters match a given signal.
     *
     * @return User[]
     */
    public function findMatchingUsersForSignal(DemandSignal $signal): array
    {
        $filters = $this->findAllActive();
        $users = [];
        $seenUserIds = [];

        foreach ($filters as $filter) {
            if ($filter->matches($signal)) {
                $userId = $filter->getUser()->getId()?->toRfc4122();
                if ($userId !== null && !isset($seenUserIds[$userId])) {
                    $users[] = $filter->getUser();
                    $seenUserIds[$userId] = true;
                }
            }
        }

        return $users;
    }

    /**
     * Find filters that match a given signal.
     *
     * @return MarketWatchFilter[]
     */
    public function findMatchingFiltersForSignal(DemandSignal $signal): array
    {
        $filters = $this->findAllActive();

        return array_filter($filters, fn (MarketWatchFilter $f) => $f->matches($signal));
    }
}
