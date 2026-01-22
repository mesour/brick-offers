<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for syncing company data from ARES asynchronously.
 *
 * Dispatched when company data needs to be fetched/updated from Czech ARES registry.
 * Processed by SyncAresDataMessageHandler.
 */
final readonly class SyncAresDataMessage
{
    /**
     * @param list<string> $icos List of IČO numbers to sync
     */
    public function __construct(
        public array $icos,
    ) {}
}
