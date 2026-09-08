<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Expense;
use App\Entity\ExpenseReversal;
use App\Entity\Fund;
use App\Enum\ExpenseCategory;
use App\Enum\FundType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExpenseReversalTest extends TestCase
{
    public function testReversalKeepsExactCompensatingSnapshotInUtc(): void
    {
        $expense = $this->expense();
        $reversal = ExpenseReversal::record(
            $expense,
            4280,
            '  Дублиран разход.  ',
            new DateTimeImmutable('2026-09-06 10:30:00', new DateTimeZone('Europe/Sofia')),
        );

        self::assertSame($expense, $reversal->getExpense());
        self::assertSame(4280, $reversal->getAmountCents());
        self::assertSame('Дублиран разход.', $reversal->getReason());
        self::assertSame('2026-09-06 07:30:00', $reversal->getReversedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $reversal->getReversedAt()->getTimezone()->getName());
    }

    public function testReversalRequiresExactOriginalAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reversal amount must exactly match the original expense.');

        ExpenseReversal::record(
            $this->expense(),
            4200,
            'Корекция.',
            new DateTimeImmutable('2026-09-06 07:30:00 UTC'),
        );
    }

    public function testReversalRequiresReason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExpenseReversal::record(
            $this->expense(),
            4280,
            '   ',
            new DateTimeImmutable('2026-09-06 07:30:00 UTC'),
        );
    }

    public function testReversalCannotPredateExpensePosting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExpenseReversal::record(
            $this->expense(),
            4280,
            'Корекция.',
            new DateTimeImmutable('2026-09-05 08:59:59 UTC'),
        );
    }

    private function expense(): Expense
    {
        return Expense::post(
            new Fund('operating', 'Управление', FundType::OPERATING),
            ExpenseCategory::COMMON_ELECTRICITY,
            4280,
            new DateTimeImmutable('2026-09-05 08:00:00 UTC'),
            new DateTimeImmutable('2026-09-05 09:00:00 UTC'),
            'Електроенергия общи части',
        );
    }
}
