<?php

declare(strict_types=1);

namespace App\Value;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class NormalizedBankStatement
{
    public string $accountIban;
    public ?string $statementReference;
    public ?DateTimeImmutable $periodFrom;
    public ?DateTimeImmutable $periodTo;

    /** @var list<NormalizedBankTransaction> */
    public array $transactions;

    /** @param list<NormalizedBankTransaction> $transactions */
    public function __construct(
        string $accountIban,
        array $transactions,
        ?string $statementReference = null,
        ?DateTimeImmutable $periodFrom = null,
        ?DateTimeImmutable $periodTo = null,
    ) {
        $periodFrom = self::normalizeDate($periodFrom);
        $periodTo = self::normalizeDate($periodTo);
        if (null !== $periodFrom && null !== $periodTo && $periodFrom > $periodTo) {
            throw new InvalidArgumentException('Statement period start cannot be after its end.');
        }

        $this->accountIban = Iban::normalize($accountIban);
        $this->transactions = $transactions;
        $this->statementReference = self::nullableTrim($statementReference);
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
    }

    private static function normalizeDate(?DateTimeImmutable $date): ?DateTimeImmutable
    {
        if (null === $date) {
            return null;
        }

        return new DateTimeImmutable($date->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC'));
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
