<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message for taking website screenshots asynchronously.
 *
 * Dispatched when a screenshot needs to be captured for a lead.
 * Processed by TakeScreenshotMessageHandler.
 */
final readonly class TakeScreenshotMessage
{
    /**
     * @param array<string, mixed> $options Screenshot options (width, height, fullPage, etc.)
     */
    public function __construct(
        public Uuid $leadId,
        public array $options = [],
    ) {}
}
