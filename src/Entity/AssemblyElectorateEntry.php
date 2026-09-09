<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssemblyPrincipalType;
use App\Repository\AssemblyElectorateEntryRepository;
use App\Util\ExactDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: AssemblyElectorateEntryRepository::class)]
#[ORM\Table(name: 'assembly_electorate_entry')]
#[ORM\Index(name: 'idx_electorate_assembly', columns: ['assembly_id'])]
#[ORM\Index(name: 'idx_electorate_unit', columns: ['unit_id'])]
#[ORM\Index(name: 'idx_electorate_source_relation', columns: ['source_relation_id'])]
#[ORM\Index(name: 'idx_electorate_person', columns: ['person_id'])]
class AssemblyElectorateEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assembly_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_electorate_assembly')]
    private GeneralAssembly $assembly;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'unit_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_electorate_unit')]
    private ?Unit $unit;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_relation_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_electorate_source_relation')]
    private ?UnitRelation $sourceRelation;

    #[ORM\Column(length: 64)]
    private string $unitDesignationSnapshot;

    #[ORM\Column(length: 32, enumType: AssemblyPrincipalType::class)]
    private AssemblyPrincipalType $principalType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'person_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_electorate_person')]
    private ?Person $person;

    #[ORM\Column(length: 255)]
    private string $principalNameSnapshot;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $principalIdentifierSnapshot;

    #[ORM\Column(length: 32)]
    private string $relationTypeSnapshot;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8, nullable: true)]
    private ?string $ownershipSharePercentSnapshot;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8, nullable: true)]
    private ?string $unitIdealPartsPercentSnapshot;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 8, nullable: true)]
    private ?string $representedIdealPartsPercentSnapshot;

    #[ORM\Column(options: ['default' => false])]
    private bool $quorumEligible;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewReason;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    private function __construct(
        GeneralAssembly $assembly,
        ?Unit $unit,
        ?UnitRelation $sourceRelation,
        string $unitDesignationSnapshot,
        AssemblyPrincipalType $principalType,
        ?Person $person,
        string $principalNameSnapshot,
        ?string $principalIdentifierSnapshot,
        string $relationTypeSnapshot,
        ?string $ownershipSharePercentSnapshot,
        ?string $unitIdealPartsPercentSnapshot,
        ?string $representedIdealPartsPercentSnapshot,
        bool $quorumEligible,
        ?string $reviewReason,
        DateTimeImmutable $createdAt,
    ) {
        $unitDesignationSnapshot = trim($unitDesignationSnapshot);
        $principalNameSnapshot = trim($principalNameSnapshot);
        $principalIdentifierSnapshot = self::nullableTrim($principalIdentifierSnapshot);
        $relationTypeSnapshot = trim($relationTypeSnapshot);
        $reviewReason = self::nullableTrim($reviewReason);

        if ('' === $unitDesignationSnapshot || mb_strlen($unitDesignationSnapshot) > 64) {
            throw new InvalidArgumentException('Unit designation snapshot must contain between 1 and 64 characters.');
        }
        if ('' === $principalNameSnapshot || mb_strlen($principalNameSnapshot) > 255) {
            throw new InvalidArgumentException('Principal name snapshot must contain between 1 and 255 characters.');
        }
        if ('' === $relationTypeSnapshot || mb_strlen($relationTypeSnapshot) > 32) {
            throw new InvalidArgumentException('Relation type snapshot must contain between 1 and 32 characters.');
        }
        if (AssemblyPrincipalType::PERSON === $principalType && null === $person) {
            throw new InvalidArgumentException('Person principal requires a source person.');
        }
        if (AssemblyPrincipalType::LEGAL_ENTITY === $principalType && null !== $person) {
            throw new InvalidArgumentException('Legal-entity principal cannot reference a person.');
        }
        if (AssemblyPrincipalType::LEGAL_ENTITY === $principalType && null === $principalIdentifierSnapshot) {
            throw new InvalidArgumentException('Legal-entity principal requires an identifier snapshot.');
        }
        if ($quorumEligible && (null === $representedIdealPartsPercentSnapshot || null !== $reviewReason)) {
            throw new InvalidArgumentException('Quorum-eligible electorate entry requires an exact represented weight and no review reason.');
        }
        if (!$quorumEligible && null === $reviewReason) {
            throw new InvalidArgumentException('Non-eligible electorate entry requires a review reason.');
        }

        $ownershipSharePercentSnapshot = self::normalizeOptionalPercent($ownershipSharePercentSnapshot, 'Ownership share');
        $unitIdealPartsPercentSnapshot = self::normalizeOptionalPercent($unitIdealPartsPercentSnapshot, 'Unit ideal parts');
        $representedIdealPartsPercentSnapshot = self::normalizeOptionalPercent($representedIdealPartsPercentSnapshot, 'Represented ideal parts');

        $this->assembly = $assembly;
        $this->unit = $unit;
        $this->sourceRelation = $sourceRelation;
        $this->unitDesignationSnapshot = $unitDesignationSnapshot;
        $this->principalType = $principalType;
        $this->person = $person;
        $this->principalNameSnapshot = $principalNameSnapshot;
        $this->principalIdentifierSnapshot = $principalIdentifierSnapshot;
        $this->relationTypeSnapshot = $relationTypeSnapshot;
        $this->ownershipSharePercentSnapshot = $ownershipSharePercentSnapshot;
        $this->unitIdealPartsPercentSnapshot = $unitIdealPartsPercentSnapshot;
        $this->representedIdealPartsPercentSnapshot = $representedIdealPartsPercentSnapshot;
        $this->quorumEligible = $quorumEligible;
        $this->reviewReason = $reviewReason;
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function snapshot(
        GeneralAssembly $assembly,
        ?Unit $unit,
        ?UnitRelation $sourceRelation,
        string $unitDesignationSnapshot,
        AssemblyPrincipalType $principalType,
        ?Person $person,
        string $principalNameSnapshot,
        ?string $principalIdentifierSnapshot,
        string $relationTypeSnapshot,
        ?string $ownershipSharePercentSnapshot,
        ?string $unitIdealPartsPercentSnapshot,
        ?string $representedIdealPartsPercentSnapshot,
        bool $quorumEligible,
        ?string $reviewReason,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $assembly,
            $unit,
            $sourceRelation,
            $unitDesignationSnapshot,
            $principalType,
            $person,
            $principalNameSnapshot,
            $principalIdentifierSnapshot,
            $relationTypeSnapshot,
            $ownershipSharePercentSnapshot,
            $unitIdealPartsPercentSnapshot,
            $representedIdealPartsPercentSnapshot,
            $quorumEligible,
            $reviewReason,
            $createdAt,
        );
    }

    public function getId(): ?int { return $this->id; }
    public function getAssembly(): GeneralAssembly { return $this->assembly; }
    public function getUnit(): ?Unit { return $this->unit; }
    public function getSourceRelation(): ?UnitRelation { return $this->sourceRelation; }
    public function getUnitDesignationSnapshot(): string { return $this->unitDesignationSnapshot; }
    public function getPrincipalType(): AssemblyPrincipalType { return $this->principalType; }
    public function getPerson(): ?Person { return $this->person; }
    public function getPrincipalNameSnapshot(): string { return $this->principalNameSnapshot; }
    public function getPrincipalIdentifierSnapshot(): ?string { return $this->principalIdentifierSnapshot; }
    public function getRelationTypeSnapshot(): string { return $this->relationTypeSnapshot; }
    public function getOwnershipSharePercentSnapshot(): ?string { return $this->ownershipSharePercentSnapshot; }
    public function getUnitIdealPartsPercentSnapshot(): ?string { return $this->unitIdealPartsPercentSnapshot; }
    public function getRepresentedIdealPartsPercentSnapshot(): ?string { return $this->representedIdealPartsPercentSnapshot; }
    public function isQuorumEligible(): bool { return $this->quorumEligible; }
    public function getReviewReason(): ?string { return $this->reviewReason; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }

    private static function normalizeOptionalPercent(?string $value, string $label): ?string
    {
        if (null === $value) {
            return null;
        }

        $normalized = ExactDecimal::normalize($value);
        if (ExactDecimal::compare($normalized, '0') < 0 || ExactDecimal::compare($normalized, '100') > 0) {
            throw new InvalidArgumentException(sprintf('%s snapshot must be between 0 and 100 percent.', $label));
        }

        return $normalized;
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
