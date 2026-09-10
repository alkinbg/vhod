# Release Readiness Hardening Design

## Goal

Close the release-readiness gaps discovered by the final whole-project audit before local acceptance testing, without changing the one-entrance/private-product scope.

## Scope

This change intentionally combines the remaining audit findings in one reviewable pull request, split into logical commits.

### 1. General Assembly legality and end-to-end workflow

- Add a real DRAFT agenda-item management workflow: create, edit and remove only while the assembly is DRAFT.
- Reuse the existing `AssemblyDecisionKind` and majority-rule snapshot model. The UI must show the exact suggested rule and legal-review state before convening.
- Keep ordinary agenda immutable after convening.
- Add third-call/next-day quorum semantics required by the current ZUES flow, represented explicitly rather than overloading delayed-call semantics.
- Validate quorum check timing against the assembly scheduled time: first call at/after scheduled time; delayed call only after the statutory one-hour delay; next-day call only on the next eligible day/time according to the product rule snapshot.
- A formal automatic `ACCEPTED`/`REJECTED` resolution requires a persisted VALID quorum check applicable to the meeting state. Missing/invalid/review-required quorum forces fail-closed behavior and must not be presented as an ordinary accepted/rejected legal conclusion.
- Minutes finalization must reject meetings without a persisted VALID quorum basis unless the meeting is explicitly in a supported next-day no-minimum-quorum mode. Review-required data must remain visibly review-required.
- Invitation posting records remain factual evidence; the application additionally reports deadline status/warnings rather than silently treating a late posting as compliant proof.

### 2. Finance concurrency, correction workflow and historical inputs

- Payment allocation must serialize competing writes for the same unit/charges using database pessimistic locking inside the posting transaction.
- Reconciliation and reversal check-then-write operations must use locking and convert uniqueness races into deterministic domain outcomes rather than 500 errors.
- Expense and external-income reversal workflows must be exposed through the management UI and append audit entries.
- Supporting finance history remains append-only: corrections are reversals, never destructive edits.
- Animal registrations become effective-dated records so monthly historical charge generation is reproducible.
- Household membership must support explicit end dates through declaration/application flow; no hard deletion of historical membership.

### 3. Bootstrap and core operational workflows

- Provide a supported admin-only bootstrap/management workflow for the minimum data needed to use a fresh installation: units, unit relations, funds and fee policies.
- Preserve the existing CLI user creation path.
- Expose the existing charge-generation/payment/reconciliation/reversal engine through authenticated management UI rather than requiring fixtures or direct SQL/code.
- Do not introduce multi-building abstractions or a SaaS setup wizard.

### 4. Data correctness and UX consistency

- Replace binary-float ownership-share validation with exact decimal validation.
- Surface Maintenance in navigation.
- Dashboard values must come from real application data/services; remove stale "next phase"/"soon" copy for already implemented modules.
- Show meaningful finance/maintenance/community entry points without exposing private debtor information.

## Security and authorization

- Existing capability/role boundaries remain authoritative.
- All new mutation routes are POST and CSRF-protected.
- Bootstrap/configuration mutations are restricted to `ROLE_ADMIN` unless an existing policy already defines a narrower safe capability.
- Finance operations reuse the existing finance reader/writer split; budget/configuration policy changes remain manager/admin only.
- No resident-facing formal-vote mutation is introduced.

## Persistence and migrations

- Only additive/reversible migrations may be added.
- Previously merged migrations are immutable.
- Historical rows are not rewritten or hard-deleted.
- Existing animal registrations migrate to an effective-from value that preserves current behavior for present/future periods; the migration must document the historical limitation for pre-migration periods.
- Add DB indexes/constraints only where they enforce a business invariant or support the new locking/query path.

## Error handling

- Domain-invalid input returns a controlled 4xx/validation response, not a 500.
- Concurrency conflicts that represent an already-applied idempotent operation return the existing result where safe; conflicting data returns a clear domain error.
- Legal ambiguity fails closed to `REVIEW_REQUIRED` or explicit rejection of finalization rather than guessing validity.

## Testing

The PR must add tests that fail before each fix and cover:

- DRAFT agenda CRUD and immutability after convening;
- third-call quorum and timing guards;
- no automatic resolution/final minutes without valid quorum basis;
- posting-deadline warning semantics;
- concurrent payment/allocation/reconciliation/reversal safety at service/schema level;
- effective-dated animal and household history in monthly charge generation;
- expense/income reversal management workflow;
- fresh-install bootstrap routes and role restrictions;
- management charge/payment/reconciliation workflows;
- dashboard/navigation using real data;
- exact-decimal ownership validation.

Final merge requires exact-head green Composer validation, Symfony container lint, Doctrine mapping, MariaDB migration up/down/up, PHPUnit, PHPStan and the existing operations-script checks.
