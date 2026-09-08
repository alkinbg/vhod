<?php

declare(strict_types=1);

namespace App\Enum;

enum ReconciliationMethod: string
{
    case AUTOMATIC = 'automatic';
    case MANUAL = 'manual';
}
