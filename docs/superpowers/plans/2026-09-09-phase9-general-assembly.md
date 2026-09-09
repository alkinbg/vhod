# Phase 9 — General Assembly Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a legally aware, audit-safe General Assembly workflow for one residential entrance, including convening, electorate snapshots, attendance/proxies, quorum, weighted formal voting, optional absentee voting, minutes, and resident read-only access.

**Architecture:** Phase 9 is a separate formal-governance bounded context. Formal meeting state is stored in dedicated entities and immutable snapshots, while Phase 8 `Document` storage is reused for invitations, minutes, proxy/posting/absentee evidence. Exact decimal arithmetic is used for ideal-parts and threshold comparisons, and all formal transitions are service-driven, transactional, pessimistically locked where races matter, and append/audit oriented after legal boundaries.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7 / DBAL 4.4, MariaDB 10.11, Twig, Dompdf 3.1, PHPUnit 12.5, PHPStan 2.2, BCMath.

**Spec:** `docs/superpowers/specs/2026-09-09-phase9-general-assembly-design.md`

## Global Constraints

- Preserve the existing single-entrance product boundary; do not add multi-tenant/building abstractions.
- Formal General Assembly votes must never reuse `CommunityPollVote`, Community services, or Community tables.
- All Phase 9 routes require authentication.
- Phase 9 management roles are exactly `ROLE_MANAGER`, `ROLE_CONTROLLER`, and `ROLE_ADMIN`; `ROLE_CASHIER` alone cannot manage meetings.
- Residents have read-only meeting access from `CONVENED` onward; no resident self-service formal-vote POST route is introduced.
- Add `DocumentAccessLevel::GOVERNANCE`: controller/manager/admin only.
- Proxy, posting, and absentee evidence use `GOVERNANCE`; invitation and finalized minutes use `RESIDENTS`.
- Add `DocumentCategory::MEETING_PROXY`; keep `MEETING_INVITATION` and `MEETING_MINUTES`.
- Use exact fixed-precision decimal strings/Doctrine decimal values for ideal-parts and legal thresholds; binary floating point is forbidden for legal conclusions.
- Add `ext-bcmath` as an explicit Composer platform requirement and CI PHP extension.
- Current snapshotted quorum baseline: first call `51.00000000%` with `AT_LEAST`; delayed call `26.00000000%` with `AT_LEAST`; dominant-owner trigger `> 51.00000000%`; dominant-owner required quorum `75.00000000%` with `AT_LEAST`.
- Incomplete or contradictory material source data yields `REVIEW_REQUIRED`; never normalize known ideal-parts values to force 100%.
- Convening freezes ordinary agenda/electorate/rules; meeting close freezes attendance/proxies/in-meeting votes; minutes finalization freezes the final record.
- Corrections are explicit audited rows/addenda, never silent historical overwrite.
- All audit-sensitive foreign keys use `ON DELETE RESTRICT`; no cascade-remove of formal meeting history.
- No hard-delete controller/service flow for Phase 9 records.
- All POST mutations are CSRF-protected.
- Use UTC timestamps for stored instants and `Europe/Sofia` as the normal meeting timezone snapshot.
- Reuse the existing private `DocumentStorage` path outside `public/`; never expose direct filesystem/static URLs.
- Dompdf keeps remote resources disabled and uses `DejaVu Sans` for Bulgarian/Cyrillic PDF output.
- Final exact-head CI must pass Composer validation, container lint, Doctrine mapping, MariaDB migrate → validate → rollback → migrate → validate, full PHPUnit, and PHPStan with no Phase 9 ignore rules.

---

## File Structure Map

### Core domain

- `src/Enum/GeneralAssemblyStatus.php` — meeting lifecycle.
- `src/Enum/AssemblyConveningBasis.php` — claimed convening basis.
- `src/Enum/AssemblyDecisionKind.php` — controlled decision classification.
- `src/Enum/AgendaItemStatus.php` — planned/open/absentee/resolved.
- `src/Enum/AssemblyVoteDenominator.php` — legal denominator semantics.
- `src/Enum/MajorityComparison.php` — `GREATER_THAN` vs `AT_LEAST`.
- `src/Enum/AssemblyLegalResult.php` — `VALID/INVALID/REVIEW_REQUIRED` for quorum-style conclusions.
- `src/Enum/AssemblyResolutionResult.php` — `ACCEPTED/REJECTED/REVIEW_REQUIRED`.
- `src/Enum/AssemblyPrincipalType.php` — person/legal entity snapshot.
- `src/Enum/AssemblyAttendanceMode.php` — in-person/online/proxy/statutory-user-authority.
- `src/Enum/AssemblyVoteChoice.php` — FOR/AGAINST/ABSTAIN.
- `src/Enum/AssemblyVoteCastMode.php` — attendance/proxy/absentee source.
- `src/Enum/AssemblyQuorumCheckKind.php` — first/delayed/manual-review.
- `src/Enum/AssemblyAbsenteeSignatureMode.php` — recorded declaration evidence type.
- `src/Value/AssemblyMajorityRuleSnapshot.php` — immutable exact threshold/denominator semantics.
- `src/Value/AssemblyQuorumRuleSnapshot.php` — immutable meeting quorum rules.
- `src/Value/AssemblyQuorumCalculation.php` — pure quorum calculator result.
- `src/Value/AssemblyResolutionCalculation.php` — pure resolution calculator result.
- `src/Util/ExactDecimal.php` — BCMath normalization/arithmetic/comparison helper.
- `src/Entity/GeneralAssembly.php` — lifecycle root.
- `src/Entity/AssemblyAgendaItem.php` — frozen agenda item and resolution wording.
- `src/Entity/AssemblyElectorateEntry.php` — immutable voting-weight snapshot.
- `src/Entity/AssemblyQuorumCheck.php` — immutable persisted quorum fact.
- `src/Entity/AssemblyAttendance.php` — one current representation row per electorate entry.
- `src/Entity/AssemblyAttendanceChange.php` — explicit attendance correction audit.
- `src/Entity/AssemblyProxy.php` — proxy authority/evidence.
- `src/Entity/AssemblyVote.php` — effective formal vote.
- `src/Entity/AssemblyVoteCorrection.php` — explicit pre-resolution vote correction audit.
- `src/Entity/AssemblyResolution.php` — immutable computed result.
- `src/Entity/AssemblyInvitationPosting.php` — physical posting evidence.
- `src/Entity/AssemblyAbsenteeWindow.php` — explicit one-per-meeting window.
- `src/Entity/AssemblyAbsenteeDeclaration.php` — declaration evidence.
- `src/Entity/AssemblyAbsenteeDeclarationVote.php` — declaration vote choice per eligible item.
- `src/Entity/AssemblyMinutesCorrection.php` — finalized-meeting addendum.

### Security, repositories and services

