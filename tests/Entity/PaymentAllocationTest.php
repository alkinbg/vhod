<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\Unit;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use App\Enum\PaymentSource;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentAllocationTest extends TestCase
{
    public function testAllocationKeepsImmutableSettlementSnapshot(): void
    {
        $unit = new Unit('12');
        $payment = $this->payment($unit);
        $charge = $this->charge($unit);

        $allocation = PaymentAllocation::allocate(
            $payment,
            $charge,
            850,
            0,
            new DateTimeImmutable('2026-09-08 05:30:00 UTC'),
        );

        self::assertSame($payment, $allocation->getPayment());
        self::assertSame($charge, $allocation->getCharge());
        self::assertSame(850, $allocation->getAmountCents());
        self::assertSame(0, $allocation->getPosition());
        self::assertSame('UTC', $allocation->getCreatedAt()->getTimezone()->getName());
    }

    public function testAllocationRejectsCrossUnitSettlement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Payment and charge must belong to the same unit.');

        PaymentAllocation::allocate(
            $this->payment(new Unit('12')),
            $this->charge(new Unit('13')),
            850,
            0,
            new DateTimeImmutable('2026-09-08 05:30:00 UTC'),
        );
    }

    #[DataProvider('invalidAllocationValues')]
    public function testAllocationRejectsInvalidAmountOrPosition(int $amountCents, int $position): void
    {
        $unit = new Unit('12');
        $this->expectException(InvalidArgumentException::class);

        PaymentAllocation::allocate(
            $this->payment($unit),
            $this->charge($unit),
            $amountCents,
            $position,
            new DateTimeImmutable('2026-09-08 05:30:00 UTC'),
        );
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidAllocationValues(): iterable
    {
        yield 'zero amount' => [0, 0];
        yield 'negative amount' => [-1, 0];
        yield 'negative position' => [100, -1];
    }

    private function payment(Unit $unit): Payment
    {
        return Payment::post(
            $unit,
            5000,
            PaymentSource::CASH,
            new DateTimeImmutable('2026-09-08 05:15:00 UTC'),
            new DateTimeImmutable('2026-09-08 05:20:00 UTC'),
        );
    }

    private function charge(Unit $unit): Charge
    {
        return Charge::post(
            $this->policy(),
            $unit,
            new DateTimeImmutable('2026-09-01'),
            '1.0000',
            850,
            850,
            ['distribution' => 'per_unit'],
            new DateTimeImmutable('2026-09-08 03:30:00 UTC'),
        );
    }

    private function policy(): FeePolicy
    {
        return FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            new Fund('operating', 'Текуща поддръжка', FundType::OPERATING),
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            850,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 4',
        );
    }
}
