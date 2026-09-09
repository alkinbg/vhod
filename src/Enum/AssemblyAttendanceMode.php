<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyAttendanceMode: string
{
    case IN_PERSON = 'in_person';
    case ONLINE = 'online';
    case BY_PROXY = 'by_proxy';
    case STATUTORY_USER_AUTHORITY = 'statutory_user_authority';
}
