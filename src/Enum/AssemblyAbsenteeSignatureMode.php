<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyAbsenteeSignatureMode: string
{
    case HAND_SIGNED = 'hand_signed';
    case ELECTRONIC_DECLARATION_RECORDED = 'electronic_declaration_recorded';
}
