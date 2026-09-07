<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Person;
use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\UnitRelationType;
use App\Security\CondominiumBookAccessPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CondominiumBookAccessPolicyTest extends TestCase
{
    public function testResidentCanViewOnlyUnitWithActiveOwnerOrUserRelation(): void
    {
        self::assertTrue(class_exists(CondominiumBookAccessPolicy::class));

        $person = new Person('Иван', 'Иванов');
        $user = new User($person, 'ivan@example.com', 'hash');
        $ownUnit = new Unit('12');
        $otherUnit = new Unit('13');
        $relation = new UnitRelation($person, $ownUnit, UnitRelationType::OWNER, new DateTimeImmutable('2026-01-01'));
        $policy = new CondominiumBookAccessPolicy();
        $today = new DateTimeImmutable('2026-09-07');

        self::assertTrue($policy->canViewUnit($user, $ownUnit, [$relation], $today));
        self::assertFalse($policy->canViewUnit($user, $otherUnit, [$relation], $today));
    }

    public function testExpiredRelationDoesNotGrantResidentAccess(): void
    {
        self::assertTrue(class_exists(CondominiumBookAccessPolicy::class));

        $person = new Person('Иван', 'Иванов');
        $user = new User($person, 'ivan@example.com', 'hash');
        $unit = new Unit('12');
        $relation = new UnitRelation($person, $unit, UnitRelationType::OWNER, new DateTimeImmutable('2026-01-01'));
        $relation->endAt(new DateTimeImmutable('2026-06-30'));

        self::assertFalse((new CondominiumBookAccessPolicy())->canViewUnit($user, $unit, [$relation], new DateTimeImmutable('2026-09-07')));
    }

    public function testManagerAndControllerCanReadAllUnitsButOnlyManagerCanReview(): void
    {
        self::assertTrue(class_exists(CondominiumBookAccessPolicy::class));

        $unit = new Unit('12');
        $manager = new User(new Person('Мария', 'Петрова'), 'manager@example.com', 'hash');
        $manager->setRoles(['ROLE_MANAGER']);
        $controller = new User(new Person('Петър', 'Петров'), 'controller@example.com', 'hash');
        $controller->setRoles(['ROLE_CONTROLLER']);
        $policy = new CondominiumBookAccessPolicy();

        self::assertTrue($policy->canViewUnit($manager, $unit, [], new DateTimeImmutable('2026-09-07')));
        self::assertTrue($policy->canViewUnit($controller, $unit, [], new DateTimeImmutable('2026-09-07')));
        self::assertTrue($policy->canReviewDeclarations($manager));
        self::assertFalse($policy->canReviewDeclarations($controller));
    }
}
