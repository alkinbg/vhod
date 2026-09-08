<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\FeePolicy;
use App\Entity\Fund;
use App\Enum\FeeCategory;
use App\Enum\FeeDistribution;
use App\Enum\FundType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FeePolicyTest extends TestCase
{
    public function testOperatingPolicyKeepsVersionedMonthlyRule(): void
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);
        $policy = FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            850,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 4',
        );

        self::assertSame('maintenance', $policy->getCode());
        self::assertSame('Месечна поддръжка', $policy->getName());
        self::assertSame($fund, $policy->getFund());
        self::assertSame(FeeCategory::MANAGEMENT_MAINTENANCE, $policy->getCategory());
        self::assertSame(FeeDistribution::PER_UNIT, $policy->getDistribution());
        self::assertSame(850, $policy->getMonthlyAmountCents());
        self::assertSame('2026-09-01', $policy->getEffectiveFrom()->format('Y-m-d'));
        self::assertSame('ОС 01/2026, т. 4', $policy->getDecisionReference());
        self::assertTrue($policy->isEffectiveFor(new DateTimeImmutable('2026-09-01')));
        self::assertTrue($policy->isEffectiveFor(new DateTimeImmutable('2026-09-30')));

        $policy->endAt(new DateTimeImmutable('2026-12-31'));

        self::assertTrue($policy->isEffectiveFor(new DateTimeImmutable('2026-12-01')));
        self::assertFalse($policy->isEffectiveFor(new DateTimeImmutable('2027-01-01')));
    }

    public function testPolicyRejectsNonPositiveAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            new Fund('operating', 'Текуща поддръжка', FundType::OPERATING),
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            0,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 4',
        );
    }

    public function testPolicyRequiresMonthBoundaryStartAndEnd(): void
    {
        $fund = new Fund('operating', 'Текуща поддръжка', FundType::OPERATING);

        try {
            FeePolicy::create(
                'maintenance',
                'Месечна поддръжка',
                $fund,
                FeeCategory::MANAGEMENT_MAINTENANCE,
                FeeDistribution::PER_UNIT,
                850,
                new DateTimeImmutable('2026-09-02'),
                'ОС 01/2026, т. 4',
            );
            self::fail('Expected invalid effectiveFrom date.');
        } catch (InvalidArgumentException) {
        }

        $policy = FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            $fund,
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            850,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 4',
        );

        $this->expectException(InvalidArgumentException::class);
        $policy->endAt(new DateTimeImmutable('2026-12-30'));
    }

    public function testAnimalEquivalentsAreOnlyAllowedForPerPersonPolicies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            new Fund('operating', 'Текуща поддръжка', FundType::OPERATING),
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            850,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 4',
            includeAnimalEquivalents: true,
        );
    }

    public function testRepairPolicyRequiresRepairFundIdealPartsAndConfirmedMinimum(): void
    {
        $repairFund = new Fund('repair', 'Ремонт и обновяване', FundType::REPAIR_RENOVATION);

        $policy = FeePolicy::create(
            'repair_monthly',
            'Фонд Ремонт и обновяване',
            $repairFund,
            FeeCategory::REPAIR_RENOVATION,
            FeeDistribution::IDEAL_PARTS,
            10000,
            new DateTimeImmutable('2026-09-01'),
            'ОС 01/2026, т. 5',
            statutoryMinimumConfirmed: true,
        );

        self::assertTrue($policy->isStatutoryMinimumConfirmed());

        foreach ([
            [new Fund('operating', 'Текуща поддръжка', FundType::OPERATING), FeeDistribution::IDEAL_PARTS, true],
            [$repairFund, FeeDistribution::PER_UNIT, true],
            [$repairFund, FeeDistribution::IDEAL_PARTS, false],
        ] as [$fund, $distribution, $confirmed]) {
            try {
                FeePolicy::create(
                    'repair_invalid',
                    'Невалидна ремонтна политика',
                    $fund,
                    FeeCategory::REPAIR_RENOVATION,
                    $distribution,
                    10000,
                    new DateTimeImmutable('2026-09-01'),
                    'ОС 01/2026, т. 5',
                    statutoryMinimumConfirmed: $confirmed,
                );
                self::fail('Expected repair policy validation to fail.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testPolicyRequiresDecisionReference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FeePolicy::create(
            'maintenance',
            'Месечна поддръжка',
            new Fund('operating', 'Текуща поддръжка', FundType::OPERATING),
            FeeCategory::MANAGEMENT_MAINTENANCE,
            FeeDistribution::PER_UNIT,
            850,
            new DateTimeImmutable('2026-09-01'),
            '   ',
        );
    }
}
