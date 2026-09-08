<?php

declare(strict_types=1);

namespace App\Enum;

enum FeeCategory: string
{
    case MANAGEMENT_MAINTENANCE = 'management_maintenance';
    case REPAIR_RENOVATION = 'repair_renovation';
    case OTHER = 'other';
}
