# Phase 7 Signals and Maintenance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the roadmap Phase 7 maintenance vertical slice: private resident signals with attachments, management assignment/status history, asset/supplier/contract/event registries, and warranty/inspection reminders.

**Architecture:** Keep resident signal workflows separate from management registry workflows. Persist audit-critical history and maintenance events, store uploaded files privately under `var/storage/maintenance`, and derive reminders from asset dates rather than creating a notification subsystem before Phase 8.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7/DBAL 4, MariaDB 10.11, Twig, AssetMapper, PHPUnit 12.5, PHPStan 2.2.

**Spec:** `docs/superpowers/specs/2026-09-08-phase7-maintenance-design.md`

## Global Constraints

- Every maintenance route is authenticated.
- Residents may view only signals they submitted; managers/admins may view all signals.
- Management mutation rights are limited to `ROLE_MANAGER` and `ROLE_ADMIN`.
- All POST mutations use CSRF protection.
- Attachments live outside `public/` and are served only through an authorized controller.
- Allowed attachment MIME types: JPEG, PNG, WebP, PDF; maximum 8 MiB.
- No hard-delete routes.
- Audit/history associations use `ON DELETE RESTRICT`.
- Assignment/status/event updates that mutate shared state use transactions and pessimistic locks.
- Do not add notifications, Messenger jobs, Viber, Mercure, expense linkage, or Phase 8/10 features.
- Do not weaken PHPStan or add Phase 7 ignore rules.

---

### Task 1: Signal enums and core entities

**Files:**
- Create: `src/Enum/MaintenanceSignalCategory.php`
- Create: `src/Enum/MaintenanceSignalPriority.php`
- Create: `src/Enum/MaintenanceSignalStatus.php`
- Create: `src/Entity/MaintenanceSignal.php`
- Create: `src/Entity/MaintenanceSignalStatusChange.php`
- Test: `tests/Entity/MaintenanceSignalTest.php`

**Interfaces:**
- `MaintenanceSignal::open(User $submittedBy, MaintenanceSignalCategory $category, MaintenanceSignalPriority $priority, string $title, string $description, string $location, DateTimeImmutable $createdAt, ?BuildingAsset $asset = null): self`
- `MaintenanceSignal::assign(?User $assignedTo, DateTimeImmutable $updatedAt): void`
- `MaintenanceSignal::changeStatus(MaintenanceSignalStatus $status, DateTimeImmutable $updatedAt): MaintenanceSignalStatus`
- `MaintenanceSignalStatusChange::record(MaintenanceSignal $signal, ?MaintenanceSignalStatus $fromStatus, MaintenanceSignalStatus $toStatus, User $changedBy, DateTimeImmutable $changedAt, string $note = ''): self`
- Enum `labelBg(): string` on all three enums.

- [ ] **Step 1: Write failing entity tests**

Cover:

```php
public function testSignalOpensWithNormalizedRequiredFieldsAndUtcTimestamps(): void;
public function testSignalRejectsBlankTitleDescriptionOrLocation(): void;
public function testSignalStatusCannotChangeToSameStatus(): void;
public function testAssignmentMayBeSetAndCleared(): void;
public function testStatusHistoryNormalizesNoteAndUtcTimestamp(): void;
```

Assert initial status is `MaintenanceSignalStatus::OPEN`, default priority is supplied explicitly by caller, and timestamps are converted to UTC.

- [ ] **Step 2: Run the focused test and confirm RED**

Run:

```bash
vendor/bin/phpunit tests/Entity/MaintenanceSignalTest.php
```

Expected: fail because enums/entities do not exist.

- [ ] **Step 3: Implement enums and entities minimally**

Use Doctrine attributes, `strict_types=1`, private constructors with named static factories, trimmed non-empty strings, `datetime_immutable`, and explicit FK index names in `#[ORM\Table]` metadata.

`MaintenanceSignal::changeStatus()` returns the previous status so the service can create an exact history row.

- [ ] **Step 4: Run focused tests and PHPStan on new files**

