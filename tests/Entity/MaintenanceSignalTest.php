<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MaintenanceSignal;
use App\Entity\MaintenanceSignalStatusChange;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use App\Enum\MaintenanceSignalStatus;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaintenanceSignalTest extends TestCase
{
    public function testSignalOpensWithNormalizedRequiredFieldsAndUtcTimestamps(): void
    {
        $user = $this->user('resident@example.com');
        $createdAt = new DateTimeImmutable('2026-09-08 21:15:00', new DateTimeZone('Europe/Sofia'));

        $signal = MaintenanceSignal::open(
            $user,
            MaintenanceSignalCategory::PLUMBING,
            MaintenanceSignalPriority::HIGH,
            '  Теч в мазето  ',
            '  Има вода около главния щранг.  ',
            '  Мазе, до водомерите  ',
            $createdAt,
        );

        self::assertSame($user, $signal->getSubmittedBy());
        self::assertNull($signal->getAsset());
        self::assertNull($signal->getAssignedTo());
        self::assertSame(MaintenanceSignalCategory::PLUMBING, $signal->getCategory());
        self::assertSame(MaintenanceSignalPriority::HIGH, $signal->getPriority());
        self::assertSame(MaintenanceSignalStatus::OPEN, $signal->getStatus());
        self::assertSame('Теч в мазето', $signal->getTitle());
        self::assertSame('Има вода около главния щранг.', $signal->getDescription());
        self::assertSame('Мазе, до водомерите', $signal->getLocation());
        self::assertSame('UTC', $signal->getCreatedAt()->getTimezone()->getName());
        self::assertSame('2026-09-08 18:15:00', $signal->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame($signal->getCreatedAt(), $signal->getUpdatedAt());
    }

    #[DataProvider('blankRequiredFieldProvider')]
    public function testSignalRejectsBlankTitleDescriptionOrLocation(string $title, string $description, string $location): void
    {
        $this->expectException(InvalidArgumentException::class);

        MaintenanceSignal::open(
            $this->user('resident@example.com'),
            MaintenanceSignalCategory::OTHER,
            MaintenanceSignalPriority::NORMAL,
            $title,
            $description,
            $location,
            new DateTimeImmutable('now'),
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function blankRequiredFieldProvider(): iterable
    {
        yield 'blank title' => ['   ', 'Описание', 'Място'];
        yield 'blank description' => ['Заглавие', '   ', 'Място'];
        yield 'blank location' => ['Заглавие', 'Описание', '   '];
    }

    public function testSignalStatusCannotChangeToSameStatus(): void
    {
        $signal = $this->signal();

        $this->expectException(DomainException::class);
        $signal->changeStatus(MaintenanceSignalStatus::OPEN, new DateTimeImmutable('+1 hour'));
    }

    public function testSignalStatusChangeReturnsPreviousStatusAndUpdatesTimestamp(): void
    {
        $signal = $this->signal();
        $changedAt = new DateTimeImmutable('2026-09-08 22:00:00', new DateTimeZone('Europe/Sofia'));

        $previous = $signal->changeStatus(MaintenanceSignalStatus::IN_PROGRESS, $changedAt);

        self::assertSame(MaintenanceSignalStatus::OPEN, $previous);
        self::assertSame(MaintenanceSignalStatus::IN_PROGRESS, $signal->getStatus());
        self::assertSame('UTC', $signal->getUpdatedAt()->getTimezone()->getName());
        self::assertSame('2026-09-08 19:00:00', $signal->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    public function testAssignmentMayBeSetAndCleared(): void
    {
        $signal = $this->signal();
        $manager = $this->user('manager@example.com');

        $signal->assign($manager, new DateTimeImmutable('2026-09-08 20:00:00+00:00'));
        self::assertSame($manager, $signal->getAssignedTo());

        $signal->assign(null, new DateTimeImmutable('2026-09-08 20:30:00+00:00'));
        self::assertNull($signal->getAssignedTo());
        self::assertSame('2026-09-08 20:30:00', $signal->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    public function testStatusHistoryNormalizesNoteAndUtcTimestamp(): void
    {
        $signal = $this->signal();
        $manager = $this->user('manager@example.com');

        $change = MaintenanceSignalStatusChange::record(
            $signal,
            MaintenanceSignalStatus::OPEN,
            MaintenanceSignalStatus::IN_PROGRESS,
            $manager,
            new DateTimeImmutable('2026-09-08 22:10:00', new DateTimeZone('Europe/Sofia')),
            '  Поет от домоуправителя.  ',
        );

        self::assertSame($signal, $change->getSignal());
        self::assertSame(MaintenanceSignalStatus::OPEN, $change->getFromStatus());
        self::assertSame(MaintenanceSignalStatus::IN_PROGRESS, $change->getToStatus());
        self::assertSame($manager, $change->getChangedBy());
        self::assertSame('Поет от домоуправителя.', $change->getNote());
        self::assertSame('UTC', $change->getChangedAt()->getTimezone()->getName());
        self::assertSame('2026-09-08 19:10:00', $change->getChangedAt()->format('Y-m-d H:i:s'));
    }

    private function signal(): MaintenanceSignal
    {
        return MaintenanceSignal::open(
            $this->user('resident@example.com'),
            MaintenanceSignalCategory::COMMON_AREA,
            MaintenanceSignalPriority::NORMAL,
            'Осветление',
            'Лампата не работи.',
            'Етаж 2',
            new DateTimeImmutable('2026-09-08 18:00:00+00:00'),
        );
    }

    private function user(string $email): User
    {
        $person = new Person('Иван', 'Иванов', email: $email);

        return new User($person, $email, 'hash');
    }
}
