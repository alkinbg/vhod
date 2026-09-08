<?php

declare(strict_types=1);

namespace App\Enum;

enum ExpenseReportSection: string
{
    case MANAGEMENT = 'management';
    case MAINTENANCE = 'maintenance';
    case REPAIRS = 'repairs';
    case SERVICES = 'services';
    case OTHER = 'other';
}
