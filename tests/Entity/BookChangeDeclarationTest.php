<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BookChangeDeclaration;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\BookChangeType;
use App\Enum\BookDeclarationStatus;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BookChangeDeclarationTest extends TestCase
{
    public function testSubmittedDeclarationKeepsImmutableRequestSnapshot(): void
    {
        self::assertTrue(enum_exists(BookChangeType::class));
        self::assertTrue(enum_exists(BookDeclarationStatus::class));
        self::assertTrue(class_exists(BookChangeDeclaration::class));

        $user = new User(new Person('Иван', 'Иванов'), 'ivan@example.com', 'hash');
        $unit = new Unit('12');
        $submittedAt = new DateTimeImmutable('2026-09-07 20:00:00');

        $declaration = BookChangeDeclaration::submit(
            $unit,
            $user,
            BookChangeType::ABSENCE,
            ['validFrom' => '2026-10-01', 'validUntil' => '2026-10-15'],
            $submittedAt,
        );

        self::assertSame(BookDeclarationStatus::SUBMITTED, $declaration->getStatus());
        self::assertSame($unit, $declaration->getUnit());
        self::assertSame($user, $declaration->getSubmittedBy());
        self::assertSame(BookChangeType::ABSENCE, $declaration->getType());
        self::assertSame(['validFrom' => '2026-10-01', 'validUntil' => '2026-10-15'], $declaration->getPayload());
        self::assertEquals($submittedAt, $declaration->getSubmittedAt());
    }

    public function testDeclarationCanBeAcceptedOnceWithAuditData(): void
    {
        self::assertTrue(class_exists(BookChangeDeclaration::class));

        $submitter = new User(new Person('Иван', 'Иванов'), 'ivan@example.com', 'hash');
        $reviewer = new User(new Person('Мария', 'Петрова'), 'maria@example.com', 'hash');
        $reviewer->setRoles(['ROLE_MANAGER']);

        $declaration = BookChangeDeclaration::submit(
            new Unit('12'),
            $submitter,
            BookChangeType::ANIMAL,
            ['species' => 'куче', 'count' => 1, 'passport' => 'BG-123'],
            new DateTimeImmutable('2026-09-07 20:00:00'),
        );

        $reviewedAt = new DateTimeImmutable('2026-09-07 21:00:00');
        $declaration->accept($reviewer, $reviewedAt, 'Проверено.');

        self::assertSame(BookDeclarationStatus::ACCEPTED, $declaration->getStatus());
        self::assertSame($reviewer, $declaration->getReviewedBy());
        self::assertEquals($reviewedAt, $declaration->getReviewedAt());
        self::assertSame('Проверено.', $declaration->getReviewNote());

        $this->expectException(DomainException::class);
        $declaration->reject($reviewer, new DateTimeImmutable('2026-09-07 21:05:00'), 'Не.');
    }

    public function testAuditTimestampsAreNormalizedToUtcBeforePersistence(): void
    {
        $submitter = new User(new Person('Иван', 'Иванов'), 'ivan@example.com', 'hash');
        $reviewer = new User(new Person('Мария', 'Петрова'), 'maria@example.com', 'hash');
        $reviewer->setRoles(['ROLE_MANAGER']);

        $sofia = new DateTimeZone('Europe/Sofia');
        $declaration = BookChangeDeclaration::submit(
            new Unit('12'),
            $submitter,
            BookChangeType::CONTACT_UPDATE,
            ['phone' => '+359888123456'],
            new DateTimeImmutable('2026-09-08 00:30:00', $sofia),
        );

        self::assertSame('UTC', $declaration->getSubmittedAt()->getTimezone()->getName());
        self::assertSame('2026-09-07 21:30:00', $declaration->getSubmittedAt()->format('Y-m-d H:i:s'));

        $declaration->accept(
            $reviewer,
            new DateTimeImmutable('2026-09-08 02:00:00', $sofia),
        );

        self::assertNotNull($declaration->getReviewedAt());
        self::assertSame('UTC', $declaration->getReviewedAt()->getTimezone()->getName());
        self::assertSame('2026-09-07 23:00:00', $declaration->getReviewedAt()->format('Y-m-d H:i:s'));
    }

    public function testRejectedDeclarationKeepsReason(): void
    {
        self::assertTrue(class_exists(BookChangeDeclaration::class));

        $submitter = new User(new Person('Иван', 'Иванов'), 'ivan@example.com', 'hash');
        $reviewer = new User(new Person('Мария', 'Петрова'), 'maria@example.com', 'hash');
        $reviewer->setRoles(['ROLE_MANAGER']);

        $declaration = BookChangeDeclaration::submit(
            new Unit('12'),
            $submitter,
            BookChangeType::CONTACT_UPDATE,
            ['email' => 'new@example.com', 'phone' => '+359888123456'],
            new DateTimeImmutable('2026-09-07 20:00:00'),
        );

        $declaration->reject($reviewer, new DateTimeImmutable('2026-09-07 21:00:00'), 'Липсва уточнение.');

        self::assertSame(BookDeclarationStatus::REJECTED, $declaration->getStatus());
        self::assertSame('Липсва уточнение.', $declaration->getReviewNote());
    }
}
