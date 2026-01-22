<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\BatchDiscoveryMessage;
use App\Message\CalculateBenchmarksMessage;
use App\Message\CheckSslCertificatesMessage;
use App\Message\CleanupOldDataMessage;
use App\Message\ExpireProposalsMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Main schedule provider for recurring background tasks.
 *
 * Defines scheduled tasks that run periodically:
 * - Daily proposal expiration
 * - Daily SSL certificate check
 * - Weekly benchmark calculation
 * - Weekly data cleanup
 * - Weekly batch discovery
 */
#[AsSchedule('default')]
final class MainScheduleProvider implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        if ($this->schedule !== null) {
            return $this->schedule;
        }

        $this->schedule = (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        // Expire proposals daily at 2:00 AM
        $this->schedule->add(
            RecurringMessage::cron('0 2 * * *', new ExpireProposalsMessage())
        );

        // Calculate industry benchmarks weekly (Monday at 3:00 AM)
        $this->schedule->add(
            RecurringMessage::cron(
                '0 3 * * 1',
                new CalculateBenchmarksMessage(recalculateAll: true),
            )
        );

        // Cleanup old data weekly on Sunday at 4:00 AM
        $this->schedule->add(
            RecurringMessage::cron(
                '0 4 * * 0',
                new CleanupOldDataMessage(target: CleanupOldDataMessage::TARGET_ALL),
            )
        );

        // Batch discovery weekly on Monday at 5:00 AM
        $this->schedule->add(
            RecurringMessage::cron('0 5 * * 1', new BatchDiscoveryMessage(allUsers: true))
        );

        // Check SSL certificates daily at 6:00 AM
        $this->schedule->add(
            RecurringMessage::cron(
                '0 6 * * *',
                new CheckSslCertificatesMessage(thresholdDays: 30),
            )
        );

        return $this->schedule;
    }
}
