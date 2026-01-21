<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status enum for email delivery lifecycle.
 */
enum EmailStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case OPENED = 'opened';
    case CLICKED = 'clicked';
    case BOUNCED = 'bounced';
    case COMPLAINED = 'complained';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::SENT => 'Sent',
            self::DELIVERED => 'Delivered',
            self::OPENED => 'Opened',
            self::CLICKED => 'Clicked',
            self::BOUNCED => 'Bounced',
            self::COMPLAINED => 'Complained',
            self::FAILED => 'Failed',
        };
    }

    /**
     * Check if this is a successful delivery status.
     */
    public function isSuccessful(): bool
    {
        return match ($this) {
            self::SENT, self::DELIVERED, self::OPENED, self::CLICKED => true,
            default => false,
        };
    }

    /**
     * Check if this is a final failure status.
     */
    public function isFailed(): bool
    {
        return match ($this) {
            self::BOUNCED, self::COMPLAINED, self::FAILED => true,
            default => false,
        };
    }

    /**
     * Check if the email has been sent (regardless of outcome).
     */
    public function isSent(): bool
    {
        return $this !== self::PENDING;
    }
}
