<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Charge;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\PaymentReversal;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\PaymentSource;
use App\Value\PaymentAllocationProposal;
use App\Value\PaymentPostingResult;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PaymentPostingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentAllocator $allocator,
        private ?AuditLogService $auditLog = null,
    ) {
    }

    public function post(
        Unit $unit,
        int $amountCents,
        PaymentSource $source,
        DateTimeImmutable $receivedAt,
        DateTimeImmutable $postedAt,
        ?string $reference = null,
        ?string $externalReference = null,
        ?string $note = null,
        ?PaymentAllocationProposal $explicitProposal = null,
        ?User $actor = null,
    ): PaymentPostingResult {
        $externalReference = self::nullableTrim($externalReference);

        if (null !== $externalReference) {
            $existing = $this->findByExternalReference($externalReference);
            if (null !== $existing) {
                $this->assertIdempotentMatch($existing, $unit, $amountCents, $source);

                return $this->resultForExistingPayment($existing);
            }
        }

        if (null === $unit->getId()) {
            throw new DomainException('Payment posting requires a persisted unit.');
        }

        try {
            return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
                $unit,
                $amountCents,
                $source,
                $receivedAt,
                $postedAt,
                $reference,
                $externalReference,
                $note,
                $explicitProposal,
                $actor,
            ): PaymentPostingResult {
                // All finance mutations lock Unit first. This serializes allocation
                // decisions for one unit and gives every path the same lock order.
                $entityManager->lock($unit, LockMode::PESSIMISTIC_WRITE);

                if (null !== $externalReference) {
                    $existing = $this->findByExternalReference($externalReference);
                    if (null !== $existing) {
                        $this->assertIdempotentMatch($existing, $unit, $amountCents, $source);

                        return $this->resultForExistingPayment($existing);
                    }
                }

                $proposal = $explicitProposal ?? $this->allocator->propose($unit, $amountCents);
                if ($proposal->getPaymentAmountCents() !== $amountCents) {
                    throw new DomainException('Allocation proposal payment amount does not match the payment amount.');
                }

                $this->lockProposalCharges($entityManager, $proposal);
                $this->validateProposal($unit, $proposal);

                $payment = Payment::post(
                    $unit,
                    $amountCents,
                    $source,
                    $receivedAt,
                    $postedAt,
                    $reference,
                    $externalReference,
                    $note,
                );
                $entityManager->persist($payment);

                foreach ($proposal->getAllocations() as $position => $proposed) {
                    $allocation = PaymentAllocation::allocate(
                        $payment,
                        $proposed->charge,
                        $proposed->amountCents,
                        $position,
                        $postedAt,
                    );
                    $entityManager->persist($allocation);
                }

                $entityManager->flush();
                $this->auditLog?->record(
                    $actor,
                    'finance.payment.posted',
                    'Payment',
                    $payment->getId(),
                    $postedAt,
                    [
                        'amount_cents' => $amountCents,
                        'source' => $source->value,
                        'unit_id' => $unit->getId(),
                        'allocated_cents' => $proposal->getAllocatedCents(),
                        'unallocated_cents' => $proposal->getUnallocatedCents(),
                    ],
                );

                return new PaymentPostingResult(
                    $payment,
                    $proposal->getAllocatedCents(),
                    $proposal->getUnallocatedCents(),
                );
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException('External payment reference is already used by another payment.', 0, $exception);
        }
    }

    private function lockProposalCharges(EntityManagerInterface $entityManager, PaymentAllocationProposal $proposal): void
    {
        $charges = [];
        foreach ($proposal->getAllocations() as $proposed) {
            $id = $proposed->charge->getId();
            if (null === $id) {
                throw new DomainException('Allocation proposal contains a non-persisted charge.');
            }
            $charges[$id] = $proposed->charge;
        }

        ksort($charges, SORT_NUMERIC);
        foreach ($charges as $charge) {
            $entityManager->lock($charge, LockMode::PESSIMISTIC_WRITE);
        }
    }

    private function validateProposal(Unit $unit, PaymentAllocationProposal $proposal): void
    {
        foreach ($proposal->getAllocations() as $proposed) {
            if (!self::sameUnit($unit, $proposed->charge->getUnit())) {
                throw new DomainException('Allocation proposal contains a charge from another unit.');
            }

            $outstanding = $proposed->charge->getAmountCents() - $this->allocatedCents($proposed->charge);
            if ($proposed->amountCents > $outstanding) {
                throw new DomainException('Allocation exceeds charge outstanding amount.');
            }
        }
    }

    private function resultForExistingPayment(Payment $payment): PaymentPostingResult
    {
        $allocated = 0;
        $allocations = $this->entityManager->getRepository(PaymentAllocation::class)->findBy(['payment' => $payment]);
        foreach ($allocations as $allocation) {
            $allocated += $allocation->getAmountCents();
        }

        return new PaymentPostingResult(
            $payment,
            $allocated,
            $payment->getAmountCents() - $allocated,
        );
    }

    private function allocatedCents(Charge $charge): int
    {
        $total = 0;
        $allocations = $this->entityManager->getRepository(PaymentAllocation::class)->findBy(['charge' => $charge]);
        foreach ($allocations as $allocation) {
            if ($this->isReversed($allocation)) {
                continue;
            }

            $total += $allocation->getAmountCents();
        }

        return $total;
    }

    private function isReversed(PaymentAllocation $allocation): bool
    {
        return null !== $this->entityManager->getRepository(PaymentReversal::class)->findOneBy([
            'payment' => $allocation->getPayment(),
        ]);
    }

    private function findByExternalReference(string $externalReference): ?Payment
    {
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy([
            'externalReference' => $externalReference,
        ]);

        return $payment instanceof Payment ? $payment : null;
    }

    private function assertIdempotentMatch(Payment $existing, Unit $unit, int $amountCents, PaymentSource $source): void
    {
        if (!self::sameUnit($existing->getUnit(), $unit)
            || $existing->getAmountCents() !== $amountCents
            || $existing->getSource() !== $source) {
            throw new DomainException('External payment reference is already used by a different payment.');
        }
    }

    private static function sameUnit(Unit $left, Unit $right): bool
    {
        if ($left === $right) {
            return true;
        }

        $leftId = $left->getId();
        $rightId = $right->getId();

        return null !== $leftId && null !== $rightId && $leftId === $rightId;
    }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
