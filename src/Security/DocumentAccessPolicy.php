<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentAccessLevel;

final class DocumentAccessPolicy
{
    /** @return list<DocumentAccessLevel> */
    public function allowedLevels(User $user): array
    {
        if (!$user->isActive()) {
            return [];
        }

        $levels = [DocumentAccessLevel::RESIDENTS];
        if ($this->hasAnyRole($user, ['ROLE_CASHIER', 'ROLE_CONTROLLER', 'ROLE_MANAGER', 'ROLE_ADMIN'])) {
            $levels[] = DocumentAccessLevel::FINANCE;
        }
        if ($this->hasAnyRole($user, ['ROLE_CONTROLLER', 'ROLE_MANAGER', 'ROLE_ADMIN'])) {
            $levels[] = DocumentAccessLevel::GOVERNANCE;
        }
        if ($this->hasAnyRole($user, ['ROLE_MANAGER', 'ROLE_ADMIN'])) {
            $levels[] = DocumentAccessLevel::MANAGEMENT;
        }

        return $levels;
    }

    public function canView(User $user, Document $document): bool
    {
        return in_array($document->getAccessLevel(), $this->allowedLevels($user), true);
    }

    public function canManageOfficialContent(User $user): bool
    {
        return $user->isActive() && $this->hasAnyRole($user, ['ROLE_MANAGER', 'ROLE_ADMIN']);
    }

    public function canManageGovernanceEvidence(User $user): bool
    {
        return $user->isActive() && $this->hasAnyRole($user, ['ROLE_CONTROLLER', 'ROLE_MANAGER', 'ROLE_ADMIN']);
    }

    /** @param list<string> $roles */
    private function hasAnyRole(User $user, array $roles): bool
    {
        return [] !== array_intersect($roles, $user->getRoles());
    }
}
