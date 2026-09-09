<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'maintenance_contract')]
#[ORM\Index(name: 'idx_maintenance_contract_supplier', columns: ['supplier_id'])]
#[ORM\Index(name: 'idx_maintenance_contract_asset', columns: ['asset_id'])]
#[ORM\Index(name: 'idx_maintenance_contract_starts_at', columns: ['starts_at'])]
class MaintenanceContract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'supplier_id', nullable: false, onDelete: 'RESTRICT')]
    private MaintenanceSupplier $supplier;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'asset_id', nullable: true, onDelete: 'RESTRICT')]
    private ?BuildingAsset $asset;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $endsAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    private function __construct(
        MaintenanceSupplier $supplier,
        string $title,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $createdAt,
        ?BuildingAsset $asset,
        ?DateTimeImmutable $endsAt,
        ?string $reference,
        ?string $note,
    ) {
        $title = trim($title);
        if ('' === $title || mb_strlen($title) > 180) {
            throw new InvalidArgumentException('Maintenance contract title must contain between 1 and 180 characters.');
        }
        if (null !== $endsAt && $endsAt < $startsAt) {
            throw new InvalidArgumentException('Maintenance contract end date cannot be before its start date.');
        }

        $this->supplier = $supplier;
        $this->asset = $asset;
        $this->title = $title;
        $this->reference = self::optional($reference);
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->note = self::optional($note);
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function create(
        MaintenanceSupplier $supplier,
        string $title,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $createdAt,
        ?BuildingAsset $asset = null,
        ?DateTimeImmutable $endsAt = null,
        ?string $reference = null,
        ?string $note = null,
    ): self {
        return new self($supplier, $title, $startsAt, $createdAt, $asset, $endsAt, $reference, $note);
    }

    public function getId(): ?int { return $this->id; }
    public function getSupplier(): MaintenanceSupplier { return $this->supplier; }
    public function getAsset(): ?BuildingAsset { return $this->asset; }
    public function getTitle(): string { return $this->title; }
    public function getReference(): ?string { return $this->reference; }
    public function getStartsAt(): DateTimeImmutable { return $this->startsAt; }
    public function getEndsAt(): ?DateTimeImmutable { return $this->endsAt; }
    public function getNote(): ?string { return $this->note; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    private static function optional(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
