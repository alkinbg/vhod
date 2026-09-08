<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExternalIncome;
use App\Entity\ExternalIncomeReversal;
use App\Entity\Fund;
use App\Enum\ExternalIncomeCategory;
use App\Enum\FundType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExternalIncomeReversalTest extends TestCase
{
    public function testReversalKeepsExactCompensatingSnapshot(): void
    {
        $income = $this->income();
        $reversal = ExternalIncomeReversal::record(
            $income,
            12500,
            '  Дублиран приход.  ',
            new DateTimeImmutable('2026-09-03 10:00:00 Europe/Sofia'),
        );

        self::assertSame($income, $reversal->getIncome());
        self::assertSame(12500, $reversal->getAmountCents());
        self::assertSame('Дублиран приход.', $reversal->getReason());
        self::assertSame('2026-09-03 07:00:00', $reversal->getReversedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $reversal->getReversedAt()->getTimezone()->getName());
    }

    public function testReversalRequiresExactOriginalAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reversal amount must exactly match the original external income.');

        ExternalIncomeReversal::record(
            $this->income(),
            12000,
            'Корекция.',
            new DateTimeImmutable('2026-09-03 07:00:00 UTC'),
        );
    }

    public function testReversalRequiresReason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExternalIncomeReversal::record(
            $this->income(),
            12500,
            '   ',
            new DateTimeImmutable('2026-09-03 07:00:00 UTC'),
        );
    }

    public function testReversalCannotPredateIncomePosting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExternalIncomeReversal::record(
            $this->income(),
            12500,
            'Корекция.',
            new DateTimeImmutable('2026-09-02 05:59:59 UTC'),
        );
    }

    private function income(): ExternalIncome
    {
        return ExternalIncome::record(
            new Fund('operating', 'Управление', FundType::OPERATING),
            ExternalIncomeCategory::COMMON_PART_RENT,
            12500,
            new DateTimeImmutable('2026-09-02 05:00:00 UTC'),
            new DateTimeImmutable('2026-09-02 06:00:00 UTC'),
            'Наем обща част',
        );
    }
}
