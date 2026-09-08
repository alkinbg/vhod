<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Expense;
use App\Entity\ExpenseReversal;
use App\Entity\ExternalIncome;
use App\Entity\ExternalIncomeReversal;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\PaymentReversal;
use App\Enum\ExpenseCategory;
use App\Enum\ExternalIncomeCategory;
use App\Enum\FeeCategory;
use App\Value\FinancialReportLine;
use App\Value\MonthlyFinancialReport;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class MonthlyFinancialReportService
{
    private const RESIDENT_MANAGEMENT = 'resident_management_maintenance';
    private const REPAIR_FUND = 'repair_renovation_fund';
    private const OTHER_INCOME = 'other';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function build(DateTimeImmutable $month): MonthlyFinancialReport
    {
        [$localMonth, $startUtc, $endUtc] = self::period($month);
        $incomeLabels = self::incomeLabels();
        $income = array_fill_keys(array_keys($incomeLabels), 0);
        $expenses = [];
        foreach (ExpenseCategory::cases() as $category) {
            $expenses[$category->value] = 0;
        }

        foreach ($this->paymentsBetween($startUtc, $endUtc) as $payment) {
            $this->applyPayment($income, $payment, 1);
        }
        foreach ($this->paymentReversalsBetween($startUtc, $endUtc) as $reversal) {
            $this->applyPayment($income, $reversal->getPayment(), -1);
        }

        foreach ($this->externalIncomeBetween($startUtc, $endUtc) as $entry) {
            $income[$entry->getCategory()->value] += $entry->getAmountCents();
        }
        foreach ($this->externalIncomeReversalsBetween($startUtc, $endUtc) as $reversal) {
            $entry = $reversal->getIncome();
            $income[$entry->getCategory()->value] -= $reversal->getAmountCents();
        }

        foreach ($this->expensesBetween($startUtc, $endUtc) as $expense) {
            $expenses[$expense->getCategory()->value] += $expense->getAmountCents();
        }
        foreach ($this->expenseReversalsBetween($startUtc, $endUtc) as $reversal) {
            $expense = $reversal->getExpense();
            $expenses[$expense->getCategory()->value] -= $reversal->getAmountCents();
        }

        $incomeLines = [];
        foreach ($incomeLabels as $code => $label) {
            if (0 === $income[$code]) {
                continue;
            }
            $incomeLines[] = new FinancialReportLine($code, $label, $income[$code]);
        }

        $expenseLines = [];
        foreach (ExpenseCategory::cases() as $category) {
            $amount = $expenses[$category->value];
            if (0 === $amount) {
                continue;
            }
            $expenseLines[] = new FinancialReportLine($category->value, $category->labelBg(), $amount);
        }

        return new MonthlyFinancialReport($localMonth, $incomeLines, $expenseLines);
    }

    /** @param array<string, int> $income */
    private function applyPayment(array &$income, Payment $payment, int $sign): void
    {
        $allocated = 0;
        $allocations = $this->entityManager->getRepository(PaymentAllocation::class)->findBy([
            'payment' => $payment,
        ]);

        foreach ($allocations as $allocation) {
            $amount = $allocation->getAmountCents();
            $allocated += $amount;
            $code = match ($allocation->getCharge()->getPolicy()->getCategory()) {
                FeeCategory::MANAGEMENT_MAINTENANCE => self::RESIDENT_MANAGEMENT,
                FeeCategory::REPAIR_RENOVATION => self::REPAIR_FUND,
                FeeCategory::OTHER => self::OTHER_INCOME,
            };
            $income[$code] += $sign * $amount;
        }

        $unallocated = $payment->getAmountCents() - $allocated;
        if ($unallocated < 0) {
            throw new DomainException('Payment allocations exceed the payment amount.');
        }
        if ($unallocated > 0) {
            $income[self::OTHER_INCOME] += $sign * $unallocated;
        }
    }

    /** @return list<Payment> */
    private function paymentsBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        /** @var list<Payment> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->where('p.receivedAt >= :start')
            ->andWhere('p.receivedAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** @return list<PaymentReversal> */
    private function paymentReversalsBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        /** @var list<PaymentReversal> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PaymentReversal::class, 'r')
            ->where('r.reversedAt >= :start')
            ->andWhere('r.reversedAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** @return list<ExternalIncome> */
    private function externalIncomeBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        /** @var list<ExternalIncome> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(ExternalIncome::class, 'i')
            ->where('i.receivedAt >= :start')
            ->andWhere('i.receivedAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** @return list<ExternalIncomeReversal> */
    private function externalIncomeReversalsBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        /** @var list<ExternalIncomeReversal> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ExternalIncomeReversal::class, 'r')
            ->where('r.reversedAt >= :start')
            ->andWhere('r.reversedAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        return $result;
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
    private function expenseReversalsBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
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

    /** @return array{DateTimeImmutable, DateTimeImmutable, DateTimeImmutable} */
    private static function period(DateTimeImmutable $month): array
    {
        $sofia = new DateTimeZone('Europe/Sofia');
        $utc = new DateTimeZone('UTC');
        $localMonth = $month->setTimezone($sofia)->modify('first day of this month')->setTime(0, 0);
        $nextLocalMonth = $localMonth->modify('first day of next month');

        return [
            $localMonth,
            $localMonth->setTimezone($utc),
            $nextLocalMonth->setTimezone($utc),
        ];
    }

    /** @return array<string, string> */
    private static function incomeLabels(): array
    {
        return [
            self::RESIDENT_MANAGEMENT => 'Вноски за управление и поддръжка',
            self::REPAIR_FUND => 'Вноски във фонд „Ремонт и обновяване“',
            ExternalIncomeCategory::COMMON_PART_RENT->value => ExternalIncomeCategory::COMMON_PART_RENT->labelBg(),
            ExternalIncomeCategory::ADVERTISING_TECHNICAL_INSTALLATIONS->value => ExternalIncomeCategory::ADVERTISING_TECHNICAL_INSTALLATIONS->labelBg(),
            ExternalIncomeCategory::PUBLIC_FUNDING_SUBSIDY->value => ExternalIncomeCategory::PUBLIC_FUNDING_SUBSIDY->labelBg(),
            ExternalIncomeCategory::LOAN_PROCEEDS->value => ExternalIncomeCategory::LOAN_PROCEEDS->labelBg(),
            ExternalIncomeCategory::RENEWABLE_ENERGY->value => ExternalIncomeCategory::RENEWABLE_ENERGY->labelBg(),
            ExternalIncomeCategory::DONATION->value => ExternalIncomeCategory::DONATION->labelBg(),
            self::OTHER_INCOME => ExternalIncomeCategory::OTHER->labelBg(),
        ];
    }
}
