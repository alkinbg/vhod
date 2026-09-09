<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MaintenanceSignalCategory;
use App\Enum\MaintenanceSignalPriority;
use App\Enum\MaintenanceSignalStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'maintenance_signal')]
#[ORM\Index(name: 'idx_maintenance_signal_submitted_by', columns: ['submitted_by_id'])]
#[ORM\Index(name: 'idx_maintenance_signal_asset', columns: ['asset_id'])]
#[ORM\Index(name: 'idx_maintenance_signal_assigned_to', columns: ['assigned_to_id'])]
#[ORM\Index(name: 'idx_maintenance_signal_status_created', columns: ['status', 'created_at'])]
class MaintenanceSignal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'submitted_by_id', nullable: false, onDelete: 'RESTRICT')]
    private User $submittedBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'asset_id', nullable: true, onDelete: 'RESTRICT')]
    private ?BuildingAsset $asset;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assigned_to_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $assignedTo = null;

    #[ORM\Column(length: 32, enumType: MaintenanceSignalCategory::class)]
    private MaintenanceSignalCategory $category;

    #[ORM\Column(length: 16, enumType: MaintenanceSignalPriority::class)]
    private MaintenanceSignalPriority $priority;

    #[ORM\Column(length: 24, enumType: MaintenanceSignalStatus::class)]
    private MaintenanceSignalStatus $status = MaintenanceSignalStatus::OPEN;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(length: 180)]
    private string $location;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    private function __construct(
        User $submittedBy,
        MaintenanceSignalCategory $category,
        MaintenanceSignalPriority $priority,
        string $title,
        string $description,
        string $location,
        DateTimeImmutable $createdAt,
        ?BuildingAsset $asset,
    ) {
        $title = trim($title);
        $description = trim($description);
        $location = trim($location);
        if ('' === $title || mb_strlen($title) > 180) {
            throw new InvalidArgumentException('Maintenance signal title must contain between 1 and 180 characters.');
        }
        if ('' === $description || mb_strlen($description) > 10000) {
            throw new InvalidArgumentException('Maintenance signal description must contain between 1 and 10000 characters.');
        }
        if ('' === $location || mb_strlen($location) > 180) {
            throw new InvalidArgumentException('Maintenance signal location must contain between 1 and 180 characters.');
        }

        $createdAt = self::toUtc($createdAt);
        $this->submittedBy = $submittedBy;
        $this->asset = $asset;
        $this->category = $category;
        $this->priority = $priority;
        $this->title = $title;
        $this->description = $description;
        $this->location = $location;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public static function open(
        User $submittedBy,
        MaintenanceSignalCategory $category,
        MaintenanceSignalPriority $priority,
        string $title,
        string $description,
        string $location,
        DateTimeImmutable $createdAt,
        ?BuildingAsset $asset = null,
    ): self {
        return new self($submittedBy, $category, $priority, $title, $description, $location, $createdAt, $asset);
    }

    public function getId(): ?int { return $this->id; }
    public function getSubmittedBy(): User { return $this->submittedBy; }
    public function getAsset(): ?BuildingAsset { return $this->asset; }
    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function getCategory(): MaintenanceSignalCategory { return $this->category; }
    public function getPriority(): MaintenanceSignalPriority { return $this->priority; }
    public function getStatus(): MaintenanceSignalStatus { return $this->status; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getLocation(): string { return $this->location; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function assign(?User $assignedTo, DateTimeImmutable $updatedAt): void
    {
        $this->assignedTo = $assignedTo;
        $this->updatedAt = self::toUtc($updatedAt);
    }

    public function changeStatus(MaintenanceSignalStatus $status, DateTimeImmutable $updatedAt): MaintenanceSignalStatus
    {
        if ($status === $this->status) {
            throw new DomainException('Maintenance signal is already in the selected status.');
        }

        $previous = $this->status;
        $this->status = $status;
        $this->updatedAt = self::toUtc($updatedAt);

        return $previous;
    }

    private static function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
