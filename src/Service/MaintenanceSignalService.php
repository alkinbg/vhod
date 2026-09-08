<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BuildingAsset;
use App\Entity\MaintenanceSignal;
use App\Entity\MaintenanceSignalStatusChange;
use App\Entity\User;
use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use App\Enum\MaintenanceSignalStatus;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MaintenanceSignalService
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function create(
        User $submittedBy,
        MaintenanceSignalCategory $category,
        MaintenanceSignalPriority $priority,
        string $title,
        string $description,
        string $location,
        DateTimeImmutable $createdAt,
        ?BuildingAsset $asset = null,
    ): MaintenanceSignal {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($submittedBy, $category, $priority, $title, $description, $location, $createdAt, $asset): MaintenanceSignal {
            $signal = MaintenanceSignal::open($submittedBy, $category, $priority, $title, $description, $location, $createdAt, $asset);
            $entityManager->persist($signal);
            $entityManager->persist(MaintenanceSignalStatusChange::record(
                $signal,
                null,
                MaintenanceSignalStatus::OPEN,
                $submittedBy,
                $createdAt,
            ));

            return $signal;
        });
    }

    public function assign(MaintenanceSignal $signal, ?User $assignedTo, DateTimeImmutable $updatedAt): void
    {
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($signal, $assignedTo, $updatedAt): void {
            $entityManager->lock($signal, LockMode::PESSIMISTIC_WRITE);
            $signal->assign($assignedTo, $updatedAt);
        });
    }

    public function changeStatus(
        MaintenanceSignal $signal,
        MaintenanceSignalStatus $status,
        User $changedBy,
        DateTimeImmutable $changedAt,
        string $note = '',
    ): MaintenanceSignalStatusChange {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($signal, $status, $changedBy, $changedAt, $note): MaintenanceSignalStatusChange {
            $entityManager->lock($signal, LockMode::PESSIMISTIC_WRITE);
            $previous = $signal->changeStatus($status, $changedAt);
            $change = MaintenanceSignalStatusChange::record($signal, $previous, $status, $changedBy, $changedAt, $note);
            $entityManager->persist($change);

            return $change;
        });
    }
}
