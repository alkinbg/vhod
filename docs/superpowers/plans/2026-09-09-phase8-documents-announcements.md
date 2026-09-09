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
- Use explicit Doctrine index/unique names and `ON DELETE RESTRICT` for audit-sensitive foreign keys.
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
// Document
public static function record(
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

// OfficialAnnouncement
/** @param iterable<Document> $documents */
public static function draft(
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

// AnnouncementReceipt
public static function record(
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

`DocumentCategory`, `DocumentAccessLevel` and `OfficialAnnouncementStatus` expose `labelBg(): string`.

Initial ORM associations in this task use fixed table/join-column names and `ON DELETE RESTRICT`, but named performance/unique metadata is deliberately added under Task 5 after its RED schema test. This keeps the schema-integrity task genuinely test-first rather than writing a test that already passes.

- [ ] **Step 1: Write RED entity tests**

Cover:

```php
public function testDocumentNormalizesMetadataAndStoresUtcTimestamp(): void;
public function testDocumentRejectsBlankTitleInvalidStorageNameOrNonPositiveSize(): void;
public function testAnnouncementCreatesEditableDraftWithResidentDocuments(): void;
public function testAnnouncementRejectsFinanceOrManagementDocumentLink(): void;
public function testPublishedAnnouncementCannotBeRevisedOrPublishedAgain(): void;
public function testAnnouncementRejectsPublicationBeforeCreation(): void;
public function testReceiptRequiresPublishedAnnouncement(): void;
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

Use Doctrine attributes, `strict_types=1`, private constructors/named factories and UTC conversion with `DateTimeZone('UTC')` for audit timestamps.

`OfficialAnnouncement` owns `Collection<int, Document>` initialized with `ArrayCollection`. `draft()` and `revise()` validate every linked document:

```php
foreach ($documents as $document) {
    if (DocumentAccessLevel::RESIDENTS !== $document->getAccessLevel()) {
        throw new DomainException('Official announcements may link only resident-visible documents.');
    }
}
```

`publish()` rejects a timestamp before `createdAt`. `AnnouncementReceipt::record()` requires a published announcement. `revise()` and `publish()` assert the aggregate is still `DRAFT`.

- [ ] **Step 4: Run focused tests and PHPStan**

```bash
vendor/bin/phpunit tests/Entity/DocumentAnnouncementEntitiesTest.php
vendor/bin/phpstan analyse src/Enum/DocumentCategory.php src/Enum/DocumentAccessLevel.php src/Enum/OfficialAnnouncementStatus.php src/Entity/Document.php src/Entity/OfficialAnnouncement.php src/Entity/AnnouncementReceipt.php --no-progress
```

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
    public function countForAnnouncement(OfficialAnnouncement $announcement): int;
    public function countReadForAnnouncement(OfficialAnnouncement $announcement): int;
}
```

- [ ] **Step 1: Write RED access-policy tests**

Assert direct-role behaviour; do not assume Symfony role hierarchy has mutated `User::getRoles()`:

```text
resident -> RESIDENTS
cashier -> RESIDENTS + FINANCE
controller -> RESIDENTS + FINANCE
manager -> RESIDENTS + FINANCE + MANAGEMENT
admin -> RESIDENTS + FINANCE + MANAGEMENT
inactive user -> no levels
```

Only manager/admin may manage official content.

- [ ] **Step 2: Implement `DocumentAccessPolicy` in the existing policy style**

Use `array_intersect()` against `User::getRoles()` as `CondominiumBookAccessPolicy` does. Do not introduce a Symfony voter solely for Phase 8.

- [ ] **Step 3: Write RED repository integration tests**

Use `KernelTestCase` + `SchemaTool`. Assert:

- `findVisible()` returns only allowed levels;
- category filtering happens in SQL;
- empty level list returns `[]` without invalid `IN ()`;
- `findPublished()` excludes drafts and orders newest first;
- unread count/list are user-scoped and `readAt IS NULL`;
- per-announcement total/read counts are correct.

- [ ] **Step 4: Implement repositories**

For the enum `IN` query map backed values and type the array explicitly:

```php
$values = array_map(
    static fn (DocumentAccessLevel $level): string => $level->value,
    $levels,
);

$qb->andWhere('d.accessLevel IN (:levels)')
   ->setParameter('levels', $values, ArrayParameterType::STRING);
```

Sort documents by `uploadedAt DESC, id DESC`, announcements by `publishedAt DESC, id DESC`, unread receipts by `availableAt DESC, id DESC`.

- [ ] **Step 5: Verify**

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

Cover:

```text
PDF/JPEG/PNG/WebP accepted with fixed server extension
text/PHP rejected
empty file rejected
file > 16 MiB rejected
randomized 32-hex storage basename
../secret.pdf rejected by pathFor()
remove() deletes a validated stored file
```

MIME detection uses `finfo(FILEINFO_MIME_TYPE)` on file contents, not client MIME/extension.

- [ ] **Step 2: Implement storage**

```php
private const MAX_SIZE = 16 * 1024 * 1024;
private const EXTENSIONS_BY_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
```

Create the directory with `0700` and generate server names using `bin2hex(random_bytes(16))`.

- [ ] **Step 3: Write RED service tests**

Assert manager/admin upload succeeds, resident/cashier/controller upload is rejected before filesystem work, persistence failure removes the just-stored file and original filename is metadata only.

- [ ] **Step 4: Implement transaction/cleanup with exact arguments**

```php
$stored = $this->storage->store($file);

try {
    return $this->entityManager->wrapInTransaction(
        function (EntityManagerInterface $entityManager) use (
            $actor,
            $category,
            $accessLevel,
            $title,
            $description,
            $stored,
            $uploadedAt,
        ): Document {
            $document = Document::record(
                $category,
                $accessLevel,
                $title,
                $description,
                $stored->originalName,
                $stored->storageName,
                $stored->mimeType,
                $stored->sizeBytes,
                $actor,
                $uploadedAt,
            );
            $entityManager->persist($document);

            return $document;
        },
    );
} catch (Throwable $exception) {
    $this->storage->remove($stored->storageName);
    throw $exception;
}
```

Check `DocumentAccessPolicy::canManageOfficialContent()` before `store()`.

- [ ] **Step 5: Verify and commit**

```bash
vendor/bin/phpunit tests/Service/DocumentStorageTest.php tests/Service/DocumentServiceTest.php
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
    public function markRead(User $user, OfficialAnnouncement $announcement, DateTimeImmutable $readAt): bool;
    public function countUnread(User $user): int;
    /** @return list<AnnouncementReceipt> */
    public function findUnread(User $user, int $limit = 5): array;
    /** @return list<int> */
    public function unreadAnnouncementIds(User $user): array;
}
```

`markRead()` returns `false` when no receipt exists and must never backfill one; otherwise it returns `true` and preserves idempotency.

- [ ] **Step 1: Write RED service integration tests**

Cover manager/admin authority, denial for other roles, draft revision, restricted-document rejection, publication metadata, active-only receipt fan-out, no retroactive receipts, immutable published state and double-publication safety.

- [ ] **Step 2: Implement draft/revise operations**

All authority checks use `DocumentAccessPolicy`; the entity enforces draft/document invariants.

- [ ] **Step 3: Implement concurrency-safe publication**

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

- [ ] **Step 4: Implement receipt service from RED tests**

Use `AnnouncementReceiptRepository` for lookup/count/list. Build `unreadAnnouncementIds()` only from current user unread receipts.

- [ ] **Step 5: Verify and commit**

```bash
vendor/bin/phpunit tests/Service/OfficialAnnouncementServiceTest.php tests/Service/AnnouncementReceiptServiceTest.php
git add src/Service/OfficialAnnouncementService.php src/Service/AnnouncementReceiptService.php tests/Service/OfficialAnnouncementServiceTest.php tests/Service/AnnouncementReceiptServiceTest.php
git commit -m "feat: add official announcement workflows"
```

---

### Task 5: MariaDB migration and named schema integrity

**Files:**
- Modify: `src/Entity/Document.php`
- Modify: `src/Entity/OfficialAnnouncement.php`
- Modify: `src/Entity/AnnouncementReceipt.php`
- Create: `migrations/Version20260909070000.php`
- Create: `tests/Doctrine/DocumentsAnnouncementsSchemaTest.php`

**Final required metadata/schema:**

```text
document:
UNIQUE uniq_document_storage_name(storage_name)
INDEX idx_document_access_category_uploaded(access_level, category, uploaded_at)
INDEX idx_document_uploaded_by(uploaded_by_id)

official_announcement:
INDEX idx_official_announcement_status_published(status, published_at)
INDEX idx_official_announcement_created_by(created_by_id)
INDEX idx_official_announcement_published_by(published_by_id)

official_announcement_document:
PRIMARY KEY (announcement_id, document_id)
INDEX idx_official_announcement_document_document(document_id)
FK announcement_id -> official_announcement ON DELETE RESTRICT
FK document_id -> document ON DELETE RESTRICT

announcement_receipt:
UNIQUE uniq_announcement_receipt_announcement_user(announcement_id, user_id)
INDEX idx_announcement_receipt_user_read(user_id, read_at)
INDEX idx_announcement_receipt_announcement(announcement_id)
```

Entity columns remain those defined in Task 1; migration uses MariaDB `utf8mb4_unicode_ci`, InnoDB and explicit FK/index names.

- [ ] **Step 1: Write RED schema metadata tests before adding named metadata**

Assert the named unique constraints/indexes above, every Phase 8 FK/join FK `ON DELETE RESTRICT`, and absence of cascade-remove. Because Task 1 deliberately omitted named performance/unique metadata, these assertions must fail now.

- [ ] **Step 2: Run and confirm RED**

```bash
vendor/bin/phpunit tests/Doctrine/DocumentsAnnouncementsSchemaTest.php
```

- [ ] **Step 3: Add exact ORM index/unique metadata and write migration**

The migration creates:

```text
document
official_announcement
official_announcement_document
announcement_receipt
```

Required columns:

```text
document: id, category VARCHAR(32), access_level VARCHAR(24), title VARCHAR(180), description LONGTEXT NULL,
original_name VARCHAR(255), storage_name VARCHAR(40), mime_type VARCHAR(80), size_bytes INT,
uploaded_at DATETIME, uploaded_by_id INT

official_announcement: id, status VARCHAR(24), title VARCHAR(180), body LONGTEXT,
created_at DATETIME, published_at DATETIME NULL, created_by_id INT, published_by_id INT NULL

announcement_receipt: id, available_at DATETIME, read_at DATETIME NULL, announcement_id INT, user_id INT
```

All user/document/announcement foreign keys use `ON DELETE RESTRICT`. `down()` drops FKs first, then tables in dependency order: receipt, join table, announcement, document.

- [ ] **Step 4: Run GREEN schema tests and mapping validation**

```bash
vendor/bin/phpunit tests/Doctrine/DocumentsAnnouncementsSchemaTest.php
php bin/console doctrine:schema:validate --skip-sync --env=test
```

- [ ] **Step 5: Commit**

```bash
git add src/Entity/Document.php src/Entity/OfficialAnnouncement.php src/Entity/AnnouncementReceipt.php migrations/Version20260909070000.php tests/Doctrine/DocumentsAnnouncementsSchemaTest.php
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
GET /documents              -> app_documents_index
GET /document/{id}/download -> app_document_download
```

- [ ] **Step 1: Write RED functional tests**

Cover anonymous redirect, SQL-level access filtering for resident/cashier/controller/manager/admin, category filter, successful private download, 404 for guessed restricted IDs, 404 for missing binary, persisted MIME and original filename disposition.

- [ ] **Step 2: Implement controller**

Dependencies:

```php
EntityManagerInterface $entityManager,
DocumentRepository $documentRepository,
DocumentAccessPolicy $accessPolicy,
DocumentStorage $storage,
```

Parse optional category with `DocumentCategory::tryFrom()`. Unknown category is 404. Load download entity, perform `canView()` before path resolution, then use `BinaryFileResponse` and:

```php
$response->setContentDisposition(
    ResponseHeaderBag::DISPOSITION_ATTACHMENT,
    $document->getOriginalName(),
);
```

- [ ] **Step 3: Build template and verify**

Show title, category label, upload date, optional description and download. Category filter uses `DocumentCategory::cases()`.

```bash
vendor/bin/phpunit tests/Controller/DocumentControllerTest.php
```

- [ ] **Step 4: Commit**

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
GET /management/documents         -> app_management_documents
GET|POST /management/document/new -> app_management_document_new
```

CSRF id: `document_create`.

- [ ] **Step 1: Write RED functional tests**

Resident/cashier/controller get 403. Manager/admin can list and upload. Invalid CSRF leaves no DB/file state. Invalid enum/MIME/size returns 422.

- [ ] **Step 2: Implement thin controller**

Use `DocumentAccessPolicy::canManageOfficialContent()`, enum `tryFrom()`, require `UploadedFile`, then call `DocumentService::upload()`.

Form fields:

```text
category
access_level
title
description
document_file
_token
```

- [ ] **Step 3: Build templates and verify**

The form uses `multipart/form-data`; no edit/delete controls.

```bash
vendor/bin/phpunit tests/Controller/DocumentManagementControllerTest.php
```

- [ ] **Step 4: Commit**

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

- [ ] **Step 1: Verify DOM and update Composer in lock-safe order**

```bash
php -m | grep -i '^dom$'
```

The command must print `dom`; do not bypass the platform requirement if it is absent.

Add root requirement first:

```json
"ext-dom": "*"
```

Then update dependency/lock:

```bash
composer require dompdf/dompdf:^3.1 --no-interaction
```

Update CI PHP extensions:

```yaml
extensions: ctype, dom, iconv, mbstring, pdo_mysql, pdo_sqlite
```

- [ ] **Step 2: Validate dependency state**

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
```

- [ ] **Step 3: Write RED PDF service test**

```php
final readonly class AnnouncementPdfService
{
    public function __construct(Environment $twig) {}
    public function render(OfficialAnnouncement $announcement): string;
}
```

Test Cyrillic content and:

```php
$pdf = $service->render($announcement);
self::assertStringStartsWith('%PDF-', $pdf);
self::assertGreaterThan(500, strlen($pdf));
```

Render the PDF Twig template directly as HTML too and assert escaped announcement/document text is present. Uploaded binary contents are never read by the PDF service.

- [ ] **Step 4: Implement controlled PDF generation**

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

Template uses escaped plain fields, DejaVu Sans, no `|raw`, no remote resources and linked document titles only.

- [ ] **Step 5: Verify and commit**

```bash
vendor/bin/phpunit tests/Service/AnnouncementPdfServiceTest.php
vendor/bin/phpstan analyse src/Service/AnnouncementPdfService.php --no-progress
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
GET /management/announcements               -> app_management_announcements
GET|POST /management/announcement/new       -> app_management_announcement_new
GET|POST /management/announcement/{id}/edit -> app_management_announcement_edit
POST /management/announcement/{id}/publish  -> app_management_announcement_publish
```

CSRF: `announcement_create`, `announcement_edit_{id}`, `announcement_publish_{id}`.

- [ ] **Step 1: Write RED functional tests**

Cover role denial, draft create/edit, resident-only document links, publication/receipts, read/total stats, published edit rejection, second-publish rejection and invalid CSRF.

- [ ] **Step 2: Parse document IDs without `getInt()` traps**

```php
$values = $request->request->all('document_ids');
```

Require `/^[1-9]\d*$/` for each value, load every `Document`, reject missing IDs, never silently discard invalid/restricted selections.

- [ ] **Step 3: Implement thin controller**

Use `DocumentAccessPolicy` for authority and `OfficialAnnouncementService` for mutations. Published edit returns controlled 422 validation. Read stats use:

```php
$receiptRepository->countForAnnouncement($announcement);
$receiptRepository->countReadForAnnouncement($announcement);
```

The UI explicitly labels this as application read state, not delivery proof.

- [ ] **Step 4: Build templates and verify**

Fields: `title`, `body`, `document_ids[]`, `_token`. Offer only resident-visible documents.

```bash
vendor/bin/phpunit tests/Controller/AnnouncementManagementControllerTest.php
```

- [ ] **Step 5: Commit**

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
GET  /announcements           -> app_announcements_index
GET  /announcement/{id}       -> app_announcement_show
POST /announcement/{id}/read  -> app_announcement_read
GET  /announcement/{id}/print -> app_announcement_print
GET  /announcement/{id}/pdf   -> app_announcement_pdf
```

CSRF id: `announcement_read_{id}`.

- [ ] **Step 1: Write RED functional tests**

Cover anonymous redirects, draft 404, published ordering, unread indicator, own-receipt idempotent POST, no historical backfill, invalid CSRF, print HTML, PDF headers/body, linked documents and conditional Viber action.

- [ ] **Step 2: Implement published-only lookup/read**

Draft/missing IDs return 404. GET never marks read. POST delegates to `AnnouncementReceiptService` after CSRF.

- [ ] **Step 3: Implement PDF response**

Use fixed ASCII filename `announcement-{id}.pdf`, `application/pdf` and attachment disposition.

- [ ] **Step 4: Build bounded Viber deep link**

Generate `UrlGeneratorInterface::ABSOLUTE_URL`. Never truncate the canonical URL. If it exceeds 200 characters, return no Viber action. Otherwise:

```php
$viberShareUrl = null;
$url = $this->generateUrl(
    'app_announcement_show',
    ['id' => $announcement->getId()],
    UrlGeneratorInterface::ABSOLUTE_URL,
);

if (mb_strlen($url) <= 200) {
    $separator = ' — ';
    $budget = max(0, 200 - mb_strlen($separator) - mb_strlen($url));
    $title = mb_substr($announcement->getTitle(), 0, $budget);
    $text = '' === $title ? $url : $title.$separator.$url;
    $viberShareUrl = 'viber://forward?text='.rawurlencode($text);
}
```

No server-side Viber request/token/delivery state.

- [ ] **Step 5: Build templates and verify**

Official pages show official label, publication actor/time, escaped body, resident document links, explicit read button only with unread receipt, print/PDF and conditional Viber. No reactions/comments.

```bash
vendor/bin/phpunit tests/Controller/AnnouncementControllerTest.php
```

- [ ] **Step 6: Commit**

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
- Test: `tests/Controller/AnnouncementControllerTest.php` — extend navigation assertions

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

Use `Symfony\Bundle\SecurityBundle\Security`; register Twig function `announcement_unread_count`. Return 0 without an authenticated `User`.

- [ ] **Step 1: Add RED navigation/badge assertions**

Resident navigation contains `Обяви`, unread badge when non-zero and `Документи`; manager/admin additionally sees management links. Resident never sees management actions.

- [ ] **Step 2: Implement extension/navigation/dashboard link**

Keep Community navigation intact. Replace the dashboard’s existing official placeholder with a link to the official announcement list and unread count; do not add another dashboard service.

- [ ] **Step 3: Add focused CSS**

Add only `.official-*`, `.document-*` and unread-badge rules that fit the existing CSS system. Official cards must be visibly separate from Community reaction/comment UI.

- [ ] **Step 4: Run focused Phase 8 suite**

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

- [ ] **Step 5: Run complete local verification**

```bash
composer validate --strict
php bin/console lint:container
php bin/console doctrine:schema:validate --skip-sync --env=test
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 6: Verify MariaDB migration round-trip**

Run the CI sequence against MariaDB 10.11:

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

- [ ] **Step 8: Commit integration changes**

```bash
git add src/Twig/AnnouncementExtension.php templates/base.html.twig templates/dashboard/index.html.twig assets/styles/app.css tests/Controller/AnnouncementControllerTest.php
git commit -m "feat: integrate phase 8 official communication"
```

- [ ] **Step 9: Final PR/CI merge gate**

Push the exact final head and verify the PR CI on that SHA: Composer metadata/install, container lint, Doctrine mapping, MariaDB migration round-trip, full PHPUnit and PHPStan. Only then mark ready and merge.

## Phase 8 Acceptance Checklist

1. Management uploads private categorized documents at all three access levels.
2. Files remain outside `public/` and download requires authorization.
3. Residents cannot infer/download finance or management documents by ID.
4. Cashier/controller read finance documents but cannot manage official content.
5. Manager/admin create/revise drafts and publish exactly once.
6. Published title/body/document links are immutable.
7. Announcements link only resident-visible documents.
8. Publication creates one receipt per currently active user and none for inactive users.
9. Later users receive no retroactive unread receipts.
10. Read mutation is POST + CSRF, user-scoped and idempotent.
11. Print HTML and valid Cyrillic-capable PDF are published/authenticated only.
12. Viber sharing is user-initiated and never bypasses authentication.
13. Official announcements remain technically/visually separate from Community.
14. UI/read statistics never claim legal proof of service.
15. Composer, container, Doctrine, MariaDB migration round-trip, full PHPUnit and PHPStan are green on the exact final PR head.
16. Final PR contains only Phase 8 scope.