<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyPrincipalType: string
{
    case PERSON = 'person';
    case LEGAL_ENTITY = 'legal_entity';
}
