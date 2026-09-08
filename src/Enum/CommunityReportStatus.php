<?php

declare(strict_types=1);

namespace App\Enum;

enum CommunityReportStatus: string
{
    case OPEN = 'open';
    case RESOLVED = 'resolved';
}
