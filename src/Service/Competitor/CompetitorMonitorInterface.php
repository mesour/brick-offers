<?php

declare(strict_types=1);

namespace App\Service\Competitor;

use App\Entity\CompetitorSnapshot;
use App\Entity\Lead;
use App\Enum\CompetitorSnapshotType;

/**
 * Interface for competitor monitoring implementations.
 * Each implementation monitors a specific aspect (portfolio, pricing, services, etc.).
 */
interface CompetitorMonitorInterface
{
    /**
     * Get the snapshot type this monitor handles.
     */
    public function getType(): CompetitorSnapshotType;

    /**
     * Check if this monitor supports the given type.
     */
    public function supports(CompetitorSnapshotType $type): bool;

    /**
     * Create a snapshot of the competitor's current state.
     */
    public function createSnapshot(Lead $competitor): ?CompetitorSnapshot;

    /**
     * Compare two snapshots and detect changes.
     *
     * @return array<array{field: string, before: mixed, after: mixed, significance: string}>
     */
    public function detectChanges(CompetitorSnapshot $previous, CompetitorSnapshot $current): array;
}
