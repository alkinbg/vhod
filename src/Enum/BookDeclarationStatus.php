<?php

declare(strict_types=1);

namespace App\Enum;

enum BookDeclarationStatus: string
{
    case SUBMITTED = 'submitted';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
}
