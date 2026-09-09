<?php

declare(strict_types=1);

namespace App\Enum;

enum MajorityComparison: string
{
    case GREATER_THAN = 'greater_than';
    case AT_LEAST = 'at_least';
}
