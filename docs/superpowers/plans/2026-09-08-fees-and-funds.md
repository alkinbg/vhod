# Fees and Funds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the auditable monthly-fee and fund foundation for the single private condominium entrance, with versioned rules, exact EUR-cent calculations and idempotent charge generation.

**Architecture:** Keep finance inside the existing modular monolith. Domain entities enforce legal/business invariants; `MonthlyChargeGenerator` is the orchestration boundary and uses Doctrine transactionality. Posted `Charge` records snapshot the policy inputs used for calculation and are immutable by design.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7, MariaDB 10.11, PHPUnit 12, PHPStan 2.

**Spec:** `docs/fees-and-funds-design.md`

## Global Constraints

- This is a single-entrance private system; no tenant/SaaS abstractions.
- Currency is EUR and money arithmetic uses integer cents; never use float for money.
- `FeePolicy` versions are historical records and must not overlap for the same stable code.
- Posted charges are never edited/deleted as a correction mechanism.
- Repair and Renovation Fund policies must use ideal-parts distribution and require explicit statutory-minimum confirmation.
- Existing condominium-book entities remain the source for persons, household members, absences and animals.
- Every task is implemented test-first.

---

### Task 1: Fund and FeePolicy invariants

**Files:**
- Create: `src/Enum/FundType.php`
- Create: `src/Enum/FeeCategory.php`
- Create: `src/Enum/FeeDistribution.php`
- Create: `src/Entity/Fund.php`
- Create: `src/Entity/FeePolicy.php`
- Test: `tests/Entity/FundTest.php`
- Test: `tests/Entity/FeePolicyTest.php`

**Interfaces:**
- `new Fund(string $code, string $name, FundType $type)`
- `FeePolicy::create(string $code, string $name, Fund $fund, FeeCategory $category, FeeDistribution $distribution, int $monthlyAmountCents, DateTimeImmutable $effectiveFrom, string $decisionReference, bool $includeAnimalEquivalents = false, bool $statutoryMinimumConfirmed = false): self`
- `FeePolicy::endAt(DateTimeImmutable $effectiveUntil): void`
- `FeePolicy::isEffectiveFor(DateTimeImmutable $billingMonth): bool`

- [ ] **Step 1: Write failing entity tests** proving trimmed/non-empty codes/names, positive cents, month-boundary dates, animal-equivalent restriction and repair-fund restrictions.
- [ ] **Step 2: Run the focused tests** and verify failure is caused only by missing classes/behaviour.
- [ ] **Step 3: Implement the enums and minimal entities** with Doctrine attributes and invariant checks.
- [ ] **Step 4: Run focused tests and PHPStan** and make them green.
- [ ] **Step 5: Commit** with `feat: add fund and fee policy domain`.

### Task 2: Explicit per-unit rules and immutable charges

**Files:**
- Create: `src/Entity/FeePolicyUnitRule.php`
- Create: `src/Entity/Charge.php`
- Test: `tests/Entity/FeePolicyUnitRuleTest.php`
- Test: `tests/Entity/ChargeTest.php`

**Interfaces:**
- `new FeePolicyUnitRule(FeePolicy $policy, Unit $unit, DateTimeImmutable $effectiveFrom, string $reason, ?string $decisionReference = null, ?string $quantityOverride = null, string $multiplier = '1.000')`
- `FeePolicyUnitRule::endAt(DateTimeImmutable $effectiveUntil): void`
- `FeePolicyUnitRule::isEffectiveFor(DateTimeImmutable $billingMonth): bool`
- `Charge::post(FeePolicy $policy, Unit $unit, DateTimeImmutable $billingMonth, string $quantity, int $policyAmountCents, int $amountCents, array $calculationDetails, DateTimeImmutable $postedAt): self`

