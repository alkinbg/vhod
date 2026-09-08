<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BudgetLine;
use App\Entity\Fund;
use App\Enum\ExpenseCategory;
use App\Enum\FundType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BudgetLineTest extends TestCase
{
    public function testPlanKeepsNormalizedSnapshot(): void
    {
        $fund = new Fund('operating', 'Управление', FundType::OPERATING);
        $line = BudgetLine::plan(
            2026,
            $fund,
            ExpenseCategory::COMMON_ELECTRICITY,
            120000,
            '  ОС 02/2026, т. 5  ',
            new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('Europe/Sofia')),
        );

        self::assertSame(2026, $line->getYear());
        self::assertSame($fund, $line->getFund());
        self::assertSame(ExpenseCategory::COMMON_ELECTRICITY, $line->getCategory());
        self::assertSame(120000, $line->getAmountCents());
        self::assertSame('ОС 02/2026, т. 5', $line->getDecisionReference());
        self::assertSame('2026-09-08 07:00:00', $line->getPostedAt()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $line->getPostedAt()->getTimezone()->getName());
    }

    public function testYearMustBeSupported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BudgetLine::plan(2019, new Fund('operating', 'Управление', FundType::OPERATING), ExpenseCategory::OTHER, 100, 'ОС', new DateTimeImmutable());
    }

    public function testAmountMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BudgetLine::plan(2026, new Fund('operating', 'Управление', FundType::OPERATING), ExpenseCategory::OTHER, 0, 'ОС', new DateTimeImmutable());
    }

    public function testDecisionReferenceIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BudgetLine::plan(2026, new Fund('operating', 'Управление', FundType::OPERATING), ExpenseCategory::OTHER, 100, '   ', new DateTimeImmutable());
    }
}
