<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message for generating an offer asynchronously.
 *
 * Dispatched when an offer needs to be generated for a lead.
 * Processed by GenerateOfferMessageHandler.
 */
final readonly class GenerateOfferMessage
{
    public function __construct(
        public Uuid $leadId,
        public Uuid $userId,
        public ?string $recipientEmail = null,
        public ?Uuid $proposalId = null,
    ) {}
}
