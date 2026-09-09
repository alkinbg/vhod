<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MaintenanceEventType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'maintenance_event')]
#[ORM\Index(name: 'idx_maintenance_event_asset', columns: ['asset_id'])]
#[ORM\Index(name: 'idx_maintenance_event_supplier', columns: ['supplier_id'])]
#[ORM\Index(name: 'idx_maintenance_event_contract', columns: ['contract_id'])]
#[ORM\Index(name: 'idx_maintenance_event_signal', columns: ['signal_id'])]
#[ORM\Index(name: 'idx_maintenance_event_recorded_by', columns: ['recorded_by_id'])]
#[ORM\Index(name: 'idx_maintenance_event_performed_at', columns: ['performed_at'])]
class MaintenanceEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'asset_id', nullable: false, onDelete: 'RESTRICT')]
    private BuildingAsset $asset;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: true, onDelete: 'RESTRICT')]
    private ?MaintenanceSupplier $supplier;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'contract_id', nullable: true, onDelete: 'RESTRICT')]
    private ?MaintenanceContract $contract;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'signal_id', nullable: true, onDelete: 'RESTRICT')]
    private ?MaintenanceSignal $signal;

    #[ORM\Column(length: 32, enumType: MaintenanceEventType::class)]
    private MaintenanceEventType $type;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $performedAt;

    #[ORM\Column(length: 255)]
    private string $summary;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $nextInspectionAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: false, onDelete: 'RESTRICT')]
    private User $recordedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $recordedAt;

    private function __construct(
        BuildingAsset $asset,
        MaintenanceEventType $type,
        DateTimeImmutable $performedAt,
        string $summary,
        User $recordedBy,
        DateTimeImmutable $recordedAt,
        ?MaintenanceSupplier $supplier,
        ?MaintenanceContract $contract,
        ?MaintenanceSignal $signal,
        ?string $note,
        ?DateTimeImmutable $nextInspectionAt,
    ) {
        $summary = trim($summary);
        if ('' === $summary || mb_strlen($summary) > 255) {
            throw new InvalidArgumentException('Maintenance event summary must contain between 1 and 255 characters.');
        }
        if (null !== $contract && null !== $supplier && $contract->getSupplier() !== $supplier) {
            throw new InvalidArgumentException('Maintenance event supplier does not match the selected contract.');
        }
        if (null !== $contract && null !== $contract->getAsset() && $contract->getAsset() !== $asset) {
            throw new InvalidArgumentException('Maintenance contract does not apply to the selected asset.');
        }
        if (null !== $signal && null !== $signal->getAsset() && $signal->getAsset() !== $asset) {
            throw new InvalidArgumentException('Maintenance signal does not belong to the selected asset.');
        }

        $this->asset = $asset;
        $this->type = $type;
        $this->performedAt = $performedAt;
        $this->summary = $summary;
        $this->recordedBy = $recordedBy;
        $this->recordedAt = $recordedAt->setTimezone(new DateTimeZone('UTC'));
        $this->supplier = $supplier;
        $this->contract = $contract;
        $this->signal = $signal;
        $this->note = self::optional($note);
        $this->nextInspectionAt = $nextInspectionAt;
    }

    public static function record(
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
    ): self {
        return new self($asset, $type, $performedAt, $summary, $recordedBy, $recordedAt, $supplier, $contract, $signal, $note, $nextInspectionAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getAsset(): BuildingAsset { return $this->asset; }
    public function getSupplier(): ?MaintenanceSupplier { return $this->supplier; }
    public function getContract(): ?MaintenanceContract { return $this->contract; }
    public function getSignal(): ?MaintenanceSignal { return $this->signal; }
    public function getType(): MaintenanceEventType { return $this->type; }
    public function getPerformedAt(): DateTimeImmutable { return $this->performedAt; }
    public function getSummary(): string { return $this->summary; }
    public function getNote(): ?string { return $this->note; }
    public function getNextInspectionAt(): ?DateTimeImmutable { return $this->nextInspectionAt; }
    public function getRecordedBy(): User { return $this->recordedBy; }
    public function getRecordedAt(): DateTimeImmutable { return $this->recordedAt; }

    private static function optional(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
