<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UnitRelationType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Index(columns: ['valid_from', 'valid_until'], name: 'idx_unit_relation_period')]
class UnitRelation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Person $person;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Unit $unit;

    #[ORM\Column(enumType: UnitRelationType::class)]
    private UnitRelationType $type;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $validUntil = null;

    #[ORM\Column(type: 'decimal', precision: 7, scale: 4, nullable: true)]
    private ?string $ownershipShare;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalEntityName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $legalEntityIdentifier = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $managementRightsAndObligations = null;

    public function __construct(
        Person $person,
        Unit $unit,
        UnitRelationType $type,
        DateTimeImmutable $validFrom,
        ?string $ownershipShare = null,
    ) {
        self::assertOwnershipShare($type, $ownershipShare);

        $this->person = $person;
        $this->unit = $unit;
        $this->type = $type;
        $this->validFrom = $validFrom;
        $this->ownershipShare = $ownershipShare;
    }

    public static function forLegalEntity(
        Unit $unit,
        UnitRelationType $type,
        DateTimeImmutable $validFrom,
        string $name,
        string $identifier,
        ?string $ownershipShare = null,
    ): self {
        $name = trim($name);
        $identifier = trim($identifier);

        if ('' === $name || '' === $identifier) {
            throw new InvalidArgumentException('Legal entity name and identifier are required.');
        }

        self::assertOwnershipShare($type, $ownershipShare);

        $relation = new self(
            new Person('__legal_entity__', '__placeholder__'),
            $unit,
            $type,
            $validFrom,
            $ownershipShare,
        );
        $relation->person = null;
        $relation->legalEntityName = $name;
        $relation->legalEntityIdentifier = $identifier;

        return $relation;
    }

    public function getId(): ?int { return $this->id; }
    public function getPerson(): ?Person { return $this->person; }
    public function getUnit(): Unit { return $this->unit; }
    public function getType(): UnitRelationType { return $this->type; }
    public function getValidFrom(): DateTimeImmutable { return $this->validFrom; }
    public function getValidUntil(): ?DateTimeImmutable { return $this->validUntil; }
    public function getOwnershipShare(): ?string { return $this->ownershipShare; }
    public function getLegalEntityName(): ?string { return $this->legalEntityName; }
    public function getLegalEntityIdentifier(): ?string { return $this->legalEntityIdentifier; }
    public function getManagementRightsAndObligations(): ?string { return $this->managementRightsAndObligations; }

    public function setManagementRightsAndObligations(?string $value): void
    {
        if (UnitRelationType::USER !== $this->type) {
            throw new InvalidArgumentException('Management rights and obligations are only valid for user relations.');
        }

        $this->managementRightsAndObligations = self::nullableTrim($value);
    }

    public function endAt(DateTimeImmutable $date): void
    {
        if ($date < $this->validFrom) {
            throw new InvalidArgumentException('Relation cannot end before it starts.');
        }

        $this->validUntil = $date;
    }

    public function isActiveAt(DateTimeImmutable $date): bool
    {
        return $date >= $this->validFrom
            && (null === $this->validUntil || $date <= $this->validUntil);
    }

    private static function assertOwnershipShare(UnitRelationType $type, ?string $ownershipShare): void
    {
        if (null !== $ownershipShare && UnitRelationType::OWNER !== $type) {
            throw new InvalidArgumentException('Ownership share is only valid for owner relations.');
        }

        if (null !== $ownershipShare && (!is_numeric($ownershipShare) || (float) $ownershipShare <= 0 || (float) $ownershipShare > 100)) {
            throw new InvalidArgumentException('Ownership share must be greater than 0 and at most 100.');
        }
    }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
