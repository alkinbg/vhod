<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BuildingAsset;
use App\Entity\MaintenanceContract;
use App\Entity\MaintenanceEvent;
use App\Entity\MaintenanceSignal;
use App\Entity\MaintenanceSupplier;
use App\Entity\User;
use App\Enum\BuildingAssetCategory;
use App\Enum\MaintenanceEventType;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MaintenanceRegistryService
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function createAsset(
        string $name,
        BuildingAssetCategory $category,
        string $location,
        ?string $manufacturer = null,
        ?string $model = null,
        ?string $serialNumber = null,
        ?DateTimeImmutable $installedAt = null,
        ?DateTimeImmutable $warrantyUntil = null,
        ?int $inspectionIntervalMonths = null,
        ?DateTimeImmutable $nextInspectionAt = null,
    ): BuildingAsset {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($name, $category, $location, $manufacturer, $model, $serialNumber, $installedAt, $warrantyUntil, $inspectionIntervalMonths, $nextInspectionAt): BuildingAsset {
            $asset = BuildingAsset::register($name, $category, $location, $manufacturer, $model, $serialNumber, $installedAt, $warrantyUntil, $inspectionIntervalMonths, $nextInspectionAt);
            $entityManager->persist($asset);

            return $asset;
        });
    }

    public function createSupplier(
        string $name,
        ?string $registrationNumber = null,
        ?string $contactPerson = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $note = null,
    ): MaintenanceSupplier {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($name, $registrationNumber, $contactPerson, $email, $phone, $address, $note): MaintenanceSupplier {
            $supplier = MaintenanceSupplier::register($name, $registrationNumber, $contactPerson, $email, $phone, $address, $note);
            $entityManager->persist($supplier);

            return $supplier;
        });
    }

    public function createContract(
        MaintenanceSupplier $supplier,
        string $title,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $createdAt,
        ?BuildingAsset $asset = null,
        ?DateTimeImmutable $endsAt = null,
        ?string $reference = null,
        ?string $note = null,
    ): MaintenanceContract {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($supplier, $title, $startsAt, $createdAt, $asset, $endsAt, $reference, $note): MaintenanceContract {
            $contract = MaintenanceContract::create($supplier, $title, $startsAt, $createdAt, $asset, $endsAt, $reference, $note);
            $entityManager->persist($contract);

            return $contract;
        });
    }

    public function recordEvent(
        BuildingAsset $asset,
        MaintenanceEventType $type,
        DateTimeImmutable $performedAt,
        string $summary,
        User $recordedBy,
        DateTimeImmutable $recordedAt,
        ?MaintenanceSupplier $supplier = null,
        ?MaintenanceContract $contract = null,
        ?MaintenanceSignal $signal = null,
        ?string $note = null,
        ?DateTimeImmutable $nextInspectionAt = null,
    ): MaintenanceEvent {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($asset, $type, $performedAt, $summary, $recordedBy, $recordedAt, $supplier, $contract, $signal, $note, $nextInspectionAt): MaintenanceEvent {
            if (null !== $nextInspectionAt) {
                $entityManager->lock($asset, LockMode::PESSIMISTIC_WRITE);
            }

            $event = MaintenanceEvent::record($asset, $type, $performedAt, $summary, $recordedBy, $recordedAt, $supplier, $contract, $signal, $note, $nextInspectionAt);
            $entityManager->persist($event);

            if (null !== $nextInspectionAt) {
                $asset->scheduleNextInspection($nextInspectionAt);
            }

            return $event;
        });
    }
}
