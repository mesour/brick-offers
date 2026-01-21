<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DemandSignal;
use App\Entity\DemandSignalSubscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandSignalSubscription>
 *
 * @method DemandSignalSubscription|null find($id, $lockMode = null, $lockVersion = null)
 * @method DemandSignalSubscription|null findOneBy(array $criteria, array $orderBy = null)
 * @method DemandSignalSubscription[]    findAll()
 * @method DemandSignalSubscription[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DemandSignalSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandSignalSubscription::class);
    }

    /**
     * Get subscription for a specific user and signal.
     */
    public function findByUserAndSignal(User $user, DemandSignal $demandSignal): ?DemandSignalSubscription
    {
        return $this->findOneBy([
            'user' => $user,
            'demandSignal' => $demandSignal,
        ]);
    }

    /**
     * Get all subscriptions for a user.
     *
     * @return DemandSignalSubscription[]
     */
    public function findByUser(User $user, ?SubscriptionStatus $status = null): array
    {
        $criteria = ['user' => $user];

        if ($status !== null) {
            $criteria['status'] = $status;
        }

        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }

    /**
     * Get actionable subscriptions for a user (new or reviewed).
     *
     * @return DemandSignalSubscription[]
     */
    public function findActionableByUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [SubscriptionStatus::NEW, SubscriptionStatus::REVIEWED])
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find or create a subscription.
     */
    public function findOrCreate(User $user, DemandSignal $demandSignal): DemandSignalSubscription
    {
        $subscription = $this->findByUserAndSignal($user, $demandSignal);

        if ($subscription === null) {
            $subscription = new DemandSignalSubscription();
            $subscription->setUser($user);
            $subscription->setDemandSignal($demandSignal);
        }

        return $subscription;
    }

    /**
     * Count subscriptions by status for a user.
     *
     * @return array<string, int>
     */
    public function countByStatusForUser(User $user): array
    {
        $results = $this->createQueryBuilder('s')
            ->select('s.status, COUNT(s.id) as count')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->groupBy('s.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['status']->value] = (int) $row['count'];
        }

        return $counts;
    }
}
