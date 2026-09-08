<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BudgetLine;
use App\Entity\Expense;
use App\Entity\ExpenseReversal;
use App\Entity\Fund;
use App\Enum\ExpenseCategory;
use App\Value\BudgetActualLine;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class BudgetActualService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<BudgetActualLine> */
    public function build(int $year, DateTimeImmutable $through): array
    {
        if ($year < 2020 || $year > 2100) {
            throw new InvalidArgumentException('Budget year is outside the supported range.');
        }

        [$startUtc, $endUtc] = self::period($year, $through);

        /** @var array<string, array{fund: Fund, category: ExpenseCategory, budget: int, actual: int}> $values */
        $values = [];

        /** @var list<BudgetLine> $budgetLines */
        $budgetLines = $this->entityManager->getRepository(BudgetLine::class)->findBy(['year' => $year]);
        foreach ($budgetLines as $budgetLine) {
            $key = self::key($budgetLine->getFund(), $budgetLine->getCategory());
            $values[$key] = [
                'fund' => $budgetLine->getFund(),
                'category' => $budgetLine->getCategory(),
                'budget' => $budgetLine->getAmountCents(),
                'actual' => $values[$key]['actual'] ?? 0,
            ];
        }

        foreach ($this->expensesBetween($startUtc, $endUtc) as $expense) {
            $this->applyActual($values, $expense->getFund(), $expense->getCategory(), $expense->getAmountCents());
        }
        foreach ($this->reversalsBetween($startUtc, $endUtc) as $reversal) {
            $expense = $reversal->getExpense();
            $this->applyActual($values, $expense->getFund(), $expense->getCategory(), -$reversal->getAmountCents());
        }

        ksort($values);
        $result = [];
        foreach ($values as $value) {
            $result[] = new BudgetActualLine(
                $value['fund'],
                $value['category'],
                $value['budget'],
                $value['actual'],
            );
        }

        return $result;
    }

    /**
     * @param array<string, array{fund: Fund, category: ExpenseCategory, budget: int, actual: int}> $values
     */
    private function applyActual(array &$values, Fund $fund, ExpenseCategory $category, int $amountCents): void
    {
        $key = self::key($fund, $category);
        if (!isset($values[$key])) {
            $values[$key] = [
                'fund' => $fund,
                'category' => $category,
                'budget' => 0,
                'actual' => 0,
            ];
        }

        $values[$key]['actual'] += $amountCents;
    }

    /** @return list<Expense> */
    private function expensesBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        /** @var list<Expense> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Expense::class, 'e')
            ->where('e.paidAt >= :start')
            ->andWhere('e.paidAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** @return list<ExpenseReversal> */
    private function reversalsBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        /** @var list<ExpenseReversal> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ExpenseReversal::class, 'r')
            ->where('r.reversedAt >= :start')
            ->andWhere('r.reversedAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    private static function period(int $year, DateTimeImmutable $through): array
    {
        $sofia = new DateTimeZone('Europe/Sofia');
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $sofia);
        $nextYear = $start->modify('+1 year');
        $localThrough = $through->setTimezone($sofia);

        if ($localThrough < $start) {
            $end = $start;
        } elseif ($localThrough >= $nextYear) {
            $end = $nextYear;
        } else {
            $end = $localThrough->setTime(0, 0)->modify('+1 day');
        }

        return [$start->setTimezone($utc), $end->setTimezone($utc)];
    }

    private static function key(Fund $fund, ExpenseCategory $category): string
    {
        return $fund->getCode()."\0".$category->value;
    }
}
