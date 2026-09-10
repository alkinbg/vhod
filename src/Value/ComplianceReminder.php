<?php

declare(strict_types=1);

namespace App\Value;

use App\Enum\ComplianceReminderType;
use DateTimeImmutable;

final readonly class ComplianceReminder
{
    public function __construct(
        public ComplianceReminderType $type,
        public string $subject,
        public DateTimeImmutable $dueAt,
        public int $daysDelta,
    ) {}
}
