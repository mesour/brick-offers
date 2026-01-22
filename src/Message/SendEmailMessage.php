<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message for sending emails asynchronously.
 *
 * Dispatched when an offer is approved and ready to be sent.
 * Processed by SendEmailMessageHandler.
 */
final readonly class SendEmailMessage
{
    public function __construct(
        public Uuid $offerId,
        public ?Uuid $userId = null,
    ) {}
}
