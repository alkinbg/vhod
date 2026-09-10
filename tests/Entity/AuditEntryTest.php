<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AuditEntry;
use App\Entity\Person;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    public function testRecordsActorSnapshotSubjectAndUtcTime(): void
    {
        $person = new Person('Audit', 'Manager', email: 'manager@example.com');
        $actor = new User($person, 'manager@example.com', 'hash');
        $occurredAt = new DateTimeImmutable('2026-09-10 12:30:00', new DateTimeZone('Europe/Sofia'));

        $entry = AuditEntry::record(
            $actor,
            'announcement.published',
            'OfficialAnnouncement',
            42,
            $occurredAt,
            ['status' => 'published'],
        );

        self::assertSame($actor, $entry->getActor());
        self::assertSame('manager@example.com', $entry->getActorIdentifier());
        self::assertSame('announcement.published', $entry->getAction());
        self::assertSame('OfficialAnnouncement', $entry->getSubjectType());
        self::assertSame(42, $entry->getSubjectId());
        self::assertSame('2026-09-10 09:30:00', $entry->getOccurredAt()->format('Y-m-d H:i:s'));
        self::assertSame(['status' => 'published'], $entry->getContext());
    }

    public function testSystemActionUsesExplicitSystemActorSnapshot(): void
    {
        $entry = AuditEntry::record(
            null,
            'payment.imported',
            'Payment',
            null,
            new DateTimeImmutable('2026-09-10 09:30:00', new DateTimeZone('UTC')),
        );

        self::assertNull($entry->getActor());
        self::assertSame('system', $entry->getActorIdentifier());
    }

    public function testRejectsBlankActionAndSubjectType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditEntry::record(
            null,
            '   ',
            'Payment',
            1,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    public function testRejectsNonPositiveSubjectId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditEntry::record(
            null,
            'payment.posted',
            'Payment',
            0,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }
}
