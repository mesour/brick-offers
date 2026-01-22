<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckSslCertificatesMessage;
use App\Repository\AnalysisResultRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for checking SSL certificates that are expiring soon.
 *
 * This handler generates a report of SSL certificates expiring within the threshold.
 * In the future, this could be extended to send email notifications.
 */
#[AsMessageHandler]
final class CheckSslCertificatesMessageHandler
{
    public function __construct(
        private readonly AnalysisResultRepository $analysisResultRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{total: int, by_user: array<string, array<array{domain: string, expires_days: int}>>}
     */
    public function __invoke(CheckSslCertificatesMessage $message): array
    {
        $this->logger->info('Starting SSL certificate check', [
            'threshold_days' => $message->thresholdDays,
            'user_code' => $message->userCode,
            'dry_run' => $message->dryRun,
        ]);

        $results = $this->analysisResultRepository->findWithExpiringSsl(
            $message->thresholdDays,
            $message->userCode
        );

        if (empty($results)) {
            $this->logger->info('No SSL certificates expiring soon');

            return ['total' => 0, 'by_user' => []];
        }

        // Group by user
        $byUser = [];
        foreach ($results as $row) {
            $userCode = $row['user_code'];
            if (!isset($byUser[$userCode])) {
                $byUser[$userCode] = [];
            }
            $byUser[$userCode][] = [
                'domain' => $row['domain'],
                'expires_days' => (int) $row['expires_days'],
            ];
        }

        $this->logger->info('SSL certificate check completed', [
            'total' => count($results),
            'users_affected' => count($byUser),
        ]);

        // Log details for each user
        foreach ($byUser as $userCode => $domains) {
            $this->logger->warning('SSL certificates expiring soon for user', [
                'user_code' => $userCode,
                'count' => count($domains),
                'domains' => array_map(
                    fn ($d) => sprintf('%s (%d days)', $d['domain'], $d['expires_days']),
                    $domains
                ),
            ]);
        }

        return [
            'total' => count($results),
            'by_user' => $byUser,
        ];
    }
}
