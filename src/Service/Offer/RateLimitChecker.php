<?php

declare(strict_types=1);

namespace App\Service\Offer;

use App\Entity\User;
use App\Repository\OfferRepository;

/**
 * Service for checking rate limits before sending offers.
 */
class RateLimitChecker
{
    private const DEFAULT_EMAILS_PER_HOUR = 10;
    private const DEFAULT_EMAILS_PER_DAY = 50;
    private const DEFAULT_EMAILS_PER_DOMAIN_DAY = 3;

    public function __construct(
        private readonly OfferRepository $repository,
    ) {
    }

    /**
     * Check if sending is allowed for user to a specific domain.
     */
    public function canSend(User $user, string $recipientDomain): RateLimitResult
    {
        $limits = $this->getLimits($user);
        $currentUsage = $this->getCurrentUsage($user, $recipientDomain);

        // Check hourly limit
        if ($currentUsage['per_hour'] >= $limits['emails_per_hour']) {
            return RateLimitResult::denied(
                reason: sprintf(
                    'Hourly limit reached (%d/%d emails)',
                    $currentUsage['per_hour'],
                    $limits['emails_per_hour']
                ),
                retryAfterSeconds: $this->getSecondsUntilNextHour(),
                currentUsage: $currentUsage,
                limits: $limits,
            );
        }

        // Check daily limit
        if ($currentUsage['per_day'] >= $limits['emails_per_day']) {
            return RateLimitResult::denied(
                reason: sprintf(
                    'Daily limit reached (%d/%d emails)',
                    $currentUsage['per_day'],
                    $limits['emails_per_day']
                ),
                retryAfterSeconds: $this->getSecondsUntilTomorrow(),
                currentUsage: $currentUsage,
                limits: $limits,
            );
        }

        // Check per-domain limit
        if ($currentUsage['per_domain_day'] >= $limits['emails_per_domain_day']) {
            return RateLimitResult::denied(
                reason: sprintf(
                    'Domain limit reached for %s (%d/%d emails today)',
                    $recipientDomain,
                    $currentUsage['per_domain_day'],
                    $limits['emails_per_domain_day']
                ),
                retryAfterSeconds: $this->getSecondsUntilTomorrow(),
                currentUsage: $currentUsage,
                limits: $limits,
            );
        }

        return RateLimitResult::allowed($currentUsage, $limits);
    }

    /**
     * Check hourly limit only.
     */
    public function checkHourlyLimit(User $user): bool
    {
        $limits = $this->getLimits($user);
        $sentLastHour = $this->repository->countSentLastHour($user);

        return $sentLastHour < $limits['emails_per_hour'];
    }

    /**
     * Check daily limit only.
     */
    public function checkDailyLimit(User $user): bool
    {
        $limits = $this->getLimits($user);
        $sentToday = $this->repository->countSentToday($user);

        return $sentToday < $limits['emails_per_day'];
    }

    /**
     * Check per-domain daily limit only.
     */
    public function checkDomainLimit(User $user, string $domain): bool
    {
        $limits = $this->getLimits($user);
        $sentToDomain = $this->repository->countSentToDomainToday($user, $domain);

        return $sentToDomain < $limits['emails_per_domain_day'];
    }

    /**
     * Get current usage stats.
     *
     * @return array<string, int>
     */
    public function getCurrentUsage(User $user, ?string $domain = null): array
    {
        $usage = [
            'per_hour' => $this->repository->countSentLastHour($user),
            'per_day' => $this->repository->countSentToday($user),
            'per_domain_day' => 0,
        ];

        if ($domain !== null) {
            $usage['per_domain_day'] = $this->repository->countSentToDomainToday($user, $domain);
        }

        return $usage;
    }

    /**
     * Get rate limits from user settings.
     *
     * @return array<string, int>
     */
    public function getLimits(User $user): array
    {
        $settings = $user->getSettings();
        $rateLimits = $settings['rate_limits'] ?? [];

        return [
            'emails_per_hour' => $rateLimits['emails_per_hour'] ?? self::DEFAULT_EMAILS_PER_HOUR,
            'emails_per_day' => $rateLimits['emails_per_day'] ?? self::DEFAULT_EMAILS_PER_DAY,
            'emails_per_domain_day' => $rateLimits['emails_per_domain_day'] ?? self::DEFAULT_EMAILS_PER_DOMAIN_DAY,
        ];
    }

    private function getSecondsUntilNextHour(): int
    {
        $now = new \DateTimeImmutable();
        $nextHour = $now->modify('+1 hour')->setTime((int) $now->format('H') + 1, 0, 0);

        return $nextHour->getTimestamp() - $now->getTimestamp();
    }

    private function getSecondsUntilTomorrow(): int
    {
        $now = new \DateTimeImmutable();
        $tomorrow = $now->modify('tomorrow');

        return $tomorrow->getTimestamp() - $now->getTimestamp();
    }
}
