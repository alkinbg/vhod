<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Index(columns: ['valid_from', 'valid_until'], name: 'idx_unit_absence_period')]
class UnitAbsence
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
    private Unit $unit;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $validUntil;

    public function __construct(
        Person $person,
        Unit $unit,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validUntil = null,
    ) {
        if (null !== $validUntil && $validUntil < $validFrom) {
            throw new InvalidArgumentException('Absence cannot end before it starts.');
        }

        $this->person = $person;
        $this->unit = $unit;
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
    }

    public function getId(): ?int { return $this->id; }
    public function getPerson(): Person { return $this->person; }
    public function getUnit(): Unit { return $this->unit; }
    public function getValidFrom(): DateTimeImmutable { return $this->validFrom; }
    public function getValidUntil(): ?DateTimeImmutable { return $this->validUntil; }

    public function covers(DateTimeImmutable $date): bool
    {
        return $date >= $this->validFrom
            && (null === $this->validUntil || $date <= $this->validUntil);
    }
}