- `src/Security/GeneralAssemblyAccessPolicy.php` — Phase 9 role/state authorization.
- `src/Security/DocumentAccessPolicy.php` — extend access matrix with GOVERNANCE.
- `src/Repository/GeneralAssemblyRepository.php` — resident/management meeting queries.
- `src/Repository/AssemblyElectorateEntryRepository.php` — meeting electorate queries.
- `src/Repository/AssemblyAttendanceRepository.php` — current representation queries.
- `src/Repository/AssemblyProxyRepository.php` — principal/representative proxy checks.
- `src/Repository/AssemblyVoteRepository.php` — effective vote queries.
- `src/Repository/AssemblyQuorumCheckRepository.php` — latest check.
- `src/Service/GeneralAssemblyService.php` — draft/convene/start/close lifecycle.
- `src/Service/AssemblyElectorateSnapshotService.php` — historical source-to-snapshot conversion.
- `src/Service/AssemblyQuorumCalculator.php` — pure exact-decimal quorum math.
- `src/Service/AssemblyQuorumService.php` — locked immutable check persistence.
- `src/Service/AssemblyInvitationService.php` — invitation render/store/link/posting.
- `src/Service/AssemblyAttendanceService.php` — attendance registration/correction.
- `src/Service/AssemblyProxyService.php` — proxy validation/register/revoke.
- `src/Service/AssemblyResolutionCalculator.php` — pure exact result math.
- `src/Service/AssemblyVotingService.php` — open/vote/correct/resolve.
- `src/Service/AssemblyAbsenteeVotingService.php` — window/declaration workflow.
- `src/Service/AssemblyMinutesService.php` — minutes render/store/finalize/addendum.
- `src/Service/DocumentStorage.php` — add controlled generated-PDF storage path.
- `src/Service/DocumentService.php` — add explicit Phase 9 generated/evidence recording paths without weakening existing official-content authorization.

### HTTP/UI

- `src/Controller/GeneralAssemblyManagementController.php` — preparation/post-meeting management.
- `src/Controller/GeneralAssemblyWorkbenchController.php` — live meeting mutations.
- `src/Controller/GeneralAssemblyController.php` — resident read-only views.
- `templates/management/assemblies/index.html.twig`
- `templates/management/assemblies/form.html.twig`
- `templates/management/assemblies/workbench.html.twig`
- `templates/management/assemblies/minutes_preview.html.twig`
- `templates/assemblies/index.html.twig`
- `templates/assemblies/show.html.twig`
- `templates/assemblies/minutes.html.twig`
- `templates/assemblies/invitation_pdf.html.twig`
- `templates/assemblies/minutes_pdf.html.twig`
- `templates/base.html.twig` — resident/management nav.
- `templates/dashboard/index.html.twig` — current/upcoming meeting summary.
- `assets/styles/app.css` — workbench/warning/read-only treatment.

### Persistence/tests

- `migrations/Version20260909150000.php` — Phase 9 schema; updated incrementally during branch development and finalized before merge.
- `tests/Entity/GeneralAssemblyDomainTest.php`
- `tests/Util/ExactDecimalTest.php`
- `tests/Service/AssemblyElectorateSnapshotServiceTest.php`
- `tests/Service/AssemblyQuorumCalculatorTest.php`
- `tests/Doctrine/GeneralAssemblySchemaTest.php`
- `tests/Security/GeneralAssemblyAccessPolicyTest.php`
- `tests/Controller/GeneralAssemblyManagementControllerTest.php`
- `tests/Service/AssemblyAttendanceProxyServiceTest.php`
- `tests/Controller/GeneralAssemblyWorkbenchControllerTest.php`
- `tests/Service/AssemblyVotingServiceTest.php`
- `tests/Service/AssemblyResolutionCalculatorTest.php`
- `tests/Service/AssemblyAbsenteeVotingServiceTest.php`
- `tests/Service/AssemblyMinutesServiceTest.php`
- `tests/Controller/GeneralAssemblyControllerTest.php`
- `tests/Controller/GeneralAssemblyNavigationTest.php`

---

### Task 1: Governance document policy, lifecycle enums, meeting and agenda domain

**Files:**
- Modify: `src/Enum/DocumentAccessLevel.php`
- Modify: `src/Enum/DocumentCategory.php`
- Modify: `src/Security/DocumentAccessPolicy.php`
- Create: `src/Security/GeneralAssemblyAccessPolicy.php`
- Create: `src/Enum/GeneralAssemblyStatus.php`
- Create: `src/Enum/AssemblyConveningBasis.php`
- Create: `src/Enum/AssemblyDecisionKind.php`
- Create: `src/Enum/AgendaItemStatus.php`
- Create: `src/Enum/AssemblyVoteDenominator.php`
- Create: `src/Enum/MajorityComparison.php`
- Create: `src/Value/AssemblyMajorityRuleSnapshot.php`
- Create: `src/Entity/GeneralAssembly.php`
- Create: `src/Entity/AssemblyAgendaItem.php`
- Create/Update: `migrations/Version20260909150000.php`
- Test: `tests/Entity/GeneralAssemblyDomainTest.php`
- Test: `tests/Security/GeneralAssemblyAccessPolicyTest.php`
- Modify tests: `tests/Security/DocumentAccessPolicyTest.php`

**Interfaces:**
- Produces `GeneralAssembly::draft(...)`, `reviseDraft(...)`, `addAgendaItem(...)`, `addEmergencyAgendaItem(...)`, `convene(...)`, `start(...)`, `close(...)`, and state-query helpers used by later services.
- Produces `AssemblyAgendaItem::draft(...)`, `reviseDraft(...)`, `open(...)`, `deferToAbsenteeWindow(...)`, `resolve(...)`.
- Produces `AssemblyMajorityRuleSnapshot` exact immutable legal semantics.
- Produces `GeneralAssemblyAccessPolicy::canManage(User): bool` and `canViewResident(User, GeneralAssembly): bool`.

- [ ] **Step 1: Write failing governance/access/domain tests**

Cover at minimum:

```php
public function testGovernanceDocumentAccessMatrix(): void
{
    self::assertSame(
        [DocumentAccessLevel::RESIDENTS],
        $this->policy->allowedLevels($this->user(['ROLE_USER'])),
    );
    self::assertContains(DocumentAccessLevel::GOVERNANCE, $this->policy->allowedLevels($this->user(['ROLE_CONTROLLER'])));
    self::assertNotContains(DocumentAccessLevel::GOVERNANCE, $this->policy->allowedLevels($this->user(['ROLE_CASHIER'])));
}
```

```php
public function testOnlyManagerControllerAdminCanManageGeneralAssembly(): void
{
    self::assertFalse($this->assemblyPolicy->canManage($this->user(['ROLE_USER'])));
    self::assertFalse($this->assemblyPolicy->canManage($this->user(['ROLE_CASHIER'])));
    self::assertTrue($this->assemblyPolicy->canManage($this->user(['ROLE_CONTROLLER'])));
    self::assertTrue($this->assemblyPolicy->canManage($this->user(['ROLE_MANAGER'])));
    self::assertTrue($this->assemblyPolicy->canManage($this->user(['ROLE_ADMIN'])));
}
```

```php
public function testConveningFreezesOrdinaryAgenda(): void
{
    $assembly = $this->draftAssembly();
    $item = $assembly->addAgendaItem(
        1,
        'Избор на управител',
        null,
        'Общото събрание избира ...',
        AssemblyDecisionKind::ELECTION_OR_REMOVAL,
        $this->ordinaryRule(),
    );

    $assembly->convene($this->manager, $this->at('2026-09-09T12:00:00Z'));

    $this->expectException(DomainException::class);
    $item->reviseDraft('Ново заглавие', null, 'Нов текст', AssemblyDecisionKind::ORDINARY, $this->ordinaryRule());
}
```

Also test blank title/place, invalid timestamp ordering, duplicate/invalid agenda positions, emergency item allowed only in `IN_PROGRESS` with non-blank reason, inactive users denied resident visibility, and `DocumentCategory::MEETING_PROXY` label.

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```bash
vendor/bin/phpunit tests/Entity/GeneralAssemblyDomainTest.php tests/Security/GeneralAssemblyAccessPolicyTest.php tests/Security/DocumentAccessPolicyTest.php
```

Expected: FAIL because Phase 9 enums/entities/policies do not exist and `GOVERNANCE` / `MEETING_PROXY` are absent.

- [ ] **Step 3: Implement enums and majority-rule value object**

Required constructor contract:

