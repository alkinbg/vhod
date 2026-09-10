<?php

declare(strict_types=1);

namespace App\Enum;

enum ManagementMandateKind: string
{
    case MANAGER = 'manager';
    case MANAGEMENT_BOARD = 'management_board';
    case PROFESSIONAL_MANAGER = 'professional_manager';

    public function labelBg(): string
    {
        return match ($this) {
            self::MANAGER => 'Управител',
            self::MANAGEMENT_BOARD => 'Управителен съвет',
            self::PROFESSIONAL_MANAGER => 'Професионален управител',
        };
    }
}
