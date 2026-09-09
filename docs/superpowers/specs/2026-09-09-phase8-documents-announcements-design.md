# Phase 8 — Documents and announcements design

## Goal

Implement roadmap Phase 8 as one authenticated official-communication vertical slice that gives the entrance a private document library, role-based document access, official announcements, in-app unread/read tracking, printable/PDF announcement output, and an optional user-initiated Viber share link.

The feature must remain clearly separate from the Community module. Community content is informal neighbour communication; Phase 8 content is management-published information and private administrative documentation.

The design deliberately avoids turning Vhod into a generic document-management, messaging, workflow or notification platform. The product boundary remains one residential entrance.

## Scope

Phase 8 includes:

- private document storage outside `public/`;
- document categories;
- simple role-based document access levels;
- authenticated document listing and download;
- official announcement drafts and publication;
- linking resident-visible documents to announcements;
- per-user announcement receipt/read state;
- unread announcement count/list for active users;
- print-friendly announcement HTML;
- downloadable PDF announcement output;
- optional user-initiated Viber share link for a published announcement;
- management UI for documents and announcements.

## Non-goals

The following are explicitly deferred:

- email delivery;
- push notifications;
- SMS;
- Viber bot/API integration;
- Messenger jobs for announcement fan-out;
- Mercure/WebSockets;
- per-document arbitrary ACL lists;
- per-unit or per-household document targeting;
- document version trees/check-in/check-out;
- OCR, full-text indexing or document content extraction;
- office-document preview/conversion;
- public document/announcement URLs;
- General Assembly invitation, agenda, quorum, voting or minutes workflows — Phase 9;
- legal-service/notification proof beyond the application’s own read state;
- hard-delete routes.

## Legal and communication boundary

Phase 8 is an operational communication layer, not a replacement for any notification, posting, service, signature, deadline or evidentiary method required by Bulgarian law.

A Vhod `AnnouncementReceipt` proves only that an application user had an in-app announcement made available and, when `readAt` is present, that the user account marked it as read. It must never be presented as statutory proof that a legally required notice, General Assembly invitation or other formal document has been served correctly.

Phase 9 must model any legally significant General Assembly notification method explicitly instead of reusing Phase 8 read receipts as legal evidence.

## Security model

All Phase 8 routes require authentication.

### Document access levels

`DocumentAccessLevel` has exactly three values:

- `RESIDENTS` — every active authenticated user;
- `FINANCE` — `ROLE_CASHIER`, `ROLE_CONTROLLER`, `ROLE_MANAGER`, `ROLE_ADMIN`;
- `MANAGEMENT` — `ROLE_MANAGER`, `ROLE_ADMIN`.

This is intentionally simpler than arbitrary ACL tables. The existing product is one entrance with a small, known role model, and these three levels cover the roadmap use-cases without introducing a permissions subsystem.

### Document management rights

Only `ROLE_MANAGER` and `ROLE_ADMIN` may upload documents or create/publish official announcements.

`ROLE_CASHIER` and `ROLE_CONTROLLER` may read `FINANCE` documents but may not publish or modify official content unless they also have manager/admin roles.

### Access enforcement

Authorization is enforced server-side for every document list/detail/download and announcement management action. Hiding links in Twig is never the security boundary.

Document download uses a voter or equivalent centralized authorization check before the private file path is resolved.

Files are never stored under `public/` and never exposed via guessable static URLs.

Every POST mutation is CSRF-protected.

## Domain model

### Document

Represents one immutable uploaded private file plus its administrative metadata.

Fields:

- `id`;
- `category: DocumentCategory`;
- `accessLevel: DocumentAccessLevel`;
- `title`;
- optional `description`;
- `originalName`;
- `storageName`;
- `mimeType`;
- `sizeBytes`;
- `uploadedBy: User`;
- `uploadedAt` UTC.

The binary and security-relevant metadata are immutable after creation. Phase 8 provides no replacement-in-place and no delete route. If a document is wrong, management uploads a corrected document instead of silently replacing audit history.

Document titles and descriptions are normalized and required/optional respectively. User-provided filenames are preserved only as display/download metadata and are never used as storage paths.

### DocumentCategory

The initial controlled categories are:

