<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\Payment;
use InvalidArgumentException;

final readonly class PaymentPostingResult
{
    public function __construct(
        public Payment $payment,
        public int $allocatedCents,
        public int $unallocatedCents,
    ) {
        if ($allocatedCents < 0 || $unallocatedCents < 0) {
            throw new InvalidArgumentException('Payment result amounts cannot be negative.');
        }

        if ($allocatedCents + $unallocatedCents !== $payment->getAmountCents()) {
            throw new InvalidArgumentException('Payment result amounts must equal the payment amount.');
        }
    }
}
