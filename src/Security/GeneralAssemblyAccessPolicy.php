<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\GeneralAssembly;
use App\Entity\User;

final class GeneralAssemblyAccessPolicy
{
    public function canManage(User $user): bool
    {
        return $user->isActive()
            && [] !== array_intersect(['ROLE_CONTROLLER', 'ROLE_MANAGER', 'ROLE_ADMIN'], $user->getRoles());
    }

    public function canViewResident(User $user, GeneralAssembly $assembly): bool
    {
        return $user->isActive() && $assembly->isResidentVisible();
    }
}
