<?php

declare(strict_types=1);

namespace App\Enum;

enum LeadType: string
{
    case WEBSITE = 'website';
    case BUSINESS_WITHOUT_WEB = 'business_without_web';

    public function getLabel(): string
    {
        return match ($this) {
            self::WEBSITE => 'Website',
            self::BUSINESS_WITHOUT_WEB => 'Business without website',
        };
    }

    public function hasWebsite(): bool
    {
        return $this === self::WEBSITE;
    }
}
