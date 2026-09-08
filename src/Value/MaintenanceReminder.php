<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\BuildingAsset;
use App\Enum\MaintenanceReminderType;
use DateTimeImmutable;

final readonly class MaintenanceReminder
{
    public function __construct(
        public BuildingAsset $asset,
        public MaintenanceReminderType $type,
        public DateTimeImmutable $dueAt,
        public int $daysDelta,
    ) {}
}
