<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message for analyzing a lead's website asynchronously.
 *
 * Dispatched when a lead needs analysis (new lead, re-analysis request).
 * Processed by AnalyzeLeadMessageHandler.
 */
final readonly class AnalyzeLeadMessage
{
    public function __construct(
        public Uuid $leadId,
        public bool $reanalyze = false,
        public ?string $industryFilter = null,
    ) {}
}
