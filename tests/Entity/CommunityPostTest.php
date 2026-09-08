<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CommunityPost;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\CommunityContentStatus;
use App\Enum\CommunityPostType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CommunityPostTest extends TestCase
{
    public function testPublishesTrimmedPostInUtc(): void
    {
        $post = CommunityPost::publish(
            $this->user(),
            CommunityPostType::POST,
            '  Асансьорът  ',
            '  Работи отново.  ',
            new DateTimeImmutable('2026-09-08 18:00:00 Europe/Sofia'),
        );

        self::assertSame('Асансьорът', $post->getTitle());
        self::assertSame('Работи отново.', $post->getBody());
        self::assertSame(CommunityContentStatus::PUBLISHED, $post->getStatus());
        self::assertSame('UTC', $post->getCreatedAt()->getTimezone()->getName());
        self::assertTrue($post->isPublished());
    }

    public function testEventRequiresStartTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommunityPost::publish(
            $this->user(),
            CommunityPostType::EVENT,
            'Среща',
            'В двора',
            new DateTimeImmutable('2026-09-08 12:00:00 UTC'),
        );
    }

    public function testEventEndMustBeAfterStart(): void
    {
        $start = new DateTimeImmutable('2026-09-10 18:00:00 Europe/Sofia');

        $this->expectException(InvalidArgumentException::class);

        CommunityPost::publish(
            $this->user(),
            CommunityPostType::EVENT,
            'Среща',
            'В двора',
            new DateTimeImmutable('2026-09-08 12:00:00 UTC'),
            $start,
            $start,
        );
    }

    public function testNonEventRejectsEventTimestamps(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommunityPost::publish(
            $this->user(),
            CommunityPostType::POST,
            'Новина',
            'Текст',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day'),
        );
    }

    public function testCanBeHiddenAndPublishedAgainWithoutDeletion(): void
    {
        $post = CommunityPost::publish(
            $this->user(),
            CommunityPostType::IDEA,
            'Идея',
            'Да боядисаме входа.',
            new DateTimeImmutable(),
        );

        $post->hide();
        self::assertSame(CommunityContentStatus::HIDDEN, $post->getStatus());
        self::assertFalse($post->isPublished());

        $post->publishAgain();
        self::assertSame(CommunityContentStatus::PUBLISHED, $post->getStatus());
    }

    private function user(): User
    {
        $person = new Person('Иван', 'Иванов', email: 'ivan@example.com');

        return new User($person, 'ivan@example.com', 'hash');
    }
}
