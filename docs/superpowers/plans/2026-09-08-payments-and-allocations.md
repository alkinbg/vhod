# Payments and Allocations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add immutable payment posting, deterministic charge allocation and payment reversal for the single private condominium entrance.

**Architecture:** Keep payments inside the existing modular monolith. `Payment`, `PaymentAllocation` and `PaymentReversal` are immutable ledger records; `PaymentAllocator`, `PaymentPostingService` and `PaymentReversalService` are transaction/application boundaries. Charge outstanding amounts and unit balances are derived from ledger entries, never stored as mutable counters.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7, MariaDB 10.11, PHPUnit 12, PHPStan 2.

**Spec:** `docs/superpowers/specs/2026-09-08-payments-and-allocations-design.md`

## Global Constraints

- Single private entrance only; no tenant/SaaS abstractions.
- Currency is EUR integer cents; no float money arithmetic.
- Posted payments, allocations and reversals are immutable.
- Corrections use reversal records; no edit/delete correction flow.
- Default allocation is oldest outstanding charge first.
- Manager-supplied allocations are validated before posting.
- Unallocated remainder is preserved as credit.
- External references are idempotency keys when present.
- Every task is implemented test-first.

---

### Task 1: Payment and allocation domain records

**Files:**
- Create: `src/Enum/PaymentSource.php`
- Create: `src/Entity/Payment.php`
- Create: `src/Entity/PaymentAllocation.php`
- Test: `tests/Entity/PaymentTest.php`
- Test: `tests/Entity/PaymentAllocationTest.php`

**Interfaces:**
- `Payment::post(Unit $unit, int $amountCents, PaymentSource $source, DateTimeImmutable $receivedAt, DateTimeImmutable $postedAt, ?string $reference = null, ?string $externalReference = null, ?string $note = null): self`
- `PaymentAllocation::allocate(Payment $payment, Charge $charge, int $amountCents, int $position, DateTimeImmutable $createdAt): self`

- [ ] **Step 1:** Write failing tests for positive amount, trimmed references, UTC normalization, same-unit allocation, positive allocation amount and non-negative position.
- [ ] **Step 2:** Verify RED is caused only by missing payment classes/behaviour.
- [ ] **Step 3:** Implement enums/entities with Doctrine attributes and no mutating setters.
- [ ] **Step 4:** Add schema migration for `payment` and `payment_allocation` and make MariaDB schema gate clean.
- [ ] **Step 5:** Run full PHPUnit and PHPStan; commit green domain foundation.

### Task 2: Derived outstanding balances and allocation proposal

**Files:**
- Create: `src/Value/PaymentAllocationProposal.php`
- Create: `src/Value/ProposedAllocation.php`
- Create: `src/Service/PaymentAllocator.php`
- Test: `tests/Service/PaymentAllocatorTest.php`

**Interfaces:**
- `PaymentAllocator::__construct(EntityManagerInterface $entityManager)`
- `PaymentAllocator::propose(Unit $unit, int $amountCents): PaymentAllocationProposal`
- `PaymentAllocationProposal` exposes ordered proposed allocations plus `allocatedCents` and `unallocatedCents`.

- [ ] **Step 1:** Write failing tests for oldest-first ordering, same-month charge-id tie-break, partial payment, one payment covering multiple charges, already-partially-paid charge and unallocated remainder.
- [ ] **Step 2:** Verify RED.
- [ ] **Step 3:** Implement derived outstanding lookup from `Charge` minus effective allocations, with integer-cent arithmetic only.
- [ ] **Step 4:** Verify focused tests, full PHPUnit and PHPStan.
- [ ] **Step 5:** Commit allocator.

### Task 3: Transactional payment posting and idempotency

**Files:**
- Create: `src/Value/PaymentPostingResult.php`
- Create: `src/Service/PaymentPostingService.php`
- Test: `tests/Service/PaymentPostingServiceTest.php`

**Interfaces:**
- `PaymentPostingService::__construct(EntityManagerInterface $entityManager, PaymentAllocator $allocator)`
- `PaymentPostingService::post(Unit $unit, int $amountCents, PaymentSource $source, DateTimeImmutable $receivedAt, DateTimeImmutable $postedAt, ?string $reference = null, ?string $externalReference = null, ?string $note = null, ?PaymentAllocationProposal $explicitProposal = null): PaymentPostingResult`

- [ ] **Step 1:** Write failing tests for automatic oldest-first posting, explicit manager proposal, cross-unit rejection, payment-total over-allocation, charge over-allocation, transaction rollback and unallocated credit.
- [ ] **Step 2:** Write failing idempotency test proving repeated `externalReference` returns the existing operation without new rows.
- [ ] **Step 3:** Implement preflight validation plus one Doctrine transaction for payment + allocations.
- [ ] **Step 4:** Add/verify unique DB constraint for non-null `external_reference` and `(payment_id, charge_id)`.
- [ ] **Step 5:** Run full CI gate; commit.

### Task 4: Immutable payment reversal

**Files:**
- Create: `src/Entity/PaymentReversal.php`
- Create: `src/Service/PaymentReversalService.php`
- Create: `src/Value/PaymentReversalResult.php`
- Modify: payment migration or add follow-up migration as appropriate.
- Test: `tests/Entity/PaymentReversalTest.php`
- Test: `tests/Service/PaymentReversalServiceTest.php`

**Interfaces:**
- `PaymentReversal::record(Payment $payment, int $amountCents, string $reason, DateTimeImmutable $reversedAt): self`
- `PaymentReversalService::reverse(Payment $payment, string $reason, DateTimeImmutable $reversedAt): PaymentReversalResult`

- [ ] **Step 1:** Write failing tests for required reason, exact amount mirror, UTC timestamp and immutable original payment/allocation records.
- [ ] **Step 2:** Write failing service tests for single reversal only and restored charge outstanding effect.
- [ ] **Step 3:** Implement `PaymentReversal` and transaction service without mutating original payment/allocation rows.
- [ ] **Step 4:** Add one-to-one uniqueness protecting against double reversal.
- [ ] **Step 5:** Run focused tests, full PHPUnit, PHPStan and MariaDB migration round-trip; commit.

### Task 5: PR verification and merge

- [ ] **Step 1:** Review branch for mutable financial setters, float arithmetic, hidden balance columns, SaaS concepts and accidental personal-data exposure.
- [ ] **Step 2:** Run full CI: Composer metadata, Symfony container, Doctrine mapping, MariaDB migrate → validate → rollback → migrate → validate, PHPUnit and PHPStan.
- [ ] **Step 3:** Open PR `Payments: immutable posting, allocation and reversal`.
- [ ] **Step 4:** Fix review/CI findings by root cause only.
- [ ] **Step 5:** Squash-merge only after a clean green PR CI and verify push CI on `main`.
