# Fees and funds design

## Scope

This slice implements the financial obligation foundation for one private condominium entrance. It does not implement payment collection, bank reconciliation or expenses; those remain separate roadmap slices.

The slice must make monthly obligations reproducible, explainable and safe to regenerate without duplicates.

## Legal model

The implementation follows the current Bulgarian Condominium Ownership Management Act (ЗУЕС):

- the Repair and Renovation Fund is a distinct fund maintained by the condominium;
- repair-fund monthly contributions are based on ideal parts and must comply with the statutory minimum in force;
- management and maintenance expenses may be distributed by General Assembly decision per person, by ideal parts, or equally per independent unit;
- special cases such as absence, business activity and other legally relevant exceptions must be represented explicitly rather than hidden in an edited balance.

The application does not invent a legal interpretation when the General Assembly decision or statutory minimum requires human/legal confirmation. A repair-fund policy therefore records that the statutory minimum has been checked before it can be used for generation.

## Currency and money

All new financial records use EUR. Money amounts are persisted and calculated as integer cents; floating-point money arithmetic is not allowed. Non-money calculation factors such as quantities, multipliers and ideal parts use canonical fixed-scale decimal strings.

A generated charge stores both its final amount and the calculation snapshot that produced it.

## Fund

`Fund` represents money with a distinct purpose.

Fields:

- `code` — stable machine code, unique;
- `name` — resident-facing name;
- `type` — `OPERATING`, `REPAIR_RENOVATION`, or `OTHER`;
- `active`.

The Repair and Renovation Fund is not merged with the operating fund.

## FeePolicy

A `FeePolicy` is one effective version of a monthly fee rule.

Fields:

- stable `code` shared by successive versions;
- `name`;
- `fund`;
- `category` — `MANAGEMENT_MAINTENANCE`, `REPAIR_RENOVATION`, `OTHER`;
- `distribution` — `PER_PERSON`, `PER_UNIT`, `IDEAL_PARTS`;
- `monthlyAmountCents` in EUR cents;
- `effectiveFrom` and optional `effectiveUntil`;
- `decisionReference` identifying the General Assembly decision;
- `includeAnimalEquivalents` for policies covering the Article 51 animal-related cost categories;
- `statutoryMinimumConfirmed` for repair-fund policies.

Policy periods use month boundaries. A policy starts on the first day of a month and, when closed, ends on the last day of a month. Successive versions keep the same code and never rewrite previous versions.

Rules:

- repair/renovation policies must use a `REPAIR_RENOVATION` fund;
- repair/renovation policies must use `IDEAL_PARTS` distribution;
- repair/renovation policies require `statutoryMinimumConfirmed=true`;
- animal equivalents may only be enabled for `PER_PERSON` policies;
- monthly amounts must be positive;
- policy versions with the same code may not overlap.

## FeePolicyUnitRule

`FeePolicyUnitRule` represents an explicit exception or special assessment for one unit and one policy version.

Fields:

- `policy`;
- `unit`;
- effective period;
- optional `quantityOverride`;
- `multiplier`, default `1.000`;
- required `reason`;
- optional `decisionReference`.

The rule is deliberately generic enough to represent a legally documented exemption, a 50% factor, a business multiplier, or an explicitly assessed person-equivalent count without collecting unnecessary sensitive data such as dates of birth.

Unit rules are supported only for `PER_UNIT` and `PER_PERSON` policies. `IDEAL_PARTS` policies use exact proportional allocation across all active units and reject individual unit rules so that a stored rule can never silently have no effect or distort the 100% allocation.

Multiplier range is `0.000` through `5.000`. A rule must actually change the normal calculation; a no-op rule with no quantity override and multiplier `1.000` is rejected.

## Charge

`Charge` is an immutable posted monthly obligation.

Fields:

- immutable `feePolicy` version, which also identifies the fund used by that policy version;
- `unit`;
- `billingMonth` normalized to the first day of the month;
- fixed-scale `quantity` used for calculation;
- `policyAmountCents` snapshot;
- final `amountCents`;
- JSON `calculationDetails` snapshot;
- UTC `postedAt`.

A unique database constraint on `(fee_policy_id, unit_id, billing_month)` is the final protection against duplicate generation.

Posted charges are never edited or deleted as a correction mechanism. Later finance slices will introduce explicit adjustments/reversals.

## Monthly generation

`MonthlyChargeGenerator` generates all active monthly policies for one billing month in a transaction.

It performs these checks before posting:

1. only one active version of a policy code may exist for the month;
2. only active units are charged;
3. an existing charge for the same policy version/unit/month is skipped;
4. all calculation input is snapshotted into the charge.

### Per unit

Quantity is `1.000` unless a unit rule overrides it. Final amount is policy amount × quantity × multiplier.

### Per person

The default person-equivalent count is derived from the condominium book at the billing-month assessment date:

- active owner/user/occupant relations count once per represented person or legal entity;
- active household members count once and are deduplicated against relation persons;
- registered animals are added only when `includeAnimalEquivalents` is enabled.

Legally relevant exceptions that cannot be derived safely from the stored book data are represented through `FeePolicyUnitRule`.

### Ideal parts

All active charged units must have ideal-parts data. The configured monthly amount represents the total amount for 100% of ideal parts.

Allocation uses integer cents and a deterministic largest-remainder method so that:

- every unit amount is rounded to euro cents;
- the sum of all generated unit charges equals the configured total exactly;
- ordering is deterministic.

Generation fails rather than silently normalising incomplete or inconsistent ideal-parts data.

## Idempotency and concurrency

Generation is transactional and storage-idempotent. Existing charges are skipped and the unique constraint prevents duplicates even if generation is accidentally triggered twice.

A concurrent race may cause one transaction to lose on the unique constraint, but it can never create duplicate obligations. A later UI/command layer may serialise generation for friendlier operator feedback; correctness does not depend on that layer.

## Access and UI boundary

This PR is the finance domain foundation only. It exposes no public registration and no SaaS concepts.

The following PR will add management workflows for configuring funds/policies and running monthly generation, plus resident-facing obligation summaries. Payment settlement belongs to the next roadmap slice.

## Tests

Tests must prove:

- entity invariants;
- repair-fund restrictions;
- policy effective-date behaviour;
- explicit unit-rule validation;
- per-unit generation;
- per-person counting and explicit overrides;
- deterministic ideal-parts allocation with exact total preservation;
- idempotent reruns;
- rejection of overlapping active policy versions;
- immutable calculation snapshots;
- Doctrine mapping and MariaDB migration round-trip through CI.
