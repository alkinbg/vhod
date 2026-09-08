<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\Charge;
use InvalidArgumentException;

final readonly class ProposedAllocation
{
    public function __construct(
        public Charge $charge,
        public int $amountCents,
    ) {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Proposed allocation amount must be positive.');
        }
    }
}