```bash
vendor/bin/phpunit tests/Entity/MaintenanceSignalTest.php
vendor/bin/phpstan analyse src/Enum/MaintenanceSignalCategory.php src/Enum/MaintenanceSignalPriority.php src/Enum/MaintenanceSignalStatus.php src/Entity/MaintenanceSignal.php src/Entity/MaintenanceSignalStatusChange.php tests/Entity/MaintenanceSignalTest.php --no-progress
```

Expected: PASS / no errors.

- [ ] **Step 5: Commit**

```bash
git add src/Enum/MaintenanceSignal*.php src/Entity/MaintenanceSignal*.php tests/Entity/MaintenanceSignalTest.php
git commit -m "feat: add structured maintenance signals"
```

---

### Task 2: Signal application service and immutable history

**Files:**
- Create: `src/Service/MaintenanceSignalService.php`
- Test: `tests/Service/MaintenanceSignalServiceTest.php`

**Interfaces:**

```php
public function create(
    User $submittedBy,
    MaintenanceSignalCategory $category,
    MaintenanceSignalPriority $priority,
    string $title,
    string $description,
    string $location,
    DateTimeImmutable $createdAt,
    ?BuildingAsset $asset = null,
): MaintenanceSignal;

public function assign(MaintenanceSignal $signal, ?User $assignedTo, DateTimeImmutable $updatedAt): void;

public function changeStatus(
    MaintenanceSignal $signal,
    MaintenanceSignalStatus $status,
    User $changedBy,
    DateTimeImmutable $changedAt,
    string $note = '',
): MaintenanceSignalStatusChange;
```

- [ ] **Step 1: Write failing service tests**

Use `KernelTestCase` + `SchemaTool`. Cover:

```php
public function testCreatePersistsSignalAndExactlyOneInitialHistoryRow(): void;
public function testAssignPersistsAssigneeUnderTransaction(): void;
public function testStatusChangePersistsAppendOnlyHistory(): void;
public function testNoOpStatusChangeDoesNotAppendHistory(): void;
```

The initial row must be `null -> OPEN` and use the submitting user as `changedBy`.

- [ ] **Step 2: Run focused test and confirm RED**

```bash
vendor/bin/phpunit tests/Service/MaintenanceSignalServiceTest.php
```

- [ ] **Step 3: Implement service**

Use `EntityManagerInterface::wrapInTransaction()`. Assignment and status change acquire `LockMode::PESSIMISTIC_WRITE` on the signal. Return transaction results directly so Doctrine generic typing remains PHPStan-clean.

- [ ] **Step 4: Run focused tests**

```bash
vendor/bin/phpunit tests/Service/MaintenanceSignalServiceTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/MaintenanceSignalService.php tests/Service/MaintenanceSignalServiceTest.php
git commit -m "feat: add maintenance signal workflows"
```

---

### Task 3: Assets, suppliers, contracts and maintenance events

**Files:**
- Create: `src/Enum/BuildingAssetCategory.php`
- Create: `src/Enum/MaintenanceEventType.php`
- Create: `src/Entity/BuildingAsset.php`
- Create: `src/Entity/MaintenanceSupplier.php`
- Create: `src/Entity/MaintenanceContract.php`
- Create: `src/Entity/MaintenanceEvent.php`
- Test: `tests/Entity/MaintenanceRegistryEntitiesTest.php`

**Interfaces:**

