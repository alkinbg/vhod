# Bank Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import CAMT.053 bank statements idempotently and reconcile incoming bank transactions to the existing immutable payment ledger without unsafe automatic guessing.

**Architecture:** Keep the bank layer format-neutral after parsing. CAMT.053 produces normalized value objects; the import service persists immutable statement/transaction records; reconciliation links bank rows to Payments through a separate immutable entity and delegates all payment creation/allocation to the existing `PaymentPostingService`.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7, MariaDB 10.11, DOM/libxml, PHPUnit 12, PHPStan 2.

**Spec:** `docs/superpowers/specs/2026-09-08-bank-reconciliation-design.md`

## Global Constraints

- Single private entrance only; no SaaS/tenant abstractions.
- EUR only, integer cents only; no float money persistence/arithmetic.
- No bank credentials, Open Banking, PSD2 aggregation or payment initiation.
- Imported bank rows and reconciliation records are immutable.
- Payment creation remains exclusively inside `PaymentPostingService`.
- Automatic reconciliation requires an exact active counterparty-IBAN mapping; no fuzzy name/amount matching.
- Outgoing bank rows are stored but never converted to `Payment` in this slice.
- Financial foreign keys use `RESTRICT`/`NO ACTION`, never cascade deletion.
- Every implementation task is test-first.

---

### Task 1: Bank ledger domain records

**Files:**
- Create: `src/Enum/BankStatementFormat.php`
- Create: `src/Entity/BankAccount.php`
- Create: `src/Entity/BankStatementImport.php`
- Create: `src/Entity/BankTransaction.php`
- Create: `src/Value/BankTransactionFingerprint.php`
- Test: `tests/Entity/BankAccountTest.php`
- Test: `tests/Entity/BankStatementImportTest.php`
- Test: `tests/Entity/BankTransactionTest.php`
- Test: `tests/Value/BankTransactionFingerprintTest.php`

**Interfaces:**
- `BankAccount::create(string $name, string $iban): self`
- `BankAccount::deactivate(): void`
- `BankStatementImport::record(BankAccount $bankAccount, BankStatementFormat $format, string $contentHash, DateTimeImmutable $importedAt, int $transactionCount, ?string $sourceFilename = null, ?string $statementReference = null, ?DateTimeImmutable $periodFrom = null, ?DateTimeImmutable $periodTo = null): self`
- `BankTransaction::record(BankAccount $bankAccount, BankStatementImport $statementImport, string $fingerprint, int $amountCents, DateTimeImmutable $bookingDate, ?DateTimeImmutable $valueDate = null, ?string $bankTransactionId = null, ?string $entryReference = null, ?string $endToEndId = null, ?string $counterpartyName = null, ?string $counterpartyIban = null, ?string $remittanceInformation = null): self`
- `BankTransactionFingerprint::fromFields(string $accountIban, int $amountCents, DateTimeImmutable $bookingDate, ?DateTimeImmutable $valueDate, ?string $bankTransactionId, ?string $entryReference, ?string $endToEndId, ?string $counterpartyIban, ?string $remittanceInformation): string`

- [ ] **Step 1: Write failing entity/value tests**

Cover IBAN normalization, rejection of blank/obviously invalid IBANs, EUR-only semantics, positive/negative but never zero bank amounts, UTC/date normalization, immutable snapshots and deterministic SHA-256 fingerprints.

```php
$account = BankAccount::create('Основна сметка', 'bg80 bnbg 9661 1020 3456 78');
self::assertSame('BG80BNBG96611020345678', $account->getIban());

$fingerprintA = BankTransactionFingerprint::fromFields(/* normalized test fields */);
$fingerprintB = BankTransactionFingerprint::fromFields(/* same semantic fields, whitespace/case variations */);
self::assertSame($fingerprintA, $fingerprintB);
self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprintA);
```

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Entity/BankAccountTest.php tests/Entity/BankStatementImportTest.php tests/Entity/BankTransactionTest.php tests/Value/BankTransactionFingerprintTest.php
```

Expected: failures caused only by missing bank-domain classes.

- [ ] **Step 3: Implement minimal domain classes**

Use a shared private normalization rule per class rather than adding a generic utility layer. Validate IBAN syntax using normalized uppercase alphanumeric form plus MOD-97; require Bulgarian/EU-style IBAN length rules only through the standard checksum, not a hard-coded Bulgarian bank list.

Fingerprint canonical form must use explicit separators/labels before SHA-256 so concatenation cannot collide accidentally.

- [ ] **Step 4: Run focused tests, PHPUnit and PHPStan**

```bash
vendor/bin/phpunit tests/Entity/BankAccountTest.php tests/Entity/BankStatementImportTest.php tests/Entity/BankTransactionTest.php tests/Value/BankTransactionFingerprintTest.php
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

