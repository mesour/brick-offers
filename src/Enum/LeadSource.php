<?php

declare(strict_types=1);

namespace App\Enum;

enum LeadSource: string
{
    case MANUAL = 'manual';
    case GOOGLE = 'google';
    case SEZNAM = 'seznam';
    case FIRMY_CZ = 'firmy_cz';
    case ZIVE_FIRMY = 'zive_firmy';
    case NAJISTO = 'najisto';
    case ZLATESTRANKY = 'zlatestranky';
    case CRAWLER = 'crawler';
    case REFERENCE_CRAWLER = 'reference_crawler';
}
