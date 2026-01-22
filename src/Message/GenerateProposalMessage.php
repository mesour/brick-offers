<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message for generating a proposal asynchronously.
 *
 * Dispatched when a proposal needs to be generated for a lead.
 * Processed by GenerateProposalMessageHandler.
 */
final readonly class GenerateProposalMessage
{
    public function __construct(
        public Uuid $leadId,
        public Uuid $userId,
        public string $proposalType,
        public ?Uuid $analysisId = null,
    ) {}
}
