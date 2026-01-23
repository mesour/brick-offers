<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Entity\DemandSignal;
use App\Entity\DemandSignalSubscription;
use App\Entity\User;
use App\Repository\DemandSignalSubscriptionRepository;
use App\Repository\MarketWatchFilterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing demand signal subscriptions.
 * Automatically creates subscriptions when signals match user filters.
 */
class DemandSignalSubscriptionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MarketWatchFilterRepository $filterRepository,
        private readonly DemandSignalSubscriptionRepository $subscriptionRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Create subscriptions for all users whose filters match the given signal.
     *
     * @return DemandSignalSubscription[] Created subscriptions
     */
    public function createSubscriptionsForSignal(DemandSignal $signal): array
    {
        $matchingUsers = $this->filterRepository->findMatchingUsersForSignal($signal);
        $subscriptions = [];

        foreach ($matchingUsers as $user) {
            $subscription = $this->createSubscription($user, $signal);
            if ($subscription !== null) {
                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * Create a subscription for a specific user and signal (if not exists).
     */
    public function createSubscription(User $user, DemandSignal $signal): ?DemandSignalSubscription
    {
        // Check if subscription already exists
        $existing = $this->subscriptionRepository->findByUserAndSignal($user, $signal);
        if ($existing !== null) {
            $this->logger?->debug('Subscription already exists', [
                'user' => $user->getEmail(),
                'signal' => $signal->getTitle(),
            ]);

            return null;
        }

        $subscription = new DemandSignalSubscription();
        $subscription->setUser($user);
        $subscription->setDemandSignal($signal);

        $this->entityManager->persist($subscription);

        $this->logger?->info('Created subscription', [
            'user' => $user->getEmail(),
            'signal' => $signal->getTitle(),
        ]);

        return $subscription;
    }

    /**
     * Process multiple signals and create subscriptions for all.
     *
     * @param DemandSignal[] $signals
     *
     * @return int Number of subscriptions created
     */
    public function processSignals(array $signals): int
    {
        $totalCreated = 0;

        foreach ($signals as $signal) {
            $subscriptions = $this->createSubscriptionsForSignal($signal);
            $totalCreated += count($subscriptions);
        }

        $this->entityManager->flush();

        return $totalCreated;
    }

    /**
     * Get matching filters for a signal (for debugging/display purposes).
     *
     * @return array<array{user: string, filter: string}>
     */
    public function getMatchingFiltersInfo(DemandSignal $signal): array
    {
        $filters = $this->filterRepository->findMatchingFiltersForSignal($signal);
        $info = [];

        foreach ($filters as $filter) {
            $info[] = [
                'user' => $filter->getUser()->getEmail(),
                'filter' => $filter->getName(),
            ];
        }

        return $info;
    }
}