```php
BuildingAsset::register(
    string $name,
    BuildingAssetCategory $category,
    string $location,
    ?string $manufacturer = null,
    ?string $model = null,
    ?string $serialNumber = null,
    ?DateTimeImmutable $installedAt = null,
    ?DateTimeImmutable $warrantyUntil = null,
    ?int $inspectionIntervalMonths = null,
    ?DateTimeImmutable $nextInspectionAt = null,
): self;

public function scheduleNextInspection(?DateTimeImmutable $date): void;
public function deactivate(): void;

MaintenanceSupplier::register(string $name, ?string $registrationNumber = null, ?string $contactPerson = null, ?string $email = null, ?string $phone = null, ?string $address = null, ?string $note = null): self;

MaintenanceContract::create(MaintenanceSupplier $supplier, string $title, DateTimeImmutable $startsAt, DateTimeImmutable $createdAt, ?BuildingAsset $asset = null, ?DateTimeImmutable $endsAt = null, ?string $reference = null, ?string $note = null): self;

MaintenanceEvent::record(BuildingAsset $asset, MaintenanceEventType $type, DateTimeImmutable $performedAt, string $summary, User $recordedBy, DateTimeImmutable $recordedAt, ?MaintenanceSupplier $supplier = null, ?MaintenanceContract $contract = null, ?MaintenanceSignal $signal = null, ?string $note = null, ?DateTimeImmutable $nextInspectionAt = null): self;
```

- [ ] **Step 1: Write failing entity tests**

Cover:

```php
public function testAssetValidatesInspectionIntervalAndDates(): void;
public function testSupplierRequiresNameAndValidatesOptionalEmail(): void;
public function testContractRejectsEndBeforeStart(): void;
public function testEventRejectsContractSupplierMismatch(): void;
public function testEventRejectsContractAssetMismatch(): void;
public function testEventRejectsSignalAssetMismatch(): void;
public function testEventAcceptsGeneralContractForAsset(): void;
```

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
vendor/bin/phpunit tests/Entity/MaintenanceRegistryEntitiesTest.php
```

- [ ] **Step 3: Implement enums/entities**

Use UTC only for audit timestamps (`createdAt`, `recordedAt`). Treat `installedAt`, `warrantyUntil`, `startsAt`, `endsAt`, `performedAt`, and `nextInspectionAt` as date/calendar values supplied by the caller.

- [ ] **Step 4: Run focused tests**

```bash
vendor/bin/phpunit tests/Entity/MaintenanceRegistryEntitiesTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Enum/BuildingAssetCategory.php src/Enum/MaintenanceEventType.php src/Entity/BuildingAsset.php src/Entity/MaintenanceSupplier.php src/Entity/MaintenanceContract.php src/Entity/MaintenanceEvent.php tests/Entity/MaintenanceRegistryEntitiesTest.php
git commit -m "feat: add maintenance registry domain"
```

---

### Task 4: Registry and reminder services

**Files:**
- Create: `src/Service/MaintenanceRegistryService.php`
- Create: `src/Service/MaintenanceReminderService.php`
- Create: `src/Value/MaintenanceReminder.php`
- Create: `src/Enum/MaintenanceReminderType.php`
- Test: `tests/Service/MaintenanceRegistryServiceTest.php`
- Test: `tests/Service/MaintenanceReminderServiceTest.php`

**Interfaces:**

```php
public function createAsset(...same scalar/date arguments as BuildingAsset::register...): BuildingAsset;
public function createSupplier(...same arguments as MaintenanceSupplier::register...): MaintenanceSupplier;
public function createContract(...same arguments as MaintenanceContract::create...): MaintenanceContract;
public function recordEvent(...same arguments as MaintenanceEvent::record...): MaintenanceEvent;
```

`recordEvent()` locks the asset when `nextInspectionAt` is supplied, persists the event, and updates the asset in the same transaction.

```php
enum MaintenanceReminderType: string
{
    case WARRANTY_EXPIRED = 'warranty_expired';
    case WARRANTY_DUE = 'warranty_due';
    case INSPECTION_OVERDUE = 'inspection_overdue';
    case INSPECTION_DUE = 'inspection_due';
}

final readonly class MaintenanceReminder
{
    public function __construct(
        public BuildingAsset $asset,
        public MaintenanceReminderType $type,
        public DateTimeImmutable $dueAt,
        public int $daysDelta,
    ) {}
}

/** @return list<MaintenanceReminder> */
public function build(DateTimeImmutable $today, array $assets): array;
```

- [ ] **Step 1: Write failing registry/reminder tests**

Cover event atomic update and reminder boundaries exactly at 60/30 days, overdue, expired, and inactive assets omitted.

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
vendor/bin/phpunit tests/Service/MaintenanceRegistryServiceTest.php tests/Service/MaintenanceReminderServiceTest.php
```

