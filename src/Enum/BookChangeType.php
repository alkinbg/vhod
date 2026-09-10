<?php

declare(strict_types=1);

namespace App\Enum;

enum BookChangeType: string
{
    case CONTACT_UPDATE = 'contact_update';
    case HOUSEHOLD_MEMBER_ADD = 'household_member_add';
    case HOUSEHOLD_MEMBER_END = 'household_member_end';
    case ABSENCE = 'absence';
    case ANIMAL = 'animal';
}
