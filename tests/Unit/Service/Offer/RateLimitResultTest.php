<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Offer;

use App\Service\Offer\RateLimitResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimitResult::class)]
final class RateLimitResultTest extends TestCase
{
    // ==================== allowed() Factory Tests ====================

    #[Test]
    public function allowed_createsAllowedResult(): void
    {
        $result = RateLimitResult::allowed();

        self::assertTrue($result->allowed);
        self::assertNull($result->reason);
        self::assertNull($result->retryAfterSeconds);
        self::assertSame([], $result->currentUsage);
        self::assertSame([], $result->limits);
    }

    #[Test]
    public function allowed_withUsageAndLimits_setsValues(): void
    {
        $usage = ['per_hour' => 5, 'per_day' => 20];
        $limits = ['per_hour' => 10, 'per_day' => 100];

        $result = RateLimitResult::allowed($usage, $limits);

        self::assertTrue($result->allowed);
        self::assertSame($usage, $result->currentUsage);
        self::assertSame($limits, $result->limits);
    }

    // ==================== denied() Factory Tests ====================

    #[Test]
    public function denied_createsDeniedResult(): void
    {
        $result = RateLimitResult::denied('Hourly limit exceeded');

        self::assertFalse($result->allowed);
        self::assertSame('Hourly limit exceeded', $result->reason);
        self::assertNull($result->retryAfterSeconds);
    }

    #[Test]
    public function denied_withRetryAfter_setsRetrySeconds(): void
    {
        $result = RateLimitResult::denied('Rate limit exceeded', 3600);

        self::assertFalse($result->allowed);
        self::assertSame('Rate limit exceeded', $result->reason);
        self::assertSame(3600, $result->retryAfterSeconds);
    }

    #[Test]
    public function denied_withUsageAndLimits_setsValues(): void
    {
        $usage = ['per_hour' => 10, 'per_day' => 50];
        $limits = ['per_hour' => 10, 'per_day' => 100];

        $result = RateLimitResult::denied(
            'Hourly limit reached',
            1800,
            $usage,
            $limits,
        );

        self::assertFalse($result->allowed);
        self::assertSame('Hourly limit reached', $result->reason);
        self::assertSame(1800, $result->retryAfterSeconds);
        self::assertSame($usage, $result->currentUsage);
        self::assertSame($limits, $result->limits);
    }

    // ==================== Constructor Tests ====================

    #[Test]
    public function constructor_allowsManualCreation(): void
    {
        $result = new RateLimitResult(
            allowed: true,
            reason: null,
            retryAfterSeconds: null,
            currentUsage: ['per_hour' => 3],
            limits: ['per_hour' => 10],
        );

        self::assertTrue($result->allowed);
        self::assertNull($result->reason);
        self::assertSame(['per_hour' => 3], $result->currentUsage);
        self::assertSame(['per_hour' => 10], $result->limits);
    }

    // ==================== getRemainingQuota Tests ====================

    #[Test]
    public function getRemainingQuota_existingType_returnsCorrectValue(): void
    {
        $result = RateLimitResult::allowed(
            ['per_hour' => 3, 'per_day' => 25],
            ['per_hour' => 10, 'per_day' => 100],
        );

        self::assertSame(7, $result->getRemainingQuota('per_hour'));
        self::assertSame(75, $result->getRemainingQuota('per_day'));
    }

    #[Test]
    public function getRemainingQuota_exceedsLimit_returnsZero(): void
    {
        $result = RateLimitResult::denied(
            'Limit exceeded',
            null,
            ['per_hour' => 15],
            ['per_hour' => 10],
        );

        self::assertSame(0, $result->getRemainingQuota('per_hour'));
    }

    #[Test]
    public function getRemainingQuota_unknownType_returnsNull(): void
    {
        $result = RateLimitResult::allowed(
            ['per_hour' => 5],
            ['per_hour' => 10],
        );

        self::assertNull($result->getRemainingQuota('per_day'));
        self::assertNull($result->getRemainingQuota('unknown'));
    }

    #[Test]
    public function getRemainingQuota_missingInLimits_returnsNull(): void
    {
        $result = RateLimitResult::allowed(
            ['per_hour' => 5],
            [],
        );

        self::assertNull($result->getRemainingQuota('per_hour'));
    }

    #[Test]
    public function getRemainingQuota_missingInUsage_returnsNull(): void
    {
        $result = RateLimitResult::allowed(
            [],
            ['per_hour' => 10],
        );

        self::assertNull($result->getRemainingQuota('per_hour'));
    }

    // ==================== Readonly Tests ====================

    #[Test]
    public function class_isReadonly(): void
    {
        $reflection = new \ReflectionClass(RateLimitResult::class);

        self::assertTrue($reflection->isReadOnly());
    }
}
