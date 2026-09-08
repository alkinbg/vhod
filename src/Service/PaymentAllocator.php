<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Charge;
use App\Entity\PaymentAllocation;
use App\Entity\Unit;
use App\Value\PaymentAllocationProposal;
use App\Value\ProposedAllocation;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class PaymentAllocator
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function propose(Unit $unit, int $amountCents): PaymentAllocationProposal
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        $remaining = $amountCents;
        $proposed = [];
        $charges = $this->entityManager->getRepository(Charge::class)->findBy(
            ['unit' => $unit],
            ['billingMonth' => 'ASC', 'id' => 'ASC'],
        );

        foreach ($charges as $charge) {
            if (0 === $remaining) {
                break;
            }

            $outstanding = $charge->getAmountCents() - $this->allocatedCents($charge);
            if ($outstanding <= 0) {
                continue;
            }

            $allocation = min($remaining, $outstanding);
            $proposed[] = new ProposedAllocation($charge, $allocation);
            $remaining -= $allocation;
        }

        return new PaymentAllocationProposal($amountCents, $proposed);
    }

    private function allocatedCents(Charge $charge): int
    {
        $total = 0;
        $allocations = $this->entityManager->getRepository(PaymentAllocation::class)->findBy(['charge' => $charge]);
        foreach ($allocations as $allocation) {
            $total += $allocation->getAmountCents();
        }

        return $total;
    }
}
