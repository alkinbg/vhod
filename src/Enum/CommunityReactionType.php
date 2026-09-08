<?php

declare(strict_types=1);

namespace App\Enum;

enum CommunityReactionType: string
{
    case LIKE = 'like';
    case SUPPORT = 'support';
    case THANKS = 'thanks';

    public function labelBg(): string
    {
        return match ($this) {
            self::LIKE => 'Харесвам',
            self::SUPPORT => 'Подкрепям',
            self::THANKS => 'Благодаря',
        };
    }
}
