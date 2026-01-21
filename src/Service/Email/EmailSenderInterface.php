<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Enum\EmailProvider;

/**
 * Interface for email senders.
 */
interface EmailSenderInterface
{
    /**
     * Get the provider type.
     */
    public function getProvider(): EmailProvider;

    /**
     * Check if this sender supports the given provider.
     */
    public function supports(EmailProvider $provider): bool;

    /**
     * Send an email message.
     */
    public function send(EmailMessage $message): EmailSendResult;

    /**
     * Check if the sender is properly configured.
     */
    public function isConfigured(): bool;
}
