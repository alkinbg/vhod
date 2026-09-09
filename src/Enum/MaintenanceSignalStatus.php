<?php

declare(strict_types=1);

namespace App\Enum;

enum MaintenanceSignalStatus: string
{
    case OPEN = 'open';
    case IN_PROGRESS = 'in_progress';
    case WAITING = 'waiting';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';

    public function labelBg(): string
    {
        return match ($this) {
            self::OPEN => 'Отворен',
            self::IN_PROGRESS => 'В работа',
            self::WAITING => 'Изчаква',
            self::RESOLVED => 'Решен',
            self::CLOSED => 'Затворен',
        };
    }
}
