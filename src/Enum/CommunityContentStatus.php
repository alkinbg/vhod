<?php

declare(strict_types=1);

namespace App\Enum;

enum CommunityContentStatus: string
{
    case PUBLISHED = 'published';
    case HIDDEN = 'hidden';
}
