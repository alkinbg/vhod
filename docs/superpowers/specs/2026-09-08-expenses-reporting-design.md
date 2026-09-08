# Expenses and Monthly Reporting Design

## Scope

This slice adds expense recording, non-resident/common income recording and a monthly income/expense report for the single private condominium entrance.

It builds on the existing immutable fee, charge, payment and bank-reconciliation ledger. It does not create a general accounting system, double-entry bookkeeping, supplier management, tax accounting, Open Banking expense matching or a document archive.

## Legal/reporting target

The monthly report follows the official MRRB template under Art. 23(1)(3a) ZUES. The template groups income into resident management/maintenance contributions, Repair and Renovation Fund contributions, common-part rent, advertising/technical installations, public/EU funding and subsidies, loan proceeds, renewable-energy income, donations and other income. Expenses are grouped into management, common-part maintenance, repairs/renovation, provided services and other expenses.

The official list is non-exhaustive, so the application keeps an explicit `OTHER` category rather than hard-coding only known real-world cases.

## Money and audit rules

- EUR only.
- Persist money as integer cents; never floats.
- `Expense` and `ExternalIncome` are immutable once posted.
- Corrections use full reversal records and, when needed, a replacement posting.
- Historical foreign keys use `RESTRICT`.
- Dates that determine a report period are explicit cash dates (`paidAt` / `receivedAt`). Audit timestamps are stored in UTC.
- No hard delete workflow is provided for posted financial records.

## Expense

`Expense` represents money paid by the condominium.

Fields:
- `Fund fund` — the fund/accounting bucket from which the expense is paid;
- `ExpenseCategory category` — exact report classification;
- positive `amountCents`;
- `paidAt` — cash date used by monthly reporting;
- `postedAt` — UTC audit timestamp;
- required `description`;
- optional `payee`;
- optional `documentReference` (invoice/receipt/protocol identifier only; file attachments belong to the later Documents module);
- optional `note`.

The entity validates positive money, non-empty description and `paidAt <= postedAt` after UTC normalization.

## Expense categories

The enum mirrors the official report rows while staying machine-stable:

### I. Management
- `MANAGER_COMPENSATION`
- `CONTROLLER_COMPENSATION`
- `CASHIER_COMPENSATION`
- `PROFESSIONAL_MANAGER_COMPENSATION`
- `MANAGEMENT_CONSUMABLES`

### II. Common-part maintenance
- `COMMON_PARTS_CLEANING`
- `ELEVATOR_MAINTENANCE`
- `COMMON_ELECTRICITY`
- `COMMON_WATER`
- `PEST_CONTROL`
- `LANDSCAPING`
- `MAINTENANCE_OTHER`

### III. Repairs, renovation and common parts
- `NECESSARY_REPAIR`
- `URGENT_REPAIR`
- `MAJOR_RENOVATION`
- `COMMON_PART_REMODELING`
- `COMMON_INSTALLATION_REPLACEMENT`
- `USEFUL_EXPENSE`

### IV. Services
- `LEGAL_SERVICES`
- `CONSULTING_SERVICES`
- `COURT_PROCEEDINGS`
- `BANK_FEES`

### V. Other
- `OTHER`

Each enum case exposes a report section and Bulgarian label through pure methods; labels are not persisted.

## Expense reversal

`ExpenseReversal` is one-to-one with `Expense` and records:
- exact original amount;
- required reason;
- UTC `reversedAt`.

The reversal must not predate expense posting and its amount must exactly match the expense. A database unique constraint prevents a second reversal.

## External/common income

Resident payments already exist as `Payment` and must not be duplicated in a generic income table.

`ExternalIncome` is only for condominium income that is not a payment attached to a unit. It references a `Fund`, stores a positive amount, `receivedAt`, UTC `postedAt`, required description/source text, optional document reference and note.

Categories:
- `COMMON_PART_RENT`
- `ADVERTISING_TECHNICAL_INSTALLATIONS`
- `PUBLIC_FUNDING_SUBSIDY`
- `LOAN_PROCEEDS`
- `RENEWABLE_ENERGY`
- `DONATION`
- `OTHER`

`ExternalIncomeReversal` follows the same full-reversal rules as expense reversal.

## Monthly report aggregation

`MonthlyFinancialReportService::build(DateTimeImmutable $month)` returns an immutable value object, not a persisted snapshot in this slice.

The month is normalized to its first day. The report is cash-based:

### Resident income
For each `Payment` whose `receivedAt` is in the month and which is not already fully offset in that same reporting calculation:
- allocation portions whose charge policy category is `MANAGEMENT_MAINTENANCE` go to report income I;
- allocation portions whose policy category is `REPAIR_RENOVATION` go to report income II;
- allocations from policy category `OTHER` go to report income IX;
- any unallocated part of a payment goes to report income IX so cash is never silently omitted.

A `PaymentReversal` contributes the exact negative counterpart in the month of `reversedAt`, using the original payment allocations. Therefore a payment and reversal in the same month net to zero, while a later correction is visible in the later month's report.

### External income
`ExternalIncome` contributes positively in its `receivedAt` month. Its reversal contributes negatively in the `reversedAt` month using the same category.

### Expenses
`Expense` contributes positively to its expense category in its `paidAt` month. `ExpenseReversal` contributes the exact negative counterpart in its `reversedAt` month.

This makes report totals reconcile with cash events/corrections without mutating old ledger rows.

## Report value objects

`MonthlyFinancialReport` exposes:
- normalized month;
- ordered income lines with code, Bulgarian label and signed cents;
- ordered expense lines with code, Bulgarian label and signed cents;
- total income cents;
- total expense cents;
- net cents (`income - expense`).

Zero-value predefined categories may be omitted from internal DTO output; the eventual printable template can render all official rows and fill missing values as zero.

## Access/UI boundary

This PR focuses on the ledger and report computation. Management UI/export is a follow-up slice after the domain and aggregation rules are green. Resident-facing visibility remains aggregated; raw expense documents, bank rows and sensitive management notes are not made public by this design.

## Database

Create four tables:
- `expense`
- `expense_reversal`
- `external_income`
- `external_income_reversal`

All financial parent FKs use `ON DELETE RESTRICT`. Reversal tables have unique parent FKs. Add indexes for report-period dates and fund/date lookups.

## Testing

TDD coverage must prove:
- money/date/text invariants;
- exact category-to-report-section mapping;
- one full reversal only;
- resident payments are split by allocation policy category;
- unallocated payment cash is not lost;
- payment reversals offset income in the reversal month;
- external-income and expense reversals offset their original category;
- report totals and net are deterministic;
- MariaDB migration up/down/up remains schema-synchronized;
- PHPStan remains clean.
