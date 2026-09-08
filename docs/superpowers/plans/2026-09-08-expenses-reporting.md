# Expenses and Monthly Reporting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add immutable condominium expense/common-income ledgers and deterministic monthly income/expense aggregation compatible with the official MRRB report categories.

**Architecture:** Keep resident income in the existing `Payment`/`PaymentAllocation` ledger. Add separate immutable records only for condominium expenses and non-unit external income, with full reversal entities for corrections. Build monthly reports on demand by aggregating cash dates plus reversals; do not persist report snapshots or add UI/file storage in this slice.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7, MariaDB 10.11, PHPUnit 12, PHPStan 2.

**Spec:** `docs/superpowers/specs/2026-09-08-expenses-reporting-design.md`

## Global Constraints

- Single private condominium entrance only; no tenant/SaaS abstractions.
- EUR only; all persisted money uses integer cents.
- Posted financial entries are immutable.
- Corrections use one full reversal record; no update/delete correction workflow.
- All financial foreign keys use `RESTRICT`.
- Resident payments are not duplicated as external income.
- Report periods use cash dates (`receivedAt`/`paidAt`); audit timestamps use UTC.
- No supplier subsystem, double-entry accounting, bank expense matching, file archive or resident-facing raw finance UI in this PR.
- Every production slice is test-first.

---

### Task 1: Expense categories, expense ledger and reversal

**Files:**
- Create: `src/Enum/ExpenseReportSection.php`
- Create: `src/Enum/ExpenseCategory.php`
- Create: `src/Entity/Expense.php`
- Create: `src/Entity/ExpenseReversal.php`
- Test: `tests/Enum/ExpenseCategoryTest.php`
- Test: `tests/Entity/ExpenseTest.php`
- Test: `tests/Entity/ExpenseReversalTest.php`

**Interfaces:**
- `ExpenseCategory::section(): ExpenseReportSection`
- `ExpenseCategory::labelBg(): string`
- `Expense::post(Fund $fund, ExpenseCategory $category, int $amountCents, DateTimeImmutable $paidAt, DateTimeImmutable $postedAt, string $description, ?string $payee = null, ?string $documentReference = null, ?string $note = null): self`
- `ExpenseReversal::record(Expense $expense, int $amountCents, string $reason, DateTimeImmutable $reversedAt): self`

- [ ] **Step 1: Write failing category and entity tests**

Tests must prove representative category mappings for all five report sections, Bulgarian labels are non-empty, expense amounts are positive, description is required, optional strings are trimmed/null-normalized, `paidAt` cannot be later than `postedAt`, audit timestamps normalize to UTC, and reversal must exactly equal the original amount with a non-empty reason and non-earlier timestamp.

Example assertions:

```php
self::assertSame(ExpenseReportSection::MANAGEMENT, ExpenseCategory::MANAGER_COMPENSATION->section());
self::assertSame(ExpenseReportSection::MAINTENANCE, ExpenseCategory::COMMON_ELECTRICITY->section());
self::assertSame(ExpenseReportSection::REPAIRS, ExpenseCategory::URGENT_REPAIR->section());
self::assertSame(ExpenseReportSection::SERVICES, ExpenseCategory::BANK_FEES->section());
self::assertSame(ExpenseReportSection::OTHER, ExpenseCategory::OTHER->section());

$expense = Expense::post(
    new Fund('operating', 'Управление и поддръжка', FundType::OPERATING),
    ExpenseCategory::COMMON_ELECTRICITY,
    4280,
    new DateTimeImmutable('2026-09-05 00:00:00 Europe/Sofia'),
    new DateTimeImmutable('2026-09-05 12:00:00 Europe/Sofia'),
    'Електроенергия общи части',
    'Тестов доставчик',
    'INV-TEST-001',
);
self::assertSame(4280, $expense->getAmountCents());
```

- [ ] **Step 2: Run focused tests and verify RED**

```bash
vendor/bin/phpunit tests/Enum/ExpenseCategoryTest.php tests/Entity/ExpenseTest.php tests/Entity/ExpenseReversalTest.php
```

Expected: failures caused by missing expense enum/entities only.

- [ ] **Step 3: Implement minimal immutable expense domain**

`ExpenseReportSection` values: `management`, `maintenance`, `repairs`, `services`, `other`.

`ExpenseCategory` cases and mappings must exactly follow the spec. Do not persist labels; implement them with exhaustive `match` expressions.

`Expense` stores `paidAt` as UTC `datetime_immutable` even though reporting compares calendar dates. `ExpenseReversal` has a unique DB relation to its parent in Task 3.

- [ ] **Step 4: Run focused tests**

