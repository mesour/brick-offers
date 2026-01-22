<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message for checking SSL certificates that are expiring soon.
 *
 * Dispatched by scheduler daily or manually via CLI.
 * Processed by CheckSslCertificatesMessageHandler.
 */
final readonly class CheckSslCertificatesMessage
{
    public function __construct(
        public int $thresholdDays = 30,
        public ?string $userCode = null,
        public bool $dryRun = false,
    ) {}
}
