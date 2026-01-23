<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\DiscoveryProfile;
use App\Message\BatchDiscoveryMessage;
use App\Message\DiscoverLeadsMessage;
use App\Repository\DiscoveryProfileRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handler for running batch discovery using DiscoveryProfile entities.
 */
#[AsMessageHandler]
final class BatchDiscoveryMessageHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly DiscoveryProfileRepository $profileRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{users_processed: int, profiles_processed: int, jobs_dispatched: int, profiles_skipped: int}
     */
    public function __invoke(BatchDiscoveryMessage $message): array
    {
        $this->logger->info('Starting batch discovery', [
            'user_code' => $message->userCode,
            'profile_name' => $message->profileName,
            'all_users' => $message->allUsers,
            'dry_run' => $message->dryRun,
        ]);

        $stats = [
            'users_processed' => 0,
            'profiles_processed' => 0,
            'jobs_dispatched' => 0,
            'profiles_skipped' => 0,
        ];

        // Get profiles to process
        $profiles = $this->getProfiles($message);
        $processedUsers = [];

        foreach ($profiles as $profile) {
            $user = $profile->getUser();
            if ($user === null) {
                $stats['profiles_skipped']++;
                continue;
            }

            // Track processed users
            $userId = $user->getId()?->toRfc4122();
            if ($userId !== null && !isset($processedUsers[$userId])) {
                $processedUsers[$userId] = true;
                $stats['users_processed']++;
            }

            // Skip if discovery not enabled
            if (!$profile->isDiscoveryEnabled()) {
                $this->logger->debug('Discovery not enabled for profile', [
                    'profile_name' => $profile->getName(),
                    'user_code' => $user->getCode(),
                ]);
                $stats['profiles_skipped']++;
                continue;
            }

            // Validate settings - now single source
            $source = $profile->getDiscoverySource();
            $queries = $profile->getQueries();

            if ($source === null) {
                $this->logger->debug('No discovery source configured for profile', [
                    'profile_name' => $profile->getName(),
                    'user_code' => $user->getCode(),
                ]);
                $stats['profiles_skipped']++;
                continue;
            }

            // For query-based sources, we need queries
            $sourceEnum = \App\Enum\LeadSource::tryFrom($source);
            if ($sourceEnum !== null && $sourceEnum->isQueryBased() && empty($queries)) {
                $this->logger->debug('No queries configured for query-based source', [
                    'profile_name' => $profile->getName(),
                    'user_code' => $user->getCode(),
                    'source' => $source,
                ]);
                $stats['profiles_skipped']++;
                continue;
            }

            $stats['profiles_processed']++;

            // Dispatch discovery job for the single source
            $userIdUuid = $user->getId();
            $profileId = $profile->getId();
            if ($userIdUuid === null || $profileId === null) {
                continue;
            }

            $this->logger->info('Dispatching discovery job', [
                'profile_name' => $profile->getName(),
                'user_code' => $user->getCode(),
                'source' => $source,
                'queries_count' => count($queries),
                'limit' => $profile->getDiscoveryLimit(),
                'auto_analyze' => $profile->isAutoAnalyze(),
                'dry_run' => $message->dryRun,
            ]);

            if (!$message->dryRun) {
                $this->messageBus->dispatch(new DiscoverLeadsMessage(
                    source: $source,
                    queries: $queries,
                    userId: $userIdUuid,
                    limit: $profile->getDiscoveryLimit(),
                    priority: $profile->getPriority(),
                    extractData: $profile->isExtractData(),
                    linkCompany: $profile->isLinkCompany(),
                    profileId: $profileId,
                    industryFilter: $user->getIndustry()?->value,
                    autoAnalyze: $profile->isAutoAnalyze(),
                    sourceSettings: $profile->getSourceSettings(),
                ));
            }

            $stats['jobs_dispatched']++;
        }

        $this->logger->info('Batch discovery completed', $stats);

        return $stats;
    }

    /**
     * Get profiles to process.
     *
     * @return array<DiscoveryProfile>
     */
    private function getProfiles(BatchDiscoveryMessage $message): array
    {
        // Specific user + profile name
        if ($message->userCode !== null && $message->profileName !== null) {
            $user = $this->userRepository->findByCode($message->userCode);
            if ($user === null) {
                return [];
            }
            $profile = $this->profileRepository->findByNameAndUser($message->profileName, $user);

            return $profile !== null ? [$profile] : [];
        }

        // Specific user - all active profiles
        if ($message->userCode !== null) {
            $user = $this->userRepository->findByCode($message->userCode);
            if ($user === null) {
                return [];
            }

            return $this->profileRepository->findActiveForUser($user);
        }

        // All active profiles from all active users
        return $this->profileRepository->findAllActiveForBatch();
    }
}