```bash
vendor/bin/phpunit tests/Enum/ExpenseCategoryTest.php tests/Entity/ExpenseTest.php tests/Entity/ExpenseReversalTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Enum/ExpenseReportSection.php src/Enum/ExpenseCategory.php src/Entity/Expense.php src/Entity/ExpenseReversal.php tests/Enum/ExpenseCategoryTest.php tests/Entity/ExpenseTest.php tests/Entity/ExpenseReversalTest.php
git commit -m "feat: add immutable expense ledger"
```

### Task 2: External/common income ledger and reversal

**Files:**
- Create: `src/Enum/ExternalIncomeCategory.php`
- Create: `src/Entity/ExternalIncome.php`
- Create: `src/Entity/ExternalIncomeReversal.php`
- Test: `tests/Enum/ExternalIncomeCategoryTest.php`
- Test: `tests/Entity/ExternalIncomeTest.php`
- Test: `tests/Entity/ExternalIncomeReversalTest.php`

**Interfaces:**
- `ExternalIncomeCategory::labelBg(): string`
- `ExternalIncome::record(Fund $fund, ExternalIncomeCategory $category, int $amountCents, DateTimeImmutable $receivedAt, DateTimeImmutable $postedAt, string $description, ?string $documentReference = null, ?string $note = null): self`
- `ExternalIncomeReversal::record(ExternalIncome $income, int $amountCents, string $reason, DateTimeImmutable $reversedAt): self`

- [ ] **Step 1: Write failing tests**

Prove all seven external income categories have non-empty Bulgarian labels, positive amount and description invariants, UTC normalization, `receivedAt <= postedAt`, and exact one-full-reversal domain invariants.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Enum/ExternalIncomeCategoryTest.php tests/Entity/ExternalIncomeTest.php tests/Entity/ExternalIncomeReversalTest.php
```

- [ ] **Step 3: Implement minimal domain**

Cases:

```php
COMMON_PART_RENT
ADVERTISING_TECHNICAL_INSTALLATIONS
PUBLIC_FUNDING_SUBSIDY
LOAN_PROCEEDS
RENEWABLE_ENERGY
DONATION
OTHER
```

Do not add unit/person fields: this ledger is explicitly for non-resident/common income.

- [ ] **Step 4: Focused tests and commit**

```bash
vendor/bin/phpunit tests/Enum/ExternalIncomeCategoryTest.php tests/Entity/ExternalIncomeTest.php tests/Entity/ExternalIncomeReversalTest.php
git add src/Enum/ExternalIncomeCategory.php src/Entity/ExternalIncome.php src/Entity/ExternalIncomeReversal.php tests/
git commit -m "feat: add external income ledger"
```

### Task 3: MariaDB schema and DB-level reversal invariants

**Files:**
- Create: `migrations/Version20260908130000.php`
- Test: `tests/Doctrine/FinancialReversalUniquenessTest.php`

**Interfaces:**
- Tables: `expense`, `expense_reversal`, `external_income`, `external_income_reversal`.

- [ ] **Step 1: Write failing Doctrine metadata test**

Assert both reversal entities have a unique constraint on their parent relation and all parent/fund associations use `RESTRICT`.

- [ ] **Step 2: Verify RED before migration/mapping completion**

```bash
vendor/bin/phpunit tests/Doctrine/FinancialReversalUniquenessTest.php
```

- [ ] **Step 3: Add MariaDB migration**

Schema requirements:

```text
expense:
  id PK
  fund_id NOT NULL FK fund(id) RESTRICT
  category VARCHAR(255) NOT NULL
  amount_cents INT NOT NULL
  paid_at DATETIME NOT NULL
  posted_at DATETIME NOT NULL
  description VARCHAR(255) NOT NULL
  payee VARCHAR(255) NULL
  document_reference VARCHAR(190) NULL
  note LONGTEXT NULL
  INDEX (paid_at)
  INDEX (fund_id, paid_at)

expense_reversal:
  id PK
  expense_id NOT NULL FK expense(id) RESTRICT UNIQUE
  amount_cents INT NOT NULL
  reason LONGTEXT NOT NULL
  reversed_at DATETIME NOT NULL
  INDEX (reversed_at)

external_income:
  id PK
  fund_id NOT NULL FK fund(id) RESTRICT
  category VARCHAR(255) NOT NULL
  amount_cents INT NOT NULL
  received_at DATETIME NOT NULL
  posted_at DATETIME NOT NULL
  description VARCHAR(255) NOT NULL
  document_reference VARCHAR(190) NULL
  note LONGTEXT NULL
  INDEX (received_at)
  INDEX (fund_id, received_at)

external_income_reversal:
  id PK
  external_income_id NOT NULL FK external_income(id) RESTRICT UNIQUE
  amount_cents INT NOT NULL
  reason LONGTEXT NOT NULL
  reversed_at DATETIME NOT NULL
  INDEX (reversed_at)
