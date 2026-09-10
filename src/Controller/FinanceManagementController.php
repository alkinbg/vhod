<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BudgetLine;
use App\Entity\Expense;
use App\Entity\ExpenseReversal;
use App\Entity\ExternalIncome;
use App\Entity\ExternalIncomeReversal;
use App\Entity\Fund;
use App\Entity\User;
use App\Enum\ExpenseCategory;
use App\Enum\ExternalIncomeCategory;
use App\Service\BudgetActualService;
use App\Service\ExpenseReversalService;
use App\Service\ExternalIncomeReversalService;
use App\Service\MonthlyFinancialReportService;
use App\Value\EuroAmount;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class FinanceManagementController extends AbstractController
{
    private const SOFIA = 'Europe/Sofia';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MonthlyFinancialReportService $monthlyReportService,
        private readonly BudgetActualService $budgetActualService,
        private readonly ExpenseReversalService $expenseReversalService,
        private readonly ExternalIncomeReversalService $externalIncomeReversalService,
    ) {
    }

    #[Route('/management/finance', name: 'app_management_finance', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireFinanceReader();
        $month = $this->parseMonth($request->query->getString('month'));
        $report = $this->monthlyReportService->build($month);
        $through = $month->modify('last day of this month');

        return $this->render('management/finance/index.html.twig', [
            'month' => $month,
            'report' => $report,
            'budget_actual' => $this->budgetActualService->build((int) $month->format('Y'), $through),
            'can_write' => $this->canWriteFinance(),
            'can_budget' => $this->canCreateBudget(),
            'recent_expenses' => $this->recentExpenses(),
            'recent_incomes' => $this->recentExternalIncomes(),
        ]);
    }

    #[Route('/management/finance/expense/new', name: 'app_management_finance_expense_new', methods: ['GET', 'POST'])]
    public function expenseNew(Request $request): Response
    {
        $this->requireFinanceWriter();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('finance_expense_new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            try {
                $fund = $this->requireFund($request->request->getInt('fund_id'));
                $category = ExpenseCategory::tryFrom($request->request->getString('category'));
                if (!$category instanceof ExpenseCategory) {
                    throw new InvalidArgumentException('Invalid expense category.');
                }

                $now = new DateTimeImmutable('now', new DateTimeZone(self::SOFIA));
                $expense = Expense::post(
                    $fund,
                    $category,
                    EuroAmount::parse($request->request->getString('amount')),
                    $this->parseLocalDate($request->request->getString('paid_at')),
                    $now,
                    $request->request->getString('description'),
                    $request->request->getString('payee'),
                    $request->request->getString('document_reference'),
                    $request->request->getString('note'),
                    $request->request->getString('decision_reference'),
                );
                $this->entityManager->persist($expense);
                $this->entityManager->flush();

                $this->addFlash('success', 'Разходът е записан.');

                return $this->redirectToRoute('app_management_finance');
            } catch (InvalidArgumentException $exception) {
                return $this->renderExpenseForm($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return $this->renderExpenseForm();
    }

    #[Route('/management/finance/income/new', name: 'app_management_finance_income_new', methods: ['GET', 'POST'])]
    public function incomeNew(Request $request): Response
    {
        $this->requireFinanceWriter();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('finance_income_new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            try {
                $fund = $this->requireFund($request->request->getInt('fund_id'));
                $category = ExternalIncomeCategory::tryFrom($request->request->getString('category'));
                if (!$category instanceof ExternalIncomeCategory) {
                    throw new InvalidArgumentException('Invalid income category.');
                }

                $now = new DateTimeImmutable('now', new DateTimeZone(self::SOFIA));
                $income = ExternalIncome::record(
                    $fund,
                    $category,
                    EuroAmount::parse($request->request->getString('amount')),
                    $this->parseLocalDate($request->request->getString('received_at')),
                    $now,
                    $request->request->getString('description'),
                    $request->request->getString('document_reference'),
                    $request->request->getString('note'),
                );
                $this->entityManager->persist($income);
                $this->entityManager->flush();

                $this->addFlash('success', 'Приходът е записан.');

                return $this->redirectToRoute('app_management_finance');
            } catch (InvalidArgumentException $exception) {
                return $this->renderIncomeForm($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return $this->renderIncomeForm();
    }

    #[Route('/management/finance/expense/{id}/reverse', name: 'app_management_finance_expense_reverse', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function expenseReverse(int $id, Request $request): Response
    {
        $actor = $this->requireFinanceWriter();
        $expense = $this->entityManager->find(Expense::class, $id);
        if (!$expense instanceof Expense) {
            throw $this->createNotFoundException('Expense not found.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('finance_expense_reverse_'.$id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            try {
                $this->expenseReversalService->reverse(
                    $expense,
                    $request->request->getString('reason'),
                    new DateTimeImmutable('now', new DateTimeZone(self::SOFIA)),
                    $actor,
                );
                $this->addFlash('success', 'Разходът е сторниран с отделен коригиращ запис.');

                return $this->redirectToRoute('app_management_finance');
            } catch (DomainException|InvalidArgumentException $exception) {
                return $this->renderReversalForm('Разход', $expense->getDescription(), 'finance_expense_reverse_'.$id, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return $this->renderReversalForm('Разход', $expense->getDescription(), 'finance_expense_reverse_'.$id);
    }

    #[Route('/management/finance/income/{id}/reverse', name: 'app_management_finance_income_reverse', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function incomeReverse(int $id, Request $request): Response
    {
        $actor = $this->requireFinanceWriter();
        $income = $this->entityManager->find(ExternalIncome::class, $id);
        if (!$income instanceof ExternalIncome) {
            throw $this->createNotFoundException('External income not found.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('finance_income_reverse_'.$id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            try {
                $this->externalIncomeReversalService->reverse(
                    $income,
                    $request->request->getString('reason'),
                    new DateTimeImmutable('now', new DateTimeZone(self::SOFIA)),
                    $actor,
                );
                $this->addFlash('success', 'Приходът е сторниран с отделен коригиращ запис.');

                return $this->redirectToRoute('app_management_finance');
            } catch (DomainException|InvalidArgumentException $exception) {
                return $this->renderReversalForm('Приход', $income->getDescription(), 'finance_income_reverse_'.$id, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return $this->renderReversalForm('Приход', $income->getDescription(), 'finance_income_reverse_'.$id);
    }

    #[Route('/management/finance/budget/new', name: 'app_management_finance_budget_new', methods: ['GET', 'POST'])]
    public function budgetNew(Request $request): Response
    {
        $this->requireBudgetWriter();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('finance_budget_new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            try {
                $fund = $this->requireFund($request->request->getInt('fund_id'));
                $category = ExpenseCategory::tryFrom($request->request->getString('category'));
                if (!$category instanceof ExpenseCategory) {
                    throw new InvalidArgumentException('Invalid expense category.');
                }

                $budget = BudgetLine::plan(
                    $request->request->getInt('year'),
                    $fund,
                    $category,
                    EuroAmount::parse($request->request->getString('amount')),
                    $request->request->getString('decision_reference'),
                    new DateTimeImmutable('now', new DateTimeZone(self::SOFIA)),
                );
                $this->entityManager->persist($budget);
                $this->entityManager->flush();

                $this->addFlash('success', 'Бюджетният ред е записан.');

                return $this->redirectToRoute('app_management_finance');
            } catch (InvalidArgumentException $exception) {
                return $this->renderBudgetForm($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
            } catch (Throwable) {
                return $this->renderBudgetForm('За тази година, фонд и категория вече има бюджетен ред.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return $this->renderBudgetForm();
    }

    #[Route('/management/finance/report/{year}/{month}', name: 'app_management_finance_report', requirements: ['year' => '\\d{4}', 'month' => '\\d{1,2}'], methods: ['GET'])]
    public function report(int $year, int $month): Response
    {
        $this->requireFinanceReader();
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw $this->createNotFoundException('Invalid reporting period.');
        }

        $period = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), new DateTimeZone(self::SOFIA));

        return $this->render('management/finance/report.html.twig', [
            'report' => $this->monthlyReportService->build($period),
            'budget_actual' => $this->budgetActualService->build($year, $period->modify('last day of this month')),
        ]);
    }

    private function renderExpenseForm(?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('management/finance/expense_new.html.twig', [
            'funds' => $this->activeFunds(),
            'categories' => ExpenseCategory::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }

    private function renderIncomeForm(?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('management/finance/income_new.html.twig', [
            'funds' => $this->activeFunds(),
            'categories' => ExternalIncomeCategory::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }

    private function renderBudgetForm(?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('management/finance/budget_new.html.twig', [
            'funds' => $this->activeFunds(),
            'categories' => ExpenseCategory::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }

    private function renderReversalForm(string $kind, string $description, string $csrfId, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('management/finance/reversal.html.twig', [
            'kind' => $kind,
            'description' => $description,
            'csrf_id' => $csrfId,
            'error' => $error,
        ], new Response(status: $status));
    }

    /** @return list<array{expense: Expense, reversed: bool}> */
    private function recentExpenses(): array
    {
        $rows = [];
        foreach ($this->entityManager->getRepository(Expense::class)->findBy([], ['paidAt' => 'DESC', 'id' => 'DESC'], 20) as $expense) {
            $rows[] = [
                'expense' => $expense,
                'reversed' => null !== $this->entityManager->getRepository(ExpenseReversal::class)->findOneBy(['expense' => $expense]),
            ];
        }

        return $rows;
    }

    /** @return list<array{income: ExternalIncome, reversed: bool}> */
    private function recentExternalIncomes(): array
    {
        $rows = [];
        foreach ($this->entityManager->getRepository(ExternalIncome::class)->findBy([], ['receivedAt' => 'DESC', 'id' => 'DESC'], 20) as $income) {
            $rows[] = [
                'income' => $income,
                'reversed' => null !== $this->entityManager->getRepository(ExternalIncomeReversal::class)->findOneBy(['income' => $income]),
            ];
        }

        return $rows;
    }

    /** @return list<Fund> */
    private function activeFunds(): array
    {
        return $this->entityManager->getRepository(Fund::class)->findBy(['active' => true], ['name' => 'ASC']);
    }

    private function requireFund(int $id): Fund
    {
        $fund = $this->entityManager->find(Fund::class, $id);
        if (!$fund instanceof Fund || !$fund->isActive()) {
            throw new InvalidArgumentException('Invalid fund.');
        }

        return $fund;
    }

    private function parseMonth(string $input): DateTimeImmutable
    {
        $timezone = new DateTimeZone(self::SOFIA);
        if ('' === $input) {
            return new DateTimeImmutable('first day of this month 00:00:00', $timezone);
        }
        if (1 !== preg_match('/^(20\\d{2})-(0[1-9]|1[0-2])$/', $input)) {
            throw $this->createNotFoundException('Invalid reporting month.');
        }

        return new DateTimeImmutable($input.'-01 00:00:00', $timezone);
    }

    private function parseLocalDate(string $input): DateTimeImmutable
    {
        if (1 !== preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $input)) {
            throw new InvalidArgumentException('Invalid date.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input, new DateTimeZone(self::SOFIA));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $input) {
            throw new InvalidArgumentException('Invalid date.');
        }

        return $date;
    }

    private function requireFinanceReader(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || (!$this->isGranted('ROLE_MANAGER') && !$this->isGranted('ROLE_CASHIER') && !$this->isGranted('ROLE_CONTROLLER') && !$this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('Finance management access is required.');
        }

        return $user;
    }

    private function requireFinanceWriter(): User
    {
        $user = $this->requireFinanceReader();
        if (!$this->canWriteFinance()) {
            throw $this->createAccessDeniedException('Finance write access is required.');
        }

        return $user;
    }

    private function requireBudgetWriter(): void
    {
        $this->requireFinanceReader();
        if (!$this->canCreateBudget()) {
            throw $this->createAccessDeniedException('Budget write access is required.');
        }
    }

    private function canWriteFinance(): bool
    {
        return $this->isGranted('ROLE_MANAGER') || $this->isGranted('ROLE_CASHIER') || $this->isGranted('ROLE_ADMIN');
    }

    private function canCreateBudget(): bool
    {
        return $this->isGranted('ROLE_MANAGER') || $this->isGranted('ROLE_ADMIN');
    }
}
