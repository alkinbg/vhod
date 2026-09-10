# Phase 10 Compliance Calendar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the first five Slice 10 requirements with an auditable condominium registry profile, historical management mandates, completion records for recurring compliance work, and one management reminder view that also includes maintenance contract/inspection deadlines.

**Architecture:** Keep this as a small compliance domain inside the existing modular monolith. Facts are persisted separately from derived reminders: the application stores the externally assigned EISES identifier, append-only mandate records, and append-only compliance completion records; reminder objects are calculated at read time and never persisted. Existing `MaintenanceReminderService` remains unchanged and is adapted into the unified compliance reminder list, avoiding a breaking API change.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7, MariaDB 10.11+, Twig, PHPUnit 12, PHPStan 2.

**Spec:** `docs/roadmap.md` — Slice 10, bullets 1–5. Legal baseline: `docs/architecture.md`, ZUES, and Ordinance No. RD-02-20-1/05.02.2026 for EISES.

## Global Constraints

- Vhod remains a private single-entrance application; do not introduce tenant/building abstractions.
- The EISES condominium identifier is externally assigned by the municipal administration; Vhod must never generate it, and once a non-null identifier is recorded it cannot be changed through the normal compliance workflow.
- Do not hard-code disputed legal reminder lead times. Reminder horizons are operational UI choices, not claims that the law sets those exact notice periods.
- Management mandates and compliance completion history are append-only in this slice: no edit/delete endpoints.
- Existing maintenance reminder behaviour and public APIs remain backward compatible.
- All mutations require CSRF and capability checks.
- Doctrine relations that preserve legal/audit history use `onDelete: 'RESTRICT'`; no cascade remove.
- New PHP files use `declare(strict_types=1);`, typed properties/returns, constructor injection, and no float arithmetic.
- Every task must finish with focused tests and the full CI gate before merge.

---

### Task 1: Persistence model for registry identity and compliance history

**Files:**
- Create: `src/Entity/CondominiumProfile.php`
- Create: `src/Entity/ManagementMandate.php`
- Create: `src/Entity/ComplianceCompletion.php`
- Create: `src/Enum/ManagementMandateKind.php`
- Create: `src/Enum/ComplianceCompletionType.php`
- Create: `tests/Entity/ComplianceDomainTest.php`
- Create: `tests/Doctrine/ComplianceSchemaTest.php`
- Create: `migrations/Version20260910090000.php`

**Interfaces:**
- `CondominiumProfile::create(User $actor, DateTimeImmutable $createdAt): self`
- `CondominiumProfile::updateRegistryData(?string $identifier, ?string $parcelNumber, ?DateTimeImmutable $registeredAt, User $actor, DateTimeImmutable $updatedAt): void`
- `ManagementMandate::record(ManagementMandateKind $kind, string $holderLabel, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, User $recordedBy, DateTimeImmutable $recordedAt, ?GeneralAssembly $sourceAssembly = null, ?string $note = null): self`
- `ComplianceCompletion::record(ComplianceCompletionType $type, string $periodKey, DateTimeImmutable $completedAt, User $recordedBy, DateTimeImmutable $recordedAt, ?Document $evidenceDocument = null, ?string $note = null): self`

- [x] **Step 1: Write failing domain and schema tests.** Verify normalized registry values, mandate date ordering, monthly `YYYY-MM` and annual `YYYY` period-key validation, named indexes/uniques, and `RESTRICT` audit/source associations.
- [x] **Step 2: Run focused tests and verify RED because the new classes/tables do not exist.**
- [x] **Step 3: Implement the three entities and two enums.** `CondominiumProfile` has a DB-unique fixed scope key (`primary`) because this product manages exactly one entrance. Registry identifier and parcel number are nullable external strings; the profile records creator/updater and timestamps. `ManagementMandate` and `ComplianceCompletion` expose no mutation methods after construction.
- [x] **Step 4: Add additive migration `Version20260910090000`.** Create the three tables, stable named indexes/unique constraints, foreign keys with `RESTRICT`, and a compound index on `management_mandate(ends_at, starts_at)` for current/expiring lookups.
- [x] **Step 5: Run focused domain/schema tests, Doctrine mapping validation, migration up/down/up, then commit.**

### Task 2: Unified reminder calculation

**Files:**
- Create: `src/Enum/ComplianceReminderType.php`
- Create: `src/Value/ComplianceReminder.php`
- Create: `src/Service/ComplianceReminderService.php`
- Create: `tests/Service/ComplianceReminderServiceTest.php`

**Interfaces:**
- `ComplianceReminderService::__construct(MaintenanceReminderService $maintenanceReminderService)`
- `ComplianceReminderService::build(DateTimeImmutable $today, array $mandates, array $completions, array $contracts, array $assets): array`
- Return type: `list<ComplianceReminder>` ordered by due date, subject, then type.

