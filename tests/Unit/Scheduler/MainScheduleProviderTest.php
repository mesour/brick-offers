<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Scheduler\MainScheduleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Contracts\Cache\CacheInterface;

final class MainScheduleProviderTest extends TestCase
{
    public function testGetSchedule_returnsSchedule(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $provider = new MainScheduleProvider($cache);

        $schedule = $provider->getSchedule();

        self::assertInstanceOf(Schedule::class, $schedule);
    }

    public function testGetSchedule_returnsSameInstance(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $provider = new MainScheduleProvider($cache);

        $schedule1 = $provider->getSchedule();
        $schedule2 = $provider->getSchedule();

        self::assertSame($schedule1, $schedule2);
    }

    public function testGetSchedule_hasRecurringMessages(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $provider = new MainScheduleProvider($cache);

        $schedule = $provider->getSchedule();
        $messages = $schedule->getRecurringMessages();

        self::assertNotEmpty($messages);
        self::assertContainsOnlyInstancesOf(RecurringMessage::class, $messages);
    }

    public function testGetSchedule_processesOnlyLastMissedRun(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $provider = new MainScheduleProvider($cache);

        $schedule = $provider->getSchedule();

        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
    }

    public function testGetSchedule_hasAtLeastOneScheduledTask(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $provider = new MainScheduleProvider($cache);

        $schedule = $provider->getSchedule();
        $messages = $schedule->getRecurringMessages();

        self::assertGreaterThanOrEqual(1, count($messages));
    }
}
