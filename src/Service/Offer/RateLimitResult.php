<?php

declare(strict_types=1);

namespace App\Service\Offer;

/**
 * DTO representing rate limit check result.
 */
readonly class RateLimitResult
{
    /**
     * @param array<string, int> $currentUsage
     * @param array<string, int> $limits
     */
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public ?int $retryAfterSeconds = null,
        public array $currentUsage = [],
        public array $limits = [],
    ) {
    }

    /**
     * Create an allowed result.
     *
     * @param array<string, int> $currentUsage
     * @param array<string, int> $limits
     */
    public static function allowed(array $currentUsage = [], array $limits = []): self
    {
        return new self(
            allowed: true,
            currentUsage: $currentUsage,
            limits: $limits,
        );
    }

    /**
     * Create a denied result.
     *
     * @param array<string, int> $currentUsage
     * @param array<string, int> $limits
     */
    public static function denied(
        string $reason,
        ?int $retryAfterSeconds = null,
        array $currentUsage = [],
        array $limits = [],
    ): self {
        return new self(
            allowed: false,
            reason: $reason,
            retryAfterSeconds: $retryAfterSeconds,
            currentUsage: $currentUsage,
            limits: $limits,
        );
    }

    /**
     * Get remaining quota for a specific limit type.
     */
    public function getRemainingQuota(string $type): ?int
    {
        if (!isset($this->limits[$type]) || !isset($this->currentUsage[$type])) {
            return null;
        }

        return max(0, $this->limits[$type] - $this->currentUsage[$type]);
    }
}
