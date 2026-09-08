<?php

declare(strict_types=1);

namespace App\Value;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class NormalizedBankTransaction
{
    public DateTimeImmutable $bookingDate;
    public ?DateTimeImmutable $valueDate;
    public ?string $bankTransactionId;
    public ?string $entryReference;
    public ?string $endToEndId;
    public ?string $counterpartyName;
    public ?string $counterpartyIban;
    public ?string $remittanceInformation;

    public function __construct(
        public int $amountCents,
        public string $currency,
        DateTimeImmutable $bookingDate,
        ?DateTimeImmutable $valueDate = null,
        ?string $bankTransactionId = null,
        ?string $entryReference = null,
        ?string $endToEndId = null,
        ?string $counterpartyName = null,
        ?string $counterpartyIban = null,
        ?string $remittanceInformation = null,
    ) {
        if (0 === $amountCents) {
            throw new InvalidArgumentException('Normalized bank transaction amount cannot be zero.');
        }
        if ('EUR' !== strtoupper(trim($currency))) {
            throw new InvalidArgumentException('Only EUR bank transactions are supported.');
        }

        $this->currency = 'EUR';
        $this->bookingDate = self::normalizeDate($bookingDate);
        $this->valueDate = null === $valueDate ? null : self::normalizeDate($valueDate);
        $this->bankTransactionId = self::nullableTrim($bankTransactionId);
        $this->entryReference = self::nullableTrim($entryReference);
        $this->endToEndId = self::nullableTrim($endToEndId);
        $this->counterpartyName = self::nullableTrim($counterpartyName);
        $counterpartyIban = self::nullableTrim($counterpartyIban);
        $this->counterpartyIban = null === $counterpartyIban ? null : Iban::normalize($counterpartyIban);
        $this->remittanceInformation = self::normalizeText($remittanceInformation);
    }

    private static function normalizeDate(DateTimeImmutable $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC'));
    }

    private static function normalizeText(?string $value): ?string
    {
        $value = self::nullableTrim($value);
        if (null === $value) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $value);

        return null === $collapsed ? $value : $collapsed;
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
