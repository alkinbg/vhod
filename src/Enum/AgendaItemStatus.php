<?php

declare(strict_types=1);

namespace App\Enum;

enum AgendaItemStatus: string
{
    case PLANNED = 'planned';
    case OPEN = 'open';
    case ABSENTEE_WINDOW = 'absentee_window';
    case RESOLVED = 'resolved';
}
