<?php

declare(strict_types=1);

namespace App\Enum;

enum BookChangeType: string
{
    case CONTACT_UPDATE = 'contact_update';
    case HOUSEHOLD_MEMBER_ADD = 'household_member_add';
    case ABSENCE = 'absence';
    case ANIMAL = 'animal';
}
