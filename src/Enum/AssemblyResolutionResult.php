<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyResolutionResult: string
{
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case REVIEW_REQUIRED = 'review_required';
}
