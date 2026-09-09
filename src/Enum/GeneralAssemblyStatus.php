<?php

declare(strict_types=1);

namespace App\Enum;

enum GeneralAssemblyStatus: string
{
    case DRAFT = 'draft';
    case CONVENED = 'convened';
    case IN_PROGRESS = 'in_progress';
    case CLOSED = 'closed';
    case MINUTES_FINALIZED = 'minutes_finalized';
}
