<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\BatchDiscoveryMessage;
use App\Message\DiscoverLeadsMessage;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handler for running batch discovery for configured users.
 *
 * Users can configure discovery in their settings:
 * {
 *   "discovery": {
 *     "enabled": true,
 *     "sources": ["google", "firmy_cz"],
 *     "queries": ["webdesign Praha", "tvorba webu"],
 *     "limit": 50
 *   }
 * }
 */
#[AsMessageHandler]
final class BatchDiscoveryMessageHandler
{
    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{users_processed: int, jobs_dispatched: int, users_skipped: int}
     */
    public function __invoke(BatchDiscoveryMessage $message): array
    {
        $this->logger->info('Starting batch discovery', [
            'user_code' => $message->userCode,
            'all_users' => $message->allUsers,
            'dry_run' => $message->dryRun,
        ]);

        // Get users to process
        $users = $this->getUsers($message);

        $stats = [
            'users_processed' => 0,
            'jobs_dispatched' => 0,
            'users_skipped' => 0,
        ];

        foreach ($users as $user) {
            $settings = $user->getSetting('discovery', []);

            // Skip if discovery not enabled
            if (!($settings['enabled'] ?? false)) {
                $this->logger->debug('Discovery not enabled for user', ['user_code' => $user->getCode()]);
                $stats['users_skipped']++;

                continue;
            }

            // Validate settings
            $sources = $settings['sources'] ?? [];
            $queries = $settings['queries'] ?? [];
            $limit = (int) ($settings['limit'] ?? self::DEFAULT_LIMIT);

            if (empty($sources) || empty($queries)) {
                $this->logger->debug('Incomplete discovery config for user', [
                    'user_code' => $user->getCode(),
                    'sources' => $sources,
                    'queries_count' => count($queries),
                ]);
                $stats['users_skipped']++;

                continue;
            }

            $stats['users_processed']++;

            // Dispatch discovery jobs for each source
            foreach ($sources as $source) {
                $userId = $user->getId();
                if ($userId === null) {
                    continue;
                }

                $this->logger->info('Dispatching discovery job', [
                    'user_code' => $user->getCode(),
                    'source' => $source,
                    'queries_count' => count($queries),
                    'limit' => $limit,
                    'dry_run' => $message->dryRun,
                ]);

                if (!$message->dryRun) {
                    $this->messageBus->dispatch(new DiscoverLeadsMessage(
                        source: $source,
                        queries: $queries,
                        userId: $userId,
                        limit: $limit,
                        extractData: true,
                        linkCompany: true,
                    ));
                }

                $stats['jobs_dispatched']++;
            }
        }

        $this->logger->info('Batch discovery completed', $stats);

        return $stats;
    }

    /**
     * @return array<\App\Entity\User>
     */
    private function getUsers(BatchDiscoveryMessage $message): array
    {
        // Specific user
        if ($message->userCode !== null) {
            $user = $this->userRepository->findByCode($message->userCode);

            return $user !== null ? [$user] : [];
        }

        // All active users (either when allUsers is true or in scheduler mode)
        if ($message->allUsers || ($message->userCode === null && !$message->dryRun)) {
            return $this->userRepository->findActive();
        }

        // For dry run without specific user, show all active users
        return $this->userRepository->findActive();
    }
}
