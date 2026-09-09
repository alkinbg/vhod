<?php

declare(strict_types=1);

namespace App\Enum;

enum OfficialAnnouncementStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';

    public function labelBg(): string
    {
        return match ($this) {
            self::DRAFT => 'Чернова',
            self::PUBLISHED => 'Публикувано',
        };
    }
}
