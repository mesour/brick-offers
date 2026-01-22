<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for expiring proposals with passed expiresAt dates.
 *
 * Dispatched by scheduler daily or manually via CLI.
 * Processed by ExpireProposalsMessageHandler.
 */
final readonly class ExpireProposalsMessage
{
    public function __construct(
        public bool $dryRun = false,
    ) {}
}
