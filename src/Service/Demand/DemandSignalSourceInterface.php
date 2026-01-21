<?php

declare(strict_types=1);

namespace App\Service\Demand;

use App\Enum\DemandSignalSource;

/**
 * Interface for demand signal sources (job portals, tender sites, RFP platforms).
 */
interface DemandSignalSourceInterface
{
    /**
     * Check if this source supports the given source type.
     */
    public function supports(DemandSignalSource $source): bool;

    /**
     * Get the source type this implementation handles.
     */
    public function getSource(): DemandSignalSource;

    /**
     * Discover demand signals from the source.
     *
     * @param array<string, mixed> $options Source-specific options (query, category, region, etc.)
     * @return DemandSignalResult[]
     */
    public function discover(array $options = [], int $limit = 50): array;
}
