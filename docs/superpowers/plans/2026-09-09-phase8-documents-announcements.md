# Phase 8 Documents and Announcements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver roadmap Phase 8 as a private official-communication vertical slice: access-controlled documents, immutable published announcements, per-user unread/read state, printable/PDF output and optional user-initiated Viber sharing.

**Architecture:** Keep official communication separate from Community. Reuse the project’s existing `*AccessPolicy` style for authorization, Doctrine transactions/pessimistic locks for publication, Phase 7’s filesystem-compensation pattern for private files, and a small repository layer only where SQL-level visibility/unread queries prevent insecure or wasteful in-memory filtering.

**Tech Stack:** PHP 8.4, Symfony 8.1, Doctrine ORM 3.7+/DBAL 4, MariaDB 10.11, Twig, AssetMapper, Dompdf 3.1, PHPUnit 12, PHPStan 2.

**Spec:** `docs/superpowers/specs/2026-09-09-phase8-documents-announcements-design.md`

## Global Constraints

- All document and announcement routes are authenticated.
- `DocumentAccessLevel::RESIDENTS` is visible to active authenticated users.
- `DocumentAccessLevel::FINANCE` is visible to cashier/controller/manager/admin roles.
- `DocumentAccessLevel::MANAGEMENT` is visible only to manager/admin roles.
- Only `ROLE_MANAGER` and `ROLE_ADMIN` may upload documents or create/edit/publish official announcements.
- Authorization is server-side; Twig visibility is never the security boundary.
- Restricted document IDs return 404 to unauthorized residents instead of revealing that the resource exists.
- Private files live under `var/storage/documents`, never under `public/`.
- Allowed document MIME types are PDF, JPEG, PNG and WebP; maximum size is 16 MiB.
- Published announcements are immutable; there is no unpublish or hard-delete workflow.
- Only `RESIDENTS` documents may be linked to announcements.
- Publication creates receipts synchronously only for users active at publication time.
- In-app receipt/read state is never presented as legally sufficient service or notification proof.
- Every POST mutation uses CSRF protection.
- No Messenger, Mercure, push, email, SMS or Viber bot/API integration is introduced.
- Do not change Community entities/routes to represent official content.
- Do not add arbitrary ACL, tenant, household-targeting, document-versioning, OCR or full-text subsystems.
- Use explicit Doctrine index names and `ON DELETE RESTRICT` for audit-sensitive foreign keys.
- Do not weaken PHPStan or add Phase 8 ignore rules.
- After the first implementation commit, open a draft Phase 8 PR so the existing pull-request CI can be used as the continuous RED/GREEN harness.

---

### Task 1: Official document and announcement domain

**Files:**
- Create: `src/Enum/DocumentCategory.php`
- Create: `src/Enum/DocumentAccessLevel.php`
- Create: `src/Enum/OfficialAnnouncementStatus.php`
- Create: `src/Entity/Document.php`
- Create: `src/Entity/OfficialAnnouncement.php`
- Create: `src/Entity/AnnouncementReceipt.php`
- Test: `tests/Entity/DocumentAnnouncementEntitiesTest.php`

**Interfaces:**

```php
Document::record(
    DocumentCategory $category,
    DocumentAccessLevel $accessLevel,
    string $title,
    ?string $description,
    string $originalName,
    string $storageName,
    string $mimeType,
    int $sizeBytes,
    User $uploadedBy,
    DateTimeImmutable $uploadedAt,
): self;

/** @param iterable<Document> $documents */
OfficialAnnouncement::draft(
    string $title,
    string $body,
    User $createdBy,
    DateTimeImmutable $createdAt,
    iterable $documents = [],
): self;

/** @param iterable<Document> $documents */
public function revise(string $title, string $body, iterable $documents): void;
public function publish(User $publishedBy, DateTimeImmutable $publishedAt): void;
public function isPublished(): bool;
/** @return Collection<int, Document> */
public function getDocuments(): Collection;

AnnouncementReceipt::record(
    OfficialAnnouncement $announcement,
    User $user,
    DateTimeImmutable $availableAt,
): self;
public function markRead(DateTimeImmutable $readAt): void;
public function isRead(): bool;
```

Enum persisted values:

```text
DocumentCategory:
meeting_invitation, meeting_minutes, house_rules, invoice_receipt,
contract_offer, warranty, bank_statement, technical_documentation,
monthly_report, other

DocumentAccessLevel:
residents, finance, management

OfficialAnnouncementStatus:
draft, published
```

`DocumentCategory` and `DocumentAccessLevel` expose `labelBg(): string`. `OfficialAnnouncementStatus` exposes `labelBg(): string` for management UI.

- [ ] **Step 1: Write RED entity tests**

Cover these behaviours explicitly:

```php
public function testDocumentNormalizesMetadataAndStoresUtcTimestamp(): void;
public function testDocumentRejectsBlankTitleInvalidStorageNameOrNonPositiveSize(): void;
public function testAnnouncementCreatesEditableDraftWithResidentDocuments(): void;
public function testAnnouncementRejectsFinanceOrManagementDocumentLink(): void;
public function testPublishedAnnouncementCannotBeRevisedOrPublishedAgain(): void;
public function testReceiptStoresUtcAvailabilityAndKeepsFirstReadTimestamp(): void;
public function testReceiptRejectsFirstReadBeforeAvailability(): void;
```

