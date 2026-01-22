<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message for discovering new leads asynchronously.
 *
 * Dispatched for batch lead discovery from various sources.
 * Processed by DiscoverLeadsMessageHandler.
 */
final readonly class DiscoverLeadsMessage
{
    /**
     * @param string[] $queries Search queries
     */
    public function __construct(
        public string $source,
        public array $queries,
        public Uuid $userId,
        public int $limit = 100,
        public ?string $affiliateHash = null,
        public int $priority = 5,
        public bool $extractData = false,
        public bool $linkCompany = false,
    ) {}
}
