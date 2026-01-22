<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for cleaning up old data from various entities.
 *
 * Dispatched by scheduler weekly or manually via CLI.
 * Processed by CleanupOldDataMessageHandler.
 */
final readonly class CleanupOldDataMessage
{
    public const TARGET_ALL = 'all';
    public const TARGET_EMAIL = 'email';
    public const TARGET_ANALYSIS = 'analysis';
    public const TARGET_COMPETITOR = 'competitor';
    public const TARGET_DEMAND = 'demand';

    public const VALID_TARGETS = [
        self::TARGET_ALL,
        self::TARGET_EMAIL,
        self::TARGET_ANALYSIS,
        self::TARGET_COMPETITOR,
        self::TARGET_DEMAND,
    ];

    public function __construct(
        public string $target = self::TARGET_ALL,
        public bool $dryRun = false,
    ) {}
}