```php
final readonly class AssemblyMajorityRuleSnapshot
{
    public function __construct(
        public string $ruleCode,
        public AssemblyVoteDenominator $denominator,
        public string $thresholdPercent,
        public MajorityComparison $comparison,
        public string $legalBasis,
        public string $sourceVersion,
        public bool $requiresLegalReview = false,
    ) {}
}
```

At this task validate decimal syntax without float conversion using a regex such as `/^(?:0|[1-9]\d{0,2})(?:\.\d{1,8})?$/` and enforce range `0..100` lexically/normalized conservatively; Task 2 replaces arithmetic/comparison logic with `ExactDecimal`.

- [ ] **Step 4: Implement GeneralAssembly and AssemblyAgendaItem**

Use explicit transition guards. Required high-level signatures:

```php
public static function draft(
    string $title,
    DateTimeImmutable $scheduledAt,
    string $timezoneSnapshot,
    DateTimeImmutable $referenceDate,
    string $place,
    AssemblyConveningBasis $conveningBasis,
    string $initiatorDisplayName,
    ?User $initiatorUser,
    User $createdBy,
    DateTimeImmutable $createdAt,
    ?string $onlineMeetingReference = null,
    ?string $conveningBasisNote = null,
): self;
```

```php
public function addAgendaItem(
    int $position,
    string $title,
    ?string $description,
    string $draftResolutionText,
    AssemblyDecisionKind $kind,
    AssemblyMajorityRuleSnapshot $majorityRule,
): AssemblyAgendaItem;
```

```php
public function convene(User $actor, DateTimeImmutable $convenedAt): void;
public function start(User $actor, DateTimeImmutable $startedAt): void;
public function close(User $actor, DateTimeImmutable $closedAt): void;
public function isResidentVisible(): bool;
```

`convene()` at Task 1 only changes lifecycle; Task 5 application service will enforce electorate/invitation prerequisites atomically before calling it.

- [ ] **Step 5: Extend document policy without weakening existing official-content management**

`allowedLevels()` must produce:

```php
RESIDENTS                                        // every active user
RESIDENTS + FINANCE                              // cashier
RESIDENTS + FINANCE + GOVERNANCE                 // controller
RESIDENTS + FINANCE + GOVERNANCE + MANAGEMENT    // manager/admin
```

Keep `canManageOfficialContent()` manager/admin only. Add:

```php
public function canManageGovernanceEvidence(User $user): bool;
```

returning true only for active controller/manager/admin.

- [ ] **Step 6: Add minimal migration for Task 1 tables**

Create `general_assembly` and `assembly_agenda_item` with enum-backed VARCHAR columns, decimal rule-threshold column `DECIMAL(14,8)`, timestamps, and `RESTRICT` FKs. Include enough indexes/constraints for Doctrine schema sync; named hardening is revisited in Task 4.

- [ ] **Step 7: Run focused and full pre-commit gates**

```bash
vendor/bin/phpunit tests/Entity/GeneralAssemblyDomainTest.php tests/Security/GeneralAssemblyAccessPolicyTest.php tests/Security/DocumentAccessPolicyTest.php
php bin/console doctrine:schema:validate --skip-sync --env=test
vendor/bin/phpstan analyse --no-progress
```

Expected: PASS.

- [ ] **Step 8: Commit Task 1**

```bash
git add src/Enum src/Value/AssemblyMajorityRuleSnapshot.php src/Entity/GeneralAssembly.php src/Entity/AssemblyAgendaItem.php src/Security migrations/Version20260909150000.php tests/Entity/GeneralAssemblyDomainTest.php tests/Security
git commit -m "feat: add General Assembly core domain"
```

---

### Task 2: Exact decimal helper and electorate snapshot

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `.github/workflows/ci.yml`
- Create: `src/Util/ExactDecimal.php`
- Create: `src/Enum/AssemblyPrincipalType.php`
- Create: `src/Entity/AssemblyElectorateEntry.php`
- Create: `src/Repository/AssemblyElectorateEntryRepository.php`
- Create: `src/Service/AssemblyElectorateSnapshotService.php`
- Modify: `migrations/Version20260909150000.php`
- Test: `tests/Util/ExactDecimalTest.php`
- Test: `tests/Service/AssemblyElectorateSnapshotServiceTest.php`

**Interfaces:**
- Produces exact decimal API used by every later calculator.
- Produces immutable electorate entries from `Unit` + active `UnitRelationType::OWNER` data as of `GeneralAssembly::referenceDate`.

- [ ] **Step 1: Add RED ExactDecimal tests**

Required API:

```php
ExactDecimal::normalize(string $value, int $scale = 8): string;
ExactDecimal::add(string $left, string $right, int $scale = 8): string;
ExactDecimal::sub(string $left, string $right, int $scale = 8): string;
ExactDecimal::mul(string $left, string $right, int $scale = 8): string;
ExactDecimal::div(string $left, string $right, int $scale = 8): string;
ExactDecimal::compare(string $left, string $right, int $scale = 8): int;
ExactDecimal::percentOf(string $part, string $whole, int $scale = 8): string;
```

Test exact examples:

```php
self::assertSame('4.00000000', ExactDecimal::mul('8.00000000', '0.50000000'));
self::assertSame(0, ExactDecimal::compare('51', '51.00000000'));
self::assertSame(-1, ExactDecimal::compare('50.99999999', '51.00000000'));
```

Reject negative values where a method explicitly requires non-negative inputs and reject malformed scientific notation such as `1e2`.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Util/ExactDecimalTest.php
```

Expected: FAIL because `ExactDecimal` does not exist.

- [ ] **Step 3: Add BCMath platform requirement and CI extension**

Add to `composer.json`:

```json
"ext-bcmath": "*"
```

Update CI extension list to:

```yaml
extensions: bcmath, ctype, dom, iconv, mbstring, pdo_mysql, pdo_sqlite
```

Refresh lock metadata with Composer rather than hand-editing dependency metadata:

```bash
composer update --lock --no-interaction --no-scripts
composer validate --strict
```

- [ ] **Step 4: Implement ExactDecimal with BCMath only**

Normalize input strings before operations. Do not cast to float. `percentOf()` computes `(part / whole) * 100`; division by zero throws `InvalidArgumentException`.

- [ ] **Step 5: Write RED electorate snapshot tests**

Cover:

1. one owner + one unit with `8.2500` ideal parts => `8.25000000` represented;
2. two 50% co-owners + `8.0000` unit => `4.00000000` each;
3. legal entity owner preserves name/identifier snapshot;
4. relation active on `referenceDate` is used; expired/future relation excluded;
5. missing `Unit::idealParts` produces review entry and readiness `REVIEW_REQUIRED`;
6. multiple owners with missing ownership shares produce review rather than guessed equal split;
7. overlapping contradictory ownership produces review;
8. later mutation/end of source relation does not change persisted snapshot values;
9. total is not rescaled to 100.

- [ ] **Step 6: Implement AssemblyElectorateEntry**

Required factory:

```php
public static function snapshot(
    GeneralAssembly $assembly,
    ?Unit $unit,
    ?UnitRelation $sourceRelation,
    string $unitDesignationSnapshot,
    AssemblyPrincipalType $principalType,
    ?Person $person,
    string $principalNameSnapshot,
    ?string $principalIdentifierSnapshot,
    string $relationTypeSnapshot,
    ?string $ownershipSharePercentSnapshot,
    ?string $unitIdealPartsPercentSnapshot,
    ?string $representedIdealPartsPercentSnapshot,
    bool $quorumEligible,
    ?string $reviewReason,
    DateTimeImmutable $createdAt,
): self;
```

When data are complete, represented weight is exact decimal. When material data are missing/ambiguous, store nullable represented weight, `quorumEligible=false`, and non-blank `reviewReason`.

- [ ] **Step 7: Implement snapshot service**

Required interface:

```php
/** @return list<AssemblyElectorateEntry> */
public function createSnapshot(GeneralAssembly $assembly, DateTimeImmutable $createdAt): array;
```

Service requirements:

- assembly must still be `DRAFT` and have no electorate rows;
- load active units and owner relations as of `referenceDate`;
- calculate co-owner common weight as `unitIdealParts * (ownershipShare / 100)`;
- never infer missing co-owner shares;
- preserve legal entity identity snapshots;
- persist entries inside caller transaction; no separate transaction in this service.

- [ ] **Step 8: Extend migration and run gates**

Add `assembly_electorate_entry` with `DECIMAL(14,8)` snapshot columns and `RESTRICT` source/assembly FKs.

```bash
vendor/bin/phpunit tests/Util/ExactDecimalTest.php tests/Service/AssemblyElectorateSnapshotServiceTest.php
composer validate --strict
php bin/console lint:container
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 9: Commit Task 2**

