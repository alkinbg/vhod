<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GeneralAssembly;
use App\Entity\User;
use App\Security\GeneralAssemblyAccessPolicy;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class GeneralAssemblyLifecycleService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GeneralAssemblyAccessPolicy $accessPolicy,
    ) {
    }

    public function start(User $actor, GeneralAssembly $assembly, DateTimeImmutable $startedAt): void
    {
        $this->assertManager($actor);

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $assembly, $startedAt): void {
            $entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
            $assembly->start($actor, $startedAt);
        });
    }

    public function close(User $actor, GeneralAssembly $assembly, DateTimeImmutable $closedAt): void
    {
        $this->assertManager($actor);

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $assembly, $closedAt): void {
            $entityManager->lock($assembly, LockMode::PESSIMISTIC_WRITE);
            $assembly->close($actor, $closedAt);
        });
    }

    private function assertManager(User $actor): void
    {
        if (!$this->accessPolicy->canManage($actor)) {
            throw new DomainException('General Assembly management access is required.');
        }
    }
}
