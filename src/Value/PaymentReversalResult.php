<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\PaymentReversal;
use InvalidArgumentException;

final readonly class PaymentReversalResult
{
    public function __construct(
        public PaymentReversal $reversal,
        public int $restoredAllocatedCents,
        public int $restoredUnallocatedCents,
    ) {
        if ($restoredAllocatedCents < 0 || $restoredUnallocatedCents < 0) {
            throw new InvalidArgumentException('Restored reversal amounts cannot be negative.');
        }

        if ($restoredAllocatedCents + $restoredUnallocatedCents !== $reversal->getAmountCents()) {
            throw new InvalidArgumentException('Restored reversal amounts must equal the reversed payment amount.');
        }
    }
}
