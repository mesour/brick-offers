<?php

declare(strict_types=1);

namespace App\Service\Offer;

/**
 * DTO representing generated offer content.
 */
readonly class OfferContent
{
    /**
     * @param array<string, mixed> $aiMetadata
     */
    public function __construct(
        public string $subject,
        public string $body,
        public ?string $plainTextBody,
        public array $aiMetadata,
        public bool $success,
        public ?string $error = null,
    ) {
    }

    /**
     * Create a successful result.
     *
     * @param array<string, mixed> $aiMetadata
     */
    public static function success(
        string $subject,
        string $body,
        ?string $plainTextBody = null,
        array $aiMetadata = [],
    ): self {
        return new self(
            subject: $subject,
            body: $body,
            plainTextBody: $plainTextBody,
            aiMetadata: $aiMetadata,
            success: true,
        );
    }

    /**
     * Create an error result.
     */
    public static function error(string $error): self
    {
        return new self(
            subject: '',
            body: '',
            plainTextBody: null,
            aiMetadata: [],
            success: false,
            error: $error,
        );
    }
}