- [ ] **Step 5: Commit green domain foundation**

```bash
git add src/Enum/BankStatementFormat.php src/Entity/BankAccount.php src/Entity/BankStatementImport.php src/Entity/BankTransaction.php src/Value/BankTransactionFingerprint.php tests/
git commit -m "feat: add immutable bank ledger domain"
```

### Task 2: CAMT.053 parser and normalized input model

**Files:**
- Create: `src/Value/NormalizedBankStatement.php`
- Create: `src/Value/NormalizedBankTransaction.php`
- Create: `src/Service/Camt053StatementParser.php`
- Create: `tests/Fixtures/bank/camt053-basic.xml`
- Test: `tests/Service/Camt053StatementParserTest.php`

**Interfaces:**
- `NormalizedBankStatement` exposes account IBAN, optional statement reference/period and `list<NormalizedBankTransaction>`.
- `NormalizedBankTransaction` exposes signed amount cents, currency, booking/value dates, optional identifiers/counterparty/remittance fields.
- `Camt053StatementParser::parse(string $xml): NormalizedBankStatement`

- [ ] **Step 1: Add a minimal realistic CAMT.053 fixture and failing parser tests**

The fixture contains one `CRDT` entry and one `DBIT` entry, decimal EUR amounts, account IBAN, booking/value dates, entry reference, end-to-end id, counterparty data and remittance text.

```php
$statement = (new Camt053StatementParser())->parse($xml);
self::assertSame('BG80BNBG96611020345678', $statement->accountIban);
self::assertSame(2, count($statement->transactions));
self::assertSame(12550, $statement->transactions[0]->amountCents);
self::assertSame(-3200, $statement->transactions[1]->amountCents);
```

Also test malformed XML, unsupported/non-EUR currency and missing account IBAN.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Service/Camt053StatementParserTest.php
```

Expected: missing parser/value classes only.

- [ ] **Step 3: Implement secure parser**

Use `DOMDocument`/`DOMXPath` with `LIBXML_NONET | LIBXML_NOBLANKS`; never enable entity substitution (`LIBXML_NOENT`). Use namespace-independent `local-name()` XPath where CAMT namespace versions differ.

Convert decimal currency strings to integer cents using string parsing, not `(float)` multiplication. Reject more than two non-zero fractional digits for EUR.

Map `CdtDbtInd=CRDT` to positive and `DBIT` to negative. Prefer transaction-detail identifiers when present, while retaining entry-level references.

- [ ] **Step 4: Run parser tests + full quality gate**

```bash
vendor/bin/phpunit tests/Service/Camt053StatementParserTest.php
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

- [ ] **Step 5: Commit parser**

```bash
git add src/Value/NormalizedBankStatement.php src/Value/NormalizedBankTransaction.php src/Service/Camt053StatementParser.php tests/Fixtures/bank/camt053-basic.xml tests/Service/Camt053StatementParserTest.php
git commit -m "feat: parse CAMT.053 statements"
```

### Task 3: Idempotent statement import and MariaDB schema

**Files:**
- Create: `src/Value/BankImportResult.php`
- Create: `src/Service/BankStatementImportService.php`
- Create: `migrations/Version20260908110000.php`
- Test: `tests/Service/BankStatementImportServiceTest.php`

**Interfaces:**
- `BankStatementImportService::__construct(EntityManagerInterface $entityManager, Camt053StatementParser $parser)`
- `BankStatementImportService::importCamt053(BankAccount $bankAccount, string $xml, DateTimeImmutable $importedAt, ?string $sourceFilename = null): BankImportResult`
- `BankImportResult` exposes `BankStatementImport $statementImport`, `int $createdTransactions`, `int $duplicateTransactions`, `bool $existingImport`.

