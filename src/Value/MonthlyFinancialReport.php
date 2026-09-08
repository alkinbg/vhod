<?php

declare(strict_types=1);

namespace App\Value;

use DateTimeImmutable;
use DateTimeZone;

final readonly class MonthlyFinancialReport
{
    private DateTimeImmutable $month;

    /** @var list<FinancialReportLine> */
    private array $incomeLines;

    /** @var list<FinancialReportLine> */
    private array $expenseLines;

    /**
     * @param list<FinancialReportLine> $incomeLines
     * @param list<FinancialReportLine> $expenseLines
     */
    public function __construct(DateTimeImmutable $month, array $incomeLines, array $expenseLines)
    {
        $sofia = new DateTimeZone('Europe/Sofia');
        $this->month = $month->setTimezone($sofia)->modify('first day of this month')->setTime(0, 0);
        $this->incomeLines = array_values($incomeLines);
        $this->expenseLines = array_values($expenseLines);
    }

    public function getMonth(): DateTimeImmutable { return $this->month; }

    /** @return list<FinancialReportLine> */
    public function getIncomeLines(): array { return $this->incomeLines; }

    /** @return list<FinancialReportLine> */
    public function getExpenseLines(): array { return $this->expenseLines; }

    public function getTotalIncomeCents(): int
    {
        return array_sum(array_map(
            static fn (FinancialReportLine $line): int => $line->getAmountCents(),
            $this->incomeLines,
        ));
    }

    public function getTotalExpenseCents(): int
    {
        return array_sum(array_map(
            static fn (FinancialReportLine $line): int => $line->getAmountCents(),
            $this->expenseLines,
        ));
    }

    public function getNetCents(): int
    {
        return $this->getTotalIncomeCents() - $this->getTotalExpenseCents();
    }
}
