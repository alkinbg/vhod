<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\PaymentReversal;
use App\Value\PaymentReversalResult;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PaymentReversalService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function reverse(
        Payment $payment,
        string $reason,
        DateTimeImmutable $reversedAt,
    ): PaymentReversalResult {
        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($payment, $reason, $reversedAt): PaymentReversalResult {
            $existing = $entityManager->getRepository(PaymentReversal::class)->findOneBy(['payment' => $payment]);
            if ($existing instanceof PaymentReversal) {
                throw new DomainException('Payment has already been reversed.');
            }

            $allocatedCents = 0;
            $allocations = $entityManager->getRepository(PaymentAllocation::class)->findBy(['payment' => $payment]);
            foreach ($allocations as $allocation) {
                $allocatedCents += $allocation->getAmountCents();
            }

            $reversal = PaymentReversal::record(
                $payment,
                $payment->getAmountCents(),
                $reason,
                $reversedAt,
            );
            $entityManager->persist($reversal);
            $entityManager->flush();

            return new PaymentReversalResult(
                $reversal,
                $allocatedCents,
                $payment->getAmountCents() - $allocatedCents,
            );
        });
    }
}
