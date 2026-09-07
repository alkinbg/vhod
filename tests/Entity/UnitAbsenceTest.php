<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitAbsence;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UnitAbsenceTest extends TestCase
{
    public function testAbsencePeriodCanBeQueried(): void
    {
        self::assertTrue(class_exists(UnitAbsence::class));

        $absence = new UnitAbsence(
            new Person('Иван', 'Иванов'),
            new Unit('12'),
            new DateTimeImmutable('2026-07-01'),
            new DateTimeImmutable('2026-07-31'),
        );

        self::assertTrue($absence->covers(new DateTimeImmutable('2026-07-15')));
        self::assertFalse($absence->covers(new DateTimeImmutable('2026-08-01')));
    }

    public function testAbsenceCannotEndBeforeItStarts(): void
    {
        self::assertTrue(class_exists(UnitAbsence::class));

        $this->expectException(InvalidArgumentException::class);
        new UnitAbsence(
            new Person('Иван', 'Иванов'),
            new Unit('12'),
            new DateTimeImmutable('2026-07-31'),
            new DateTimeImmutable('2026-07-01'),
        );
    }
}
