<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * Email message DTO for sending.
 */
readonly class EmailMessage
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $metadata
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $htmlBody,
        public ?string $textBody = null,
        public ?string $toName = null,
        public ?string $from = null,
        public ?string $fromName = null,
        public ?string $replyTo = null,
        public array $headers = [],
        public array $metadata = [],
    ) {
    }

    /**
     * Get recipient domain.
     */
    public function getRecipientDomain(): string
    {
        $parts = explode('@', $this->to);

        return $parts[1] ?? '';
    }

    /**
     * Create from Offer entity.
     */
    public static function fromOffer(
        \App\Entity\Offer $offer,
        ?string $from = null,
        ?string $fromName = null,
    ): self {
        return new self(
            to: $offer->getRecipientEmail(),
            subject: $offer->getSubject(),
            htmlBody: $offer->getBody() ?? '',
            textBody: $offer->getPlainTextBody(),
            toName: $offer->getRecipientName(),
            from: $from,
            fromName: $fromName,
            metadata: [
                'offer_id' => $offer->getId()?->toRfc4122(),
                'tracking_token' => $offer->getTrackingToken(),
            ],
        );
    }
}
