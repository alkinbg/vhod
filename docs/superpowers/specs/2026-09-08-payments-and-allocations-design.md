# Payments and Allocations Design

## Scope

This slice adds the first payment ledger layer for the single private condominium entrance. It records incoming money, allocates it to posted monthly charges, and preserves a complete audit trail.

This slice does **not** implement bank-statement import, QR payment initiation, Open Banking, card processing, expenses, or resident-facing payment UI. Those remain separate slices.

## Core principles

- This is a private single-entrance system; there are no SaaS or tenant abstractions.
- All money is EUR and stored as integer cents.
- Posted financial records are immutable.
- Corrections use explicit reversal records; posted payments and allocations are never edited or deleted.
- A payment may cover one or many charges and may partially settle a charge.
- Allocation is oldest-debt-first by default, but a manager may override the proposed allocation **before posting**.
- Allocation order is deterministic.
- A payment may remain partially unallocated when there is no matching debt; the unapplied balance remains visible as credit/unallocated money.
- Re-running the same imported/external payment reference must never create a duplicate payment.

## Payment

`Payment` represents money received by the condominium.

Fields:

- `unit` — the unit whose balance receives the payment;
- `amountCents` — positive EUR cents;
- `source` — `CASH`, `BANK_TRANSFER`, or `OTHER`;
- `receivedAt` — timestamp when the money was received;
- `postedAt` — UTC timestamp when the operation was recorded in the system;
- `reference` — optional human-facing payment reference;
- `externalReference` — optional provider/bank/import identifier used for idempotency;
- `note` — optional internal note;
- `status` — `POSTED` or `REVERSED`;
- optional link to the payment that reverses or is reversed by another payment.

Rules:

- `amountCents` must be positive for a normal payment;
- `receivedAt` cannot be after `postedAt` by more than normal timezone representation; both are normalized for persistence consistently;
- `externalReference`, when present, is trimmed and unique;
- a posted payment is immutable;
- reversal creates a new compensating payment record with the opposite ledger effect; it does not mutate the original amount or allocations.

## PaymentAllocation

`PaymentAllocation` represents how much of one posted payment settles one posted `Charge`.

Fields:

- `payment`;
- `charge`;
- `amountCents` — positive EUR cents;
- `position` — deterministic allocation order;
- `createdAt` — UTC timestamp.

Rules:

- payment and charge must belong to the same `Unit`;
- allocated amount must be positive;
- total allocations for a payment may never exceed the payment amount;
- total allocations against a charge may never exceed its outstanding amount;
- one payment may allocate partially to a charge;
- one charge may be settled by multiple payments;
- allocations are immutable after posting;
- a unique constraint prevents duplicate `(payment_id, charge_id)` rows for the same posted allocation set.

## Outstanding balance

The outstanding amount of a charge is derived, not stored as mutable state:

`charge.amountCents - sum(non-reversed allocations applied to that charge)`

A unit balance is likewise derived from immutable ledger entries:

`sum(charges) - sum(effective payment allocations)`

Any unapplied amount from a posted payment is tracked as unallocated credit:

`payment.amountCents - sum(payment allocations)`

The system does not write a mutable `balance` column on `Unit`, `Charge`, or `Payment`.

## Allocation proposal

`PaymentAllocator` proposes allocations before posting.

Default algorithm:

1. consider only outstanding charges for the payment unit;
2. sort by `billingMonth` ascending;
3. for the same month, sort by charge id ascending;
4. allocate until the payment amount is exhausted or there are no outstanding charges;
5. leave any remainder unallocated.

The proposal is a transient value object and has no financial effect until it is posted.

A manager may replace the proposed amounts/order before posting, but the posting service validates the final proposal against all invariants.

## Posting service

`PaymentPostingService` is the transaction boundary.

Inputs:

- unit;
- positive amount;
- source;
- received timestamp;
- posted timestamp;
- optional external/human references and note;
- optional explicit allocation proposal.

Behavior:

1. validate idempotency by `externalReference` when present;
2. create the immutable payment;
3. use explicit allocations if supplied, otherwise generate oldest-first proposal;
4. validate unit consistency and current outstanding amounts;
5. persist payment and allocations in one Doctrine transaction;
6. return the posted payment plus applied/unallocated totals.

If any validation fails, nothing is posted.

## Idempotency and concurrency

- `externalReference` has a database unique constraint when non-null.
- `(payment_id, charge_id)` has a unique constraint.
- posting uses one transaction.
- outstanding validation is repeated inside the transaction before persist/flush.
- database constraints remain the final protection against duplicate posting races.

No normal control flow depends on catching a uniqueness violation for routine repeat submissions; the service performs an application-level idempotency check first.

## Reversal

A reversal never edits the original payment or allocations.

`PaymentReversalService` creates a compensating financial record that neutralizes the original payment's effect and marks the relationship between original and reversal.

Rules:

- only a posted, non-reversed payment may be reversed;
- a payment may be reversed only once;
- reversal amount must exactly match the original payment amount;
- reversal effective allocation impact mirrors the original allocations;
- reversal reason is required;
- reversal is posted transactionally.

The exact UI for initiating reversal belongs to a later management workflow slice; this slice implements the domain/service behavior and tests.

## Access boundary

This slice is backend finance infrastructure only.

Later UI rules will be:

- `ROLE_MANAGER` and `ROLE_CASHIER` may post payments;
- `ROLE_MANAGER` may reverse payments;
- residents may see only payments and allocations belonging to units they are authorized to view;
- aggregate/common financial transparency never exposes another resident's personal balance.

## Migration

Add tables:

- `payment`;
- `payment_allocation`.

Add indexes for:

- unit + received date;
- external reference;
- payment allocation by payment;
- payment allocation by charge.

Schema must remain synchronized with Doctrine metadata and pass the existing MariaDB 10.11 migrate → validate → rollback → migrate → validate CI gate.

## Testing

Tests must prove:

- payment invariants and UTC normalization;
- unique/idempotent external references;
- default oldest-first allocation;
- partial payment;
- one payment covering multiple charges;
- one charge settled by multiple payments;
- explicit manager allocation override before posting;
- rejection of cross-unit allocations;
- rejection of over-allocation against payment or charge;
- unapplied remainder preservation;
- posting transaction rollback on invalid allocation;
- duplicate external reference returns the existing posted operation rather than posting twice;
- reversal creates compensating ledger effect and original records remain unchanged;
- reversal may occur only once;
- Doctrine mapping and MariaDB migration round-trip;
- PHPStan remains clean.

## Follow-up slices

After this slice:

1. bank transaction import and reconciliation;
2. resident/manager payment UI, balance summaries and receipts;
3. payment references and QR-assisted bank transfer;
4. formal adjustments/reversals for charge corrections;
5. expenses and fund cash/bank reporting.
