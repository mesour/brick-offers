<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for running batch discovery for configured users.
 *
 * Dispatched by scheduler weekly or manually via CLI.
 * Processed by BatchDiscoveryMessageHandler.
 */
final readonly class BatchDiscoveryMessage
{
    public function __construct(
        public ?string $userCode = null,
        public bool $allUsers = false,
        public bool $dryRun = false,
    ) {}
}
