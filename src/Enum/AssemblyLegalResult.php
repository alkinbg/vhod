<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyLegalResult: string
{
    case VALID = 'valid';
    case INVALID = 'invalid';
    case REVIEW_REQUIRED = 'review_required';
}
