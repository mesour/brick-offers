<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for processing AWS SES webhooks asynchronously.
 *
 * Dispatched when SES sends bounce, complaint, or delivery notifications.
 * Processed by ProcessSesWebhookMessageHandler.
 */
final readonly class ProcessSesWebhookMessage
{
    /**
     * @param array<string, mixed> $payload Raw webhook payload from SES
     */
    public function __construct(
        public string $messageId,
        public string $notificationType,
        public array $payload,
    ) {}
}
