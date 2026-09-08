<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Payment;
use App\Entity\PaymentReversal;
use App\Entity\Unit;
use App\Enum\PaymentSource;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentReversalTest extends TestCase
{
    public function testReversalKeepsExactCompensatingSnapshotInUtc(): void
    {
        $payment = $this->payment();
        $reversal = PaymentReversal::record(
            $payment,
            850,
            '  Грешно осчетоводено плащане.  ',
            new DateTimeImmutable('2026-09-08 10:30:00', new DateTimeZone('Europe/Sofia')),
        );

        self::assertSame($payment, $reversal->getPayment());
        self::assertSame(850, $reversal->getAmountCents());
        self::assertSame('Грешно осчетоводено плащане.', $reversal->getReason());
        self::assertSame('2026-09-08 07:30:00', $reversal->getReversedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $reversal->getReversedAt()->getTimezone()->getName());
    }

    public function testReversalRequiresExactOriginalAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reversal amount must exactly match the original payment.');

        PaymentReversal::record(
            $this->payment(),
            800,
            'Корекция.',
            new DateTimeImmutable('2026-09-08 07:30:00 UTC'),
        );
    }

    public function testReversalRequiresReason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentReversal::record(
            $this->payment(),
            850,
            '   ',
            new DateTimeImmutable('2026-09-08 07:30:00 UTC'),
        );
    }

    public function testReversalCannotPredatePaymentPosting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaymentReversal::record(
            $this->payment(),
            850,
            'Корекция.',
            new DateTimeImmutable('2026-09-08 06:59:59 UTC'),
        );
    }

    private function payment(): Payment
    {
        return Payment::post(
            new Unit('12'),
            850,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-09-08 06:55:00 UTC'),
            new DateTimeImmutable('2026-09-08 07:00:00 UTC'),
        );
    }
}
