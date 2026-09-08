<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Charge;
use App\Entity\Payment;
use App\Entity\PaymentReversal;
use App\Entity\Unit;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UnitBalanceCalculator
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function netBalanceCents(Unit $unit): int
    {
        $chargeTotalCents = 0;
        $charges = $this->entityManager->getRepository(Charge::class)->findBy(['unit' => $unit]);
        foreach ($charges as $charge) {
            $chargeTotalCents += $charge->getAmountCents();
        }

        $effectivePaymentTotalCents = 0;
        $payments = $this->entityManager->getRepository(Payment::class)->findBy(['unit' => $unit]);
        foreach ($payments as $payment) {
            $reversal = $this->entityManager->getRepository(PaymentReversal::class)->findOneBy([
                'payment' => $payment,
            ]);
            if ($reversal instanceof PaymentReversal) {
                continue;
            }

            $effectivePaymentTotalCents += $payment->getAmountCents();
        }

        return $chargeTotalCents - $effectivePaymentTotalCents;
    }
}