```

`down()` drops children first, then parent tables.

- [ ] **Step 4: Run schema gate**

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
php bin/console doctrine:migrations:migrate prev --no-interaction --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
```

- [ ] **Step 5: Commit**

```bash
git add migrations/Version20260908130000.php tests/Doctrine/FinancialReversalUniquenessTest.php
git commit -m "feat: persist expense and external income ledgers"
```

### Task 4: Monthly report value model and aggregation

**Files:**
- Create: `src/Value/FinancialReportLine.php`
- Create: `src/Value/MonthlyFinancialReport.php`
- Create: `src/Service/MonthlyFinancialReportService.php`
- Test: `tests/Value/MonthlyFinancialReportTest.php`
- Test: `tests/Service/MonthlyFinancialReportServiceTest.php`

**Interfaces:**
- `FinancialReportLine::__construct(string $code, string $label, int $amountCents)`
- `MonthlyFinancialReport::__construct(DateTimeImmutable $month, array $incomeLines, array $expenseLines)`
- `MonthlyFinancialReport::getTotalIncomeCents(): int`
- `MonthlyFinancialReport::getTotalExpenseCents(): int`
- `MonthlyFinancialReport::getNetCents(): int`
- `MonthlyFinancialReportService::build(DateTimeImmutable $month): MonthlyFinancialReport`

- [ ] **Step 1: Write value-object tests RED**

Prove month normalization, deterministic totals and signed lines.

- [ ] **Step 2: Write integration tests RED for report aggregation**

Use persisted `Fund`, `FeePolicy`, `Charge`, `Payment`, `PaymentAllocation`, `PaymentReversal`, `ExternalIncome`, `ExternalIncomeReversal`, `Expense` and `ExpenseReversal` records.

Required scenarios:

1. A resident payment allocated across management and repair charges is split into official income I and II.
2. Unallocated payment remainder appears in income IX (`other`) so total received cash is preserved.
3. A payment reversal in a later month contributes negative income in that later month using the original allocation split.
4. External rent, subsidy and donation records map to their official income rows.
5. Expense categories aggregate into their exact official expense rows.
6. Expense/external-income reversals in a later month contribute negative values to the same category.
7. Records outside the requested month do not affect totals.
8. `net = totalIncome - totalExpense`.

Example:

```php
$report = $service->build(new DateTimeImmutable('2026-09-15'));
self::assertSame(15_000, $report->getTotalIncomeCents());
self::assertSame(4_280, $report->getTotalExpenseCents());
self::assertSame(10_720, $report->getNetCents());
```

- [ ] **Step 3: Implement aggregation without raw SQL money arithmetic**

Fetch period candidates through Doctrine repositories using `[monthStart, nextMonthStart)` and aggregate integer cents in PHP. This keeps category/reversal rules explicit and testable at current entrance scale.

For each payment, load allocations and split by `FeeCategory`. `PaymentReversal` entries whose `reversedAt` falls in the target month contribute the negative equivalent of the original payment split even if the original payment was received earlier.

For external income and expenses, add positive entries by cash date and negative reversal entries by reversal date.

- [ ] **Step 4: Run report tests and full suite**

```bash
vendor/bin/phpunit tests/Value/MonthlyFinancialReportTest.php tests/Service/MonthlyFinancialReportServiceTest.php
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 5: Commit**

```bash
git add src/Value/FinancialReportLine.php src/Value/MonthlyFinancialReport.php src/Service/MonthlyFinancialReportService.php tests/Value/MonthlyFinancialReportTest.php tests/Service/MonthlyFinancialReportServiceTest.php
git commit -m "feat: generate monthly financial report"
```

### Task 5: Final verification and review gate

**Files:**
- Review: `docs/superpowers/specs/2026-09-08-expenses-reporting-design.md`
- Review: all files introduced by Tasks 1-4.

- [ ] **Step 1: Verify no destructive financial FK**

Inspect the PR diff. Production mappings/migration `up()` must contain only `RESTRICT` for financial parent references. `CASCADE` must not appear in new financial `up()` SQL.

- [ ] **Step 2: Run complete CI-equivalent gate**

```bash
composer validate --strict
php bin/console lint:container
php bin/console doctrine:schema:validate --skip-sync --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
php bin/console doctrine:migrations:migrate prev --no-interaction --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 3: Review against official-report semantics**

Confirm every official income group is either derived from resident payments or represented by `ExternalIncomeCategory`, every official expense row has a stable `ExpenseCategory`, and `OTHER` exists for both sides because the official template is non-exhaustive.

- [ ] **Step 4: Open PR and require green GitHub CI before merge**

PR title: `Finance: expenses and monthly reporting`.

Merge only the exact CI-verified head SHA.