- [ ] **Step 3: Implement minimal services/value object**

Sort reminders by `dueAt ASC`, then asset id/name for deterministic output. Do not persist reminders.

- [ ] **Step 4: Run focused tests**

```bash
vendor/bin/phpunit tests/Service/MaintenanceRegistryServiceTest.php tests/Service/MaintenanceReminderServiceTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Service/MaintenanceRegistryService.php src/Service/MaintenanceReminderService.php src/Value/MaintenanceReminder.php src/Enum/MaintenanceReminderType.php tests/Service/MaintenanceRegistryServiceTest.php tests/Service/MaintenanceReminderServiceTest.php
git commit -m "feat: add maintenance registry workflows and reminders"
```

---

### Task 5: Private attachment storage and metadata

**Files:**
- Create: `src/Entity/MaintenanceAttachment.php`
- Create: `src/Value/MaintenanceStoredFile.php`
- Create: `src/Service/MaintenanceAttachmentStorage.php`
- Create: `src/Service/MaintenanceAttachmentService.php`
- Test: `tests/Entity/MaintenanceAttachmentTest.php`
- Test: `tests/Service/MaintenanceAttachmentStorageTest.php`
- Test: `tests/Service/MaintenanceAttachmentServiceTest.php`

**Interfaces:**

```php
MaintenanceAttachment::record(MaintenanceSignal $signal, User $uploadedBy, string $originalName, string $storageName, string $mimeType, int $sizeBytes, DateTimeImmutable $uploadedAt): self;

final readonly class MaintenanceStoredFile
{
    public function __construct(
        public string $originalName,
        public string $storageName,
        public string $mimeType,
        public int $sizeBytes,
        public string $absolutePath,
    ) {}
}

public function store(UploadedFile $file): MaintenanceStoredFile;
public function pathFor(string $storageName): string;
public function remove(string $storageName): void;

public function add(User $actor, MaintenanceSignal $signal, UploadedFile $file, DateTimeImmutable $uploadedAt): MaintenanceAttachment;
```

`MaintenanceAttachmentStorage` constructor:

```php
public function __construct(
    #[Autowire('%kernel.project_dir%/var/storage/maintenance')]
    private readonly string $storageDirectory,
) {}
```

- [ ] **Step 1: Write failing validation/storage tests**

Use temporary `UploadedFile` fixtures. Cover allowed JPEG/PDF, rejected text/PHP, rejected >8 MiB, randomized filename, path traversal rejection in `pathFor()`, and file cleanup on service persistence failure.

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
vendor/bin/phpunit tests/Entity/MaintenanceAttachmentTest.php tests/Service/MaintenanceAttachmentStorageTest.php tests/Service/MaintenanceAttachmentServiceTest.php
```

- [ ] **Step 3: Implement storage and service**

Determine MIME from `UploadedFile::getMimeType()`, never from extension supplied by the user. Map MIME to fixed extensions: `jpg`, `png`, `webp`, `pdf`. Generate `bin2hex(random_bytes(16)).'.'.$extension`.

The service stores first, then persists metadata in `wrapInTransaction()`. On any thrown `Throwable` after successful storage, call `remove()` and rethrow.

- [ ] **Step 4: Run focused tests**

```bash
vendor/bin/phpunit tests/Entity/MaintenanceAttachmentTest.php tests/Service/MaintenanceAttachmentStorageTest.php tests/Service/MaintenanceAttachmentServiceTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Entity/MaintenanceAttachment.php src/Value/MaintenanceStoredFile.php src/Service/MaintenanceAttachmentStorage.php src/Service/MaintenanceAttachmentService.php tests/Entity/MaintenanceAttachmentTest.php tests/Service/MaintenanceAttachmentStorageTest.php tests/Service/MaintenanceAttachmentServiceTest.php
git commit -m "feat: add private maintenance attachments"
```

---

### Task 6: MariaDB migration and schema integrity tests

**Files:**
- Create: `migrations/Version20260908200000.php`
- Create: `tests/Doctrine/MaintenanceSchemaTest.php`

**Interfaces:**

Tables:

```text
maintenance_signal
maintenance_signal_status_change
maintenance_attachment
building_asset
maintenance_supplier
maintenance_contract
maintenance_event
```

- [ ] **Step 1: Write failing schema test**

Assert every to-one maintenance association has `onDelete: RESTRICT` and explicit indexes exist for all FK columns. Assert maintenance audit/history tables do not expose cascade delete.

- [ ] **Step 2: Run schema test and confirm RED before migration**

```bash
vendor/bin/phpunit tests/Doctrine/MaintenanceSchemaTest.php
```

- [ ] **Step 3: Write migration matching ORM metadata exactly**

Use MariaDB-compatible `VARCHAR`, `INT`, `BIGINT`, `DATETIME`, `TINYINT(1)` and explicit index names from entity metadata. `down()` drops tables in reverse dependency order.

- [ ] **Step 4: Verify MariaDB round trip**

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
php bin/console doctrine:migrations:migrate prev --no-interaction --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
```

