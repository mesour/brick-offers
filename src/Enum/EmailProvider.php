<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Email provider/transport type.
 */
enum EmailProvider: string
{
    case SMTP = 'smtp';
    case SES = 'ses';
    case NULL = 'null';
    case LOG = 'log';

    public function label(): string
    {
        return match ($this) {
            self::SMTP => 'SMTP',
            self::SES => 'Amazon SES',
            self::NULL => 'Null (Testing)',
            self::LOG => 'File Log (Local Testing)',
        };
    }

    /**
     * Check if this provider requires AWS credentials.
     */
    public function requiresAwsCredentials(): bool
    {
        return $this === self::SES;
    }

    /**
     * Check if this is a production-ready provider.
     */
    public function isProduction(): bool
    {
        return match ($this) {
            self::SES => true,
            self::SMTP, self::NULL, self::LOG => false,
        };
    }
}
