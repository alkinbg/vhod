<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Enum\UnitRelationType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UnitRelationTest extends TestCase
{
    public function testRelationKeepsHistoricalDateRange(): void
    {
        $relation = new UnitRelation(new Person('Иван', 'Иванов'), new Unit('12'), UnitRelationType::OWNER, new DateTimeImmutable('2026-01-01'), '50.0000');
        self::assertTrue($relation->isActiveAt(new DateTimeImmutable('2026-06-01')));
        $relation->endAt(new DateTimeImmutable('2026-06-30'));
        self::assertTrue($relation->isActiveAt(new DateTimeImmutable('2026-06-30')));
        self::assertFalse($relation->isActiveAt(new DateTimeImmutable('2026-07-01')));
    }

    public function testRejectsOwnershipShareForOccupant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UnitRelation(new Person('Иван', 'Иванов'), new Unit('12'), UnitRelationType::OCCUPANT, new DateTimeImmutable('2026-01-01'), '50.0000');
    }

    public function testCannotEndBeforeStart(): void
    {
        $relation = new UnitRelation(new Person('Иван', 'Иванов'), new Unit('12'), UnitRelationType::OWNER, new DateTimeImmutable('2026-05-01'));
        $this->expectException(InvalidArgumentException::class);
        $relation->endAt(new DateTimeImmutable('2026-04-30'));
    }
}
