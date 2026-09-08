<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\Iban;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'bank_transaction')]
#[ORM\UniqueConstraint(name: 'uniq_bank_transaction_account_fingerprint', columns: ['bank_account_id', 'fingerprint'])]
#[ORM\Index(name: 'idx_bank_transaction_account_booking', columns: ['bank_account_id', 'booking_date'])]
#[ORM\Index(name: 'idx_bank_transaction_import', columns: ['statement_import_id'])]
class BankTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private BankAccount $bankAccount;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private BankStatementImport $statementImport;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $bookingDate;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $valueDate;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $bankTransactionId;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $entryReference;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $endToEndId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $counterpartyName;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $counterpartyIban;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $remittanceInformation;

    private function __construct(
        BankAccount $bankAccount,
        BankStatementImport $statementImport,
        string $fingerprint,
        int $amountCents,
        DateTimeImmutable $bookingDate,
        ?DateTimeImmutable $valueDate,
        ?string $bankTransactionId,
        ?string $entryReference,
        ?string $endToEndId,
        ?string $counterpartyName,
        ?string $counterpartyIban,
        ?string $remittanceInformation,
    ) {
        if (!self::sameBankAccount($bankAccount, $statementImport->getBankAccount())) {
            throw new InvalidArgumentException('Bank transaction and statement import must belong to the same bank account.');
        }

        $fingerprint = strtolower(trim($fingerprint));
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            throw new InvalidArgumentException('Bank transaction fingerprint must be a SHA-256 hex digest.');
        }
        if (0 === $amountCents) {
            throw new InvalidArgumentException('Bank transaction amount cannot be zero.');
        }

        $this->bankAccount = $bankAccount;
        $this->statementImport = $statementImport;
        $this->fingerprint = $fingerprint;
        $this->amountCents = $amountCents;
        $this->bookingDate = self::normalizeDate($bookingDate);
        $this->valueDate = null === $valueDate ? null : self::normalizeDate($valueDate);
        $this->bankTransactionId = self::nullableTrim($bankTransactionId);
        $this->entryReference = self::nullableTrim($entryReference);
        $this->endToEndId = self::nullableTrim($endToEndId);
        $this->counterpartyName = self::nullableTrim($counterpartyName);
        $counterpartyIban = self::nullableTrim($counterpartyIban);
        $this->counterpartyIban = null === $counterpartyIban ? null : Iban::normalize($counterpartyIban);
        $this->remittanceInformation = self::nullableTrim($remittanceInformation);
    }

    public static function record(
        BankAccount $bankAccount,
        BankStatementImport $statementImport,
        string $fingerprint,
        int $amountCents,
        DateTimeImmutable $bookingDate,
        ?DateTimeImmutable $valueDate = null,
        ?string $bankTransactionId = null,
        ?string $entryReference = null,
        ?string $endToEndId = null,
        ?string $counterpartyName = null,
        ?string $counterpartyIban = null,
        ?string $remittanceInformation = null,
    ): self {
        return new self(
            $bankAccount,
            $statementImport,
            $fingerprint,
            $amountCents,
            $bookingDate,
            $valueDate,
            $bankTransactionId,
            $entryReference,
            $endToEndId,
            $counterpartyName,
            $counterpartyIban,
            $remittanceInformation,
        );
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getStatementImport(): BankStatementImport { return $this->statementImport; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function getCurrency(): string { return $this->currency; }
    public function getBookingDate(): DateTimeImmutable { return $this->bookingDate; }
    public function getValueDate(): ?DateTimeImmutable { return $this->valueDate; }
    public function getBankTransactionId(): ?string { return $this->bankTransactionId; }
    public function getEntryReference(): ?string { return $this->entryReference; }
    public function getEndToEndId(): ?string { return $this->endToEndId; }
    public function getCounterpartyName(): ?string { return $this->counterpartyName; }
    public function getCounterpartyIban(): ?string { return $this->counterpartyIban; }
    public function getRemittanceInformation(): ?string { return $this->remittanceInformation; }

    public function isIncoming(): bool
    {
        return $this->amountCents > 0;
    }

    public function isOutgoing(): bool
    {
        return $this->amountCents < 0;
    }

    private static function normalizeDate(DateTimeImmutable $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC'));
    }

    private static function sameBankAccount(BankAccount $left, BankAccount $right): bool
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