Use title limit 180 characters, announcement body limit 20,000 characters and document description limit 2,000 characters. `Document::record()` accepts only the four approved MIME types and validates storage names with:

```php
/^[a-f0-9]{32}\.(?:pdf|jpg|png|webp)$/
```

`AnnouncementReceipt::markRead()` sets the first valid read timestamp and leaves it unchanged on repeated calls.

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
vendor/bin/phpunit tests/Entity/DocumentAnnouncementEntitiesTest.php
```

Expected: FAIL because Phase 8 enums/entities do not exist.

- [ ] **Step 3: Implement the domain minimally**

Use Doctrine attributes, `strict_types=1`, private constructors/named factories and UTC conversion with `DateTimeZone('UTC')` for `uploadedAt`, `createdAt`, `publishedAt`, `availableAt` and `readAt`.

`OfficialAnnouncement` owns a Doctrine `Collection<int, Document>` initialized with `ArrayCollection`. `draft()` and `revise()` validate every document before replacing the collection:

```php
foreach ($documents as $document) {
    if (DocumentAccessLevel::RESIDENTS !== $document->getAccessLevel()) {
        throw new DomainException('Official announcements may link only resident-visible documents.');
    }
}
```

`revise()` and `publish()` first assert `DRAFT`; after publication no title/body/document mutation method succeeds.

- [ ] **Step 4: Run focused tests and PHPStan on the new domain**

```bash
vendor/bin/phpunit tests/Entity/DocumentAnnouncementEntitiesTest.php
vendor/bin/phpstan analyse src/Enum/DocumentCategory.php src/Enum/DocumentAccessLevel.php src/Enum/OfficialAnnouncementStatus.php src/Entity/Document.php src/Entity/OfficialAnnouncement.php src/Entity/AnnouncementReceipt.php --no-progress
```

Expected: PASS / no PHPStan errors.

- [ ] **Step 5: Commit**

```bash
git add src/Enum/DocumentCategory.php src/Enum/DocumentAccessLevel.php src/Enum/OfficialAnnouncementStatus.php src/Entity/Document.php src/Entity/OfficialAnnouncement.php src/Entity/AnnouncementReceipt.php tests/Entity/DocumentAnnouncementEntitiesTest.php
git commit -m "feat: add documents and announcements domain"
```

---

### Task 2: Document access policy and secure query repositories

**Files:**
- Modify: `src/Entity/Document.php` — set `repositoryClass`
- Modify: `src/Entity/OfficialAnnouncement.php` — set `repositoryClass`
- Modify: `src/Entity/AnnouncementReceipt.php` — set `repositoryClass`
- Create: `src/Security/DocumentAccessPolicy.php`
- Create: `src/Repository/DocumentRepository.php`
- Create: `src/Repository/OfficialAnnouncementRepository.php`
- Create: `src/Repository/AnnouncementReceiptRepository.php`
- Test: `tests/Security/DocumentAccessPolicyTest.php`
- Test: `tests/Repository/OfficialCommunicationRepositoryTest.php`

**Interfaces:**

```php
final class DocumentAccessPolicy
{
    /** @return list<DocumentAccessLevel> */
    public function allowedLevels(User $user): array;
    public function canView(User $user, Document $document): bool;
    public function canManageOfficialContent(User $user): bool;
}

final class DocumentRepository extends ServiceEntityRepository
{
    /**
     * @param list<DocumentAccessLevel> $levels
     * @return list<Document>
     */
    public function findVisible(array $levels, ?DocumentCategory $category = null): array;
}

final class OfficialAnnouncementRepository extends ServiceEntityRepository
{
    /** @return list<OfficialAnnouncement> */
    public function findPublished(): array;
}

final class AnnouncementReceiptRepository extends ServiceEntityRepository
{
    public function findFor(User $user, OfficialAnnouncement $announcement): ?AnnouncementReceipt;
    public function countUnreadFor(User $user): int;
    /** @return list<AnnouncementReceipt> */
    public function findUnreadFor(User $user, int $limit = 5): array;
}
```

- [ ] **Step 1: Write RED access-policy tests**

Assert the policy without Symfony role-hierarchy assumptions, because `User::getRoles()` stores direct domain roles plus `ROLE_USER`:

```text
resident -> RESIDENTS
cashier -> RESIDENTS + FINANCE
controller -> RESIDENTS + FINANCE
manager -> RESIDENTS + FINANCE + MANAGEMENT
admin -> RESIDENTS + FINANCE + MANAGEMENT
inactive user -> no levels
```

Also assert only manager/admin return `true` from `canManageOfficialContent()`.

- [ ] **Step 2: Implement `DocumentAccessPolicy` following the existing policy style**

Use `array_intersect()` against `User::getRoles()` as `CondominiumBookAccessPolicy` already does. Do not introduce a Symfony voter solely for Phase 8.

- [ ] **Step 3: Write RED repository integration tests**

Use `KernelTestCase` + `SchemaTool`. Persist documents at all three access levels and published/draft announcements. Assert:

- `DocumentRepository::findVisible()` returns only permitted levels;
- optional category filtering is applied in SQL;
- empty allowed-level list returns `[]` without issuing an invalid `IN ()` query;
- `OfficialAnnouncementRepository::findPublished()` excludes drafts and orders `publishedAt DESC`;
- unread receipt count/list are scoped to the requested user and `readAt IS NULL`.

- [ ] **Step 4: Implement repositories**

For enum `IN` parameters map backed values explicitly and use DBAL string-array typing:

```php
$values = array_map(
    static fn (DocumentAccessLevel $level): string => $level->value,
    $levels,
);

