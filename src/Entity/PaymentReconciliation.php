<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentSource;
use App\Enum\ReconciliationMethod;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'payment_reconciliation')]
#[ORM\UniqueConstraint(name: 'uniq_payment_reconciliation_bank_transaction', columns: ['bank_transaction_id'])]
#[ORM\UniqueConstraint(name: 'uniq_payment_reconciliation_payment', columns: ['payment_id'])]
class PaymentReconciliation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private BankTransaction $bankTransaction;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Payment $payment;

    #[ORM\Column(enumType: ReconciliationMethod::class)]
    private ReconciliationMethod $method;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $reconciledAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    private function __construct(
        BankTransaction $bankTransaction,
        Payment $payment,
        ReconciliationMethod $method,
        DateTimeImmutable $reconciledAt,
        ?string $note,
    ) {
        if (!$bankTransaction->isIncoming()) {
            throw new InvalidArgumentException('Only incoming bank transactions can be reconciled to payments.');
        }
        if (PaymentSource::BANK_TRANSFER !== $payment->getSource()) {
            throw new InvalidArgumentException('Reconciled payment must be a bank transfer.');
        }
        if ($bankTransaction->getAmountCents() !== $payment->getAmountCents()) {
            throw new InvalidArgumentException('Payment amount must equal the bank transaction amount.');
        }

        $this->bankTransaction = $bankTransaction;
        $this->payment = $payment;
        $this->method = $method;
        $this->reconciledAt = $reconciledAt->setTimezone(new DateTimeZone('UTC'));
        $this->note = self::nullableTrim($note);
    }

    public static function record(
        BankTransaction $bankTransaction,
        Payment $payment,
        ReconciliationMethod $method,
        DateTimeImmutable $reconciledAt,
        ?string $note = null,
    ): self {
        return new self($bankTransaction, $payment, $method, $reconciledAt, $note);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBankTransaction(): BankTransaction
    {
        return $this->bankTransaction;
    }

    public function getPayment(): Payment
    {
        return $this->payment;
    }

    public function getMethod(): ReconciliationMethod
    {
        return $this->method;
    }

    public function getReconciledAt(): DateTimeImmutable
    {
        return $this->reconciledAt;
    }

    public function getNote(): ?string
    {
        return $this->note;
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
