<?php

declare(strict_types=1);

namespace App\Enum;

enum FundType: string
{
    case OPERATING = 'operating';
    case REPAIR_RENOVATION = 'repair_renovation';
    case OTHER = 'other';
}