$qb->andWhere('d.accessLevel IN (:levels)')
   ->setParameter('levels', $values, ArrayParameterType::STRING);
```

Sort documents by `uploadedAt DESC, id DESC`, published announcements by `publishedAt DESC, id DESC`, unread receipts by `availableAt DESC, id DESC`.

- [ ] **Step 5: Verify focused tests**

```bash
vendor/bin/phpunit tests/Security/DocumentAccessPolicyTest.php tests/Repository/OfficialCommunicationRepositoryTest.php
vendor/bin/phpstan analyse src/Security/DocumentAccessPolicy.php src/Repository --no-progress
```

- [ ] **Step 6: Commit**

```bash
git add src/Entity/Document.php src/Entity/OfficialAnnouncement.php src/Entity/AnnouncementReceipt.php src/Security/DocumentAccessPolicy.php src/Repository tests/Security/DocumentAccessPolicyTest.php tests/Repository/OfficialCommunicationRepositoryTest.php
git commit -m "feat: add official content access policy"
```

---

### Task 3: Private document storage and upload service

**Files:**
- Create: `src/Value/DocumentStoredFile.php`
- Create: `src/Service/DocumentStorage.php`
- Create: `src/Service/DocumentService.php`
- Test: `tests/Service/DocumentStorageTest.php`
- Test: `tests/Service/DocumentServiceTest.php`

**Interfaces:**

```php
final readonly class DocumentStoredFile
{
    public function __construct(
        public string $originalName,
        public string $storageName,
        public string $mimeType,
        public int $sizeBytes,
        public string $absolutePath,
    ) {}
}

final readonly class DocumentStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/storage/documents')]
        private string $storageDirectory,
    ) {}

    public function store(UploadedFile $file): DocumentStoredFile;
    public function pathFor(string $storageName): string;
    public function remove(string $storageName): void;
}

final readonly class DocumentService
{
    public function upload(
        User $actor,
        DocumentCategory $category,
        DocumentAccessLevel $accessLevel,
        string $title,
        ?string $description,
        UploadedFile $file,
        DateTimeImmutable $uploadedAt,
    ): Document;
}
```

- [ ] **Step 1: Write RED storage tests**

Cover real temporary files and MIME detection:

```text
PDF accepted -> random .pdf storage name
PNG accepted -> random .png storage name
JPEG accepted -> .jpg
WebP accepted -> .webp
text/PHP rejected
empty file rejected
file > 16 MiB rejected
../secret.pdf rejected by pathFor()
remove() deletes a validated stored file
```

Do not trust `UploadedFile` client MIME or extension; use `finfo(FILEINFO_MIME_TYPE)` on the file contents.

- [ ] **Step 2: Implement `DocumentStorage`**

Use:

```php
private const MAX_SIZE = 16 * 1024 * 1024;
private const EXTENSIONS_BY_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
```

Create the storage directory with mode `0700`. Generate server names with `bin2hex(random_bytes(16))`. Validate `pathFor()` names before joining them to the storage directory.

- [ ] **Step 3: Write RED `DocumentService` tests**

Using a manager and resident, assert:

- manager upload persists exactly one document and one private file;
- admin upload is accepted;
- resident/cashier/controller upload is rejected with `DomainException`;
- persistence failure removes the just-stored file;
- metadata preserves the original filename but never uses it as the path.

- [ ] **Step 4: Implement filesystem/database compensation**

Follow the established Phase 7 pattern:

```php
$stored = $this->storage->store($file);

try {
    return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use (...) {
        $document = Document::record(...);
        $entityManager->persist($document);

        return $document;
    });
} catch (Throwable $exception) {
    $this->storage->remove($stored->storageName);
    throw $exception;
}
```

`DocumentService` checks `DocumentAccessPolicy::canManageOfficialContent()` before touching the filesystem.

- [ ] **Step 5: Verify focused tests**

```bash
vendor/bin/phpunit tests/Service/DocumentStorageTest.php tests/Service/DocumentServiceTest.php
```

- [ ] **Step 6: Commit**

```bash
git add src/Value/DocumentStoredFile.php src/Service/DocumentStorage.php src/Service/DocumentService.php tests/Service/DocumentStorageTest.php tests/Service/DocumentServiceTest.php
git commit -m "feat: add private document storage"
```

---

### Task 4: Draft, publication and unread/read services

**Files:**
- Create: `src/Service/OfficialAnnouncementService.php`
- Create: `src/Service/AnnouncementReceiptService.php`
- Test: `tests/Service/OfficialAnnouncementServiceTest.php`
- Test: `tests/Service/AnnouncementReceiptServiceTest.php`

**Interfaces:**

```php
final readonly class OfficialAnnouncementService
{
    /** @param iterable<Document> $documents */
    public function createDraft(
        User $actor,
        string $title,
        string $body,
        DateTimeImmutable $createdAt,
        iterable $documents = [],
    ): OfficialAnnouncement;

    /** @param iterable<Document> $documents */
    public function revise(
        User $actor,
        OfficialAnnouncement $announcement,
        string $title,
        string $body,
        iterable $documents,
    ): void;

    public function publish(
        User $actor,
        OfficialAnnouncement $announcement,
        DateTimeImmutable $publishedAt,
    ): void;
}

