<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\Industry;

/**
 * Message for calculating industry benchmarks asynchronously.
 *
 * Dispatched for batch benchmark calculations.
 * Processed by CalculateBenchmarksMessageHandler.
 */
final readonly class CalculateBenchmarksMessage
{
    public function __construct(
        public ?Industry $industry = null,
        public bool $recalculateAll = false,
    ) {}
}
