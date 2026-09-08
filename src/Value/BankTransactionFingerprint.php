<?php

declare(strict_types=1);

namespace App\Value;

use DateTimeImmutable;
use JsonException;

final class BankTransactionFingerprint
{
    /** @throws JsonException */
    public static function fromFields(
        string $accountIban,
        int $amountCents,
        DateTimeImmutable $bookingDate,
        ?DateTimeImmutable $valueDate,
        ?string $bankTransactionId,
        ?string $entryReference,
        ?string $endToEndId,
        ?string $counterpartyIban,
        ?string $remittanceInformation,
    ): string {
        $counterpartyIban = self::nullableTrim($counterpartyIban);

        $canonical = [
            'accountIban' => Iban::normalize($accountIban),
            'amountCents' => $amountCents,
            'bookingDate' => $bookingDate->format('Y-m-d'),
            'valueDate' => $valueDate?->format('Y-m-d'),
            'bankTransactionId' => self::nullableTrim($bankTransactionId),
            'entryReference' => self::nullableTrim($entryReference),
            'endToEndId' => self::nullableTrim($endToEndId),
            'counterpartyIban' => null === $counterpartyIban ? null : Iban::normalize($counterpartyIban),
            'remittanceInformation' => self::normalizeText($remittanceInformation),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