final readonly class AnnouncementReceiptService
{
    public function markRead(
        User $user,
        OfficialAnnouncement $announcement,
        DateTimeImmutable $readAt,
    ): bool;

    public function countUnread(User $user): int;

    /** @return list<AnnouncementReceipt> */
    public function findUnread(User $user, int $limit = 5): array;

    /** @return list<int> */
    public function unreadAnnouncementIds(User $user): array;
}
```

`markRead()` returns `false` when the user has no receipt for that historical announcement; it must not create a retroactive receipt. It returns `true` when a receipt exists, whether newly marked or already read.

- [ ] **Step 1: Write RED announcement-service integration tests**

Cover:

```text
manager/admin can create a draft
resident/cashier/controller cannot create or revise official content
draft revision replaces title/body/document links
restricted document link is rejected
publication sets publisher/time and creates one receipt per active user
inactive user receives no receipt
publication does not create retroactive receipts later
published announcement cannot be revised
double publication cannot create duplicate receipts
```

Use `SchemaTool` and persist at least two active users plus one deactivated user.

- [ ] **Step 2: Implement draft/create/revise operations**

All management authority checks go through `DocumentAccessPolicy`. `createDraft()` persists the new aggregate. `revise()` delegates immutable-state/document-link validation to the entity.

- [ ] **Step 3: Implement concurrency-safe publication**

Use the project’s existing locking pattern:

```php
$this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $announcement, $publishedAt): void {
    $entityManager->lock($announcement, LockMode::PESSIMISTIC_WRITE);
    $announcement->publish($actor, $publishedAt);

    /** @var list<User> $users */
    $users = $entityManager->getRepository(User::class)->findBy(['active' => true], ['id' => 'ASC']);
    foreach ($users as $user) {
        $entityManager->persist(AnnouncementReceipt::record($announcement, $user, $publishedAt));
    }
});
```

The later database unique constraint on `(announcement_id, user_id)` is the final duplicate safeguard.

- [ ] **Step 4: Write and implement receipt-service tests**

Assert count/list are user-scoped, `markRead()` is idempotent, the first timestamp remains unchanged and an old announcement with no receipt is not backfilled.

- [ ] **Step 5: Verify focused tests**

```bash
vendor/bin/phpunit tests/Service/OfficialAnnouncementServiceTest.php tests/Service/AnnouncementReceiptServiceTest.php
```

- [ ] **Step 6: Commit**

```bash
git add src/Service/OfficialAnnouncementService.php src/Service/AnnouncementReceiptService.php tests/Service/OfficialAnnouncementServiceTest.php tests/Service/AnnouncementReceiptServiceTest.php
git commit -m "feat: add official announcement workflows"
```

---

### Task 5: MariaDB migration and schema integrity

**Files:**
- Create: `migrations/Version20260909070000.php`
- Create: `tests/Doctrine/DocumentsAnnouncementsSchemaTest.php`

**Tables:**

```text
document
official_announcement
official_announcement_document
announcement_receipt
```

**Required schema:**

`document`:

```text
id INT PK AUTO_INCREMENT
category VARCHAR(32) NOT NULL
access_level VARCHAR(24) NOT NULL
title VARCHAR(180) NOT NULL
description LONGTEXT NULL
original_name VARCHAR(255) NOT NULL
storage_name VARCHAR(40) NOT NULL UNIQUE
mime_type VARCHAR(80) NOT NULL
size_bytes INT NOT NULL
uploaded_at DATETIME NOT NULL
uploaded_by_id INT NOT NULL -> app_user(id) ON DELETE RESTRICT
INDEX idx_document_access_category_uploaded (access_level, category, uploaded_at)
INDEX idx_document_uploaded_by (uploaded_by_id)
```

`official_announcement`:

```text
id INT PK AUTO_INCREMENT
status VARCHAR(24) NOT NULL
title VARCHAR(180) NOT NULL
body LONGTEXT NOT NULL
created_at DATETIME NOT NULL
published_at DATETIME NULL
created_by_id INT NOT NULL -> app_user(id) ON DELETE RESTRICT
published_by_id INT NULL -> app_user(id) ON DELETE RESTRICT
INDEX idx_official_announcement_status_published (status, published_at)
INDEX idx_official_announcement_created_by (created_by_id)
INDEX idx_official_announcement_published_by (published_by_id)
```

`official_announcement_document`:

```text
announcement_id INT NOT NULL -> official_announcement(id) ON DELETE RESTRICT
document_id INT NOT NULL -> document(id) ON DELETE RESTRICT
PRIMARY KEY (announcement_id, document_id)
INDEX idx_official_announcement_document_document (document_id)
```

`announcement_receipt`:

```text
id INT PK AUTO_INCREMENT
available_at DATETIME NOT NULL
read_at DATETIME NULL
announcement_id INT NOT NULL -> official_announcement(id) ON DELETE RESTRICT
user_id INT NOT NULL -> app_user(id) ON DELETE RESTRICT
UNIQUE uniq_announcement_receipt_announcement_user (announcement_id, user_id)
INDEX idx_announcement_receipt_user_read (user_id, read_at)
INDEX idx_announcement_receipt_announcement (announcement_id)
```

- [ ] **Step 1: Write RED schema metadata tests**

Assert:

- exact four tables are represented by Phase 8 metadata;
- all to-one/join-table foreign keys specify `RESTRICT`;
- the receipt unique constraint exists;
- document visibility and receipt unread indexes exist with the expected columns;
- `storageName` is unique;
- no Phase 8 entity contains a cascade-remove association.

- [ ] **Step 2: Run the schema test and confirm RED**

```bash
vendor/bin/phpunit tests/Doctrine/DocumentsAnnouncementsSchemaTest.php
```

Expected: FAIL because the migration/schema metadata is not yet complete.

- [ ] **Step 3: Write the migration explicitly**

Follow the existing MariaDB migration style: `utf8mb4_unicode_ci`, `ENGINE = InnoDB`, explicit index names, explicit foreign-key constraints, and `down()` dropping foreign keys before tables in dependency order:

```text
announcement_receipt
official_announcement_document
official_announcement
document
```

- [ ] **Step 4: Verify schema metadata and SQLite-based focused tests**

```bash
vendor/bin/phpunit tests/Doctrine/DocumentsAnnouncementsSchemaTest.php
php bin/console doctrine:schema:validate --skip-sync --env=test
```

- [ ] **Step 5: Commit**

```bash
git add migrations/Version20260909070000.php tests/Doctrine/DocumentsAnnouncementsSchemaTest.php
git commit -m "feat: add documents and announcements schema"
```

---

### Task 6: Resident document list and authorized download

**Files:**
- Create: `src/Controller/DocumentController.php`
- Create: `templates/documents/index.html.twig`
- Test: `tests/Controller/DocumentControllerTest.php`

**Routes:**

```text
GET /documents                    -> app_documents_index
GET /document/{id}/download       -> app_document_download
```

- [ ] **Step 1: Write RED functional tests**

Cover:

```text
anonymous /documents -> /login
resident list contains RESIDENTS but not FINANCE/MANAGEMENT
a cashier/controller list includes FINANCE but not MANAGEMENT
manager/admin list includes all three levels
category query filters visible rows
resident can download an allowed private file
restricted guessed ID returns 404
missing physical file returns 404
response Content-Type equals persisted MIME
Content-Disposition uses original filename
```

Use a temporary real file in `%kernel.project_dir%/var/storage/documents` and clean it in `tearDown()`.

- [ ] **Step 2: Implement `DocumentController`**

Constructor dependencies:

```php
EntityManagerInterface $entityManager,
DocumentRepository $documentRepository,
DocumentAccessPolicy $accessPolicy,
DocumentStorage $storage,
```

`index()` obtains current active `User`, parses optional `category` via `DocumentCategory::tryFrom()`, computes `allowedLevels()` and calls `findVisible()`. Unknown category returns 404.

`download()` loads `Document` by ID; if absent or `!$accessPolicy->canView($user, $document)`, return 404 before resolving the filesystem path. Use `BinaryFileResponse`, persisted MIME and:

```php
$response->setContentDisposition(
    ResponseHeaderBag::DISPOSITION_ATTACHMENT,
    $document->getOriginalName(),
);
```

- [ ] **Step 3: Build the resident template**

Show title, Bulgarian category label, upload date, optional description and download action. Do not expose access-level details as a substitute for authorization. Include a category filter using only `DocumentCategory::cases()`.

- [ ] **Step 4: Verify focused functional test**

```bash
vendor/bin/phpunit tests/Controller/DocumentControllerTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Controller/DocumentController.php templates/documents/index.html.twig tests/Controller/DocumentControllerTest.php
git commit -m "feat: add resident document access"
```

---

### Task 7: Management document upload UI

**Files:**
- Create: `src/Controller/DocumentManagementController.php`
- Create: `templates/management/documents/index.html.twig`
- Create: `templates/management/documents/new.html.twig`
- Test: `tests/Controller/DocumentManagementControllerTest.php`

**Routes:**

```text
GET /management/documents             -> app_management_documents
GET|POST /management/document/new     -> app_management_document_new
```

CSRF id: `document_create`.

- [ ] **Step 1: Write RED management functional tests**

Assert resident/cashier/controller receive 403, manager/admin can list all documents and manager can upload a PNG/PDF through the rendered form. Invalid CSRF must produce 403 and create no database/file state. Invalid enum/MIME/size returns 422 with a controlled message.

- [ ] **Step 2: Implement a thin management controller**

Use `DocumentAccessPolicy::canManageOfficialContent()` for authority. Parse `category` and `access_level` with `tryFrom()`. Require `UploadedFile` before calling `DocumentService::upload()`.

The upload form fields are exactly:

```text
category
access_level
title
description
document_file
_token
```

- [ ] **Step 3: Build management templates**

The list shows title, category, access level, uploader and timestamp. The create form uses enum `labelBg()` values and `enctype="multipart/form-data"`. There is no edit/delete control.

- [ ] **Step 4: Verify focused test**

```bash
vendor/bin/phpunit tests/Controller/DocumentManagementControllerTest.php
```

- [ ] **Step 5: Commit**

```bash
git add src/Controller/DocumentManagementController.php templates/management/documents tests/Controller/DocumentManagementControllerTest.php
git commit -m "feat: add document management UI"
```

---

### Task 8: Dompdf dependency, CI extension and PDF service

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `.github/workflows/ci.yml`
- Create: `src/Service/AnnouncementPdfService.php`
- Create: `templates/announcements/pdf.html.twig`
- Test: `tests/Service/AnnouncementPdfServiceTest.php`

- [ ] **Step 1: Add the required runtime extension and dependency**

Run:

```bash
composer require dompdf/dompdf:^3.1 --no-interaction
```

Add explicit platform requirement to `composer.json`:

```json
"ext-dom": "*"
```

Keep existing `ext-mbstring`. Update GitHub Actions setup to:

```yaml
extensions: ctype, dom, iconv, mbstring, pdo_mysql, pdo_sqlite
```

Do not add GD or remote-fetch support because Phase 8 PDF output does not embed remote images.

- [ ] **Step 2: Validate Composer metadata before writing the PDF service**

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
```

