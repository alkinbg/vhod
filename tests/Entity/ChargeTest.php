<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Charge;
use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Entity\Unit;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChargeTest extends TestCase
{
    public function testPostedChargeKeepsImmutableCalculationSnapshot(): void
    {
        $policy = $this->policy();
        $unit = new Unit('12');
        $charge = Charge::post(
            $policy,
            $unit,
            new DateTimeImmutable('2026-09-18'),
            '1.500',
            850,
            1275,
            [
                'distribution' => 'per_unit',
                'base_quantity' => '1.000',
                'quantity_override' => '1.500',
                'multiplier' => '1.000',
                'decision_reference' => 'ОС 01/2026, т. 4',
            ],
            new DateTimeImmutable('2026-09-08 06:30:00', new DateTimeZone('Europe/Sofia')),
        );

        self::assertSame($policy, $charge->getPolicy());
        self::assertSame($unit, $charge->getUnit());
        self::assertSame('2026-09-01', $charge->getBillingMonth()->format('Y-m-d'));
        self::assertSame('1.5000', $charge->getQuantity());
        self::assertSame(850, $charge->getPolicyAmountCents());
        self::assertSame(1275, $charge->getAmountCents());
        self::assertSame('per_unit', $charge->getCalculationDetails()['distribution']);
        self::assertSame('UTC', $charge->getPostedAt()->getTimezone()->getName());
        self::assertSame('2026-09-08 03:30:00', $charge->getPostedAt()->format('Y-m-d H:i:s'));
    }

    public function testChargePreservesFourDecimalQuantity(): void
    {
        $charge = Charge::post(
            $this->policy(),
            new Unit('12'),
            new DateTimeImmutable('2026-09-01'),
            '33.3333',
            1001,
            334,
            ['distribution' => 'ideal_parts'],
            new DateTimeImmutable('2026-09-08 03:30:00 UTC'),
        );

        self::assertSame('33.3333', $charge->getQuantity());
    }

    #[DataProvider('invalidAmounts')]
    public function testChargeRejectsNonPositiveMoney(int $policyAmountCents, int $amountCents): void
    {
        $this->expectException(InvalidArgumentException::class);

        Charge::post(
            $this->policy(),
            new Unit('12'),
            new DateTimeImmutable('2026-09-01'),
            '1.000',
            $policyAmountCents,
            $amountCents,
            ['distribution' => 'per_unit'],
            new DateTimeImmutable('2026-09-08 03:30:00 UTC'),
        );
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidAmounts(): iterable
    {
        yield 'zero policy amount' => [0, 850];
        yield 'negative policy amount' => [-1, 850];
        yield 'zero charge amount' => [850, 0];
        yield 'negative charge amount' => [850, -1];
    }

    #[DataProvider('invalidQuantities')]
    public function testChargeRejectsInvalidQuantity(string $quantity): void
    {
        $this->expectException(InvalidArgumentException::class);

        Charge::post(
            $this->policy(),
            new Unit('12'),
            new DateTimeImmutable('2026-09-01'),
            $quantity,
            850,
            850,
            ['distribution' => 'per_unit'],
            new DateTimeImmutable('2026-09-08 03:30:00 UTC'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidQuantities(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1.0000'];
        yield 'too many decimals' => ['1.00001'];
        yield 'not numeric' => ['abc'];
    }

    public function testChargeRequiresCalculationDetails(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Charge::post(
            $this->policy(),
            new Unit('12'),
            new DateTimeImmutable('2026-09-01'),
            '1.000',
            850,
            850,
            [],
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