- `MEETING_INVITATION`;
- `MEETING_MINUTES`;
- `HOUSE_RULES`;
- `INVOICE_RECEIPT`;
- `CONTRACT_OFFER`;
- `WARRANTY`;
- `BANK_STATEMENT`;
- `TECHNICAL_DOCUMENTATION`;
- `MONTHLY_REPORT`;
- `OTHER`.

Categories expose Bulgarian labels for forms and filters.

The Phase 9 meeting domain may later create/link documents in the meeting-related categories, but Phase 8 does not implement meeting semantics.

### DocumentAccessLevel

Values:

- `RESIDENTS`;
- `FINANCE`;
- `MANAGEMENT`.

The enum is the persisted policy decision for a document. Authorization logic maps the current user’s roles to the required access level.

### OfficialAnnouncement

Represents one management-authored official announcement, separate from `CommunityPost`.

Fields:

- `id`;
- `status: OfficialAnnouncementStatus`;
- `title`;
- `body`;
- `createdBy: User`;
- `createdAt` UTC;
- nullable `publishedBy: User`;
- nullable `publishedAt` UTC;
- many-to-many `documents: Document`.

Announcement body content is plain text with preserved line breaks in Phase 8. No rich-text editor, arbitrary HTML or Markdown engine is introduced.

A draft may be edited by management. Once published, title, body and linked documents become immutable. A factual correction is made by publishing a new announcement rather than silently rewriting previously published official content.

Only `RESIDENTS` documents may be linked to an announcement. This is validated by the application service so publishing an announcement can never expose a finance/management document indirectly.

### OfficialAnnouncementStatus

Values:

- `DRAFT`;
- `PUBLISHED`.

There is no delete/unpublish transition in Phase 8.

### AnnouncementReceipt

Represents in-app availability/read state for one published announcement and one user.

Fields:

- `announcement: OfficialAnnouncement`;
- `user: User`;
- `availableAt` UTC;
- nullable `readAt` UTC.

There is a unique database constraint on `(announcement_id, user_id)`.

When an announcement is published, one receipt is created synchronously for every currently active user. Because Vhod serves one entrance, the user set is intentionally small and does not justify Messenger fan-out.

A user created or reactivated after publication can still browse previously published announcements, but receives unread tracking only for announcements published while the account is active. This rule avoids retroactive receipt backfills and keeps Phase 8 deterministic.

Marking a receipt read is idempotent: the first successful operation sets `readAt`; repeated attempts keep the original timestamp.

Deactivating a user never removes historical receipt rows.

## Private document storage

### DocumentStorage

Use a dedicated Phase 8 storage service rather than prematurely generalizing `MaintenanceAttachmentStorage`.

Directory:

`%kernel.project_dir%/var/storage/documents`

Allowed MIME types:

- `application/pdf`;
- `image/jpeg`;
- `image/png`;
- `image/webp`.

Maximum file size: 16 MiB.

Storage rules:

- detect MIME using `finfo`, never trust the extension;
- generate a cryptographically random server filename;
- map MIME to a fixed extension;
- reject path traversal in path resolution;
- create the directory with non-public permissions when needed;
- never execute or render uploaded file contents as HTML;
- remove a just-stored file when subsequent database persistence fails.

Uploaded files are downloaded through an authenticated controller only.

## Application services

### DocumentService

Responsibilities:

- validate that the actor may manage documents;
- store the uploaded file;
- create immutable `Document` metadata;
- persist metadata transactionally;
- compensate by deleting the just-stored file if persistence fails.

Authorization for reading remains centralized in the document voter/access service and is not duplicated inside storage.

### OfficialAnnouncementService

Responsibilities:

- create a draft;
- update draft title/body/document links;
- reject mutation of published announcements;
- validate that every linked document is `RESIDENTS`;
- publish a draft under a pessimistic write lock;
- set `publishedBy` and `publishedAt`;
- create exactly one `AnnouncementReceipt` for each currently active user in the same database transaction.

Publishing is concurrency-safe. A double submit must not produce duplicate receipts or two publish transitions.

### AnnouncementReceiptService

Responsibilities:

- load the current user’s receipt for a published announcement;
- mark it read idempotently;
- expose unread-count/list queries used by the resident UI.

