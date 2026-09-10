<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UnitType;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'property_unit')]
class Unit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $designation;

    #[ORM\Column(enumType: UnitType::class)]
    private UnitType $type;

    #[ORM\Column(nullable: true)]
    private ?int $floor;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $builtArea;

    #[ORM\Column(type: 'decimal', precision: 7, scale: 4, nullable: true)]
    private ?string $idealParts;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(
        string $designation,
        UnitType $type = UnitType::APARTMENT,
        ?int $floor = null,
        ?string $builtArea = null,
        ?string $idealParts = null,
    ) {
        $designation = trim($designation);
        if ('' === $designation) {
            throw new InvalidArgumentException('Unit designation cannot be empty.');
        }

        self::assertNonNegativeDecimal($builtArea, 'Built area');
        self::assertPercentage($idealParts, 'Ideal parts');

        $this->designation = $designation;
        $this->type = $type;
        $this->floor = $floor;
        $this->builtArea = $builtArea;
        $this->idealParts = $idealParts;
    }

    public function getId(): ?int { return $this->id; }
    public function getDesignation(): string { return $this->designation; }
    public function getType(): UnitType { return $this->type; }
    public function getFloor(): ?int { return $this->floor; }
    public function getBuiltArea(): ?string { return $this->builtArea; }
    public function getIdealParts(): ?string { return $this->idealParts; }
    public function isActive(): bool { return $this->active; }
    public function deactivate(): void { $this->active = false; }

    private static function assertNonNegativeDecimal(?string $value, string $label): void
    {
        if (null === $value) {
            return;
        }

        if (1 !== preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException(sprintf('%s must be a non-negative decimal.', $label));
        }
    }

    private static function assertPercentage(?string $value, string $label): void
    {
        if (null === $value) {
            return;
        }

        if (1 !== preg_match('/^(?:100(?:\.0{1,4})?|(?:0|[1-9]\d?)(?:\.\d{1,4})?)$/D', $value)) {
            throw new InvalidArgumentException(sprintf('%s must be between 0 and 100.', $label));
        }
    }
}
