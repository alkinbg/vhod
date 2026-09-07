<?php

declare(strict_types=1);

namespace App\Enum;

enum UnitRelationType: string
{
    case OWNER = 'owner';
    case USER = 'user';
    case OCCUPANT = 'occupant';
}
