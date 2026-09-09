<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentAccessLevel: string
{
    case RESIDENTS = 'residents';
    case FINANCE = 'finance';
    case GOVERNANCE = 'governance';
    case MANAGEMENT = 'management';

    public function labelBg(): string
    {
        return match ($this) {
            self::RESIDENTS => 'За всички жители',
            self::FINANCE => 'Финансов достъп',
            self::GOVERNANCE => 'Управленски и контролен достъп',
            self::MANAGEMENT => 'Управление',
        };
    }
}
