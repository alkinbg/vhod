<?php

declare(strict_types=1);

namespace App\Value;

use InvalidArgumentException;

final readonly class PaymentAllocationProposal
{
    /**
     * @param list<ProposedAllocation> $allocations
     */
    public function __construct(
        private int $paymentAmountCents,
        private array $allocations,
    ) {
        if ($paymentAmountCents <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        $allocated = 0;
        $seen = [];
        foreach ($allocations as $allocation) {
            $key = spl_object_id($allocation->charge);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('A charge may appear only once in an allocation proposal.');
            }
            $seen[$key] = true;
            $allocated += $allocation->amountCents;
        }

        if ($allocated > $paymentAmountCents) {
            throw new InvalidArgumentException('Allocation proposal exceeds payment amount.');
        }
    }

    /** @return list<ProposedAllocation> */
    public function getAllocations(): array
    {
        return $this->allocations;
    }

    public function getAllocatedCents(): int
    {
        $allocated = 0;
        foreach ($this->allocations as $allocation) {
            $allocated += $allocation->amountCents;
        }

        return $allocated;
    }

    public function getUnallocatedCents(): int
    {
        return $this->paymentAmountCents - $this->getAllocatedCents();
    }

    public function getPaymentAmountCents(): int
    {
        return $this->paymentAmountCents;
    }
}
