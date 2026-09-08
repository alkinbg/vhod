<?php

declare(strict_types=1);

namespace App\Enum;

enum MaintenanceEventType: string
{
    case INSPECTION = 'inspection';
    case PREVENTIVE_MAINTENANCE = 'preventive_maintenance';
    case REPAIR = 'repair';
    case WARRANTY_SERVICE = 'warranty_service';
    case OTHER = 'other';

    public function labelBg(): string
    {
        return match ($this) {
            self::INSPECTION => 'Проверка',
            self::PREVENTIVE_MAINTENANCE => 'Профилактика',
            self::REPAIR => 'Ремонт',
            self::WARRANTY_SERVICE => 'Гаранционно обслужване',
            self::OTHER => 'Друго',
        };
    }
}
