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
        $accountIban = Iban::normalize($accountIban);
        $accountServicerReference = self::stableAccountServicerReference($entryReference);

        if (null !== $accountServicerReference) {
            return self::hash([
                'strategy' => 'account-servicer-reference',
                'accountIban' => $accountIban,
                'accountServicerReference' => $accountServicerReference,
            ]);
        }

        $counterpartyIban = self::nullableTrim($counterpartyIban);

        return self::hash([
            'strategy' => 'stable-fallback',
            'accountIban' => $accountIban,
            'amountCents' => $amountCents,
            'bookingDate' => $bookingDate->format('Y-m-d'),
            'counterpartyIban' => null === $counterpartyIban ? null : Iban::normalize($counterpartyIban),
            'remittanceInformation' => self::normalizeText($remittanceInformation),
        ]);
    }

    /**
     * @param array<string, int|string|null> $canonical
     *
     * @throws JsonException
     */
    private static function hash(array $canonical): string
    {
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function stableAccountServicerReference(?string $value): ?string
    {
        $value = self::nullableTrim($value);
        if (null === $value) {
            return null;
        }

        $normalized = strtoupper($value);
        if (in_array($normalized, ['NOTPROVIDED', 'NOT PROVIDED', 'NONREF', 'N/A', 'NA', 'NONE', 'UNKNOWN'], true)) {
            return null;
        }

        return $normalized;
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