```bash
git add composer.json composer.lock .github/workflows/ci.yml src/Util src/Enum/AssemblyPrincipalType.php src/Entity/AssemblyElectorateEntry.php src/Repository/AssemblyElectorateEntryRepository.php src/Service/AssemblyElectorateSnapshotService.php migrations/Version20260909150000.php tests/Util tests/Service/AssemblyElectorateSnapshotServiceTest.php
git commit -m "feat: snapshot General Assembly electorate"
```

---

### Task 3: Quorum rule snapshot and pure exact calculator

**Files:**
- Create: `src/Enum/AssemblyLegalResult.php`
- Create: `src/Enum/AssemblyQuorumCheckKind.php`
- Create: `src/Value/AssemblyQuorumRuleSnapshot.php`
- Create: `src/Value/AssemblyQuorumCalculation.php`
- Create: `src/Entity/AssemblyQuorumCheck.php`
- Create: `src/Service/AssemblyQuorumCalculator.php`
- Create: `src/Repository/AssemblyQuorumCheckRepository.php`
- Modify: `src/Entity/GeneralAssembly.php`
- Modify: `migrations/Version20260909150000.php`
- Test: `tests/Service/AssemblyQuorumCalculatorTest.php`

**Interfaces:**
- Produces a pure calculator with no Doctrine dependency.
- Meeting stores a quorum-rule snapshot copied before/at convening.

- [ ] **Step 1: Write RED calculator tests**

Create snapshot:

```php
$rule = new AssemblyQuorumRuleSnapshot(
    'zues-2025-default',
    '51.00000000',
    '26.00000000',
    '51.00000000',
    '75.00000000',
    'ЗУЕС — приложим кворум към 2026-09-09',
    'effective-through-2026-09-09',
    false,
);
```

Test exact cases:

- `51.00000000` first call => VALID;
- `50.99999999` first call => INVALID;
- `26.00000000` delayed => VALID;
- `25.99999999` delayed => INVALID;
- one principal owning `51.00000001` => dominant-owner rule and `75.00000000` required;
- exactly `51.00000000` does **not** trigger dominant-owner `GREATER_THAN` rule;
- duplicate electorate/representation input cannot increase represented sum;
- any material electorate `reviewReason` that could affect outcome => REVIEW_REQUIRED.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Service/AssemblyQuorumCalculatorTest.php
```

- [ ] **Step 3: Implement immutable rule/calculation values**

Required calculator signature:

```php
/**
 * @param list<AssemblyElectorateEntry> $electorate
 * @param list<AssemblyElectorateEntry> $represented
 */
public function calculate(
    AssemblyQuorumRuleSnapshot $rule,
    array $electorate,
    array $represented,
    AssemblyQuorumCheckKind $kind,
): AssemblyQuorumCalculation;
```

`AssemblyQuorumCalculation` contains:

```php
public string $representedIdealPartsPercent;
public string $requiredIdealPartsPercent;
public string $ruleCode;
public AssemblyLegalResult $result;
public string $explanation;
```

- [ ] **Step 4: Implement pure exact calculator**

Use `ExactDecimal` only. Deduplicate represented entries by stable persisted ID when available, otherwise by object identity in unit tests. Dominant-owner detection uses electorate represented-weight snapshot and `GREATER_THAN` trigger semantics.

- [ ] **Step 5: Add persisted AssemblyQuorumCheck**

Factory:

```php
public static function record(
    GeneralAssembly $assembly,
    AssemblyQuorumCheckKind $kind,
    DateTimeImmutable $checkedAt,
    AssemblyQuorumCalculation $calculation,
    User $checkedBy,
): self;
```

No mutation methods.

- [ ] **Step 6: Extend migration and run focused gates**

```bash
vendor/bin/phpunit tests/Service/AssemblyQuorumCalculatorTest.php
php bin/console doctrine:schema:validate --skip-sync --env=test
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 7: Commit Task 3**

```bash
git add src/Enum/AssemblyLegalResult.php src/Enum/AssemblyQuorumCheckKind.php src/Value/AssemblyQuorumRuleSnapshot.php src/Value/AssemblyQuorumCalculation.php src/Entity/AssemblyQuorumCheck.php src/Service/AssemblyQuorumCalculator.php src/Repository/AssemblyQuorumCheckRepository.php src/Entity/GeneralAssembly.php migrations/Version20260909150000.php tests/Service/AssemblyQuorumCalculatorTest.php
git commit -m "feat: calculate General Assembly quorum"
```

---

### Task 4: Core Phase 9 schema integrity and named constraints

**Files:**
- Modify: Phase 9 entities introduced in Tasks 1–3
- Modify: `migrations/Version20260909150000.php`
- Create: `tests/Doctrine/GeneralAssemblySchemaTest.php`

**Interfaces:**
- Locks in table/index/unique/FK names needed by later tasks.

- [ ] **Step 1: Write RED schema test against MariaDB/Doctrine metadata**

Assert current core tables and names:

```text
general_assembly
assembly_agenda_item
assembly_electorate_entry
assembly_quorum_check
```

Required named constraints/indexes include:

```text
uniq_assembly_agenda_position (assembly_id, position)
idx_general_assembly_status_scheduled (status, scheduled_at)
idx_electorate_assembly_unit (assembly_id, unit_id)
idx_electorate_assembly_person (assembly_id, person_id)
idx_quorum_assembly_checked (assembly_id, checked_at)
```

Assert every audit/source FK has `ON DELETE RESTRICT` and no Phase 9 association uses cascade remove.

- [ ] **Step 2: Run and verify RED**

```bash
vendor/bin/phpunit tests/Doctrine/GeneralAssemblySchemaTest.php
```

Expected: FAIL for missing exact names where Tasks 1–3 used minimal metadata.

- [ ] **Step 3: Add repeatable Doctrine `#[ORM\Index]` / `#[ORM\UniqueConstraint]` metadata**

Follow the Doctrine 3.7 pattern already used in Phase 8. Do not force custom names for join-table indexes that Doctrine cannot represent; where Doctrine requires generated names, mirror Doctrine in the migration and assert the generated name instead of schema hacks.

- [ ] **Step 4: Synchronize migration exactly**