Expected: mapping/schema in sync both times.

- [ ] **Step 5: Run schema test**

```bash
vendor/bin/phpunit tests/Doctrine/MaintenanceSchemaTest.php
```

- [ ] **Step 6: Commit**

```bash
git add migrations/Version20260908200000.php tests/Doctrine/MaintenanceSchemaTest.php
git commit -m "feat: persist phase 7 maintenance data"
```

---

### Task 7: Resident maintenance HTTP flow

**Files:**
- Create: `src/Controller/MaintenanceController.php`
- Create: `templates/maintenance/index.html.twig`
- Create: `templates/maintenance/signal_new.html.twig`
- Create: `templates/maintenance/signal_show.html.twig`
- Test: `tests/Controller/MaintenanceControllerTest.php`

**Routes:**

```text
GET       /maintenance                              app_maintenance_index
GET|POST  /maintenance/signal/new                   app_maintenance_signal_new
GET       /maintenance/signal/{id}                  app_maintenance_signal_show
POST      /maintenance/signal/{id}/attachment       app_maintenance_signal_attachment
GET       /maintenance/attachment/{id}/download     app_maintenance_attachment_download
```

- [ ] **Step 1: Write failing WebTestCase**

Cover:

```php
public function testAnonymousUserIsRedirectedToLogin(): void;
public function testResidentCanCreateAndViewOwnSignal(): void;
public function testResidentCannotViewAnotherResidentsSignal(): void;
public function testResidentCanUploadAndDownloadOwnAttachment(): void;
public function testResidentCannotDownloadAnotherResidentsAttachment(): void;
public function testInvalidCsrfBlocksSignalCreateAndAttachmentUpload(): void;
```

Render and submit real Twig forms. Keep fixtures created before BrowserKit write lifecycles to avoid detached ORM entities.

- [ ] **Step 2: Run focused functional test and confirm RED**

```bash
vendor/bin/phpunit tests/Controller/MaintenanceControllerTest.php
```

- [ ] **Step 3: Implement controller and templates**

`requireUser()` accepts active authenticated `App\Entity\User`. `canManage()` checks manager/admin. `requireVisibleSignal()` returns signal only when submitted by current user or management role.

For download, resolve file through storage service only after authorization, then return `BinaryFileResponse`/`AbstractController::file()` with original filename.

- [ ] **Step 4: Run focused functional test**

```bash
vendor/bin/phpunit tests/Controller/MaintenanceControllerTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Controller/MaintenanceController.php templates/maintenance tests/Controller/MaintenanceControllerTest.php
git commit -m "feat: add resident maintenance signal flow"
```

---

### Task 8: Management maintenance HTTP/UI flow and navigation

