# Bank Import and Reconciliation Design

## Scope

This slice extends the existing immutable payment ledger with bank-account statement import and safe reconciliation for one private condominium entrance.

The practical workflow is: import a bank statement, preserve every normalized bank row, deduplicate repeated/overlapping reports, automatically reconcile only exact known payer IBANs, and leave everything else unmatched for explicit manager/cashier action.

This slice does **not** implement Open Banking, bank credentials, PSD2 account aggregation, card processing, payment initiation, bank-login automation, expenses or resident-facing banking UI.

## Product boundary

- one private entrance only;
- the application never acts as a wallet and never holds funds;
- money moves directly through the condominium bank account;
- raw bank data is management/controller data, not resident-visible data;
- no bank credentials or secrets are stored.

## Input model

The bank domain is parser-neutral. The first adapter is ISO 20022 `camt.053` (`BankToCustomerStatement`). Future MT940 or bank-specific CSV adapters must produce the same normalized statement/transaction value objects rather than introduce bank-specific reconciliation logic.

The CAMT parser:

- rejects DOCTYPE declarations before XML parsing;
- uses `LIBXML_NONET`;
- never enables external entity substitution (`LIBXML_NOENT`);
- rejects malformed documents and unsupported currency cleanly;
- uses integer-cent decimal parsing rather than float multiplication.

## BankAccount

`BankAccount` identifies a condominium bank account whose statements may be imported.

Fields:

- manager-facing name;
- normalized IBAN;
- currency (`EUR`);
- active flag.

Rules:

- IBAN is normalized and MOD-97 validated;
- IBAN is unique;
- only EUR accounts are supported in this slice;
- inactive accounts reject new imports;
- deactivation is preferred over deletion after financial history exists.

## BankStatementImport

Immutable audit record for one imported payload.

Fields:

- bank account;
- format (`CAMT053` initially);
- exact-payload SHA-256 content hash;
- optional source filename;
- optional statement reference;
- optional statement period;
- UTC import timestamp;
- source transaction count.

Rules:

- `(bank_account_id, content_hash)` is unique;
- exact same-file re-import returns the existing import and creates no duplicate transactions;
- filename is metadata only, never identity;
- original uploaded bytes do not need to be stored in this slice.

## BankTransaction

Immutable normalized bank-ledger row.

Fields:

- bank account and statement import;
- deterministic fingerprint;
- signed EUR cents;
- booking date and optional value date;
- optional bank transaction id;
- optional `entryReference`, which for CAMT.053 contains parsed `AcctSvcrRef`;
- optional end-to-end id;
- optional counterparty name/IBAN;
- optional remittance information.

Positive amounts are incoming credits. Negative amounts are outgoing debits. Zero is invalid.

### Transaction identity

Overlapping bank reports must not create duplicate ledger rows. Identity is therefore deliberately tiered.

#### Preferred identity: Account Servicer Reference

When a usable CAMT `AcctSvcrRef` is present, it is treated as the stable bank-assigned entry identity. The fingerprint is derived from:

- selected condominium bank account IBAN;
- normalized `AcctSvcrRef`.

The parser exposes this value as `entryReference` in the normalized/domain transaction model.

Placeholder references (`NOTPROVIDED`, `NONREF`, `N/A`, `NONE`, `UNKNOWN`, etc.) are not accepted as stable identity.

#### Stable fallback

When no usable account-servicer reference exists, the fallback fingerprint is derived from stable semantic fields:

- condominium account IBAN;
- signed amount cents;
- booking date;
- normalized counterparty IBAN when present;
- normalized remittance information when present.

`TxId`, `EndToEndId` and `valueDate` are preserved as audit metadata but intentionally excluded from fallback identity because they may appear, disappear or change between overlapping report windows.

The fallback is intentionally safety-biased: in the rare case of two truly identical same-day payments without a stable bank reference, treating them as a potential duplicate is safer than silently posting money twice. Such cases stay reviewable at the statement level.

A database unique constraint on `(bank_account_id, fingerprint)` is the final race-condition guard.

## Idempotent import workflow

1. require a persisted, active `BankAccount`;
2. hash the exact payload;
3. parse to normalized values before persistence;
4. require statement account IBAN to match the selected account;
5. return existing import immediately for identical `(account, contentHash)`;
6. calculate each transaction fingerprint;
7. skip already-existing fingerprints, including overlaps from another file;
8. persist import record plus genuinely new bank rows atomically.

The overlap integration fixture deliberately changes optional TxId/EndToEndId values for the same entry while retaining its `AcctSvcrRef`, proving stable deduplication across reports.

## BankCounterpartyMapping

Explicit manager-confirmed mapping from a payer IBAN to a condominium `Unit`.

Fields:

- normalized counterparty IBAN;
- unit;
- active marker;
- UTC creation timestamp.

Rules:

