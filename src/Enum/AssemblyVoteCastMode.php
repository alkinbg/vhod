<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyVoteCastMode: string
{
    case ATTENDANCE = 'attendance';
    case PROXY = 'proxy';
    case STATUTORY_AUTHORITY = 'statutory_authority';
    case ABSENTEE = 'absentee';
}
