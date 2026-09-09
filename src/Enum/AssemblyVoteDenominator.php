<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyVoteDenominator: string
{
    case ALL_COMMON_IDEAL_PARTS = 'all_common_ideal_parts';
    case REPRESENTED_AT_MEETING = 'represented_at_meeting';
    case ELIGIBLE_ABSENTEE_UNIVERSE = 'eligible_absentee_universe';
}
