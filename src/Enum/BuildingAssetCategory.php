<?php

declare(strict_types=1);

namespace App\Enum;

enum BuildingAssetCategory: string
{
    case ELEVATOR = 'elevator';
    case ACCESS_SYSTEM = 'access_system';
    case ELECTRICAL = 'electrical';
    case PLUMBING = 'plumbing';
    case FIRE_SAFETY = 'fire_safety';
    case ROOF = 'roof';
    case COMMON_AREA = 'common_area';
    case OTHER = 'other';

    public function labelBg(): string
    {
        return match ($this) {
            self::ELEVATOR => 'Асансьор',
            self::ACCESS_SYSTEM => 'Система за достъп',
            self::ELECTRICAL => 'Електроинсталация',
            self::PLUMBING => 'ВиК',
            self::FIRE_SAFETY => 'Пожарна безопасност',
            self::ROOF => 'Покрив',
            self::COMMON_AREA => 'Общи части',
            self::OTHER => 'Друго',
        };
    }
}
