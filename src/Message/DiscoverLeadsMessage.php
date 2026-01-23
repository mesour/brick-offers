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
     * @param string[] $queries Search queries (for query-based sources)
     * @param Uuid|null $profileId Discovery profile ID (optional, for profile-based discovery)
     * @param string|null $industryFilter Industry filter for the discovered leads
     * @param bool $autoAnalyze Whether to automatically analyze discovered leads
     * @param array<string, mixed> $sourceSettings Source-specific settings (e.g., school types for atlas_skolstvi)
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
        public ?Uuid $profileId = null,
        public ?string $industryFilter = null,
        public bool $autoAnalyze = false,
        public array $sourceSettings = [],
    ) {}
}