- [ ] **Step 1: Write failing integration tests**

Prove:

```php
$first = $service->importCamt053($account, $xml, $now, 'statement.xml');
$second = $service->importCamt053($account, $xml, $now, 'statement-again.xml');
self::assertSame(2, $first->createdTransactions);
self::assertTrue($second->existingImport);
self::assertCount(2, $entityManager->getRepository(BankTransaction::class)->findAll());
```

Also prove account-IBAN mismatch writes zero rows and overlapping statements skip already-fingerprinted transactions while importing genuinely new rows.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Service/BankStatementImportServiceTest.php
```

- [ ] **Step 3: Implement transactional import**

Compute `contentHash = hash('sha256', $xml)` before parsing persistence. Parse first, validate selected account and active state, then use one `EntityManagerInterface::wrapInTransaction()` for batch + transaction rows.

For each normalized row calculate `BankTransactionFingerprint::fromFields(...)`; check `(bankAccount, fingerprint)` before persist. Database unique constraints remain the race-condition backstop.

- [ ] **Step 4: Add migration**

Create `bank_account`, `bank_statement_import`, `bank_transaction` with `RESTRICT` foreign keys and uniqueness from the spec. Migration `down()` drops children first.

- [ ] **Step 5: Run MariaDB/Doctrine gate and full tests**

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
php bin/console doctrine:migrations:migrate prev --no-interaction --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

- [ ] **Step 6: Commit import foundation**

```bash
git add src/Value/BankImportResult.php src/Service/BankStatementImportService.php migrations/Version20260908110000.php tests/Service/BankStatementImportServiceTest.php
git commit -m "feat: import bank statements idempotently"
```

### Task 4: Counterparty mappings and reconciliation ledger

**Files:**
- Create: `src/Enum/ReconciliationMethod.php`
- Create: `src/Entity/BankCounterpartyMapping.php`
- Create: `src/Entity/PaymentReconciliation.php`
- Create: `src/Service/BankCounterpartyMappingService.php`
- Modify: `migrations/Version20260908110000.php`
- Test: `tests/Entity/BankCounterpartyMappingTest.php`
- Test: `tests/Entity/PaymentReconciliationTest.php`
- Test: `tests/Service/BankCounterpartyMappingServiceTest.php`

**Interfaces:**
- `BankCounterpartyMapping::create(string $counterpartyIban, Unit $unit, DateTimeImmutable $createdAt): self`
- `BankCounterpartyMapping::deactivate(): void`
- `BankCounterpartyMappingService::assign(string $counterpartyIban, Unit $unit, DateTimeImmutable $createdAt): BankCounterpartyMapping`
- `PaymentReconciliation::record(BankTransaction $bankTransaction, Payment $payment, ReconciliationMethod $method, DateTimeImmutable $reconciledAt, ?string $note = null): self`

- [ ] **Step 1: Write failing tests for mapping/reconciliation invariants**

Reject missing/invalid payer IBAN, conflicting active mapping to a different Unit, debit transaction reconciliation, non-bank Payment source, amount mismatch and payment/transaction reuse.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Entity/BankCounterpartyMappingTest.php tests/Entity/PaymentReconciliationTest.php tests/Service/BankCounterpartyMappingServiceTest.php
```

- [ ] **Step 3: Implement entities/service**

`PaymentReconciliation::record()` performs local invariants (incoming amount, bank source, exact amount). Cross-row uniqueness is enforced in reconciliation service/database.

`BankCounterpartyMappingService::assign()` returns the existing active mapping for the same Unit, rejects a different active Unit, and may create a new mapping after a prior mapping was explicitly deactivated.

- [ ] **Step 4: Extend migration**

Add `bank_counterparty_mapping` and `payment_reconciliation`; unique indexes on reconciliation transaction/payment; `RESTRICT` all historical FKs.

