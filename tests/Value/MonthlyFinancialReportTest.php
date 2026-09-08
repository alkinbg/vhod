<?php

declare(strict_types=1);

namespace App\Tests\Value;

use App\Value\FinancialReportLine;
use App\Value\MonthlyFinancialReport;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MonthlyFinancialReportTest extends TestCase
{
    public function testReportNormalizesMonthToSofiaAndCalculatesSignedTotals(): void
    {
        $report = new MonthlyFinancialReport(
            new DateTimeImmutable('2026-09-18 15:00:00 UTC'),
            [
                new FinancialReportLine('resident_management', 'Вноски за управление и поддръжка', 10000),
                new FinancialReportLine('other_income', 'Други приходи', -500),
            ],
            [
                new FinancialReportLine('common_electricity', 'Електроенергия за общите части', 4280),
                new FinancialReportLine('bank_fees', 'Банкови такси', -30),
            ],
        );

        self::assertSame('2026-09-01 00:00:00', $report->getMonth()->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Sofia', $report->getMonth()->getTimezone()->getName());
        self::assertSame(9500, $report->getTotalIncomeCents());
        self::assertSame(4250, $report->getTotalExpenseCents());
        self::assertSame(5250, $report->getNetCents());
        self::assertCount(2, $report->getIncomeLines());
        self::assertCount(2, $report->getExpenseLines());
    }
}
