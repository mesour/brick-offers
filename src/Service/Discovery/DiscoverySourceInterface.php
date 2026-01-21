<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Enum\LeadSource;

interface DiscoverySourceInterface
{
    public function supports(LeadSource $source): bool;

    /**
     * Discover URLs from the source.
     *
     * @return array<DiscoveryResult>
     */
    public function discover(string $query, int $limit = 50): array;

    public function getSource(): LeadSource;
}
