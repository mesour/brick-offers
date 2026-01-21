<?php

declare(strict_types=1);

namespace App\Enum;

enum AnalysisStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
