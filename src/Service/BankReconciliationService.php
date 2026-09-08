<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BankCounterpartyMapping;
use App\Entity\BankTransaction;
use App\Entity\Payment;
use App\Entity\PaymentReconciliation;
use App\Entity\Unit;
use App\Enum\PaymentSource;
use App\Enum\ReconciliationMethod;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class BankReconciliationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentPostingService $paymentPostingService,
    ) {
    }

    public function reconcileToUnit(
        BankTransaction $transaction,
        Unit $unit,
        DateTimeImmutable $reconciledAt,
        ?string $note = null,
    ): PaymentReconciliation {
        $this->assertPersistedTransaction($transaction);
        $this->assertUsableUnit($unit);
        $this->assertIncoming($transaction);
        $this->assertTransactionUnreconciled($transaction);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $transaction,
            $unit,
            $reconciledAt,
            $note,
        ): PaymentReconciliation {
            $this->assertTransactionUnreconciled($transaction);

            return $this->postAndRecord(
                $entityManager,
                $transaction,
                $unit,
                ReconciliationMethod::MANUAL,
                $reconciledAt,
                $note,
            );
        });
    }

    public function autoReconcile(
        BankTransaction $transaction,
        DateTimeImmutable $reconciledAt,
    ): ?PaymentReconciliation {
        $this->assertPersistedTransaction($transaction);

        if (!$transaction->isIncoming() || null !== $this->findByTransaction($transaction)) {
            return null;
        }

        $counterpartyIban = $transaction->getCounterpartyIban();
        if (null === $counterpartyIban) {
            return null;
        }

        $mappings = $this->entityManager->getRepository(BankCounterpartyMapping::class)->findBy(
            ['counterpartyIban' => $counterpartyIban, 'active' => true],
            ['id' => 'ASC'],
            2,
        );
        if (1 !== count($mappings) || !$mappings[0] instanceof BankCounterpartyMapping) {
            return null;
        }

        $unit = $mappings[0]->getUnit();
        if (!$unit->isActive() || null === $unit->getId()) {
            return null;
        }

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $transaction,
            $unit,
            $reconciledAt,
        ): ?PaymentReconciliation {
            if (null !== $this->findByTransaction($transaction)) {
                return null;
            }

            return $this->postAndRecord(
                $entityManager,
                $transaction,
                $unit,
                ReconciliationMethod::AUTOMATIC,
                $reconciledAt,
                null,
            );
        });
    }

    public function linkExistingPayment(
        BankTransaction $transaction,
        Payment $payment,
        DateTimeImmutable $reconciledAt,
        ?string $note = null,
    ): PaymentReconciliation {
        $this->assertPersistedTransaction($transaction);
        if (null === $payment->getId()) {
            throw new DomainException('Payment must be persisted before reconciliation.');
        }
        $this->assertIncoming($transaction);
        $this->assertTransactionUnreconciled($transaction);
        $this->assertPaymentUnreconciled($payment);

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (
            $transaction,
            $payment,
            $reconciledAt,
            $note,
        ): PaymentReconciliation {
            $this->assertTransactionUnreconciled($transaction);
            $this->assertPaymentUnreconciled($payment);

            $reconciliation = PaymentReconciliation::record(
                $transaction,
                $payment,
                ReconciliationMethod::MANUAL,
                $reconciledAt,
                $note,
            );
            $entityManager->persist($reconciliation);
            $entityManager->flush();

            return $reconciliation;
        });
    }

    private function postAndRecord(
        EntityManagerInterface $entityManager,
        BankTransaction $transaction,
        Unit $unit,
        ReconciliationMethod $method,
        DateTimeImmutable $reconciledAt,
        ?string $note,
    ): PaymentReconciliation {
        $paymentResult = $this->paymentPostingService->post(
            $unit,
            $transaction->getAmountCents(),
            PaymentSource::BANK_TRANSFER,
            $transaction->getBookingDate(),
            $reconciledAt,
            reference: $transaction->getRemittanceInformation(),
            externalReference: 'bank:'.$transaction->getFingerprint(),
        );

        $payment = $paymentResult->payment;
        $this->assertPaymentUnreconciled($payment);

        $reconciliation = PaymentReconciliation::record(
            $transaction,
            $payment,
            $method,
            $reconciledAt,
            $note,
        );
        $entityManager->persist($reconciliation);
        $entityManager->flush();

        return $reconciliation;
    }

    private function assertPersistedTransaction(BankTransaction $transaction): void
    {
        if (null === $transaction->getId()) {
            throw new DomainException('Bank transaction must be persisted before reconciliation.');
        }
    }

    private function assertUsableUnit(Unit $unit): void
    {
        if (null === $unit->getId()) {
            throw new DomainException('Unit must be persisted before reconciliation.');
        }
        if (!$unit->isActive()) {
            throw new DomainException('Reconciliation requires an active unit.');
        }
    }

    private function assertIncoming(BankTransaction $transaction): void
    {
        if (!$transaction->isIncoming()) {
            throw new DomainException('Only incoming bank transactions can be reconciled.');
        }
    }

    private function assertTransactionUnreconciled(BankTransaction $transaction): void
    {
        if (null !== $this->findByTransaction($transaction)) {
            throw new DomainException('Bank transaction is already reconciled.');
        }
    }

    private function assertPaymentUnreconciled(Payment $payment): void
    {
        if (null !== $this->findByPayment($payment)) {
            throw new DomainException('Payment is already reconciled.');
        }
    }

    private function findByTransaction(BankTransaction $transaction): ?PaymentReconciliation
    {
        $reconciliation = $this->entityManager->getRepository(PaymentReconciliation::class)->findOneBy([
            'bankTransaction' => $transaction,
        ]);

        return $reconciliation instanceof PaymentReconciliation ? $reconciliation : null;
    }

    private function findByPayment(Payment $payment): ?PaymentReconciliation
    {
        $reconciliation = $this->entityManager->getRepository(PaymentReconciliation::class)->findOneBy([
            'payment' => $payment,
        ]);

        return $reconciliation instanceof PaymentReconciliation ? $reconciliation : null;
    }
}
