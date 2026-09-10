# Release Readiness Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the current green codebase into an end-to-end usable release candidate by fixing the business-logic, concurrency, bootstrap and UX gaps found by the final audit.

**Architecture:** Keep the existing bounded contexts and services; add only the missing workflows/invariants. Legal and financial history stays append-only, ambiguous legal conclusions fail closed, and competing financial writes serialize at the database boundary. One PR contains independently reviewable commits for GA, finance/history, bootstrap/operations, UX/data correctness, and final verification.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7, MariaDB 10.11+, Twig, PHPUnit 12, PHPStan 2.

**Spec:** `docs/superpowers/specs/2026-09-10-release-readiness-hardening-design.md`

## Global Constraints

- One residential entrance only; no SaaS or multi-building abstractions.
- All monetary values remain integer cents; legal percentages use exact decimal strings, never binary floating point.
- Previously merged migrations remain immutable; new migrations are additive and reversible.
- Historical finance/governance/book data is corrected by append-only lifecycle/reversal records, never hard deletion.
- All new mutation endpoints are POST + CSRF protected and reuse existing role/capability policies.
- General Assembly ambiguity fails closed; no automatic accepted/rejected legal result without an applicable valid quorum basis.
- Final merge requires exact-head green Composer, Symfony container lint, Doctrine mapping, MariaDB migration round-trip, operations checks, PHPUnit and PHPStan.

---

### Task 1: General Assembly agenda and quorum legality

**Files:**
- Modify: `src/Enum/AssemblyQuorumCheckKind.php`
- Modify: `src/Service/AssemblyQuorumService.php`
- Modify: `src/Service/AssemblyVotingService.php`
- Modify: `src/Service/AssemblyMinutesService.php`
- Modify: `src/Service/GeneralAssemblyService.php`
- Modify: `src/Controller/GeneralAssemblyManagementController.php`
- Modify: `templates/management/assemblies/form.html.twig`
- Modify: `templates/management/assemblies/index.html.twig`
- Modify: `templates/management/assemblies/workbench.html.twig`
- Create/modify focused GA functional/service tests under `tests/Controller/` and `tests/Service/`.
- Add migration only if persisted quorum-kind/state shape requires it.

**Interfaces:**
- Consume existing `GeneralAssembly::addAgendaItem()`, `AssemblyAgendaItem::reviseDraft()`, majority snapshots and quorum calculator.
- Produce a DRAFT-only agenda management HTTP workflow and explicit `NEXT_DAY_CALL` quorum check semantics.

- [ ] **Step 1: Write failing tests for the missing DRAFT agenda workflow.** Verify manager/controller/admin can add/edit/remove ordinary agenda items before convening, resident/cashier cannot, invalid CSRF fails, and convened items are immutable.
- [ ] **Step 2: Run the focused tests and confirm RED because the routes/workflow do not exist.**
- [ ] **Step 3: Implement minimal agenda application/controller methods using existing entity invariants.** Do not duplicate entity validation in Twig; map decision kind to the existing suggested majority rule service/factory used by current tests.
- [ ] **Step 4: Write failing tests for quorum timing and next-day semantics.** Cover first-call before scheduled time, delayed call before +1 hour, valid delayed call, next-day call too early, and supported next-day no-minimum case.
- [ ] **Step 5: Implement `NEXT_DAY_CALL` and timing validation in `AssemblyQuorumService`, preserving exact-decimal calculation snapshots.**
- [ ] **Step 6: Write failing tests proving an item cannot receive an ordinary accepted/rejected automatic resolution, and minutes cannot finalize, without an applicable persisted VALID quorum basis.**
- [ ] **Step 7: Implement fail-closed quorum gating in voting/finalization.** Missing/invalid/review-required quorum must reject automatic legal finalization; next-day call is valid only through its explicit persisted check.
- [ ] **Step 8: Add posting-deadline status tests and minimal factual warning calculation.** Never claim the application proves statutory service.
- [ ] **Step 9: Run all GA tests and commit the independently green GA slice.**

### Task 2: Finance locking, historical occupancy and correction workflows

**Files:**
- Modify: `src/Entity/AnimalRegistration.php`
- Modify: `src/Entity/HouseholdMember.php`
- Modify: `src/Enum/BookChangeType.php`
- Modify: `src/Controller/BookController.php`
- Modify: `src/Service/BookChangeApplicationService.php`
- Modify: `src/Service/MonthlyChargeGenerator.php`
- Modify: `src/Service/PaymentAllocator.php`
- Modify: `src/Service/PaymentPostingService.php`
- Modify: `src/Service/BankReconciliationService.php`
- Modify: `src/Service/PaymentReversalService.php`
- Modify: `src/Controller/FinanceManagementController.php`
- Create focused finance reversal services if the current entities have no application service.
- Modify finance/book Twig templates.
- Create an additive reversible migration for effective-dated animal data/indexes if needed.
- Add service/controller/schema tests.

**Interfaces:**
- `AnimalRegistration::isActiveAt(DateTimeImmutable $date): bool`.
- Household member lifecycle gets an explicit end operation/date rather than deletion.
- Payment posting serializes competing allocations for the same unit/charge set inside one DB transaction.

