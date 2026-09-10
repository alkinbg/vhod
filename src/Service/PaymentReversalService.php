<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\PaymentReversal;
use App\Entity\User;
use App\Value\PaymentReversalResult;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PaymentReversalService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?AuditLogService $auditLog = null,
    ) {
    }

    public function reverse(
        Payment $payment,
        string $reason,
        DateTimeImmutable $reversedAt,
        ?User $actor = null,
    ): PaymentReversalResult {
        if (null === $payment->getId()) {
            throw new DomainException('Only a persisted payment can be reversed.');
        }
        if (null === $payment->getUnit()->getId()) {
            throw new DomainException('Payment reversal requires a persisted unit.');
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($payment, $reason, $reversedAt, $actor): PaymentReversalResult {
            // Keep the same lock order as posting/reconciliation: Unit first, then Payment.
            $entityManager->lock($payment->getUnit(), LockMode::PESSIMISTIC_WRITE);
            $entityManager->lock($payment, LockMode::PESSIMISTIC_WRITE);

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
            $this->auditLog?->record(
                $actor,
                'finance.payment.reversed',
                'Payment',
                $payment->getId(),
                $reversedAt,
                [
                    'reversal_id' => $reversal->getId(),
                    'amount_cents' => $payment->getAmountCents(),
                    'allocated_cents' => $allocatedCents,
                ],
            );

            return new PaymentReversalResult(
                $reversal,
                $allocatedCents,
                $payment->getAmountCents() - $allocatedCents,
            );
        });
    }
}