Run the MariaDB round-trip locally/CI-equivalent:

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
php bin/console doctrine:migrations:migrate prev --no-interaction --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
```

- [ ] **Step 5: Run tests and commit**

```bash
vendor/bin/phpunit tests/Doctrine/GeneralAssemblySchemaTest.php
vendor/bin/phpstan analyse --no-progress
git add src/Entity migrations/Version20260909150000.php tests/Doctrine/GeneralAssemblySchemaTest.php
git commit -m "test: harden General Assembly schema integrity"
```

---

### Task 5: Draft/convening management, generated invitation and posting evidence

**Files:**
- Create: `src/Repository/GeneralAssemblyRepository.php`
- Create: `src/Service/GeneralAssemblyService.php`
- Create: `src/Entity/AssemblyInvitationPosting.php`
- Create: `src/Service/AssemblyInvitationService.php`
- Modify: `src/Service/DocumentStorage.php`
- Modify: `src/Service/DocumentService.php`
- Create: `src/Controller/GeneralAssemblyManagementController.php`
- Create: `templates/management/assemblies/index.html.twig`
- Create: `templates/management/assemblies/form.html.twig`
- Create: `templates/assemblies/invitation_pdf.html.twig`
- Modify: `migrations/Version20260909150000.php`
- Test: `tests/Controller/GeneralAssemblyManagementControllerTest.php`
- Test: `tests/Service/AssemblyInvitationServiceTest.php`

**Interfaces:**
- Produces transactionally safe draft/convene workflow.
- Adds controlled generated-PDF and governance-evidence document APIs while preserving Phase 8 authorization behavior.

- [ ] **Step 1: Write RED management functional tests**

Cover:

- anonymous redirected to login;
- resident/cashier => 403 management routes;
- controller/manager/admin can list/create/edit draft;
- invalid CSRF blocks create/edit/convene/posting;
- draft invisible to residents;
- convene freezes agenda and creates electorate snapshot + invitation exactly once;
- double convene does not duplicate invitation or electorate;
- posting evidence is distinct from `AnnouncementReceipt`;
- invitation document access is `RESIDENTS`.

- [ ] **Step 2: Write RED generated-document tests**

Add storage API:

```php
public function storeGeneratedPdf(string $originalName, string $pdfBytes): DocumentStoredFile;
```

Requirements:

- non-empty bytes beginning `%PDF-`;
- maximum 16 MiB;
- random `.pdf` storage name;
- private directory; compensating delete available.

Add DocumentService APIs:

```php
public function recordGenerated(
    User $actor,
    DocumentCategory $category,
    DocumentAccessLevel $accessLevel,
    string $title,
    ?string $description,
    string $originalName,
    string $pdfBytes,
    DateTimeImmutable $recordedAt,
    bool $allowGovernanceManager = false,
): Document;
```

```php
public function uploadGovernanceEvidence(
    User $actor,
    DocumentCategory $category,
    string $title,
    ?string $description,
    UploadedFile $file,
    DateTimeImmutable $uploadedAt,
): Document;
```

Authorization rule for `recordGenerated()`:

- manager/admin may use normal official content behavior;
- controller is accepted only when `$allowGovernanceManager=true` and category/access combination is one of the Phase 9 safe combinations (`MEETING_INVITATION/RESIDENTS`, `MEETING_MINUTES/RESIDENTS`, or evidence/GOVERNANCE as called by dedicated services);
- resident/cashier never accepted.

`uploadGovernanceEvidence()` forcibly stores `GOVERNANCE`; caller cannot choose a broader access level.

- [ ] **Step 3: Implement invitation rendering**

`AssemblyInvitationService::renderPdf(GeneralAssembly): string` uses Dompdf with remote disabled and `DejaVu Sans`.

`convene(...)` orchestration must run under `PESSIMISTIC_WRITE` on `GeneralAssembly`:

1. authorize actor;
2. verify DRAFT and required metadata;
3. create electorate snapshot;
4. verify/snapshot rule state;
5. render/store invitation with cleanup on failure;
6. link invitation;
7. call entity `convene()`;
8. commit atomically.

If DB persistence after file storage fails, remove the just-stored PDF.

- [ ] **Step 4: Implement posting evidence**

Factory:

```php
public static function record(
    GeneralAssembly $assembly,
    DateTimeImmutable $postedAt,
    string $postingPlace,
    User $confirmedBy,
    ?Document $evidenceDocument,
    ?string $notes,
): self;
```

Evidence document, when present, must be `GOVERNANCE`.

- [ ] **Step 5: Extend migration, run focused gates, commit**

```bash
vendor/bin/phpunit tests/Controller/GeneralAssemblyManagementControllerTest.php tests/Service/AssemblyInvitationServiceTest.php
php bin/console lint:container
vendor/bin/phpstan analyse --no-progress
git add src/Repository/GeneralAssemblyRepository.php src/Service/GeneralAssemblyService.php src/Service/AssemblyInvitationService.php src/Service/DocumentStorage.php src/Service/DocumentService.php src/Entity/AssemblyInvitationPosting.php src/Controller/GeneralAssemblyManagementController.php templates/management/assemblies templates/assemblies/invitation_pdf.html.twig migrations/Version20260909150000.php tests/Controller/GeneralAssemblyManagementControllerTest.php tests/Service/AssemblyInvitationServiceTest.php
git commit -m "feat: convene General Assembly meetings"
```

---

### Task 6: Attendance, proxies and audit-safe corrections

**Files:**
- Create: `src/Enum/AssemblyAttendanceMode.php`
- Create: `src/Entity/AssemblyAttendance.php`
- Create: `src/Entity/AssemblyAttendanceChange.php`
- Create: `src/Entity/AssemblyProxy.php`
- Create: `src/Repository/AssemblyAttendanceRepository.php`
- Create: `src/Repository/AssemblyProxyRepository.php`
- Create: `src/Service/AssemblyAttendanceService.php`
- Create: `src/Service/AssemblyProxyService.php`
- Modify: `migrations/Version20260909150000.php`
- Test: `tests/Service/AssemblyAttendanceProxyServiceTest.php`

**Interfaces:**
- Produces current effective representation data consumed by quorum and voting services.

- [ ] **Step 1: Write RED tests**

Cover:

- IN_PERSON and ONLINE registration;
- BY_PROXY requires effective proxy;
- STATUTORY_USER_AUTHORITY requires non-blank authority note;
- unique one attendance row per assembly/electorate entry;
- same principal cannot be personally present and proxied;
- proxy requires GOVERNANCE evidence document;
- one effective proxy per principal;
- same representative may represent 1, 2, 3 principals, but fourth is rejected;
- explicit attendance correction creates `AssemblyAttendanceChange` with reason/actor/time;
- proxy revocation records reason/actor/time;
- no attendance/proxy mutation after assembly `CLOSED`.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Service/AssemblyAttendanceProxyServiceTest.php
```

- [ ] **Step 3: Implement repositories and locked services**

Required attendance APIs:

```php
public function register(
    User $actor,
    GeneralAssembly $assembly,
    AssemblyElectorateEntry $entry,
    AssemblyAttendanceMode $mode,
    DateTimeImmutable $registeredAt,
    ?Person $representativePerson = null,
    ?string $representativeName = null,
    ?string $authorityNote = null,
): AssemblyAttendance;
```

```php
public function correct(
    User $actor,
    AssemblyAttendance $attendance,
    AssemblyAttendanceMode $newMode,
    string $reason,
    DateTimeImmutable $changedAt,
): AssemblyAttendanceChange;
```

Required proxy API:

```php
public function register(
    User $actor,
    GeneralAssembly $assembly,
    AssemblyElectorateEntry $principal,
    ?Person $representativePerson,
    string $representativeName,
    string $authorityKind,
    Document $evidenceDocument,
    DateTimeImmutable $registeredAt,
    ?string $notes = null,
): AssemblyProxy;
```

Use pessimistic lock on assembly for proxy-count and representation-race safety.

- [ ] **Step 4: Extend schema**

Add unique attendance `(assembly_id, electorate_entry_id)`, proxy principal supporting unique/index strategy, representative indexes, audit table, and RESTRICT FKs.

- [ ] **Step 5: Run gates and commit**

