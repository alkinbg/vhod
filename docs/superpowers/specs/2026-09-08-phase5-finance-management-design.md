# Phase 5 Finance Management Completion Design

## Goal

Complete roadmap slice 5 with a management-facing finance workflow on top of the already-merged immutable payment, expense, external-income and monthly-report ledgers.

## Scope

This slice adds:

- management finance dashboard;
- expense entry UI;
- external/common income entry UI;
- optional General Assembly/approval reference on expenses;
- annual budget lines by fund and expense category;
- budget-versus-actual view;
- monthly income/expense report view suitable for printing;
- strict management access and CSRF protection.

It does not add double-entry accounting, VAT/tax accounting, supplier master data, payment initiation, public debtor lists or file storage. Actual private document files are delivered by roadmap slice 8; this slice continues to preserve `documentReference` metadata so invoices, receipts and protocols can already be identified.

## Access model

- `ROLE_MANAGER`, `ROLE_CASHIER`, `ROLE_ADMIN`: read finance and post expenses/external income.
- `ROLE_MANAGER`, `ROLE_ADMIN`: create annual budget lines.
- `ROLE_CONTROLLER`: read-only finance/report access.
- ordinary residents do not get raw ledger access from these routes.

All `/management/finance/**` routes enforce this explicitly in addition to the global authenticated-user rule.

## Expense audit metadata

`Expense` gains optional `decisionReference`, normalized like the existing document reference. It is intended for the General Assembly resolution, urgent-repair protocol or other legal/management basis when one exists. It remains immutable after posting.

## Budget model

`BudgetLine` is an immutable annual planning record:

- `year` (2020..2100);
- `Fund`;
- `ExpenseCategory`;
- positive `amountCents`;
- required `decisionReference`;
- UTC `postedAt`.

A unique database constraint prevents duplicate `(year, fund, category)` lines. Corrections are made by replacing the future planning record before real use; posted financial cash ledgers remain immutable. Budget lines are planning data, not cash ledger entries.

## Dashboard semantics

For a selected Bulgarian calendar month:

- total income, total expenses and net come from `MonthlyFinancialReportService`;
- year-to-date actual expenses aggregate immutable `Expense` and `ExpenseReversal` cash events using `Europe/Sofia` boundaries;
- annual budget comes from `BudgetLine`;
- remaining budget = annual budget - signed YTD actual expense;
- no individual debtor names or household balances are shown.

## UI

`FinanceManagementController` exposes:

- `GET /management/finance` dashboard;
- `GET|POST /management/finance/expense/new`;
- `GET|POST /management/finance/income/new`;
- `GET|POST /management/finance/budget/new`;
- `GET /management/finance/report/{year}/{month}`.

The UI follows the existing Twig application shell and manual request/CSRF pattern already used in the condominium-book controllers. Amount input is decimal EUR text and is parsed deterministically to integer cents; no floats are used.

## Validation

- amount input accepts `123`, `123.4`, `123.45` and comma equivalents;
- zero, negative, malformed and >2-decimal values are rejected;
- selected fund/category must exist and be valid;
- date input is interpreted in `Europe/Sofia`, then the entity stores UTC;
- expense description is required;
- budget decision reference is required;
- CSRF token is required for every write.

## Database

Add `decision_reference VARCHAR(190) NULL` to `expense` and create `budget_line` with `ON DELETE RESTRICT` to `fund`. Migration must pass MariaDB up/down/up schema synchronization.

## Testing

Tests cover money parsing, budget invariants, DB uniqueness/restrict policy, management authorization, write CSRF, posting flows, report rendering and budget-vs-actual arithmetic. Full PHPUnit and PHPStan remain green.