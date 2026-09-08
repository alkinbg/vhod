# Bank Import and Reconciliation Design

## Scope

This slice extends the existing immutable payment ledger with bank-account statement import and reconciliation for the single private condominium entrance.

The goal is practical: the manager/cashier can import a statement, avoid duplicate rows, see which incoming transfers are already understood, automatically reconcile only high-confidence transactions, and leave ambiguous transactions in a manual queue.

This slice does **not** implement Open Banking/API credentials, PSD2 account aggregation, card processing, payment initiation, bank login automation, expenses, or resident-facing payment UI.

## Product boundary

- Vhod remains a private system for one entrance.
- The application never acts as a wallet and never holds funds.
- Money continues to move directly through the condominium bank account.
- Bank data is operational/financial data and is visible only to authorised management roles.
- No bank credentials are stored.

## Input formats

The import domain is format-neutral.

The first concrete parser is ISO 20022 `camt.053` (`BankToCustomerStatement`) because it is a standard statement format supported by Bulgarian banking channels and can be added without coupling the domain to one bank.

`MT940` and bank-specific CSV adapters are follow-up parsers over the same normalized transaction model. They must not introduce bank-specific logic into reconciliation services.

XML parsing must disable external entity/network resolution and reject malformed or unsupported documents cleanly.

## BankAccount

`BankAccount` identifies a condominium bank account whose statements may be imported.

Fields:

- `name` — manager-facing label, e.g. “Основна сметка”;
- `iban` — normalized uppercase IBAN without spaces;
- `currency` — `EUR` in this system;
- `active` — whether new imports are allowed.

Rules:

- IBAN is required and unique;
- IBAN normalization is deterministic;
- this slice supports EUR accounts only;
- deactivation is preferred over deletion once financial history exists;
- no online-banking credentials or secrets belong on this entity.

## BankStatementImport

`BankStatementImport` is an immutable record of one imported statement payload.

Fields:

- `bankAccount`;
- `format` — initially `CAMT053`;
- `sourceFilename` — optional original filename for audit convenience;
- `contentHash` — SHA-256 of the exact imported bytes;
- `statementReference` — statement id from the source format when available;
- `periodFrom` / `periodTo` — statement period when available;
- `importedAt` — UTC timestamp;
- `transactionCount`.

Rules:

- `(bank_account_id, content_hash)` is unique;
- re-importing the exact same file returns the existing import result and creates no duplicate transactions;
- the original uploaded bytes are not required to be stored in this slice;
- filename is metadata, never an idempotency key.

## BankTransaction

`BankTransaction` is an immutable normalized bank-ledger row.

Fields:

- `bankAccount`;
- `statementImport`;
- `bankTransactionId` — bank/statement transaction id when supplied;
- `entryReference` — entry/account-servicer reference when supplied;
- `endToEndId` — payment end-to-end id when supplied;
- `bookingDate`;
- `valueDate` — optional;
- `amountCents` — signed EUR cents: positive credit/incoming, negative debit/outgoing;
- `counterpartyName` — optional;
- `counterpartyIban` — optional normalized IBAN;
- `remittanceInformation` — optional payment details/reference text;
- `fingerprint` — deterministic SHA-256 fallback identity derived from normalized source fields.

Rules:

- zero-value transactions are rejected;
- only EUR transactions are accepted in this slice;
- dates and text are normalized before fingerprinting;
- imported rows are immutable;
- a bank transaction may be reconciled at most once;
- outgoing transactions are imported and preserved but are not converted into `Payment` records; they will be useful when the Expense slice arrives.

### Transaction identity

A bank-provided stable transaction identifier is preferred when available, but import idempotency must not depend on every bank populating one field consistently.

Each normalized row therefore has a deterministic fingerprint. The fingerprint includes at minimum:

- bank account IBAN;
- signed amount;
- currency;
- booking date;
- value date when present;
- bank transaction id / entry reference / end-to-end id when present;
- normalized counterparty IBAN;
- normalized remittance information.

A unique constraint on `(bank_account_id, fingerprint)` prevents duplicate ledger rows across overlapping statement imports.

## BankCounterpartyMapping

`BankCounterpartyMapping` is an explicit manager-confirmed mapping from a payer bank account to a condominium `Unit`.

Fields:

- `counterpartyIban`;
- `unit`;
- `active`;
- `createdAt`.

Rules:

- mapping is never inferred silently from a name;
- a counterparty IBAN may have at most one active unit mapping;
- the manager may choose not to create a mapping when one bank account pays for multiple units;
- deactivation preserves historical reconciliation behaviour.

The first manual reconciliation may optionally create this mapping. Future incoming transactions from that exact IBAN can then be high-confidence candidates for automatic reconciliation.

## PaymentReconciliation

`PaymentReconciliation` is a separate immutable one-to-one link between one `BankTransaction` and one existing `Payment`.

Fields:

- `bankTransaction`;
- `payment`;
- `method` — `AUTOMATIC` or `MANUAL`;
- `reconciledAt` — UTC;
- optional manager note.

Rules:

- one bank transaction may be reconciled once;
- one payment may be linked to at most one bank transaction in this slice;
- transaction amount must be positive;
- payment source must be `BANK_TRANSFER`;
- payment amount must equal transaction amount exactly;
- reconciliation never mutates the original `Payment` row;
- a reversed payment does not make the bank transaction disappear; the reconciliation remains historical evidence and the manager can see that the linked payment was reversed.

## Reconciliation workflow

### Import

1. manager selects the target `BankAccount`;
2. system parses the `camt.053` payload into normalized records without writing financial rows;
3. parser verifies statement account IBAN matches the selected `BankAccount`;
4. import service calculates the file hash and row fingerprints;
5. in one transaction it creates the immutable import record and any new bank transactions;
6. duplicate file or overlapping duplicate rows create no duplicate transactions;
7. imported incoming unmatched rows enter the reconciliation queue.

### Automatic reconciliation

Automatic reconciliation is deliberately conservative.

An incoming transaction may be auto-reconciled only when:

- it is not already reconciled;
- amount is positive;
- an active `BankCounterpartyMapping` exists for the exact normalized counterparty IBAN;
- the mapped unit still exists/active;
- no conflicting reconciliation/payment already exists for the transaction;
- posting the payment satisfies existing `PaymentPostingService` invariants.

When these conditions are met, the service:

1. posts a `BANK_TRANSFER` Payment to the mapped Unit;
2. uses a deterministic external reference derived from the bank account and bank transaction fingerprint;
3. lets the existing `PaymentPostingService` allocate the payment oldest-debt-first;
4. records one immutable `PaymentReconciliation(method=AUTOMATIC)`.

No fuzzy name matching, approximate amount matching or “most likely apartment” logic is allowed to post money automatically.

### Manual reconciliation

For an unmatched incoming transaction the manager/cashier may select a Unit.

The service then:

1. validates that the transaction is incoming and unreconciled;
2. posts the bank-transfer payment through `PaymentPostingService`;
3. records `PaymentReconciliation(method=MANUAL)`;
4. optionally creates/activates a `BankCounterpartyMapping` only when explicitly requested and a counterparty IBAN is present.

If the transaction is ambiguous, it remains in the queue without financial effect.

## Existing payment compatibility

The reconciliation model must also support linking an imported transaction to an already-posted `BANK_TRANSFER` Payment when all of these match:

- same amount;
- same effective Unit chosen/known;
- payment is not already reconciled to another bank transaction;
- transaction is not already reconciled.

This supports the real workflow where the cashier records a transfer before importing the statement.

The service must never silently link by amount alone.

## Transaction boundaries

- Import batch persistence is atomic.
- Manual/automatic payment creation plus reconciliation link is atomic from the caller’s perspective.
- Existing `PaymentPostingService` remains the only service that creates `Payment` + allocations.
- Reconciliation services do not duplicate payment allocation rules.
- Database unique constraints are the final guard against races.

## Access boundary

Backend services are the primary scope of this slice. A minimal management-facing queue may be added only if needed to prove the workflow; resident UI is not part of this PR.

Later UI permissions:

- `ROLE_MANAGER` and `ROLE_CASHIER`: import statements and reconcile incoming transfers;
- `ROLE_MANAGER`: manage bank accounts and payer mappings;
- `ROLE_CONTROLLER`: read bank/reconciliation history;
- residents: no raw bank-statement or other residents’ payer details.

## Schema

Add:

- `bank_account`;
- `bank_statement_import`;
- `bank_transaction`;
- `bank_counterparty_mapping`;
- `payment_reconciliation`.

Use `RESTRICT`/`NO ACTION` financial foreign keys; never cascade-delete bank/payment history.

Key uniqueness:

- `bank_account.iban`;
- `(bank_statement_import.bank_account_id, content_hash)`;
- `(bank_transaction.bank_account_id, fingerprint)`;
- active counterparty mapping per IBAN enforced by service/domain invariant where partial unique indexes are not portable to MariaDB;
- `payment_reconciliation.bank_transaction_id`;
- `payment_reconciliation.payment_id`.

## Testing

Tests must prove:

- IBAN normalization and validation boundary;
- immutable statement/import records;
- signed integer-cent transaction semantics;
- duplicate file import is idempotent;
- overlapping statements do not duplicate bank transactions;
- transaction fingerprint is deterministic;
- CAMT.053 account mismatch is rejected;
- malformed/unsupported XML is rejected without partial writes;
- CAMT.053 credit and debit entries parse correctly;
- outgoing transactions are imported but never posted as Payments;
- exact counterparty mapping can produce an automatic match;
- unknown/ambiguous payer remains unmatched;
- manual reconciliation posts exactly one bank Payment;
- repeated reconciliation is idempotent/rejected as appropriate and never duplicates money;
- existing bank Payment may be linked explicitly without creating another Payment;
- reconciliation never links by amount alone;
- reversed linked payment remains auditable;
- MariaDB migrate → validate → rollback → migrate → validate remains green;
- PHPStan remains clean.

## Follow-up

After this slice:

1. MT940 parser;
2. bank-specific CSV adapters only where needed by the actual condominium bank;
3. management reconciliation UI and statement history polish;
4. stable payment-reference/QR workflow for residents;
5. expenses and outgoing-bank-transaction reconciliation.
