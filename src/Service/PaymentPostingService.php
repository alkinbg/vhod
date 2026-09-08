<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Charge;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\Unit;
use App\Enum\PaymentSource;
use App\Value\PaymentAllocationProposal;
use App\Value\PaymentPostingResult;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class PaymentPostingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentAllocator $allocator,
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
    ): PaymentPostingResult {
        $externalReference = self::nullableTrim($externalReference);

        if (null !== $externalReference) {
            $existing = $this->findByExternalReference($externalReference);
            if (null !== $existing) {
                return $this->resultForExistingPayment($existing);
            }
        }

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
        ): PaymentPostingResult {
            if (null !== $externalReference) {
                $existing = $this->findByExternalReference($externalReference);
                if (null !== $existing) {
                    return $this->resultForExistingPayment($existing);
                }
            }

            $proposal = $explicitProposal ?? $this->allocator->propose($unit, $amountCents);
            if ($proposal->getPaymentAmountCents() !== $amountCents) {
                throw new DomainException('Allocation proposal payment amount does not match the payment amount.');
            }

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

            return new PaymentPostingResult(
                $payment,
                $proposal->getAllocatedCents(),
                $proposal->getUnallocatedCents(),
            );
        });
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
            $total += $allocation->getAmountCents();
        }

        return $total;
    }

    private function findByExternalReference(string $externalReference): ?Payment
    {
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy([
            'externalReference' => $externalReference,
        ]);

        return $payment instanceof Payment ? $payment : null;
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