```bash
vendor/bin/phpunit tests/Service/AssemblyAttendanceProxyServiceTest.php
vendor/bin/phpstan analyse --no-progress
git add src/Enum/AssemblyAttendanceMode.php src/Entity/AssemblyAttendance.php src/Entity/AssemblyAttendanceChange.php src/Entity/AssemblyProxy.php src/Repository/AssemblyAttendanceRepository.php src/Repository/AssemblyProxyRepository.php src/Service/AssemblyAttendanceService.php src/Service/AssemblyProxyService.php migrations/Version20260909150000.php tests/Service/AssemblyAttendanceProxyServiceTest.php
git commit -m "feat: record General Assembly attendance and proxies"
```

---

### Task 7: Persisted live quorum workflow and workbench foundation

**Files:**
- Create: `src/Service/AssemblyQuorumService.php`
- Create: `src/Controller/GeneralAssemblyWorkbenchController.php`
- Create: `templates/management/assemblies/workbench.html.twig`
- Modify: `assets/styles/app.css`
- Test: `tests/Controller/GeneralAssemblyWorkbenchControllerTest.php`

**Interfaces:**
- Uses Task 6 effective attendance to persist Task 3 immutable quorum calculations.

- [ ] **Step 1: Write RED functional/service tests**

Cover:

- only manager/controller/admin access workbench;
- workbench shows exact represented ideal parts and unresolved electorate warnings;
- `POST quorum-check` requires CSRF;
- FIRST_CALL/DELAYED_CALL persist immutable `AssemblyQuorumCheck`;
- subsequent attendance correction does not rewrite previous check;
- latest check query returns newest by `checkedAt,id`;
- REVIEW_REQUIRED is warning-styled and never success-styled;
- meeting can start while latest check is REVIEW_REQUIRED/INVALID, but UI must not label it valid;
- repeated identical POST creates a new explicit historical check only when user actually submits again; no hidden GET side effect.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Controller/GeneralAssemblyWorkbenchControllerTest.php
```

- [ ] **Step 3: Implement AssemblyQuorumService**

```php
public function check(
    User $actor,
    GeneralAssembly $assembly,
    AssemblyQuorumCheckKind $kind,
    DateTimeImmutable $checkedAt,
): AssemblyQuorumCheck;
```

Inside one transaction:

1. authorize actor;
2. lock assembly PESSIMISTIC_WRITE;
3. load electorate;
4. derive currently represented electorate entries from active attendance/proxies;
5. call pure calculator;
6. persist immutable check.

- [ ] **Step 4: Implement workbench GET + quorum POST**

Use explicit route names and CSRF id `assembly_quorum_{id}`. Keep controller thin; calculations stay in service.

- [ ] **Step 5: Run and commit**

```bash
vendor/bin/phpunit tests/Controller/GeneralAssemblyWorkbenchControllerTest.php tests/Service/AssemblyQuorumCalculatorTest.php
vendor/bin/phpstan analyse --no-progress
git add src/Service/AssemblyQuorumService.php src/Controller/GeneralAssemblyWorkbenchController.php templates/management/assemblies/workbench.html.twig assets/styles/app.css tests/Controller/GeneralAssemblyWorkbenchControllerTest.php
git commit -m "feat: add General Assembly quorum workbench"
```

---

### Task 8: Formal voting, vote corrections and resolution calculator

**Files:**
- Create: `src/Enum/AssemblyVoteChoice.php`
- Create: `src/Enum/AssemblyVoteCastMode.php`
- Create: `src/Enum/AssemblyResolutionResult.php`
- Create: `src/Value/AssemblyResolutionCalculation.php`
- Create: `src/Entity/AssemblyVote.php`
- Create: `src/Entity/AssemblyVoteCorrection.php`
- Create: `src/Entity/AssemblyResolution.php`
- Create: `src/Repository/AssemblyVoteRepository.php`
- Create: `src/Service/AssemblyResolutionCalculator.php`
- Create: `src/Service/AssemblyVotingService.php`
- Modify: `src/Controller/GeneralAssemblyWorkbenchController.php`
- Modify: `templates/management/assemblies/workbench.html.twig`
- Modify: `migrations/Version20260909150000.php`
- Test: `tests/Service/AssemblyResolutionCalculatorTest.php`
- Test: `tests/Service/AssemblyVotingServiceTest.php`
- Modify test: `tests/Controller/GeneralAssemblyWorkbenchControllerTest.php`

**Interfaces:**
- Produces one effective weighted formal vote per electorate entry/item and immutable resolution result.

- [ ] **Step 1: Write RED pure calculator tests**

Test all exact denominator/comparison combinations:

```php
// AT_LEAST 50 of ALL_COMMON_IDEAL_PARTS: exactly 50 accepted
// GREATER_THAN 50: exactly 50 rejected, 50.00000001 accepted
// REPRESENTED_AT_MEETING denominator uses represented snapshot, not 100
// REVIEW_REQUIRED rule or incomplete denominator -> REVIEW_REQUIRED
```

`AssemblyResolutionCalculation` returns exact for/against/abstain/denominator/threshold plus result and explanation.

- [ ] **Step 2: Write RED voting-service tests**

Cover:

- only effective represented principals vote;
- exact weight comes from electorate snapshot;
- operator cannot provide custom weight;
- one effective vote per `(agenda,item,entry)`;
- IN_PERSON/ONLINE/PROXY/STATUTORY_USER_AUTHORITY modes match representation;
- FOR/AGAINST/ABSTAIN;
- correction before resolve creates audit row and changes effective choice;
- correction after resolve rejected;
- opening item freezes `finalResolutionText`;
- resolving item creates one resolution;
- double resolve deterministic/no duplicate;
- Community poll data has zero effect.

- [ ] **Step 3: Implement pure resolution calculator**

Required signature:

```php
/** @param list<AssemblyVote> $votes */
public function calculate(
    AssemblyAgendaItem $item,
    array $votes,
    string $allCommonIdealPartsPercent,
    string $representedAtMeetingPercent,
): AssemblyResolutionCalculation;
```

Apply `ExactDecimal::compare()` with exact `GREATER_THAN`/`AT_LEAST` semantics.

- [ ] **Step 4: Implement locked voting service**

Required APIs:

```php
public function openItem(User $actor, AssemblyAgendaItem $item, string $finalResolutionText, DateTimeImmutable $openedAt): void;
public function recordVote(User $actor, AssemblyAgendaItem $item, AssemblyElectorateEntry $entry, AssemblyVoteChoice $choice, DateTimeImmutable $recordedAt): AssemblyVote;
public function correctVote(User $actor, AssemblyVote $vote, AssemblyVoteChoice $newChoice, string $reason, DateTimeImmutable $changedAt): AssemblyVoteCorrection;
public function resolveItem(User $actor, AssemblyAgendaItem $item, DateTimeImmutable $resolvedAt): AssemblyResolution;
```

Lock agenda item/assembly for race safety. Unique DB constraint is final duplicate-vote defense.

- [ ] **Step 5: Add workbench POST actions with CSRF**

Use ids:

```text
assembly_item_open_{itemId}
assembly_vote_{itemId}_{entryId}
assembly_vote_correct_{voteId}
assembly_item_resolve_{itemId}
```

No vote mutation through GET.

- [ ] **Step 6: Extend migration, run gates, commit**

```bash
vendor/bin/phpunit tests/Service/AssemblyResolutionCalculatorTest.php tests/Service/AssemblyVotingServiceTest.php tests/Controller/GeneralAssemblyWorkbenchControllerTest.php
vendor/bin/phpstan analyse --no-progress
git add src/Enum/AssemblyVoteChoice.php src/Enum/AssemblyVoteCastMode.php src/Enum/AssemblyResolutionResult.php src/Value/AssemblyResolutionCalculation.php src/Entity/AssemblyVote.php src/Entity/AssemblyVoteCorrection.php src/Entity/AssemblyResolution.php src/Repository/AssemblyVoteRepository.php src/Service/AssemblyResolutionCalculator.php src/Service/AssemblyVotingService.php src/Controller/GeneralAssemblyWorkbenchController.php templates/management/assemblies/workbench.html.twig migrations/Version20260909150000.php tests/Service/AssemblyResolutionCalculatorTest.php tests/Service/AssemblyVotingServiceTest.php tests/Controller/GeneralAssemblyWorkbenchControllerTest.php
git commit -m "feat: record formal General Assembly votes"
```

---

### Task 9: Absentee window and declaration evidence

**Files:**
- Create: `src/Enum/AssemblyAbsenteeSignatureMode.php`
- Create: `src/Entity/AssemblyAbsenteeWindow.php`
- Create: `src/Entity/AssemblyAbsenteeDeclaration.php`
- Create: `src/Entity/AssemblyAbsenteeDeclarationVote.php`
- Create: `src/Service/AssemblyAbsenteeVotingService.php`
- Modify: `src/Service/AssemblyVotingService.php`
- Modify: `src/Controller/GeneralAssemblyManagementController.php`
- Modify: `templates/management/assemblies/workbench.html.twig`
- Modify: `migrations/Version20260909150000.php`
- Test: `tests/Service/AssemblyAbsenteeVotingServiceTest.php`

**Interfaces:**
- Adds explicit optional post-meeting formal vote evidence; does not expose resident self-service vote route.

- [ ] **Step 1: Write RED tests**

Cover:

- only CLOSED meeting may operate post-meeting absentee workflow;
- one effective window per meeting;
- only agenda items whose rule/kind is explicitly marked eligible may bind to window;
- deadline must be after open time and persisted;
- GOVERNANCE evidence document required;
- declaration after deadline rejected;
- declaration after close rejected;
- duplicate declaration per entry rejected;
- duplicate effective vote for same principal/item rejected when in-meeting vote already exists;
- declaration choice can be FOR/AGAINST/ABSTAIN;
- electronic signature mode records evidence only, with no cryptographic-certification claim;
- closing window resolves every deferred item exactly once.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Service/AssemblyAbsenteeVotingServiceTest.php
```