**Reminder rules:**
- Among mandates whose `startsAt` is not in the future, the one with the latest `endsAt` is considered current for reminder purposes. Emit `MANAGEMENT_MANDATE_EXPIRED` when past due and `MANAGEMENT_MANDATE_DUE` within 60 days. This prevents an already-entered future mandate from hiding the current one.
- A monthly report completion is expected for the immediately preceding calendar month. If absent, emit `MONTHLY_REPORT_MISSING` with due date equal to the first day of the current month. The date is an operational reminder anchor, not a statutory deadline.
- An annual cash/control audit completion is expected for the current calendar year. If absent, emit `ANNUAL_CASH_AUDIT_DUE` with due date 31 December of the current year; keep it visible throughout the year rather than hiding it behind a lead-time threshold.
- Contracts with `endsAt` in the past emit `CONTRACT_EXPIRED`; contracts ending within 60 days emit `CONTRACT_DUE`.
- Existing asset warranty/inspection reminders are converted losslessly to the corresponding compliance reminder types; `MaintenanceReminderService` itself is not modified.

- [x] **Step 1: Write failing reminder tests covering overdue/upcoming mandate, missing/present monthly report, annual audit, contract expiry, existing asset inspection/warranty adaptation, inactive assets, and deterministic sorting.**
- [x] **Step 2: Run the focused test and verify RED.**
- [x] **Step 3: Implement enum/value/service with date-only calculations in the timezone supplied by `$today`.**
- [x] **Step 4: Run the focused service tests and PHPStan, then commit.**

### Task 3: Capability policy and management workflow

**Files:**
- Create: `src/Security/ComplianceAccessPolicy.php`
- Create: `src/Service/ComplianceRegistryService.php`
- Create: `src/Controller/ComplianceManagementController.php`
- Create: `templates/management/compliance/index.html.twig`
- Create: `tests/Controller/ComplianceManagementControllerTest.php`
- Modify: `templates/base.html.twig`

**Interfaces and permissions:**
- `ComplianceAccessPolicy::canView(User $user): bool`: manager, controller, admin.
- `ComplianceAccessPolicy::canManageRegistry(User $user): bool`: manager or admin.
- `ComplianceAccessPolicy::canRecordMandate(User $user): bool`: manager or admin.
- `ComplianceAccessPolicy::canRecordMonthlyReport(User $user): bool`: manager or admin.
- `ComplianceAccessPolicy::canRecordAnnualAudit(User $user): bool`: manager, controller or admin. The actor records that the audit occurred; this does not imply the actor personally performed the statutory check.
- `ComplianceRegistryService::profile(): ?CondominiumProfile` is read-only and never mutates on GET.
- `ComplianceRegistryService::updateRegistryData(...)` creates the singleton profile only during the explicit registry POST when it does not yet exist.
- `ComplianceRegistryService::recordMandate(...)` and `recordCompletion(...)` wrap writes in Doctrine transactions and reject unauthorized actors. Database unique races are normalized into domain errors instead of leaking 500 responses.

**HTTP surface:**
- `GET /management/compliance` — view current registry data, mandate history, completion history, and unified reminders; it must not create database rows.
- `POST /management/compliance/registry` — create/update external registry metadata; a previously recorded external identifier cannot be replaced through this workflow.
- `POST /management/compliance/mandate` — append a mandate.
- `POST /management/compliance/completion` — append a monthly report or annual audit completion.

- [x] **Step 1: Write functional tests for anonymous/resident/cashier/manager/controller/admin role matrix, GET-no-mutation, each mutation's valid and invalid CSRF path, duplicate completion rejection, no mutation on rejected requests, and the reminder output rendered from persisted data.**
- [x] **Step 2: Run the controller test and verify RED.**
- [x] **Step 3: Implement policy, transactional service, controller and Twig view.** Use explicit hidden CSRF tokens per action and show forms only when the current role has the corresponding capability.
- [x] **Step 4: Add one navigation entry `Съответствие` only for manager/controller/admin.**
- [x] **Step 5: Run controller tests and relevant navigation tests, then commit.**

### Task 4: Final slice-10A verification and cleanup

**Files:**
- Modify only files already touched above if verification finds defects.

- [x] **Step 1: Run full PHPUnit.** Expected: all tests green.
- [x] **Step 2: Run PHPStan.** Expected: zero errors without ignores/baseline additions.
- [x] **Step 3: Run `lint:container` and Doctrine mapping validation.** Expected: green.
- [x] **Step 4: Run MariaDB migrations from empty DB, validate schema, migrate one step down, migrate up again, validate schema.** Expected: green and reversible.
- [x] **Step 5: Review the PR diff for accidental cascade remove, generated identifiers, resident leakage, mutation endpoints without CSRF, or unrelated feature churn.** Review additionally found and fixed external-identifier replacement and unique-constraint race handling before the final gate.
- [ ] **Step 6: Merge only from the exact verified head.**

## Follow-on Slice 10 plans

The remaining Slice 10 requirements are intentionally separate reviewer-sized changes after this PR: (1) access-control matrix plus meaningful audit trail/review, (2) security headers plus rate limiting/login throttling, and (3) encrypted backup/restore verification plus production health/monitoring. Each will receive its own implementation plan and exact-head CI gate.