Read-state mutation is performed through an explicit CSRF-protected POST route rather than a side effect of GET.

### AnnouncementPdfService

Generates PDF from a dedicated print Twig template.

Use `dompdf/dompdf:^3.1`. As verified on 2026-09-09, Dompdf 3.1.x supports PHP 8.x and ships DejaVu fonts suitable for Unicode/Cyrillic output.

PDF rules:

- render only controlled Twig output from announcement fields;
- use `DejaVu Sans` explicitly for Bulgarian/Cyrillic text;
- keep remote resource loading disabled;
- do not embed arbitrary uploaded document contents;
- linked documents are listed by title only;
- A4 portrait output;
- return `application/pdf` with a safe attachment filename.

The printable HTML and PDF use the same content structure, but separate presentation CSS where required by Dompdf limitations.

## Official announcement and document relationship

The announcement-to-document link is a plain Doctrine many-to-many association backed by an explicit join table.

No separate join entity is needed because Phase 8 requires no per-link metadata, ordering, signatures or version state.

A linked document remains independently accessible through the document library subject to its own access level. Because announcements only accept `RESIDENTS` documents, every user who can read a published announcement can also download its linked documents.

## HTTP/UI flows

### Resident documents

- `GET /documents` — list documents visible to the current user, grouped/filterable by category;
- `GET /document/{id}/download` — authorized private download.

The list query must enforce access in SQL/repository selection where practical rather than loading all documents and filtering in Twig.

### Resident announcements

- `GET /announcements` — published announcements, newest first, with unread indicator;
- `GET /announcement/{id}` — published announcement detail;
- `POST /announcement/{id}/read` — mark current user receipt as read, CSRF-protected;
- `GET /announcement/{id}/print` — print-friendly authenticated HTML;
- `GET /announcement/{id}/pdf` — authenticated PDF download.

Only `PUBLISHED` announcements are visible outside management routes.

The resident dashboard may show the unread announcement count and latest published announcements, but Phase 8 does not add a generic notification center.

### Management documents

- `GET /management/documents` — all documents with category/access metadata;
- `GET|POST /management/document/new` — upload a document.

No delete route is introduced.

### Management announcements

- `GET /management/announcements` — drafts and published announcements;
- `GET|POST /management/announcement/new` — create a draft;
- `GET|POST /management/announcement/{id}/edit` — edit draft only;
- `POST /management/announcement/{id}/publish` — publish atomically and create receipts.

Published announcements render read statistics for management as informational application state only: total receipts and read count. This is not labeled as legal delivery proof.

## Viber share link

Phase 8 may show a `Сподели във Viber` action only on a published announcement.

The action is user-initiated and constructs a Viber share/deep-link payload containing:

- the announcement title;
- the canonical authenticated announcement URL.

There is no server-side Viber request, bot token, recipient selection, delivery tracking or automatic messaging.

The shared URL remains protected by normal Vhod authentication, so forwarding the link does not make the announcement public.

If the client device does not support Viber deep links, the normal authenticated announcement page remains the canonical destination.

## Persistence and integrity

A new migration creates:

- `document`;
- `official_announcement`;
- `official_announcement_document` join table;
- `announcement_receipt`.

Important constraints/indexes:

- explicit FK indexes matching Doctrine metadata;
- unique `(announcement_id, user_id)` on `announcement_receipt`;
- index on `official_announcement(status, published_at)`;
- index on `document(access_level, category, uploaded_at)`;
- index on `announcement_receipt(user_id, read_at)` for unread queries.

Audit-sensitive associations use `ON DELETE RESTRICT`.

No Phase 8 entity receives a hard-delete controller flow.

## Transactions and concurrency

Document upload coordinates filesystem and database work with compensating cleanup, following the proven Phase 7 attachment pattern.

Announcement publication runs inside a database transaction and acquires `PESSIMISTIC_WRITE` on the draft before checking state. Publication timestamp, publisher and all active-user receipts are persisted atomically.

The unique receipt constraint is a final database-level safeguard against duplicate fan-out.

Draft edits do not require pessimistic locking in Phase 8 because management is small and draft content has no legal/audit meaning before publication. Publication is the immutable boundary.