- [ ] **Step 3: Write RED PDF service tests**

Interface:

```php
final readonly class AnnouncementPdfService
{
    public function __construct(Environment $twig) {}
    public function render(OfficialAnnouncement $announcement): string;
}
```

Test a published announcement containing Bulgarian Cyrillic text and assert:

```php
$pdf = $service->render($announcement);
self::assertStringStartsWith('%PDF-', $pdf);
self::assertGreaterThan(500, strlen($pdf));
```

Also assert a linked document appears by title in the rendered input path without embedding the uploaded binary.

- [ ] **Step 4: Implement controlled PDF generation**

Use a new `Options` instance per render:

```php
$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml(
    $this->twig->render('announcements/pdf.html.twig', ['announcement' => $announcement]),
    'UTF-8',
);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

return $dompdf->output();
```

The PDF Twig template uses plain escaped fields, `white-space: pre-wrap`, `font-family: 'DejaVu Sans', sans-serif`, and lists linked document titles only. It does not use `|raw`, remote CSS, remote images or uploaded file content.

- [ ] **Step 5: Verify PDF and static analysis**

```bash
vendor/bin/phpunit tests/Service/AnnouncementPdfServiceTest.php
vendor/bin/phpstan analyse src/Service/AnnouncementPdfService.php --no-progress
```

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock .github/workflows/ci.yml src/Service/AnnouncementPdfService.php templates/announcements/pdf.html.twig tests/Service/AnnouncementPdfServiceTest.php
git commit -m "feat: add announcement PDF output"
```

---

### Task 9: Management announcement authoring and publication UI

**Files:**
- Create: `src/Controller/AnnouncementManagementController.php`
- Create: `templates/management/announcements/index.html.twig`
- Create: `templates/management/announcements/form.html.twig`
- Test: `tests/Controller/AnnouncementManagementControllerTest.php`

**Routes:**

```text
GET /management/announcements                  -> app_management_announcements
GET|POST /management/announcement/new          -> app_management_announcement_new
GET|POST /management/announcement/{id}/edit    -> app_management_announcement_edit
POST /management/announcement/{id}/publish     -> app_management_announcement_publish
```

CSRF ids:

```text
announcement_create
announcement_edit_{id}
announcement_publish_{id}
```

- [ ] **Step 1: Write RED management functional tests**

Cover:

```text
resident/cashier/controller receive 403
manager creates a draft
manager edits title/body/resident-document links
restricted document ID cannot be linked
manager publishes a draft
publication creates receipts for active users only
published announcement shows read/total receipt statistics
published announcement cannot be edited
second publish is rejected without extra receipts
invalid CSRF blocks create/edit/publish families
```

- [ ] **Step 2: Implement document-ID parsing without `getInt()` traps**

Checkboxes use `document_ids[]`. Read with:

```php
$values = $request->request->all('document_ids');
```

For every value require a decimal positive integer using `/^[1-9]\d*$/`, load `Document`, reject missing IDs, and rely on `OfficialAnnouncement`/service validation to reject non-`RESIDENTS` documents. Never silently drop invalid/restricted selections.

- [ ] **Step 3: Implement the thin controller**

Use `DocumentAccessPolicy` for management authority and `OfficialAnnouncementService` for all mutations. GET index may use repository `findBy([], ['createdAt' => 'DESC'])`. The edit route returns 422/domain error if the announcement is already published rather than mutating it.

For published rows calculate informational read statistics from `AnnouncementReceipt` counts. Label them as application read state, not delivery proof.

- [ ] **Step 4: Build management templates**

Draft forms contain:

```text
title
body
document_ids[]
_token
```

Only resident-visible documents are offered as checkboxes. Published rows have no edit form and expose only view/status/statistics.

- [ ] **Step 5: Verify focused tests**

```bash
vendor/bin/phpunit tests/Controller/AnnouncementManagementControllerTest.php
```

- [ ] **Step 6: Commit**

```bash
git add src/Controller/AnnouncementManagementController.php templates/management/announcements tests/Controller/AnnouncementManagementControllerTest.php
git commit -m "feat: add official announcement management"
```

---

### Task 10: Resident announcements, read state, print/PDF and Viber share

**Files:**
- Create: `src/Controller/AnnouncementController.php`
- Create: `templates/announcements/index.html.twig`
- Create: `templates/announcements/show.html.twig`
- Create: `templates/announcements/print.html.twig`
- Test: `tests/Controller/AnnouncementControllerTest.php`

**Routes:**

```text
GET  /announcements                 -> app_announcements_index
GET  /announcement/{id}             -> app_announcement_show
POST /announcement/{id}/read        -> app_announcement_read
GET  /announcement/{id}/print       -> app_announcement_print
GET  /announcement/{id}/pdf         -> app_announcement_pdf
```

CSRF id: `announcement_read_{id}`.

- [ ] **Step 1: Write RED resident functional tests**

Cover:

```text
anonymous routes redirect to login
draft announcement is 404 to resident
published announcement is listed newest first
unread receipt produces an unread indicator
POST /read changes only current user receipt
second /read keeps first readAt timestamp
user with no historical receipt does not get one created by /read
invalid CSRF does not mark read
print route renders authenticated print-friendly HTML
PDF route returns application/pdf, attachment disposition and %PDF body
linked resident documents are visible/downloadable
Viber action exists only for published announcement
```

- [ ] **Step 2: Implement published-only lookup and read action**

Create one private helper that finds by ID and requires `OfficialAnnouncementStatus::PUBLISHED`; drafts/missing IDs return 404. `read()` calls `AnnouncementReceiptService::markRead()` after CSRF validation and redirects back to detail.

GET routes must not implicitly mark an announcement read.

- [ ] **Step 3: Implement PDF response**

Use `AnnouncementPdfService::render()` and return a normal `Response` with:

```text
Content-Type: application/pdf
Content-Disposition: attachment; filename="announcement-{id}.pdf"
```

Use a fixed ASCII filename based on numeric ID rather than deriving a filesystem/header filename from the title.

- [ ] **Step 4: Implement the Viber deep link in the controller view model**

Generate the canonical authenticated announcement URL with `UrlGeneratorInterface::ABSOLUTE_URL`. Keep the share payload within 200 characters by truncating only the title, never the URL:

```php
$separator = ' — ';
$url = $this->generateUrl(
    'app_announcement_show',
    ['id' => $announcement->getId()],
    UrlGeneratorInterface::ABSOLUTE_URL,
);
$budget = max(0, 200 - mb_strlen($separator) - mb_strlen($url));
$title = mb_substr($announcement->getTitle(), 0, $budget);
$text = '' === $title ? $url : $title.$separator.$url;
$viberShareUrl = 'viber://forward?text='.rawurlencode($text);
```

The link is user-initiated only. There is no server-side Viber request and the shared Vhod URL remains authenticated.

- [ ] **Step 5: Build resident templates**

`index.html.twig`: official label, publication timestamp, unread badge, title/summary.

`show.html.twig`: title/body with escaped plain text and preserved line breaks, publisher/time, linked document download actions, explicit read button only when an unread receipt exists, print/PDF actions and Viber action. No comments, reactions or Community styling semantics.

`print.html.twig`: standalone print-friendly HTML without application navigation; use escaped fields and `window.print()` only if a visible print button is desired. No state mutation.

- [ ] **Step 6: Verify focused functional test**

```bash
vendor/bin/phpunit tests/Controller/AnnouncementControllerTest.php
```

- [ ] **Step 7: Commit**

```bash
git add src/Controller/AnnouncementController.php templates/announcements tests/Controller/AnnouncementControllerTest.php
git commit -m "feat: add resident official announcements"
```

---

### Task 11: Navigation unread badge, visual separation and final Phase 8 gate

**Files:**
- Create: `src/Twig/AnnouncementExtension.php`
- Modify: `templates/base.html.twig`
- Modify: `templates/dashboard/index.html.twig`
- Modify: `assets/styles/app.css`
- Test: `tests/Controller/AnnouncementControllerTest.php` — extend navigation/badge assertions

**Interface:**

```php
final class AnnouncementExtension extends AbstractExtension
{
    public function __construct(
        Security $security,
        AnnouncementReceiptService $receiptService,
    ) {}

