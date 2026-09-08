# Bank Reconciliation Implementation Plan

**Goal:** Import ISO 20022 CAMT.053 bank statements idempotently and reconcile incoming transfers to the existing immutable payment ledger without unsafe automatic guessing.

**Architecture:** Keep parsing, bank-ledger persistence and payment reconciliation separate. CAMT.053 is normalized first; immutable bank records are persisted second; payment creation remains exclusively in `PaymentPostingService`; reconciliation is an immutable audit link.

**Tech stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7, MariaDB 10.11, DOM/libxml, PHPUnit 12 and PHPStan 2.

**Spec:** `docs/superpowers/specs/2026-09-08-bank-reconciliation-design.md`

## Global constraints

- Single private condominium entrance; no SaaS or tenant abstractions.
- EUR only; money is integer cents.
- No bank credentials, PSD2 aggregation, payment initiation or online-banking automation.
- Imported bank rows and reconciliation records are immutable.
- Payment creation/allocation remains inside `PaymentPostingService`.
- Automatic reconciliation requires an exact active payer-IBAN mapping.
- No fuzzy name matching and no amount-only matching.
- Outgoing bank rows are preserved but never converted to `Payment` in this slice.
- Financial foreign keys use `RESTRICT`/`NO ACTION`.
- Every implementation slice is test-first.

## Task 1 — Bank ledger domain and IBAN validation

**Files**

- `src/Value/Iban.php`
- `src/Enum/BankStatementFormat.php`
- `src/Entity/BankAccount.php`
- `src/Entity/BankStatementImport.php`
- `src/Entity/BankTransaction.php`
- `src/Value/BankTransactionFingerprint.php`
- `tests/Value/IbanTest.php`
- `tests/Entity/BankAccountTest.php`
- `tests/Entity/BankStatementImportTest.php`
- `tests/Entity/BankTransactionTest.php`
- `tests/Value/BankTransactionFingerprintTest.php`

**Completed behavior**

- `Iban::normalize()` removes whitespace, uppercases, validates syntax and MOD-97.
- `BankAccount` stores a normalized unique EUR IBAN and supports deactivation rather than deletion.
- statement imports keep immutable audit metadata and SHA-256 content identity.
- bank transactions use signed cents: positive incoming, negative outgoing, never zero.
- all test IBANs are synthetic and intentionally use `TEST`, `FAKE`, `DEMO` or `MOCK` bank codes.

### Transaction fingerprint strategy

Identity is deliberately tiered because optional CAMT references may change between overlapping reports.

1. If a usable `AcctSvcrRef` is present, hash only the selected account IBAN plus that normalized account-servicer reference. `AcctSvcrRef` is parsed into the normalized transaction `entryReference` field.
2. Placeholder references such as `NOTPROVIDED`, `NONREF`, `N/A`, `NONE` or `UNKNOWN` are not treated as stable identifiers.
3. Otherwise use a safety-biased fallback based on normalized account IBAN, signed amount, booking date, counterparty IBAN and normalized remittance text.
4. `TxId`, `EndToEndId` and `valueDate` are retained as audit metadata but intentionally excluded from fallback identity because banks may populate or change them across report windows.

The database unique constraint `(bank_account_id, fingerprint)` remains the race-condition backstop.

## Task 2 — Secure CAMT.053 parser

**Files**

- `src/Value/NormalizedBankStatement.php`
- `src/Value/NormalizedBankTransaction.php`
- `src/Service/Camt053StatementParser.php`
- `tests/Fixtures/bank/camt053-basic.xml`
- `tests/Fixtures/bank/camt053-overlap.xml`
- `tests/Service/Camt053StatementParserTest.php`

**Completed behavior**

- parse CAMT.053 with `DOMDocument`/`DOMXPath` and namespace-independent XPath selectors;
- reject DOCTYPE before parsing;
- use `LIBXML_NONET`; never enable `LIBXML_NOENT`;
- reject malformed XML, missing statement IBAN and non-EUR entries;
- convert decimal EUR strings to integer cents without float arithmetic;
- map `CRDT` to positive and `DBIT` to negative;
- parse `AcctSvcrRef` into `entryReference` and keep optional transaction identifiers as audit metadata.

