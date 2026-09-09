<?php

declare(strict_types=1);

namespace App\Enum;

enum MaintenanceReminderType: string
{
    case WARRANTY_EXPIRED = 'warranty_expired';
    case WARRANTY_DUE = 'warranty_due';
    case INSPECTION_OVERDUE = 'inspection_overdue';
    case INSPECTION_DUE = 'inspection_due';

    public function labelBg(): string
    {
        return match ($this) {
            self::WARRANTY_EXPIRED => 'Изтекла гаранция',
            self::WARRANTY_DUE => 'Гаранцията изтича скоро',
            self::INSPECTION_OVERDUE => 'Просрочена проверка',
            self::INSPECTION_DUE => 'Предстояща проверка',
        };
    }
}
