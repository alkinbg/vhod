<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
class AnimalRegistration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Unit $unit;

    #[ORM\Column(length: 100)]
    private string $species;

    #[ORM\Column(name: 'animal_count')]
    private int $count;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $veterinaryPassportNumber;

    public function __construct(Unit $unit, string $species, int $count, ?string $veterinaryPassportNumber = null)
    {
        $species = trim($species);
        if ('' === $species) {
            throw new InvalidArgumentException('Animal species cannot be empty.');
        }

        if ($count <= 0) {
            throw new InvalidArgumentException('Animal count must be positive.');
        }

        $this->unit = $unit;
        $this->species = $species;
        $this->count = $count;
        $this->veterinaryPassportNumber = self::nullableTrim($veterinaryPassportNumber);
    }

    public function getId(): ?int { return $this->id; }
    public function getUnit(): Unit { return $this->unit; }
    public function getSpecies(): string { return $this->species; }
    public function getCount(): int { return $this->count; }
    public function getVeterinaryPassportNumber(): ?string { return $this->veterinaryPassportNumber; }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
