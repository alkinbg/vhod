<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\FeePolicy;
use App\Entity\FeePolicyUnitRule;
use App\Entity\Fund;
use App\Entity\Unit;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeePolicyUnitRuleTest extends TestCase
{
    public function testRuleKeepsExplicitOverrideAndEffectivePeriod(): void
    {
        $policy = $this->policy();
        $unit = new Unit('12');
        $rule = new FeePolicyUnitRule(
            $policy,
            $unit,
            new DateTimeImmutable('2026-09-01'),
            'Решение за временно намаление.',
            'ОС 01/2026, т. 6',
            '0.500',
            '1.250',
        );

        self::assertSame($policy, $rule->getPolicy());
        self::assertSame($unit, $rule->getUnit());
        self::assertSame('0.500', $rule->getQuantityOverride());
        self::assertSame('1.250', $rule->getMultiplier());
        self::assertSame('Решение за временно намаление.', $rule->getReason());
        self::assertSame('ОС 01/2026, т. 6', $rule->getDecisionReference());
        self::assertTrue($rule->isEffectiveFor(new DateTimeImmutable('2026-09-15')));

        $rule->endAt(new DateTimeImmutable('2026-11-30'));

        self::assertTrue($rule->isEffectiveFor(new DateTimeImmutable('2026-11-01')));
        self::assertFalse($rule->isEffectiveFor(new DateTimeImmutable('2026-12-01')));
    }

    public function testRuleRequiresReason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FeePolicyUnitRule(
            $this->policy(),
            new Unit('12'),
            new DateTimeImmutable('2026-09-01'),
            '   ',
        );
    }

    public function testRuleMustActuallyChangeCalculation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A fee rule must change quantity or multiplier.');

        new FeePolicyUnitRule(
            $this->policy(),
            new Unit('12'),
            new DateTimeImmutable('2026-09-01'),
            'Документирана корекция.',
        );
    }

    public function testRuleRejectsNonMonthBoundaryDates(): void
    {
        try {
            new FeePolicyUnitRule(
                $this->policy(),
                new Unit('12'),
                new DateTimeImmutable('2026-09-02'),
                'Корекция.',
                multiplier: '0.500',
            );
            self::fail('Expected invalid rule start date.');
        } catch (InvalidArgumentException) {
        }

        $rule = new FeePolicyUnitRule(
            $this->policy(),
            new Unit('12'),
            new DateTimeImmutable('2026-09-01'),
            'Корекция.',
            multiplier: '0.500',
        );

        $this->expectException(InvalidArgumentException::class);
        $rule->endAt(new DateTimeImmutable('2026-10-30'));
    }

    #[DataProvider('invalidMultipliers')]
    public function testRuleRejectsInvalidMultiplier(string $multiplier): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FeePolicyUnitRule(
            $this->policy(),
            new Unit('12'),
            new DateTimeImmutable('2026-09-01'),
            'Корекция.',
            multiplier: $multiplier,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMultipliers(): iterable
    {
        yield 'negative' => ['-0.001'];
        yield 'above configured ceiling' => ['5.001'];
        yield 'too many decimals' => ['1.0001'];
        yield 'not numeric' => ['abc'];
    }

    #[DataProvider('invalidQuantities')]
    public function testRuleRejectsInvalidQuantityOverride(string $quantity): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FeePolicyUnitRule(
            $this->policy(),
            new Unit('12'),
            new DateTimeImmutable('2026-09-01'),
            'Корекция.',
            quantityOverride: $quantity,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidQuantities(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1.000'];
        yield 'too many decimals' => ['1.0001'];
        yield 'not numeric' => ['abc'];
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
