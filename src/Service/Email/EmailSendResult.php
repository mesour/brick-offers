<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * Result of email send operation.
 */
readonly class EmailSendResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $success,
        public ?string $messageId = null,
        public ?string $error = null,
        public array $metadata = [],
    ) {
    }

    /**
     * Create success result.
     *
     * @param array<string, mixed> $metadata
     */
    public static function success(string $messageId, array $metadata = []): self
    {
        return new self(
            success: true,
            messageId: $messageId,
            metadata: $metadata,
        );
    }

    /**
     * Create failure result.
     *
     * @param array<string, mixed> $metadata
     */
    public static function failure(string $error, array $metadata = []): self
    {
        return new self(
            success: false,
            error: $error,
            metadata: $metadata,
        );
    }
}
