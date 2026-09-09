<?php

declare(strict_types=1);

namespace App\Enum;

enum AssemblyConveningBasis: string
{
    case MANAGER_OR_BOARD = 'manager_or_board';
    case CONTROLLER_OR_CONTROL_BOARD = 'controller_or_control_board';
    case OWNERS_REQUEST_20_PERCENT = 'owners_request_20_percent';
    case OWNERS_AFTER_MANAGER_INACTION = 'owners_after_manager_inaction';
    case URGENT_OWNER_OR_USER = 'urgent_owner_or_user';
    case FIRST_ASSEMBLY = 'first_assembly';
    case OTHER_LEGAL_BASIS = 'other_legal_basis';
}
