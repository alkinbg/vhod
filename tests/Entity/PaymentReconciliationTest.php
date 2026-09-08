<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BankAccount;
use App\Entity\BankStatementImport;
use App\Entity\BankTransaction;
use App\Entity\Payment;
use App\Entity\PaymentReconciliation;
use App\Entity\Unit;
use App\Enum\BankStatementFormat;
use App\Enum\PaymentSource;
use App\Enum\ReconciliationMethod;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentReconciliationTest extends TestCase
{
    public function testValidReconciliationKeepsImmutableAuditLink(): void
    {
        $unit = new Unit('12');
        $transaction = $this->transaction(12550);
        $payment = Payment::post(
            $unit,
            12550,
            PaymentSource::BANK_TRANSFER,
            new DateTimeImmutable('2026-09-08 00:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 08:20:00 Europe/Sofia'),
        );

        $reconciliation = PaymentReconciliation::record(
            $transaction,
            $payment,
            ReconciliationMethod::MANUAL,
            new DateTimeImmutable('2026-09-08 08:30:00 Europe/Sofia'),
            '  Потвърдено от касиера.  ',
        );

        self::assertSame($transaction, $reconciliation->getBankTransaction());
        self::assertSame($payment, $reconciliation->getPayment());
        self::assertSame(ReconciliationMethod::MANUAL, $reconciliation->getMethod());
        self::assertSame('2026-09-08 05:30:00', $reconciliation->getReconciledAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $reconciliation->getReconciledAt()->getTimezone()->getName());
        self::assertSame('Потвърдено от касиера.', $reconciliation->getNote());
    }

    public function testOutgoingTransactionCannotBeReconciledAsPayment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only incoming bank transactions can be reconciled to payments.');

        PaymentReconciliation::record(
            $this->transaction(-3200),
            $this->payment(3200, PaymentSource::BANK_TRANSFER),
            ReconciliationMethod::MANUAL,
            new DateTimeImmutable('2026-09-08 05:30:00 UTC'),
        );
    }

    public function testNonBankPaymentCannotBeReconciled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reconciled payment must be a bank transfer.');

        PaymentReconciliation::record(
            $this->transaction(12550),
            $this->payment(12550, PaymentSource::CASH),
            ReconciliationMethod::MANUAL,
            new DateTimeImmutable('2026-09-08 05:30:00 UTC'),
        );
    }

    public function testAmountMismatchIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Payment amount must equal the bank transaction amount.');

        PaymentReconciliation::record(
            $this->transaction(12550),
            $this->payment(12500, PaymentSource::BANK_TRANSFER),
            ReconciliationMethod::AUTOMATIC,
            new DateTimeImmutable('2026-09-08 05:30:00 UTC'),
        );
    }

    private function transaction(int $amountCents): BankTransaction
    {
        $account = BankAccount::create('Основна сметка', 'BG35TEST00000000000000');
        $import = BankStatementImport::record(
            $account,
            BankStatementFormat::CAMT053,
            str_repeat('a', 64),
            new DateTimeImmutable('2026-09-08 05:00:00 UTC'),
            1,
        );

        return BankTransaction::record(
            $account,
            $import,
            str_repeat($amountCents > 0 ? 'b' : 'c', 64),
            $amountCents,
            new DateTimeImmutable('2026-09-08'),
            counterpartyIban: 'BG97FAKE00000000000001',
        );
    }

    private function payment(int $amountCents, PaymentSource $source): Payment
    {
        return Payment::post(
            new Unit('12'),
            $amountCents,
            $source,
            new DateTimeImmutable('2026-09-08 05:00:00 UTC'),
            new DateTimeImmutable('2026-09-08 05:10:00 UTC'),
        );
    }
}