    /** @return list<TwigFunction> */
    public function getFunctions(): array;
    public function unreadCount(): int;
}
```

Register Twig function name `announcement_unread_count`. Return `0` for anonymous/non-`User` contexts; otherwise delegate to `AnnouncementReceiptService::countUnread()`.

- [ ] **Step 1: Add RED functional assertions for navigation**

In the announcement functional test, persist one unread receipt, request a normal authenticated page and assert navigation contains:

```text
Обяви
Документи
```

and an unread badge/count. Manager/admin navigation also exposes management links for official announcements/documents. A resident must not receive management links.

- [ ] **Step 2: Implement the Twig extension and navigation**

Add resident links in `base.html.twig`:

```text
Обяви [badge only when count > 0]
Документи
```

Add manager/admin links:

```text
Официални обяви
Документи (управление)
```

Keep the existing Community navigation intact and visually distinct.

Update the dashboard’s existing `Официално / Съобщения и решения` placeholder to link to the real announcement list and show the unread count. Do not add a new dashboard query service.

- [ ] **Step 3: Add focused CSS only**

Add small `.official-*`, `.document-*` and unread-badge rules using the existing visual system. Do not redesign the global layout. Ensure official cards have a visible `Официално` label and do not reuse Community reaction/comment presentation.

- [ ] **Step 4: Run all focused Phase 8 tests**

```bash
vendor/bin/phpunit \
  tests/Entity/DocumentAnnouncementEntitiesTest.php \
  tests/Security/DocumentAccessPolicyTest.php \
  tests/Repository/OfficialCommunicationRepositoryTest.php \
  tests/Service/DocumentStorageTest.php \
  tests/Service/DocumentServiceTest.php \
  tests/Service/OfficialAnnouncementServiceTest.php \
  tests/Service/AnnouncementReceiptServiceTest.php \
  tests/Service/AnnouncementPdfServiceTest.php \
  tests/Doctrine/DocumentsAnnouncementsSchemaTest.php \
  tests/Controller/DocumentControllerTest.php \
  tests/Controller/DocumentManagementControllerTest.php \
  tests/Controller/AnnouncementManagementControllerTest.php \
  tests/Controller/AnnouncementControllerTest.php
