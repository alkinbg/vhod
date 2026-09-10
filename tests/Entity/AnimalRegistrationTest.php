<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AnimalRegistration;
use App\Entity\Unit;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AnimalRegistrationTest extends TestCase
{
    public function testAnimalRecordKeepsSpeciesCountPassportAndEffectivePeriod(): void
    {
        self::assertTrue(class_exists(AnimalRegistration::class));

        $registration = new AnimalRegistration(
            new Unit('12'),
            'куче',
            1,
            'BG-123456',
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2027-08-31'),
        );

        self::assertSame('куче', $registration->getSpecies());
        self::assertSame(1, $registration->getCount());
        self::assertSame('BG-123456', $registration->getVeterinaryPassportNumber());
        self::assertSame('2026-09-01', $registration->getValidFrom()->format('Y-m-d'));
        self::assertSame('2027-08-31', $registration->getValidUntil()?->format('Y-m-d'));
        self::assertFalse($registration->isActiveAt(new DateTimeImmutable('2026-08-31')));
        self::assertTrue($registration->isActiveAt(new DateTimeImmutable('2026-09-01')));
        self::assertTrue($registration->isActiveAt(new DateTimeImmutable('2027-08-31')));
        self::assertFalse($registration->isActiveAt(new DateTimeImmutable('2027-09-01')));
    }

    public function testAnimalCountMustBePositive(): void
    {
        self::assertTrue(class_exists(AnimalRegistration::class));

        $this->expectException(InvalidArgumentException::class);
        new AnimalRegistration(new Unit('12'), 'котка', 0);
    }

    public function testAnimalPeriodCannotEndBeforeItStarts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AnimalRegistration(
            new Unit('12'),
            'котка',
            1,
            null,
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-08-31'),
        );
    }
}
