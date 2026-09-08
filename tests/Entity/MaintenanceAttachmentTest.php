<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MaintenanceAttachment;
use App\Entity\MaintenanceSignal;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MaintenanceAttachmentTest extends TestCase
{
    public function testRecordsImmutableNormalizedMetadataAndUtcTime(): void
    {
        $user = $this->user();
        $attachment = MaintenanceAttachment::record(
            $this->signal($user),
            $user,
            '  теч снимка.png  ',
            '0123456789abcdef0123456789abcdef.png',
            'image/png',
            1234,
            new DateTimeImmutable('2026-09-08 22:00:00', new DateTimeZone('Europe/Sofia')),
        );

        self::assertSame('теч снимка.png', $attachment->getOriginalName());
        self::assertSame('0123456789abcdef0123456789abcdef.png', $attachment->getStorageName());
        self::assertSame('image/png', $attachment->getMimeType());
        self::assertSame(1234, $attachment->getSizeBytes());
        self::assertSame('UTC', $attachment->getUploadedAt()->getTimezone()->getName());
        self::assertSame('2026-09-08 19:00:00', $attachment->getUploadedAt()->format('Y-m-d H:i:s'));
    }

    public function testRejectsInvalidStoredMetadata(): void
    {
        $user = $this->user();
        $this->expectException(InvalidArgumentException::class);

        MaintenanceAttachment::record(
            $this->signal($user),
            $user,
            'photo.png',
            '../photo.png',
            'image/png',
            100,
            new DateTimeImmutable(),
        );
    }

    private function signal(User $user): MaintenanceSignal
    {
        return MaintenanceSignal::open(
            $user,
            MaintenanceSignalCategory::PLUMBING,
            MaintenanceSignalPriority::NORMAL,
            'Теч',
            'Теч в общите части.',
            'Мазе',
            new DateTimeImmutable(),
        );
    }

    private function user(): User
    {
        $person = new Person('Иван', 'Иванов', email: 'resident@example.com');

        return new User($person, 'resident@example.com', 'hash');
    }
}