## Error handling

Invalid document MIME/size returns a validation error without persisting metadata.

Unauthorized document IDs and downloads return 404 where revealing resource existence would leak restricted information; management authorization failures on known management routes return 403.

Attempting to:

- edit a published announcement;
- publish an already published announcement;
- publish a blank announcement;
- link a `FINANCE`/`MANAGEMENT` document to an announcement

returns a controlled domain/validation error and does not partially mutate state.

Missing private files return 404 and never expose filesystem paths.

PDF generation failure returns a normal application error; it does not change announcement or receipt state.

## Navigation and UX

Resident navigation adds:

- `Обяви` / official announcements with unread badge when non-zero;
- `Документи`.

Management navigation adds:

- `Официални обяви`;
- `Документи`.

Official announcement pages use a deliberately different visual treatment from Community posts: management attribution, publication timestamp, official label and no reactions/comments.

The UI remains mobile-first and does not add a separate notification center, mailbox metaphor or complex document tree.

## Testing strategy

### Entity tests

Cover:

- document title/metadata normalization;
- supported enum values;
- announcement draft creation;
- published announcement immutability;
- receipt first-read timestamp is immutable/idempotent.

### Storage/service tests

Cover:

- PDF/JPEG/PNG/WebP accepted;
- executable/text/unknown MIME rejected;
- >16 MiB rejected;
- randomized storage filename;
- traversal rejection;
- filesystem cleanup on persistence failure;
- draft update allowed before publication;
- restricted document cannot be linked to announcement;
- publication creates exactly one receipt per active user;
- inactive users receive no receipt;
- double publication cannot duplicate receipts;
- mark-read operation is idempotent.

### Authorization/voter tests

Cover:

- `RESIDENTS` visible to normal resident;
- `FINANCE` hidden from normal resident;
- `FINANCE` visible to cashier/controller/manager/admin;
- `MANAGEMENT` visible only to manager/admin;
- guessed restricted document IDs do not leak content.

### Functional WebTestCase coverage

Cover:

- anonymous user redirected to login;
- resident sees only permitted documents;
- resident can download a permitted private document;
- resident cannot download restricted document;
- resident sees only published announcements;
- resident can mark own receipt read;
- resident cannot mutate another user’s receipt;
- resident print/PDF routes require announcement visibility;
- resident cannot access management routes;
- manager uploads document;
- manager creates and edits draft;
- manager cannot attach restricted document to announcement;
- manager publishes and receipts are created;
- manager cannot edit published announcement;
- invalid CSRF blocks every POST mutation.

### PDF tests

Cover:

- response MIME is `application/pdf`;
- response uses attachment disposition and safe filename;
- generated body begins with a valid PDF signature;
- Bulgarian/Cyrillic announcement content renders without generation error using `DejaVu Sans`;
- unauthorized users cannot generate PDF for inaccessible content.

### Schema/CI gates

- Composer validation;
- Symfony container lint;
- Doctrine mapping validation;
- MariaDB migrate -> schema validate -> rollback -> migrate -> schema validate;
- full PHPUnit;
- PHPStan with no Phase 8 ignore rules;
- final physical diff review before merge.

## Acceptance criteria

Phase 8 is complete only when:

1. Management can upload a private categorized document with one of the three defined access levels.
2. Files remain outside `public/` and are downloadable only after server-side authorization.
3. A normal resident cannot infer or download finance/management documents by guessing IDs.
4. Management can create/edit a draft official announcement and publish it exactly once.
5. Published announcement content and linked documents cannot be silently changed.
6. Only resident-visible documents may be attached to official announcements.
7. Publication creates one unread receipt for every currently active user and none for inactive users.
8. A user can mark only their own receipt read, idempotently.
9. Residents can view printable HTML and download a valid Cyrillic-capable PDF for published announcements.
10. Optional Viber sharing is user-initiated only and never bypasses authentication.
11. Community posts remain technically and visually separate from official announcements.
12. No Phase 8 UI claims that in-app read state proves legally sufficient notification/service.
13. Every mutation route is CSRF-protected.
14. Doctrine/MariaDB schema round-trip, PHPUnit and PHPStan are green.
15. The final PR contains only Phase 8 scope.
