<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BuildingAssetCategory;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'building_asset')]
#[ORM\Index(name: 'idx_building_asset_active_name', columns: ['active', 'name'])]
class BuildingAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 32, enumType: BuildingAssetCategory::class)]
    private BuildingAssetCategory $category;

    #[ORM\Column(length: 180)]
    private string $location;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $manufacturer;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $model;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $serialNumber;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $installedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $warrantyUntil;

    #[ORM\Column(nullable: true)]
    private ?int $inspectionIntervalMonths;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $nextInspectionAt;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    private function __construct(
        string $name,
        BuildingAssetCategory $category,
        string $location,
        ?string $manufacturer,
        ?string $model,
        ?string $serialNumber,
        ?DateTimeImmutable $installedAt,
        ?DateTimeImmutable $warrantyUntil,
        ?int $inspectionIntervalMonths,
        ?DateTimeImmutable $nextInspectionAt,
    ) {
        $name = trim($name);
        $location = trim($location);
        if ('' === $name || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Building asset name must contain between 1 and 160 characters.');
        }
        if ('' === $location || mb_strlen($location) > 180) {
            throw new InvalidArgumentException('Building asset location must contain between 1 and 180 characters.');
        }
        if (null !== $inspectionIntervalMonths && $inspectionIntervalMonths <= 0) {
            throw new InvalidArgumentException('Inspection interval must be a positive number of months.');
        }
        if (null !== $installedAt && null !== $warrantyUntil && $warrantyUntil < $installedAt) {
            throw new InvalidArgumentException('Warranty end date cannot be before installation date.');
        }

        $this->name = $name;
        $this->category = $category;
        $this->location = $location;
        $this->manufacturer = self::optional($manufacturer);
        $this->model = self::optional($model);
        $this->serialNumber = self::optional($serialNumber);
        $this->installedAt = $installedAt;
        $this->warrantyUntil = $warrantyUntil;
        $this->inspectionIntervalMonths = $inspectionIntervalMonths;
        $this->nextInspectionAt = $nextInspectionAt;
    }

    public static function register(
        string $name,
        BuildingAssetCategory $category,
        string $location,
        ?string $manufacturer = null,
        ?string $model = null,
        ?string $serialNumber = null,
        ?DateTimeImmutable $installedAt = null,
        ?DateTimeImmutable $warrantyUntil = null,
        ?int $inspectionIntervalMonths = null,
        ?DateTimeImmutable $nextInspectionAt = null,
    ): self {
        return new self($name, $category, $location, $manufacturer, $model, $serialNumber, $installedAt, $warrantyUntil, $inspectionIntervalMonths, $nextInspectionAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getCategory(): BuildingAssetCategory { return $this->category; }
    public function getLocation(): string { return $this->location; }
    public function getManufacturer(): ?string { return $this->manufacturer; }
    public function getModel(): ?string { return $this->model; }
    public function getSerialNumber(): ?string { return $this->serialNumber; }
    public function getInstalledAt(): ?DateTimeImmutable { return $this->installedAt; }
    public function getWarrantyUntil(): ?DateTimeImmutable { return $this->warrantyUntil; }
    public function getInspectionIntervalMonths(): ?int { return $this->inspectionIntervalMonths; }
    public function getNextInspectionAt(): ?DateTimeImmutable { return $this->nextInspectionAt; }
    public function isActive(): bool { return $this->active; }

    public function scheduleNextInspection(?DateTimeImmutable $date): void
    {
        $this->nextInspectionAt = $date;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    private static function optional(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
