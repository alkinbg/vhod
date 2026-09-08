# Phase 5 Finance Management Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete roadmap slice 5 with management finance entry, annual budgeting, budget-vs-actual and printable monthly reporting.

**Architecture:** Reuse the immutable ledgers and `MonthlyFinancialReportService`. Add one planning entity (`BudgetLine`), one management controller, small finance services/value objects, Twig templates and a forward MariaDB migration. Keep all money as integer cents and all financial history protected by `RESTRICT`.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7, MariaDB 10.11, Twig, PHPUnit 12, PHPStan 2.

**Spec:** `docs/superpowers/specs/2026-09-08-phase5-finance-management-design.md`

## Global Constraints

- single private entrance only;
- EUR integer cents only;
- no public debtor list;
- no raw finance access for ordinary residents;
- financial write routes require CSRF;
- `ROLE_CONTROLLER` remains read-only;
- all new financial FKs use `RESTRICT`;
- no document-file storage in this slice.

---

### Task 1: Money input and budget domain

**Files:**
- Create: `src/Value/EuroAmount.php`
- Create: `src/Entity/BudgetLine.php`
- Test: `tests/Value/EuroAmountTest.php`
- Test: `tests/Entity/BudgetLineTest.php`

**Interfaces:**
- `EuroAmount::parse(string $input): int`
- `EuroAmount::format(int $cents): string`
- `BudgetLine::plan(int $year, Fund $fund, ExpenseCategory $category, int $amountCents, string $decisionReference, DateTimeImmutable $postedAt): self`

- [ ] Write RED tests for decimal/comma parsing, malformed/negative/overprecision input, formatting and budget invariants.
- [ ] Implement minimal deterministic parser without floats and immutable `BudgetLine`.
- [ ] Run focused PHPUnit.

### Task 2: Expense decision reference and schema

**Files:**
- Modify: `src/Entity/Expense.php`
- Create: `migrations/Version20260908140000.php`
- Test: `tests/Doctrine/FinanceManagementSchemaTest.php`

**Interfaces:**
- extend `Expense::post(..., ?string $decisionReference = null): self`
- `Expense::getDecisionReference(): ?string`

- [ ] Write RED tests for normalization plus budget unique/restrict metadata.
- [ ] Add nullable expense decision reference and `budget_line` table.
- [ ] Verify MariaDB migrate/schema/rollback/migrate/schema.

### Task 3: Budget-vs-actual service

**Files:**
- Create: `src/Value/BudgetActualLine.php`
- Create: `src/Service/BudgetActualService.php`
- Test: `tests/Service/BudgetActualServiceTest.php`

**Interfaces:**
- `BudgetActualService::build(int $year, DateTimeImmutable $through): array`
- output: `list<BudgetActualLine>` with category/fund/budget/actual/remaining cents.

- [ ] Persist budgets, expenses and later reversals in RED integration tests.
- [ ] Implement Sofia-year cash aggregation and exact reversal offsets.
- [ ] Run focused tests.

### Task 4: Management finance controller and write workflows

**Files:**
- Create: `src/Controller/FinanceManagementController.php`
- Test: `tests/Controller/FinanceManagementControllerTest.php`

**Routes:**
- `GET /management/finance`
- `GET|POST /management/finance/expense/new`
- `GET|POST /management/finance/income/new`
- `GET|POST /management/finance/budget/new`
- `GET /management/finance/report/{year}/{month}`

- [ ] RED functional tests for anonymous/resident/manager/cashier/controller/admin access matrix.
- [ ] RED tests for CSRF and successful expense/income/budget posting.
- [ ] Implement explicit role checks and server-side validation.
- [ ] Run controller tests.

### Task 5: Twig finance UI and printable report

**Files:**
- Create: `templates/management/finance/index.html.twig`
- Create: `templates/management/finance/expense_new.html.twig`
- Create: `templates/management/finance/income_new.html.twig`
- Create: `templates/management/finance/budget_new.html.twig`
- Create: `templates/management/finance/report.html.twig`
- Modify: `templates/base.html.twig`
- Modify: `assets/styles/app.css`
- Test: `tests/Controller/FinanceManagementControllerTest.php`

- [ ] Add rendering assertions for totals, report rows and budget variance.
- [ ] Implement compact Bulgarian management UI with clear EUR labels and print layout.
- [ ] Add management navigation only for authorized roles.
- [ ] Run functional tests.

### Task 6: Phase 5 verification and merge gate

- [ ] `composer validate --strict`.
- [ ] `php bin/console lint:container`.
- [ ] Doctrine mapping and MariaDB up/down/up schema gates.
- [ ] `vendor/bin/phpunit`.
- [ ] `vendor/bin/phpstan analyse --no-progress`.
- [ ] Inspect PR diff: no `CASCADE`/`SET NULL` in new financial production mappings/migration `up()`.
- [ ] Merge only the exact green head SHA.