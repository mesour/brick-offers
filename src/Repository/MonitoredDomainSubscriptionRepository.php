<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredDomain;
use App\Entity\MonitoredDomainSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoredDomainSubscription>
 *
 * @method MonitoredDomainSubscription|null find($id, $lockMode = null, $lockVersion = null)
 * @method MonitoredDomainSubscription|null findOneBy(array $criteria, array $orderBy = null)
 * @method MonitoredDomainSubscription[]    findAll()
 * @method MonitoredDomainSubscription[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MonitoredDomainSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoredDomainSubscription::class);
    }

    /**
     * Get subscription for a specific user and domain.
     */
    public function findByUserAndDomain(User $user, MonitoredDomain $monitoredDomain): ?MonitoredDomainSubscription
    {
        return $this->findOneBy([
            'user' => $user,
            'monitoredDomain' => $monitoredDomain,
        ]);
    }

    /**
     * Get all subscriptions for a user.
     *
     * @return MonitoredDomainSubscription[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    /**
     * Get all users subscribed to a domain.
     *
     * @return User[]
     */
    public function findUsersByDomain(MonitoredDomain $monitoredDomain): array
    {
        $subscriptions = $this->findBy(['monitoredDomain' => $monitoredDomain]);

        return array_map(fn (MonitoredDomainSubscription $s) => $s->getUser(), $subscriptions);
    }

    /**
     * Get subscriptions that should be alerted for a domain change.
     *
     * @return MonitoredDomainSubscription[]
     */
    public function findAlertableByDomain(MonitoredDomain $monitoredDomain): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.monitoredDomain = :domain')
            ->andWhere('s.alertOnChange = true')
            ->setParameter('domain', $monitoredDomain)
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if a user is subscribed to a domain.
     */
    public function isSubscribed(User $user, MonitoredDomain $monitoredDomain): bool
    {
        return $this->findByUserAndDomain($user, $monitoredDomain) !== null;
    }

    /**
     * Subscribe a user to a domain.
     */
    public function subscribe(User $user, MonitoredDomain $monitoredDomain): MonitoredDomainSubscription
    {
        $subscription = $this->findByUserAndDomain($user, $monitoredDomain);

        if ($subscription === null) {
            $subscription = new MonitoredDomainSubscription();
            $subscription->setUser($user);
            $subscription->setMonitoredDomain($monitoredDomain);
        }

        return $subscription;
    }
}
