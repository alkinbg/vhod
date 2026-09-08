<?php

declare(strict_types=1);

namespace App\Enum;

enum FeeDistribution: string
{
    case PER_PERSON = 'per_person';
    case PER_UNIT = 'per_unit';
    case IDEAL_PARTS = 'ideal_parts';
}