- never infer a mapping silently from a person name;
- only active/persisted units may receive new mappings;
- assigning the already-active same IBAN/unit pair is idempotent;
- assigning an active IBAN to another unit is rejected;
- deactivation preserves history;
- multiple historical mappings for the same IBAN are allowed.

### DB-level current-mapping invariant

The active marker is nullable:

- `TRUE` = current mapping;
- `NULL` = historical inactive mapping.

A unique constraint on `(counterparty_iban, active)` permits multiple historical NULL rows while guaranteeing at most one `(IBAN, TRUE)` row. This remains safe when concurrent/direct writes bypass the application-level pre-check.

## PaymentReconciliation

Separate immutable one-to-one link between one `BankTransaction` and one existing `Payment`.

Fields:

- bank transaction;
- payment;
- method (`AUTOMATIC` or `MANUAL`);
- UTC reconciliation timestamp;
- optional manager note.

Rules:

- transaction must be incoming;
- payment source must be `BANK_TRANSFER`;
- amounts must match exactly;
- one bank transaction may be reconciled once;
- one payment may be linked to at most one bank transaction;
- reconciliation never mutates the original `Payment`;
- reversing the linked payment does not erase reconciliation history.

Database uniqueness on transaction and payment IDs provides the final race guard.

## Automatic reconciliation

Automatic reconciliation is deliberately conservative. It is permitted only when:

- the bank transaction is persisted, incoming and unreconciled;
- it has a counterparty IBAN;
- exactly one active mapping exists for that exact normalized IBAN;
- the mapped Unit remains persisted and active.

Then the service:

1. delegates payment creation/allocation to `PaymentPostingService`;
2. creates a `BANK_TRANSFER` payment for exactly the bank amount;
3. uses deterministic external reference `bank:<fingerprint>`;
4. records one immutable `PaymentReconciliation(AUTOMATIC)`.

There is no fuzzy name matching, nearest amount matching or fallback to another resident/payment.

## Manual reconciliation

For an unmatched incoming row, manager/cashier may select a Unit explicitly. The same existing `PaymentPostingService` creates the bank payment and the service records `PaymentReconciliation(MANUAL)`.

If a bank transfer was entered manually before statement import, an explicitly supplied existing `BANK_TRANSFER` Payment may be linked when the reconciliation invariants match. The service never finds an existing payment by amount alone.

## Transaction boundaries

- statement import is atomic;
- new Payment + allocations + reconciliation link are atomic from the caller's perspective;
- `BankReconciliationService` wraps the existing `PaymentPostingService` in an outer Doctrine transaction;
- integration tests force reconciliation persistence failure after the nested payment flush and prove the Payment is rolled back too;
- database unique constraints remain the final concurrent-write backstop.

## Schema

Tables added:

- `bank_account`;
- `bank_statement_import`;
- `bank_transaction`;
- `bank_counterparty_mapping`;
- `payment_reconciliation`.

Key uniqueness:

- `bank_account.iban`;
- `(bank_statement_import.bank_account_id, content_hash)`;
- `(bank_transaction.bank_account_id, fingerprint)`;
- `(bank_counterparty_mapping.counterparty_iban, active)` with TRUE/current and NULL/history semantics;
- `payment_reconciliation.bank_transaction_id`;
- `payment_reconciliation.payment_id`.

All financial/history foreign keys use `RESTRICT`/`NO ACTION`; no financial history is cascade-deleted.

## Access boundary

This PR implements backend/domain services. Later management UI permissions should be:

- `ROLE_MANAGER`, `ROLE_CASHIER`: statement import and reconciliation;
- `ROLE_MANAGER`: bank account and payer-mapping management;
- `ROLE_CONTROLLER`: read bank/reconciliation history;
- residents: no raw statement data and no other residents' payer information.

## Verification requirements

Tests and CI must prove:

- IBAN normalization/MOD-97 validation;
- synthetic bank data only in repository fixtures/tests;
- immutable statement/transaction records;
- signed integer-cent semantics;
- exact file idempotency;
- stable overlap idempotency even if optional TxId/E2E data changes;
- account mismatch and inactive account create no rows;
- secure XML behavior including explicit DOCTYPE rejection;
- debit rows never produce Payments;
- exact payer mapping enables automatic reconciliation;
- unknown/ambiguous payer stays unmatched;
- manual reconciliation creates exactly one bank Payment;
- repeated reconciliation never duplicates money;
- explicitly supplied existing Payment can be linked without creating a second Payment;
- amount-only matching never occurs;
- forced reconciliation failure rolls back nested Payment posting;
- payment reversal preserves historical reconciliation;
- database prevents two active mappings for the same payer IBAN;
- multiple inactive mapping rows remain valid history;
- MariaDB migrate → validate → rollback → migrate → validate remains green;
- PHPStan remains clean.

## Follow-up

After this slice, useful follow-ups are:

1. management reconciliation queue/history UI;
2. resident payment reference/QR workflow;
3. MT940 or actual-bank CSV adapter only if the entrance's bank requires it;
4. expenses and outgoing-bank-transaction reconciliation.