## Task 3 — Idempotent statement import and MariaDB schema

**Files**

- `src/Value/BankImportResult.php`
- `src/Service/BankStatementImportService.php`
- `migrations/Version20260908110000.php`
- `tests/Service/BankStatementImportServiceTest.php`

**Completed behavior**

- reject transient or inactive bank accounts;
- parse before persistence and require statement account IBAN to match the selected bank account;
- exact file re-import is idempotent by `(bank_account_id, content_hash)`;
- overlapping files skip existing transaction fingerprints and keep only genuinely new rows;
- the overlap fixture deliberately changes `TxId` and `EndToEndId` while keeping the same `AcctSvcrRef`, proving that duplicate detection does not depend on unstable optional references;
- import batch persistence is transactional;
- database unique constraints backstop content-hash and transaction-fingerprint races.

## Task 4 — Payer mappings and reconciliation ledger

**Files**

- `src/Enum/ReconciliationMethod.php`
- `src/Entity/BankCounterpartyMapping.php`
- `src/Entity/PaymentReconciliation.php`
- `src/Service/BankCounterpartyMappingService.php`
- `tests/Entity/BankCounterpartyMappingTest.php`
- `tests/Entity/PaymentReconciliationTest.php`
- `tests/Service/BankCounterpartyMappingServiceTest.php`

**Completed behavior**

- payer IBAN mappings are explicit; names are never silently inferred;
- assigning the same active IBAN to the same unit is idempotent;
- assigning an active IBAN to a different unit is rejected;
- deactivation preserves history;
- inactive history may contain multiple rows for the same payer IBAN;
- the database itself guarantees at most one active mapping per payer IBAN.

### Database invariant for active mappings

`BankCounterpartyMapping.active` is a nullable boolean marker:

- `TRUE` = current active mapping;
- `NULL` = historical inactive mapping.

The unique constraint `(counterparty_iban, active)` therefore permits multiple historical `NULL` rows while preventing a second `(IBAN, TRUE)` row, including concurrent/direct writes that bypass the application service.

`PaymentReconciliation` is a separate immutable one-to-one audit record. It requires an incoming bank transaction, a `BANK_TRANSFER` payment and exact amount equality. Unique database constraints prevent transaction or payment reuse.

## Task 5 — Manual, automatic and existing-payment reconciliation

**Files**

- `src/Service/BankReconciliationService.php`
- `tests/Service/BankReconciliationServiceTest.php`

**Completed behavior**

- manual reconciliation posts exactly one bank-transfer payment through `PaymentPostingService`;
- automatic reconciliation requires one exact active payer-IBAN mapping;
- automatic reconciliation returns unmatched rather than guessing;
- amount-only matching is explicitly tested and rejected;
- debit/outgoing rows never become payments;
- duplicate reconciliation cannot duplicate money;
- an explicitly supplied matching existing bank payment can be linked without creating another payment;
- a payment cannot be linked to a second bank transaction;
- payment creation and reconciliation link are wrapped in one outer transaction;
- a forced reconciliation persistence failure proves the nested payment write is rolled back;
- reversing a payment does not erase the historical reconciliation link.

## Task 6 — Final quality and security gate

Before merge, verify all of the following on the final PR head:

```bash
composer validate --strict
php bin/console lint:container
php bin/console doctrine:schema:validate --skip-sync --env=test
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

GitHub CI additionally runs MariaDB 10.11:

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
php bin/console doctrine:migrations:migrate prev --no-interaction --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
```

If schema validation fails, CI prints `doctrine:schema:update --dump-sql` diagnostics and still fails the gate.

Final review checklist:

- [x] No bank credentials or payment initiation.
- [x] No fuzzy or amount-only automatic matching.
- [x] No debit-to-Payment conversion.
- [x] No mutation of existing Payment rows during reconciliation.
- [x] No cascade deletion of financial history.
- [x] Secure XML parser with DOCTYPE rejection and `LIBXML_NONET`.
- [x] Stable transaction identity across overlapping CAMT reports.
- [x] DB-level guard for one active payer mapping per IBAN.
- [x] Synthetic bank data only in tests and fixtures.
- [ ] Final PR-head CI green.
- [ ] Merge PR after the final green gate.
