<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MaintenanceSignalStatus;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'maintenance_signal_status_change')]
#[ORM\Index(name: 'idx_maintenance_signal_status_change_signal', columns: ['signal_id'])]
#[ORM\Index(name: 'idx_maintenance_signal_status_change_changed_by', columns: ['changed_by_id'])]
#[ORM\Index(name: 'idx_maintenance_signal_status_change_at', columns: ['changed_at'])]
class MaintenanceSignalStatusChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'signal_id', nullable: false, onDelete: 'RESTRICT')]
    private MaintenanceSignal $signal;

    #[ORM\Column(length: 24, enumType: MaintenanceSignalStatus::class, nullable: true)]
    private ?MaintenanceSignalStatus $fromStatus;

    #[ORM\Column(length: 24, enumType: MaintenanceSignalStatus::class)]
    private MaintenanceSignalStatus $toStatus;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'changed_by_id', nullable: false, onDelete: 'RESTRICT')]
    private User $changedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $changedAt;

    #[ORM\Column(type: 'text')]
    private string $note;

    private function __construct(
        MaintenanceSignal $signal,
        ?MaintenanceSignalStatus $fromStatus,
        MaintenanceSignalStatus $toStatus,
        User $changedBy,
        DateTimeImmutable $changedAt,
        string $note,
    ) {
        if (null !== $fromStatus && $fromStatus === $toStatus) {
            throw new DomainException('Maintenance status history cannot record a no-op transition.');
        }

        $this->signal = $signal;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt->setTimezone(new DateTimeZone('UTC'));
        $this->note = trim($note);
    }

    public static function record(
        MaintenanceSignal $signal,
        ?MaintenanceSignalStatus $fromStatus,
        MaintenanceSignalStatus $toStatus,
        User $changedBy,
        DateTimeImmutable $changedAt,
        string $note = '',
    ): self {
        return new self($signal, $fromStatus, $toStatus, $changedBy, $changedAt, $note);
    }

    public function getId(): ?int { return $this->id; }
    public function getSignal(): MaintenanceSignal { return $this->signal; }
    public function getFromStatus(): ?MaintenanceSignalStatus { return $this->fromStatus; }
    public function getToStatus(): MaintenanceSignalStatus { return $this->toStatus; }
    public function getChangedBy(): User { return $this->changedBy; }
    public function getChangedAt(): DateTimeImmutable { return $this->changedAt; }
    public function getNote(): string { return $this->note; }
}
