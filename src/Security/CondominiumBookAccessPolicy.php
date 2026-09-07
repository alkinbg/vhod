<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Unit;
use App\Entity\UnitRelation;
use App\Entity\User;
use App\Enum\UnitRelationType;
use DateTimeImmutable;

final class CondominiumBookAccessPolicy
{
    /**
     * @param iterable<UnitRelation> $relations
     */
    public function canViewUnit(User $user, Unit $unit, iterable $relations, DateTimeImmutable $at): bool
    {
        if ($this->hasAnyRole($user, ['ROLE_MANAGER', 'ROLE_CONTROLLER', 'ROLE_ADMIN'])) {
            return true;
        }

        return $this->hasActivePersonalRelation($user, $unit, $relations, $at);
    }

    /**
     * @param iterable<UnitRelation> $relations
     */
    public function canSubmitDeclaration(User $user, Unit $unit, iterable $relations, DateTimeImmutable $at): bool
    {
        return $this->hasActivePersonalRelation($user, $unit, $relations, $at);
    }

    public function canReviewDeclarations(User $user): bool
    {
        return $this->hasAnyRole($user, ['ROLE_MANAGER', 'ROLE_ADMIN']);
    }

    /**
     * @param iterable<UnitRelation> $relations
     */
    private function hasActivePersonalRelation(User $user, Unit $unit, iterable $relations, DateTimeImmutable $at): bool
    {
        foreach ($relations as $relation) {
            if ($relation->getPerson() !== $user->getPerson()) {
                continue;
            }

            if ($relation->getUnit() !== $unit) {
                continue;
            }

            if (!in_array($relation->getType(), [UnitRelationType::OWNER, UnitRelationType::USER], true)) {
                continue;
            }

            if ($relation->isActiveAt($at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $roles
     */
    private function hasAnyRole(User $user, array $roles): bool
    {
        return [] !== array_intersect($roles, $user->getRoles());
    }
}