**Files:**
- Create: `src/Controller/MaintenanceManagementController.php`
- Create: `templates/management/maintenance/index.html.twig`
- Create: `templates/management/maintenance/asset_new.html.twig`
- Create: `templates/management/maintenance/supplier_new.html.twig`
- Create: `templates/management/maintenance/contract_new.html.twig`
- Create: `templates/management/maintenance/event_new.html.twig`
- Modify: `templates/base.html.twig`
- Modify: `assets/styles/app.css`
- Test: `tests/Controller/MaintenanceManagementControllerTest.php`

**Routes:**

```text
GET       /management/maintenance                        app_management_maintenance
POST      /management/maintenance/signal/{id}/assign     app_management_maintenance_assign
POST      /management/maintenance/signal/{id}/status     app_management_maintenance_status
GET|POST  /management/maintenance/asset/new              app_management_maintenance_asset_new
GET|POST  /management/maintenance/supplier/new           app_management_maintenance_supplier_new
GET|POST  /management/maintenance/contract/new           app_management_maintenance_contract_new
GET|POST  /management/maintenance/event/new              app_management_maintenance_event_new
```

- [ ] **Step 1: Write failing management WebTestCase**

Cover:

```php
public function testResidentCannotAccessMaintenanceManagement(): void;
public function testManagerCanAssignSignalAndChangeStatusWithHistory(): void;
public function testManagerCanCreateAssetSupplierContractAndEvent(): void;
public function testDashboardShowsWarrantyAndInspectionReminders(): void;
public function testInvalidCsrfBlocksEveryManagementMutationFamily(): void;
```

- [ ] **Step 2: Run focused test and confirm RED**

```bash
vendor/bin/phpunit tests/Controller/MaintenanceManagementControllerTest.php
```

- [ ] **Step 3: Implement management controller/templates/navigation**

Dashboard queries:

- signals ordered `createdAt DESC`;
- active assets by `name ASC`;
- active suppliers by `name ASC`;
- contracts by `startsAt DESC`;
- last 25 maintenance events by `performedAt DESC`;
- reminders from `MaintenanceReminderService::build(new DateTimeImmutable('today', new DateTimeZone('Europe/Sofia')), $activeAssets)`.

Forms parse calendar dates with `DateTimeImmutable::createFromFormat('!Y-m-d', ..., Europe/Sofia)` and reject invalid dates with HTTP 422 form rendering.

Navigation:

- authenticated user: `Поддръжка` -> `app_maintenance_index`;
- manager/admin: `Управление на поддръжката` -> `app_management_maintenance`.

- [ ] **Step 4: Run focused management tests**

```bash
vendor/bin/phpunit tests/Controller/MaintenanceManagementControllerTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Controller/MaintenanceManagementController.php templates/management/maintenance templates/base.html.twig assets/styles/app.css tests/Controller/MaintenanceManagementControllerTest.php
git commit -m "feat: add maintenance management dashboard"
```

---

### Task 9: Phase 7 full verification and PR gate

**Files:**
- Review all Phase 7 files only.

- [ ] **Step 1: Run PHP syntax checks on changed PHP files**

```bash
find src tests migrations -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: no syntax errors.

- [ ] **Step 2: Run full project verification**

```bash
composer validate --strict
php bin/console lint:container
php bin/console doctrine:schema:validate --skip-sync --env=test
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

Expected: all commands exit 0.

- [ ] **Step 3: Run MariaDB migration round-trip gate**

Use the same up/validate/down/up/validate sequence from Task 6 against MariaDB 10.11.

- [ ] **Step 4: Physical diff review**

Compare `main...feat/phase7-maintenance` and verify:

- only Phase 7 docs/domain/services/controllers/templates/styles/tests/migration are changed;
- no Phase 8/9/10 files or behavior are introduced;
- every roadmap Phase 7 bullet maps to an implemented path;
- no public attachment path exists;
- no mutation route lacks CSRF.

- [ ] **Step 5: Open PR**

Title:

```text
Maintenance: complete roadmap phase 7
```

PR body must enumerate the scope, security boundaries, private attachment policy, test gates, and non-goals.

- [ ] **Step 6: Merge only the verified head**

After GitHub Actions is green, re-check PR head SHA and diff, then squash merge with `expected_head_sha`.