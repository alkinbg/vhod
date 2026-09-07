<?php

declare(strict_types=1);

namespace App\Enum;

enum UnitType: string
{
    case APARTMENT = 'apartment';
    case GARAGE = 'garage';
    case SHOP = 'shop';
    case OTHER = 'other';
}
