<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyVoteChoice: string
{
    case FOR = 'for';
    case AGAINST = 'against';
    case ABSTAIN = 'abstain';
}