- [ ] **Step 5: Full verification and commit**

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
php bin/console doctrine:schema:validate --env=test
```

```bash
git add src/Enum/ReconciliationMethod.php src/Entity/BankCounterpartyMapping.php src/Entity/PaymentReconciliation.php src/Service/BankCounterpartyMappingService.php migrations/Version20260908110000.php tests/
git commit -m "feat: add bank reconciliation ledger"
```

### Task 5: Manual, automatic and existing-payment reconciliation

**Files:**
- Create: `src/Service/BankReconciliationService.php`
- Test: `tests/Service/BankReconciliationServiceTest.php`

**Interfaces:**
- `BankReconciliationService::__construct(EntityManagerInterface $entityManager, PaymentPostingService $paymentPostingService)`
- `BankReconciliationService::reconcileToUnit(BankTransaction $transaction, Unit $unit, DateTimeImmutable $reconciledAt, ?string $note = null): PaymentReconciliation`
- `BankReconciliationService::autoReconcile(BankTransaction $transaction, DateTimeImmutable $reconciledAt): ?PaymentReconciliation`
- `BankReconciliationService::linkExistingPayment(BankTransaction $transaction, Payment $payment, DateTimeImmutable $reconciledAt, ?string $note = null): PaymentReconciliation`

- [ ] **Step 1: Write failing workflow tests**

Manual workflow:

```php
$reconciliation = $service->reconcileToUnit($incoming, $unit, $now);
self::assertSame(PaymentSource::BANK_TRANSFER, $reconciliation->getPayment()->getSource());
self::assertSame($incoming->getAmountCents(), $reconciliation->getPayment()->getAmountCents());
self::assertSame('bank:'.$incoming->getFingerprint(), $reconciliation->getPayment()->getExternalReference());
```

Automatic workflow must return `null` when there is no exact active payer-IBAN mapping and must post exactly once when one exists.

Existing-payment workflow must link an explicitly supplied matching `BANK_TRANSFER` Payment and create no second Payment.

Also test debit rejection, duplicate reconciliation rejection, amount-only ambiguity, and transaction rollback if reconciliation persistence fails.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Service/BankReconciliationServiceTest.php
```

- [ ] **Step 3: Implement conservative reconciliation**

Use an outer `EntityManagerInterface::wrapInTransaction()` and call existing `PaymentPostingService` inside it. The nested payment transaction must remain inside the outer database transaction so a later reconciliation failure rolls back the payment too; prove this in the integration test.

For new payments:

```php
$result = $this->paymentPostingService->post(
    $unit,
    $transaction->getAmountCents(),
    PaymentSource::BANK_TRANSFER,
    $transaction->getBookingDate(),
    $reconciledAt,
    reference: $transaction->getRemittanceInformation(),
    externalReference: 'bank:'.$transaction->getFingerprint(),
);
```

Do not add fuzzy matching or fallback by amount.

- [ ] **Step 4: Full quality gate**

```bash
php bin/console lint:container
php bin/console doctrine:schema:validate --skip-sync --env=test
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

- [ ] **Step 5: Commit reconciliation workflow**

```bash
git add src/Service/BankReconciliationService.php tests/Service/BankReconciliationServiceTest.php
git commit -m "feat: reconcile bank transfers safely"
```

### Task 6: Final review and PR gate

**Files:**
- Review all files changed by this branch.
- Modify spec/plan only if implementation intentionally differs.

- [ ] **Step 1: Requirements review**

Check every spec item against code/tests. In particular verify no bank credentials, no payment mutation, no debit-to-Payment conversion, no amount-only matching and no cascade deletion of financial history.

- [ ] **Step 2: Security review of XML parser**

Confirm `LIBXML_NONET`, no `LIBXML_NOENT`, malformed XML fails cleanly, and parser has no filesystem/network resolution path.

- [ ] **Step 3: Fresh CI-equivalent verification**

```bash
composer validate --strict
php bin/console lint:container
php bin/console doctrine:schema:validate --skip-sync --env=test
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Use GitHub CI for the MariaDB 10.11 migrate → validate → rollback → migrate → validate gate.

- [ ] **Step 4: Open/update PR and merge only after green CI**

PR title: `Banking: statement import and safe reconciliation`.
