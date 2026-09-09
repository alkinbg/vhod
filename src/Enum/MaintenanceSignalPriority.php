<?php

declare(strict_types=1);

namespace App\Enum;

enum MaintenanceSignalPriority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case URGENT = 'urgent';

    public function labelBg(): string
    {
        return match ($this) {
            self::LOW => 'Нисък',
            self::NORMAL => 'Нормален',
            self::HIGH => 'Висок',
            self::URGENT => 'Спешен',
        };
    }
}