```

Expected: all PASS.

- [ ] **Step 5: Run the complete local verification gate**

```bash
composer validate --strict
php bin/console lint:container
php bin/console doctrine:schema:validate --skip-sync --env=test
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

Do not claim Phase 8 complete from focused tests alone.

- [ ] **Step 6: Verify MariaDB migration round-trip**

Against MariaDB 10.11 test configuration run exactly the CI sequence:

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
php bin/console doctrine:migrations:migrate prev --no-interaction --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/console doctrine:schema:validate --env=test
```

- [ ] **Step 7: Physical diff review**

Review `main...feat/phase8-documents-announcements` and confirm:

```text
no Community domain mutation
no public upload directory
no email/Messenger/Mercure/Viber API code
no arbitrary ACL subsystem
no Phase 9 General Assembly semantics
no delete/unpublish routes
no PHPStan ignore changes
all POST routes are CSRF-protected
all restricted download decisions are server-side
published announcement mutation is impossible through public methods/routes
```

- [ ] **Step 8: Commit final integration changes**

```bash
git add src/Twig/AnnouncementExtension.php templates/base.html.twig templates/dashboard/index.html.twig assets/styles/app.css tests/Controller/AnnouncementControllerTest.php
git commit -m "feat: integrate phase 8 official communication"
```

- [ ] **Step 9: Final PR/CI merge gate**

Push the exact final head, wait for the pull-request CI run on that SHA, and verify every job step is green: Composer metadata, install, container lint, Doctrine mapping, MariaDB migration round-trip, full PHPUnit and PHPStan. Only then mark the PR ready and merge.

## Phase 8 acceptance checklist

Before merge, verify every item against code/tests rather than inferred intent:

1. Management can upload private categorized documents at `RESIDENTS`, `FINANCE` or `MANAGEMENT` access levels.
2. Private file binaries remain outside `public/` and require server-side authorization to download.
3. Residents cannot discover or download finance/management documents by guessed IDs.
4. Cashier/controller can read finance documents but cannot manage official content.
5. Manager/admin can create and revise drafts and publish them exactly once.
6. Published title/body/document links cannot be silently changed.
7. Announcements can link only resident-visible documents.
8. Publication creates exactly one receipt per currently active user and none for inactive users.
9. Later users are not retroactively given unread receipts for old announcements.
10. Read mutation is explicit POST + CSRF, user-scoped and idempotent.
11. Print HTML and a valid Cyrillic-capable PDF are available only for published authenticated announcements.
12. Viber sharing is user-initiated and shares only an authenticated canonical URL plus bounded title text.
13. Official announcements remain technically and visually separate from Community posts/polls.
14. UI/read statistics never claim legal proof of service.
15. Composer, Symfony container, Doctrine mapping, MariaDB migration round-trip, full PHPUnit and PHPStan are green on the exact final PR head.
16. Final PR contains only Phase 8 scope.