- [ ] **Step 3: Implement entities and service**

Required APIs:

```php
public function openWindow(User $actor, GeneralAssembly $assembly, array $agendaItems, DateTimeImmutable $openedAt, DateTimeImmutable $deadlineAt, string $legalBasis): AssemblyAbsenteeWindow;
public function registerDeclaration(User $actor, AssemblyAbsenteeWindow $window, AssemblyElectorateEntry $entry, Document $evidence, AssemblyAbsenteeSignatureMode $signatureMode, array $choices, DateTimeImmutable $submittedAt, ?string $notes = null): AssemblyAbsenteeDeclaration;
public function closeWindow(User $actor, AssemblyAbsenteeWindow $window, DateTimeImmutable $closedAt): void;
```

`$choices` is `array<int, AssemblyVoteChoice>` keyed by agenda-item id; validate every id belongs to window.

- [ ] **Step 4: Add management forms/actions only**

No resident route. CSRF ids:

```text
assembly_absentee_open_{assemblyId}
assembly_absentee_declaration_{windowId}
assembly_absentee_close_{windowId}
```

- [ ] **Step 5: Extend migration, run gates, commit**

```bash
vendor/bin/phpunit tests/Service/AssemblyAbsenteeVotingServiceTest.php tests/Service/AssemblyVotingServiceTest.php
vendor/bin/phpstan analyse --no-progress
git add src/Enum/AssemblyAbsenteeSignatureMode.php src/Entity/AssemblyAbsenteeWindow.php src/Entity/AssemblyAbsenteeDeclaration.php src/Entity/AssemblyAbsenteeDeclarationVote.php src/Service/AssemblyAbsenteeVotingService.php src/Service/AssemblyVotingService.php src/Controller/GeneralAssemblyManagementController.php templates/management/assemblies/workbench.html.twig migrations/Version20260909150000.php tests/Service/AssemblyAbsenteeVotingServiceTest.php
git commit -m "feat: add General Assembly absentee voting evidence"
```

---

### Task 10: Minutes generation, atomic finalization and append-only addenda

**Files:**
- Create: `src/Entity/AssemblyMinutesCorrection.php`
- Create: `src/Service/AssemblyMinutesService.php`
- Create: `templates/management/assemblies/minutes_preview.html.twig`
- Create: `templates/assemblies/minutes_pdf.html.twig`
- Modify: `src/Entity/GeneralAssembly.php`
- Modify: `src/Controller/GeneralAssemblyManagementController.php`
- Modify: `migrations/Version20260909150000.php`
- Test: `tests/Service/AssemblyMinutesServiceTest.php`

**Interfaces:**
- Finalization atomically produces/stores/links the final `MEETING_MINUTES` resident document and moves meeting to `MINUTES_FINALIZED`.

- [ ] **Step 1: Write RED minutes tests**

Cover:

- preview contains meeting identification, convening basis, latest/all quorum checks, attendance, proxies, represented ideal parts, agenda, emergency reasons, final resolution wording, vote totals/results, absentee summary, REVIEW_REQUIRED warnings;
- Bulgarian/Cyrillic PDF begins `%PDF-` and renders with DejaVu Sans;
- remote resources disabled;
- finalization blocked while meeting not CLOSED;
- blocked while absentee window open;
- blocked while any item unresolved/not REVIEW_REQUIRED;
- required chairperson/secretary/formal metadata validation according to application rules;
- finalization stores `MEETING_MINUTES` with `RESIDENTS` access;
- controller is allowed through Phase 9 generated-document path;
- storage/persistence failure removes generated file and meeting remains CLOSED;
- double finalization does not create duplicate document;
- finalized meeting immutable;
- correction/addendum requires document and reason and does not alter old votes/results.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Service/AssemblyMinutesServiceTest.php
```

- [ ] **Step 3: Implement structured minutes view model inside service**

Keep Twig free of legal calculations. Service assembles already-persisted snapshots/results.

Required APIs:

```php
/** @return array<string, mixed> */
public function buildViewModel(GeneralAssembly $assembly): array;
public function renderPdf(GeneralAssembly $assembly): string;
public function finalize(User $actor, GeneralAssembly $assembly, DateTimeImmutable $finalizedAt): Document;
public function addCorrection(User $actor, GeneralAssembly $assembly, string $reason, Document $document, DateTimeImmutable $recordedAt): AssemblyMinutesCorrection;
```

- [ ] **Step 4: Implement atomic finalization**

Under `PESSIMISTIC_WRITE`:

1. authorize;
2. verify CLOSED + prerequisites;
3. render PDF;
4. store generated file;
5. create/persist Document and link to assembly;
6. compute/store `minutesDueOn` if not already snapshotted;
7. call entity finalization transition;
8. commit;
9. compensate file on throwable.

- [ ] **Step 5: Extend migration, run gates, commit**

```bash
vendor/bin/phpunit tests/Service/AssemblyMinutesServiceTest.php
php bin/console lint:container
vendor/bin/phpstan analyse --no-progress
git add src/Entity/AssemblyMinutesCorrection.php src/Service/AssemblyMinutesService.php templates/management/assemblies/minutes_preview.html.twig templates/assemblies/minutes_pdf.html.twig src/Entity/GeneralAssembly.php src/Controller/GeneralAssemblyManagementController.php migrations/Version20260909150000.php tests/Service/AssemblyMinutesServiceTest.php
git commit -m "feat: finalize General Assembly minutes"
```

---

### Task 11: Resident meeting views, navigation and official-vs-community separation

**Files:**
- Create: `src/Controller/GeneralAssemblyController.php`
- Create: `templates/assemblies/index.html.twig`
- Create: `templates/assemblies/show.html.twig`
- Create: `templates/assemblies/minutes.html.twig`
- Modify: `src/Repository/GeneralAssemblyRepository.php`
- Modify: `templates/base.html.twig`
- Modify: `templates/dashboard/index.html.twig`
- Modify: `assets/styles/app.css`
- Test: `tests/Controller/GeneralAssemblyControllerTest.php`
- Test: `tests/Controller/GeneralAssemblyNavigationTest.php`

**Interfaces:**
- Resident surface is read-only and uses existing authenticated Document download route.

- [ ] **Step 1: Write RED resident visibility tests**

Cover:

- anonymous -> login;
- active resident sees CONVENED/IN_PROGRESS/CLOSED/MINUTES_FINALIZED, not DRAFT;
- inactive user denied;
- resident sees invitation after CONVENED;
- resident sees agenda and published/persisted results appropriate to meeting state;
- finalized minutes page available only after finalization;
- resident/cashier cannot access governance evidence document through guessed ID;
- invitation/minutes RESIDENTS document remains downloadable through Phase 8 route;
- no resident vote POST route exists;
- rendered text clearly labels General Assembly as official and does not show Community reaction/poll controls.

- [ ] **Step 2: Verify RED**

```bash
vendor/bin/phpunit tests/Controller/GeneralAssemblyControllerTest.php tests/Controller/GeneralAssemblyNavigationTest.php
```

- [ ] **Step 3: Implement resident repository queries/controllers/templates**

Repository methods:

```php
/** @return list<GeneralAssembly> */
public function findResidentVisible(): array;
public function findResidentVisibleById(int $id): ?GeneralAssembly;
```

Sort by `scheduledAt DESC, id DESC` for history; dashboard may query next upcoming separately.

- [ ] **Step 4: Add navigation**

Resident nav: `Общи събрания`.

Management nav visible to manager/controller/admin: `Управление на ОС`.

Keep Community nav/polls separate.

- [ ] **Step 5: Run and commit**

```bash
vendor/bin/phpunit tests/Controller/GeneralAssemblyControllerTest.php tests/Controller/GeneralAssemblyNavigationTest.php
vendor/bin/phpstan analyse --no-progress
git add src/Controller/GeneralAssemblyController.php src/Repository/GeneralAssemblyRepository.php templates/assemblies templates/base.html.twig templates/dashboard/index.html.twig assets/styles/app.css tests/Controller/GeneralAssemblyControllerTest.php tests/Controller/GeneralAssemblyNavigationTest.php
git commit -m "feat: add resident General Assembly views"
```

---

### Task 12: Final security, audit, schema and CI hardening

**Files:**
- Modify: all Phase 9 entities/services/controllers only where final review identifies a concrete acceptance gap
- Modify: `migrations/Version20260909150000.php`
- Modify: `tests/Doctrine/GeneralAssemblySchemaTest.php`
- Modify: `tests/Controller/GeneralAssemblyManagementControllerTest.php`
- Modify: `tests/Controller/GeneralAssemblyWorkbenchControllerTest.php`
- Modify: `tests/Controller/GeneralAssemblyControllerTest.php`
- Modify: `.github/workflows/ci.yml` only if CI drift is found
- Modify: `docs/roadmap.md` only if project convention marks completed phases there; otherwise do not rewrite roadmap semantics

**Interfaces:**
- Produces the merge-ready Phase 9 exact-head tree.

- [ ] **Step 1: Expand final schema assertions for every Phase 9 table**

Verify all final tables listed in the spec exist and all important unique/index/FK guarantees are exact. In addition to Task 4 core assertions, cover:

```text
uniq_assembly_attendance_entry
uniq_assembly_vote_item_entry
uniq_assembly_resolution_item
uniq_absentee_window_assembly
uniq_absentee_declaration_window_entry
idx_proxy_assembly_representative
idx_vote_item_choice
idx_absentee_deadline
```

Where Doctrine-generated join-table index names are unavoidable, assert the exact Doctrine-generated name present in the final mapping/migration.

- [ ] **Step 2: Add final security regression matrix**

Run role coverage across all management POST routes:

```text
ROLE_USER       -> denied
ROLE_CASHIER    -> denied
ROLE_CONTROLLER -> allowed
ROLE_MANAGER    -> allowed
ROLE_ADMIN      -> allowed
```

Every POST gets a valid-CSRF and invalid-CSRF assertion. Verify protected governance document guessed IDs return 404 through resident download path.

- [ ] **Step 3: Add audit immutability regression tests**

Explicitly verify after `MINUTES_FINALIZED`:

- ordinary agenda cannot change;
- electorate snapshots cannot be regenerated/replaced;
- attendance cannot change;
- proxy cannot register/revoke;
- quorum checks remain append-only historical rows;
- votes cannot create/correct;
- resolutions cannot recalculate through normal service;
- absentee window/declaration cannot mutate;
- minutes can only receive append-only correction/addendum.

- [ ] **Step 4: Run focused Phase 9 suite**

```bash
vendor/bin/phpunit \
  tests/Entity/GeneralAssemblyDomainTest.php \
  tests/Util/ExactDecimalTest.php \
  tests/Service/AssemblyElectorateSnapshotServiceTest.php \
  tests/Service/AssemblyQuorumCalculatorTest.php \
  tests/Doctrine/GeneralAssemblySchemaTest.php \
  tests/Security/GeneralAssemblyAccessPolicyTest.php \
  tests/Controller/GeneralAssemblyManagementControllerTest.php \
  tests/Service/AssemblyAttendanceProxyServiceTest.php \
  tests/Controller/GeneralAssemblyWorkbenchControllerTest.php \
  tests/Service/AssemblyResolutionCalculatorTest.php \
  tests/Service/AssemblyVotingServiceTest.php \
  tests/Service/AssemblyAbsenteeVotingServiceTest.php \
  tests/Service/AssemblyMinutesServiceTest.php \
  tests/Controller/GeneralAssemblyControllerTest.php \
  tests/Controller/GeneralAssemblyNavigationTest.php
```

Expected: PASS.

- [ ] **Step 5: Run complete local/CI-equivalent gate**

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
php bin/console lint:container
php bin/console doctrine:schema:validate --skip-sync --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
php bin/console doctrine:migrations:migrate prev --no-interaction --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

Expected: all commands exit 0.

- [ ] **Step 6: Review physical diff**

```bash
git diff --stat main...HEAD
git diff --check main...HEAD
git status --short
```

Verify:

- no public file storage;
- no Community formal-vote coupling;
- no resident formal vote endpoint;
- no float legal math;
- no hard delete/cascade remove;
- no Phase 9 PHPStan ignores;
- no debug code/temporary fixtures.

- [ ] **Step 7: Commit final hardening**

```bash
git add src tests migrations .github composer.json composer.lock templates assets docs/roadmap.md
git commit -m "test: harden General Assembly workflow"
```

If `docs/roadmap.md` was intentionally unchanged, omit it from `git add`.

- [ ] **Step 8: Open/update PR and require exact-head CI**

PR title:

```text
Phase 9: General Assembly workflow
```

PR summary must mention:

- immutable electorate/legal-rule snapshots;
- attendance/proxies and exact ideal-parts quorum;
- formal weighted voting separate from Community polls;
- optional evidence-backed absentee workflow;
- private invitation/minutes/evidence documents;
- finalized minutes/addenda immutability;
- no resident self-service statutory vote claim.

Do not merge until the PR workflow run for the exact branch HEAD reports green Composer, container, Doctrine mapping, MariaDB round-trip, PHPUnit and PHPStan.
