<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyQuorumCheckKind: string
{
    case FIRST_CALL = 'first_call';
    case DELAYED_CALL = 'delayed_call';
}
