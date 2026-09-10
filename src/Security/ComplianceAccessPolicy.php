<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

final class ComplianceAccessPolicy
{
    public function canView(User $user): bool
    {
        return $this->hasAnyRole($user, ['ROLE_MANAGER', 'ROLE_CONTROLLER', 'ROLE_ADMIN']);
    }

    public function canManageRegistry(User $user): bool
    {
        return $this->hasAnyRole($user, ['ROLE_MANAGER', 'ROLE_ADMIN']);
    }

    public function canRecordMandate(User $user): bool
    {
        return $this->hasAnyRole($user, ['ROLE_MANAGER', 'ROLE_ADMIN']);
    }

    public function canRecordMonthlyReport(User $user): bool
    {
        return $this->hasAnyRole($user, ['ROLE_MANAGER', 'ROLE_ADMIN']);
    }

    public function canRecordAnnualAudit(User $user): bool
    {
        return $this->hasAnyRole($user, ['ROLE_MANAGER', 'ROLE_CONTROLLER', 'ROLE_ADMIN']);
    }

    /** @param list<string> $roles */
    private function hasAnyRole(User $user, array $roles): bool
    {
        return $user->isActive() && [] !== array_intersect($roles, $user->getRoles());
    }
}
