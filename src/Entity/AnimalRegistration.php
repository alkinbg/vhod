<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Index(columns: ['unit_id', 'valid_from', 'valid_until'], name: 'idx_animal_registration_unit_period')]
class AnimalRegistration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Unit $unit;

    #[ORM\Column(length: 100)]
    private string $species;

    #[ORM\Column(name: 'animal_count')]
    private int $count;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $veterinaryPassportNumber;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $validUntil;

    public function __construct(
        Unit $unit,
        string $species,
        int $count,
        ?string $veterinaryPassportNumber = null,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
    ) {
        $species = trim($species);
        if ('' === $species) {
            throw new InvalidArgumentException('Animal species cannot be empty.');
        }

        if ($count <= 0) {
            throw new InvalidArgumentException('Animal count must be positive.');
        }

        $validFrom ??= new DateTimeImmutable('1970-01-01');
        if (null !== $validUntil && $validUntil < $validFrom) {
            throw new InvalidArgumentException('Animal registration cannot end before it starts.');
        }

        $this->unit = $unit;
        $this->species = $species;
        $this->count = $count;
        $this->veterinaryPassportNumber = self::nullableTrim($veterinaryPassportNumber);
        $this->validFrom = $validFrom;
        $this->validUntil = $validUntil;
    }

    public function getId(): ?int { return $this->id; }
    public function getUnit(): Unit { return $this->unit; }
    public function getSpecies(): string { return $this->species; }
    public function getCount(): int { return $this->count; }
    public function getVeterinaryPassportNumber(): ?string { return $this->veterinaryPassportNumber; }
    public function getValidFrom(): DateTimeImmutable { return $this->validFrom; }
    public function getValidUntil(): ?DateTimeImmutable { return $this->validUntil; }

    public function endAt(DateTimeImmutable $date): void
    {
        if ($date < $this->validFrom) {
            throw new InvalidArgumentException('Animal registration cannot end before it starts.');
        }

        $this->validUntil = $date;
    }

    public function isActiveAt(DateTimeImmutable $date): bool
    {
        return $date >= $this->validFrom
            && (null === $this->validUntil || $date <= $this->validUntil);
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
