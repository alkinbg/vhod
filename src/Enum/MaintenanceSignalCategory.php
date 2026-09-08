<?php

declare(strict_types=1);

namespace App\Enum;

enum MaintenanceSignalCategory: string
{
    case COMMON_AREA = 'common_area';
    case ELECTRICAL = 'electrical';
    case PLUMBING = 'plumbing';
    case ELEVATOR = 'elevator';
    case ROOF = 'roof';
    case ACCESS = 'access';
    case CLEANING = 'cleaning';
    case SAFETY = 'safety';
    case OTHER = 'other';

    public function labelBg(): string
    {
        return match ($this) {
            self::COMMON_AREA => 'Общи части',
            self::ELECTRICAL => 'Електроинсталация',
            self::PLUMBING => 'ВиК',
            self::ELEVATOR => 'Асансьор',
            self::ROOF => 'Покрив',
            self::ACCESS => 'Достъп и вход',
            self::CLEANING => 'Почистване',
            self::SAFETY => 'Безопасност',
            self::OTHER => 'Друго',
        };
    }
}
