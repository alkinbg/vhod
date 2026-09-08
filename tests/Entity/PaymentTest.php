<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Payment;
use App\Entity\Unit;
use App\Enum\PaymentSource;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    public function testPostedPaymentKeepsImmutableReceiptSnapshot(): void
    {
        $unit = new Unit('12');
        $payment = Payment::post(
            $unit,
            5000,
            PaymentSource::BANK_TRANSFER,
            new DateTimeImmutable('2026-09-08 08:15:00', new DateTimeZone('Europe/Sofia')),
            new DateTimeImmutable('2026-09-08 08:20:00', new DateTimeZone('Europe/Sofia')),
            '  септември 2026  ',
            '  bank-000123  ',
            '  Платено по банков път.  ',
        );

        self::assertSame($unit, $payment->getUnit());
        self::assertSame(5000, $payment->getAmountCents());
        self::assertSame(PaymentSource::BANK_TRANSFER, $payment->getSource());
        self::assertSame('2026-09-08 05:15:00', $payment->getReceivedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-08 05:20:00', $payment->getPostedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $payment->getReceivedAt()->getTimezone()->getName());
        self::assertSame('UTC', $payment->getPostedAt()->getTimezone()->getName());
        self::assertSame('септември 2026', $payment->getReference());
        self::assertSame('bank-000123', $payment->getExternalReference());
        self::assertSame('Платено по банков път.', $payment->getNote());
    }

    #[DataProvider('invalidAmounts')]
    public function testPaymentRejectsNonPositiveAmounts(int $amountCents): void
    {
        $this->expectException(InvalidArgumentException::class);

        Payment::post(
            new Unit('12'),
            $amountCents,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-09-08 05:15:00 UTC'),
            new DateTimeImmutable('2026-09-08 05:20:00 UTC'),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidAmounts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testBlankOptionalTextNormalizesToNull(): void
    {
        $payment = Payment::post(
            new Unit('12'),
            100,
            PaymentSource::OTHER,
            new DateTimeImmutable('2026-09-08 05:15:00 UTC'),
            new DateTimeImmutable('2026-09-08 05:20:00 UTC'),
            ' ',
            ' ',
            ' ',
        );

        self::assertNull($payment->getReference());
        self::assertNull($payment->getExternalReference());
        self::assertNull($payment->getNote());
    }
}
