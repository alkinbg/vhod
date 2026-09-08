<?php

declare(strict_types=1);

namespace App\Enum;

enum CommunityPostType: string
{
    case POST = 'post';
    case POLL = 'poll';
    case EVENT = 'event';
    case IDEA = 'idea';
    case HELP = 'help';
    case GIVE = 'give';
    case LEND = 'lend';
    case BORROW = 'borrow';
    case FIND = 'find';

    public function labelBg(): string
    {
        return match ($this) {
            self::POST => 'Публикация',
            self::POLL => 'Неформална анкета',
            self::EVENT => 'Събитие',
            self::IDEA => 'Идея / предложение',
            self::HELP => 'Помощ между съседи',
            self::GIVE => 'Подарявам',
            self::LEND => 'Давам назаем',
            self::BORROW => 'Търся назаем',
            self::FIND => 'Търся / намерено',
        };
    }
}