- [ ] **Step 1: Write failing historical-charge tests.** Show that animal counts differ correctly before/after an effective date and household members stop counting after their end date.
- [ ] **Step 2: Implement effective-dated animal lifecycle and household-member end declaration/application semantics, with backward-safe migration.**
- [ ] **Step 3: Change `MonthlyChargeGenerator` to evaluate animals at billing month and record the historical source in `calculationDetails`.**
- [ ] **Step 4: Write failing concurrency/invariant tests for over-allocation and duplicate reconcile/reversal attempts.**
- [ ] **Step 5: Add pessimistic locking in payment/reconciliation/reversal transaction boundaries and deterministic uniqueness-conflict handling.** Lock persisted Unit/Charge/Payment/BankTransaction rows in stable order to avoid deadlock-prone lock ordering.
- [ ] **Step 6: Write failing management tests for expense and external-income reversal.**
- [ ] **Step 7: Add reversal application services/routes/forms with CSRF, finance-writer permissions and audit entries.** Do not edit original rows.
- [ ] **Step 8: Run focused finance/book tests plus Doctrine mapping/migration round-trip and commit the green slice.**

### Task 3: Fresh-install bootstrap and core finance operations

**Files:**
- Create: `src/Security/SetupAccessPolicy.php` only if existing policies cannot express admin-only setup cleanly.
- Create: `src/Controller/SetupManagementController.php` or focused controllers if one file becomes too large.
- Create focused setup Twig templates under `templates/management/setup/`.
- Modify: `src/Controller/FinanceManagementController.php` or split payment/charge operations into a focused controller if necessary.
- Create/modify finance operation templates.
- Modify `templates/base.html.twig` for discoverability.
- Add controller tests.

**Interfaces:**
- Admin-only setup creates Units, UnitRelations, Funds and FeePolicies through existing entity constructors/factories and validates ownership/effective-date invariants.
- Finance operations expose existing `MonthlyChargeGenerator`, `PaymentPostingService`, bank import/reconciliation services and `PaymentReversalService` without duplicating their business rules.

- [ ] **Step 1: Write failing fresh-install workflow tests starting from schema + admin account.** Verify admin can create minimum building/finance configuration and non-admin roles cannot.
- [ ] **Step 2: Implement minimal setup screens/actions for Unit, UnitRelation, Fund and FeePolicy.** No generalized CMS/admin framework; keep forms specific to this one-entrance product.
- [ ] **Step 3: Write failing management tests for monthly charge generation, manual cash payment posting, bank reconciliation/linking and payment reversal.**
- [ ] **Step 4: Implement thin controllers/forms that call existing services.** Every POST is CSRF protected; service/domain exceptions become controlled validation responses.
- [ ] **Step 5: Add navigation links only where the role can actually use the destination.
- [ ] **Step 6: Run functional tests for a complete scenario: bootstrap → generate charges → post payment → reverse/reconcile as applicable.**
- [ ] **Step 7: Commit the independently green operational workflow slice.**

### Task 4: Exact-decimal validation and release UX consistency

**Files:**
- Modify: `src/Entity/UnitRelation.php`
- Modify: `src/Controller/DashboardController.php`
- Modify: `templates/dashboard/index.html.twig`
- Modify: `templates/base.html.twig`
- Reuse existing repositories/services; create a small dashboard query/view service only if controller queries would become tangled.
- Add unit/controller tests.

**Interfaces:**
- Ownership-share validation accepts canonical decimal input in `(0,100]` without `(float)` conversion.
- Dashboard reports real current-user debt/financial summary, active signals and working module links without exposing another resident's debt.

- [ ] **Step 1: Write failing exact-decimal ownership validation tests, including boundary and malformed values.**
- [ ] **Step 2: Replace float validation with exact string/decimal validation consistent with `ExactDecimal`.**
- [ ] **Step 3: Write failing dashboard/navigation tests proving implemented modules are not labelled “soon”, Maintenance is discoverable, and user-visible values come from persisted data.**
- [ ] **Step 4: Implement minimal dashboard queries/view model and update copy/navigation.** Preserve privacy: no public debtor list or unrelated unit balances.
- [ ] **Step 5: Run focused tests and commit the green UX/data-correctness slice.**

### Task 5: Whole-PR audit and exact-head release gate

**Files:**
- Modify this plan only to mark completed steps after the implementation is proven.
- Modify documentation if new operational/legal behavior requires it; do not rewrite roadmap history for cosmetic completion markers.

**Interfaces:**
- Produces one merge-ready PR whose exact head passes the complete project gate.

- [ ] **Step 1: Review the complete PR diff for accidental scope, hard deletes, float money/legal math, missing CSRF, missing authorization, unsafe file access, transaction leaks and migration irreversibility.**
- [ ] **Step 2: Compare every final-audit finding to a concrete test/fix or an explicit documented non-blocking disposition.** No finding may silently disappear.
- [ ] **Step 3: Run exact-head CI and require success for Composer validation/install, Symfony container lint, Doctrine mapping, operations shell checks, MariaDB migration up/down/up, PHPUnit and PHPStan.**
- [ ] **Step 4: If CI fails, inspect the exact job log, fix only the root cause, and repeat exact-head CI.**
- [ ] **Step 5: Mark plan steps complete, creating a new head; run one final exact-head CI on that documentation head.**
- [ ] **Step 6: Mark the PR ready and merge with normal merge commit using `expected_head_sha`/equivalent race protection.**
- [ ] **Step 7: Verify post-merge CI on `main` before declaring release-readiness hardening complete.**
