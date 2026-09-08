<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UnitRelationType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Index(columns: ['valid_from', 'valid_until'], name: 'idx_household_member_period')]
class HouseholdMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Person $person;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private UnitRelation $relation;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $validUntil = null;

    public function __construct(Person $person, UnitRelation $relation, DateTimeImmutable $validFrom)
    {
        if (!in_array($relation->getType(), [UnitRelationType::OWNER, UnitRelationType::USER], true)) {
            throw new InvalidArgumentException('Household members can only belong to owner or user relations.');
        }

        $this->person = $person;
        $this->relation = $relation;
        $this->validFrom = $validFrom;
    }

    public function getId(): ?int { return $this->id; }
    public function getPerson(): Person { return $this->person; }
    public function getRelation(): UnitRelation { return $this->relation; }
    public function getValidFrom(): DateTimeImmutable { return $this->validFrom; }
    public function getValidUntil(): ?DateTimeImmutable { return $this->validUntil; }

    public function endAt(DateTimeImmutable $date): void
    {
        if ($date < $this->validFrom) {
            throw new InvalidArgumentException('Household membership cannot end before it starts.');
        }

        $this->validUntil = $date;
    }

    public function isActiveAt(DateTimeImmutable $date): bool
    {
        return $date >= $this->validFrom
            && (null === $this->validUntil || $date <= $this->validUntil);
    }
}