- [ ] **Step 1: Write failing tests** for multiplier `0.000..5.000`, positive quantity override, required reason, effective dates, normalized billing month and UTC posted timestamp.
- [ ] **Step 2: Verify RED.**
- [ ] **Step 3: Implement the entities** and unique ORM constraint `(fee_policy_id, unit_id, billing_month)` on `Charge`.
- [ ] **Step 4: Verify focused tests and PHPStan.**
- [ ] **Step 5: Commit** with `feat: add fee rules and charge snapshots`.

### Task 3: MonthlyChargeGenerator calculations

**Files:**
- Create: `src/Service/MonthlyChargeGenerator.php`
- Create: `src/Value/ChargeGenerationResult.php`
- Test: `tests/Service/MonthlyChargeGeneratorTest.php`

**Interfaces:**
- `MonthlyChargeGenerator::__construct(EntityManagerInterface $entityManager)`
- `MonthlyChargeGenerator::generate(DateTimeImmutable $billingMonth, DateTimeImmutable $postedAt): ChargeGenerationResult`
- `ChargeGenerationResult` exposes integer `created`, `skipped` and `totalAmountCents`.

**Calculation contracts:**
- `PER_UNIT`: base quantity `1.000`, then unit-rule quantity override and multiplier.
- `PER_PERSON`: deduplicate active natural persons/legal entities from `UnitRelation`, add active `HouseholdMember` persons not already counted, optionally add registered-animal equivalents, then apply explicit unit rule.
- `IDEAL_PARTS`: policy amount is the total for 100%; all active charged units must have ideal parts; allocate cents using deterministic largest remainder ordered by unit id/designation.
- Existing `(policy, unit, month)` charge is skipped.
- More than one effective version for the same policy code causes `DomainException` before posting.

- [ ] **Step 1: Write failing per-unit test** including multiplier/quantity override and exact snapshot assertions.
- [ ] **Step 2: Verify RED, implement only per-unit generation, verify GREEN.**
- [ ] **Step 3: Write failing per-person tests** for relation/household deduplication, animals and explicit quantity override.
- [ ] **Step 4: Implement per-person counting and verify GREEN.**
- [ ] **Step 5: Write failing ideal-parts tests** for exact total preservation, deterministic remainder allocation and missing ideal-parts rejection.
- [ ] **Step 6: Implement integer-cent largest-remainder allocation and verify GREEN.**
- [ ] **Step 7: Write failing idempotency/overlap tests**, implement existing-charge skip and overlapping-policy detection, verify GREEN.
- [ ] **Step 8: Run full PHPUnit and PHPStan.**
- [ ] **Step 9: Commit** with `feat: generate monthly condominium charges`.

### Task 4: MariaDB schema migration

**Files:**
- Create: `migrations/Version20260908070000.php`

**Schema:**
- `fund`
- `fee_policy`
- `fee_policy_unit_rule`
- `charge`
- foreign keys to existing `property_unit`
- unique/index constraints matching Doctrine metadata

- [ ] **Step 1: Add migration after the entity tests are green.**
- [ ] **Step 2: Run/observe CI migration gate**: migrate → schema validate → rollback latest → migrate → schema validate on MariaDB 10.11.
- [ ] **Step 3: Correct only actual ORM/MariaDB diffs if reported.**
- [ ] **Step 4: Commit** with `feat: add fees and funds schema`.

### Task 5: PR verification and cleanup

**Files:**
- Modify docs only if implementation discoveries require clarifying the spec; do not broaden scope into payments or UI.

- [ ] **Step 1: Run the full CI gate**: Composer metadata, Symfony container, Doctrine mapping, MariaDB migration round-trip, PHPUnit, PHPStan.
- [ ] **Step 2: Review the branch for accidental mutable finance setters, float money arithmetic, SaaS abstractions or personal-data exposure.**
- [ ] **Step 3: Open PR `Fees and funds: versioned policies and monthly charges`.**
- [ ] **Step 4: Fix any CI failure by root cause and re-run the full gate.**
- [ ] **Step 5: Squash-merge only after a clean green PR CI, then verify the push CI on `main`.**
