<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\HouseholdMember;
use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Enum\UnitRelationType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HouseholdMemberTest extends TestCase
{
    public function testHouseholdMemberKeepsHistoricalPeriod(): void
    {
        self::assertTrue(class_exists(HouseholdMember::class));

        $relation = new UnitRelation(new Person('Иван', 'Иванов'), new Unit('12'), UnitRelationType::OWNER, new DateTimeImmutable('2026-01-01'));
        $member = new HouseholdMember(new Person('Мария', 'Иванова'), $relation, new DateTimeImmutable('2026-02-01'));

        self::assertTrue($member->isActiveAt(new DateTimeImmutable('2026-06-01')));
        $member->endAt(new DateTimeImmutable('2026-06-30'));
        self::assertFalse($member->isActiveAt(new DateTimeImmutable('2026-07-01')));
    }

    public function testHouseholdMemberRequiresOwnerOrUserRelation(): void
    {
        self::assertTrue(class_exists(HouseholdMember::class));

        $relation = new UnitRelation(new Person('Иван', 'Иванов'), new Unit('12'), UnitRelationType::OCCUPANT, new DateTimeImmutable('2026-01-01'));

        $this->expectException(InvalidArgumentException::class);
        new HouseholdMember(new Person('Мария', 'Иванова'), $relation, new DateTimeImmutable('2026-02-01'));
    }
}
