<?php

declare(strict_types=1);

namespace App\Value;

final readonly class ChargeGenerationResult
{
    public function __construct(
        public int $created,
        public int $skipped,
        public int $totalAmountCents,
    ) {
    }
}
