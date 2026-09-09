# Phase 7 — Signals and maintenance design

## Goal

Implement roadmap Phase 7 as one authenticated maintenance vertical slice that lets residents submit structured building signals and lets managers/admins triage them, attach them to building assets, assign responsibility, preserve status history, record suppliers/contracts/maintenance events, and see warranty/inspection reminders.

The feature is intentionally smaller than a general CMMS. It must fit the existing Vhod identity/security model and leave the application in a working, testable state.

## Scope

Phase 7 includes:

- structured resident signals;
- private attachments/photos for signals;
- append-only signal status history;
- optional signal assignment to an application user;
- building asset registry;
- supplier registry;
- maintenance contracts;
- maintenance/service events;
- warranty and inspection reminders shown in the management UI.

## Non-goals

The following are explicitly deferred:

- resident notifications and notification preferences — Phase 8;
- general private document library — Phase 8;
- official announcements — Phase 8;
- expense/payment linkage — not required by Phase 7;
- contract-expiry reminder automation — Phase 10;
- background schedulers, Messenger jobs, email, Viber, push, Mercure or WebSockets;
- public signal pages;
- hard-delete workflows.

## Security model

All maintenance routes require authentication.

Residents (`ROLE_USER`) may:

- create signals;
- view only signals they submitted;
- upload and download attachments belonging to their own signals.

Managers and admins (`ROLE_MANAGER`, `ROLE_ADMIN`) may:

- view every signal;
- download every signal attachment;
- assign signals;
- change signal status;
- create assets, suppliers, contracts and maintenance events;
- view maintenance reminders.

Residents may never access another resident's signal by guessing an ID. Management mutations use CSRF tokens. Files are never served directly from `public/`.

`ROLE_CONTROLLER` and `ROLE_CASHIER` receive no maintenance-management rights unless they also have manager/admin roles.

## Domain model

### MaintenanceSignal

Represents one structured issue reported by a resident.

Fields:

- `id`;
- `submittedBy: User`;
- optional `asset: BuildingAsset`;
- optional `assignedTo: User`;
- `category: MaintenanceSignalCategory`;
- `priority: MaintenanceSignalPriority`;
- `status: MaintenanceSignalStatus`;
- `title`;
- `description`;
- `location`;
- `createdAt` UTC;
- `updatedAt` UTC.

Creation always starts in `OPEN` status. Assignment does not implicitly change status. Status changes are performed only through the application service so history cannot be skipped.

### MaintenanceSignalStatusChange

Append-only audit row for signal status transitions.

Fields:

- `signal`;
- nullable `fromStatus` for the initial creation row;
- `toStatus`;
- `changedBy: User`;
- `changedAt` UTC;
- optional `note`.

The service creates an initial row (`null -> OPEN`) when the signal is created. A later transition to the same status is rejected.

### MaintenanceAttachment

Metadata for a private uploaded file linked to a signal.

Fields:

- `signal`;
- `uploadedBy: User`;
- `originalName`;
- `storageName`;
- `mimeType`;
- `sizeBytes`;
- `uploadedAt` UTC.

Storage rules:

- directory: `%kernel.project_dir%/var/storage/maintenance`;
- randomized server filename; user filenames are never used as paths;
- allowed MIME types: JPEG, PNG, WebP and PDF;
- maximum file size: 8 MiB;
- authenticated controller download only;
- no executable/script formats;
- failed validation must not persist metadata.

The storage service is responsible only for validating, storing, locating and removing a just-stored file when a persistence failure occurs. The entity stores immutable metadata.

### BuildingAsset

Represents a maintained common building asset.

Fields:

- `name`;
- `category: BuildingAssetCategory`;
- `location`;
- optional `manufacturer`;
- optional `model`;
- optional `serialNumber`;
- optional `installedAt`;
- optional `warrantyUntil`;
- optional `inspectionIntervalMonths`;
- optional `nextInspectionAt`;
- `active`.

No hard-delete UI is provided. Assets may be deactivated later without losing maintenance history.

### MaintenanceSupplier

Supplier/service-provider master data.

Fields:

- `name`;
- optional `registrationNumber`;
- optional `contactPerson`;
- optional `email`;
- optional `phone`;
- optional `address`;
- optional `note`;
- `active`.

### MaintenanceContract

Represents a supplier contract.

Fields:

- `supplier`;
- optional `asset` — null means a general building contract;
- `title`;
- optional `reference`;
- `startsAt`;
- optional `endsAt`;
- optional `note`;
- `createdAt` UTC.

The contract validates that `endsAt`, when present, is not before `startsAt`.

### MaintenanceEvent

Immutable record of work performed on an asset.

Fields:

- `asset`;
- optional `supplier`;
- optional `contract`;
- optional `signal`;
- `type: MaintenanceEventType`;
- `performedAt`;
- `summary`;
- optional `note`;
- optional `nextInspectionAt`;
- `recordedBy: User`;
- `recordedAt` UTC.

If a contract is supplied, it must belong to the same supplier when a supplier is also supplied and, when contract asset is non-null, it must match the event asset. If a signal is supplied and the signal has an asset, it must match the event asset.

When an event contains `nextInspectionAt`, the application service also updates the asset's `nextInspectionAt` in the same transaction.

## Enums

### MaintenanceSignalCategory

- `COMMON_AREA`
- `ELECTRICAL`
- `PLUMBING`
- `ELEVATOR`
- `ROOF`
- `ACCESS`
- `CLEANING`
- `SAFETY`
- `OTHER`

### MaintenanceSignalPriority

- `LOW`
- `NORMAL`
- `HIGH`
- `URGENT`

### MaintenanceSignalStatus

- `OPEN`
- `IN_PROGRESS`
- `WAITING`
- `RESOLVED`
- `CLOSED`

No artificial transition graph is imposed beyond rejecting a no-op transition. Real maintenance work sometimes moves backward (for example `RESOLVED -> IN_PROGRESS`), so the system preserves history instead of forbidding operational corrections.

### BuildingAssetCategory

- `ELEVATOR`
- `ACCESS_SYSTEM`
- `ELECTRICAL`
- `PLUMBING`
- `FIRE_SAFETY`
- `ROOF`
- `COMMON_AREA`
- `OTHER`

### MaintenanceEventType

- `INSPECTION`
- `PREVENTIVE_MAINTENANCE`
- `REPAIR`
- `WARRANTY_SERVICE`
- `OTHER`

All enums expose Bulgarian labels for Twig forms.

## Application services

### MaintenanceSignalService

Responsibilities:

- create a signal and its initial status-history row transactionally;
- assign/unassign a signal under a pessimistic write lock;
- change signal status and append history under a pessimistic write lock.

### MaintenanceAttachmentStorage

Responsibilities:

- validate MIME/size;
- generate safe random filenames;
- move uploaded files into private storage;
- resolve a stored file path for authenticated download;
- clean up the stored file if database persistence subsequently fails.

### MaintenanceAttachmentService

Responsibilities:

- verify the actor may attach to the signal;
- store the file;
- persist immutable attachment metadata;
- remove the stored file on transaction/persistence failure.

### MaintenanceRegistryService

Responsibilities:

- create assets;
- create suppliers;
- create contracts with compatibility/date validation;
- record maintenance events;
- update an asset's next-inspection date atomically when an event supplies one.

### MaintenanceReminderService

Produces non-persistent management dashboard reminder DTOs/arrays from current asset data:

- warranty expired;
- warranty ending within 60 days;
- inspection overdue;
- inspection due within 30 days.

This is deliberately derived data. Phase 8 can later turn these conditions into notifications without changing Phase 7 persistence.

## HTTP/UI flows

### Resident routes

- `GET /maintenance` — resident's own signals;
- `GET|POST /maintenance/signal/new` — create a signal;
- `GET /maintenance/signal/{id}` — own signal detail, or management access;
- `POST /maintenance/signal/{id}/attachment` — private upload;
- `GET /maintenance/attachment/{id}/download` — authorized download.

The signal detail shows status, priority, assignment, attachments and chronological status history.

### Management routes

- `GET /management/maintenance` — dashboard with open signals, assets, suppliers/contracts, recent events and reminders;
- `POST /management/maintenance/signal/{id}/assign`;
- `POST /management/maintenance/signal/{id}/status`;
- `GET|POST /management/maintenance/asset/new`;
- `GET|POST /management/maintenance/supplier/new`;
- `GET|POST /management/maintenance/contract/new`;
- `GET|POST /management/maintenance/event/new`.

No delete routes are introduced.

Navigation adds `Поддръжка` for authenticated residents and `Управление на поддръжката` for manager/admin users.

## Persistence and integrity

A new migration creates seven tables:

- `maintenance_signal`;
- `maintenance_signal_status_change`;
- `maintenance_attachment`;
- `building_asset`;
- `maintenance_supplier`;
- `maintenance_contract`;
- `maintenance_event`.

All audit/history associations use `ON DELETE RESTRICT`. Every FK index used by Doctrine is explicitly named in ORM metadata and migration DDL to avoid DBAL-generated index-name drift.

Dates stored as `datetime_immutable` are UTC for audit timestamps. Calendar dates such as warranty, contract and performed dates retain their date semantics and are parsed in `Europe/Sofia` at the HTTP boundary.

## Concurrency and transactions

Assignment and status mutations acquire `PESSIMISTIC_WRITE` on the signal. Maintenance event recording acquires a write lock on the asset when it changes `nextInspectionAt`.

Signal creation plus initial history is atomic. Status update plus history append is atomic. Event creation plus asset inspection-date update is atomic.

Attachments intentionally coordinate filesystem and database work with compensating cleanup: store file first, persist metadata transactionally, delete the just-created file if persistence fails.

## Testing strategy

### Entity tests

Cover:

- signal creation invariants;
- no-op status change rejection;
- asset date validation;
- contract date/compatibility rules;
- maintenance-event compatibility rules;
- attachment metadata validation.

### Service/integration tests

Cover:

- signal creation creates exactly one initial history row;
- assignment updates without duplicate records;
- status change appends immutable history;
- event with next inspection updates the asset atomically;
- reminder classification for expired/due/future dates;
- private file validation/storage metadata.

### Functional WebTestCase coverage

Cover:

- anonymous redirect;
- resident create/view own signal;
- resident cannot view another resident's signal;
- resident upload/download own attachment;
- invalid CSRF blocks resident mutation routes;
- resident cannot access management routes;
- manager can assign and change status;
- manager can create asset/supplier/contract/event;
- invalid CSRF blocks management mutation routes.

### Schema/CI gates

- Composer validation;
- Symfony container lint;
- Doctrine mapping validation;
- MariaDB migrate -> schema validate -> rollback -> migrate -> schema validate;
- full PHPUnit;
- PHPStan with no ignores added for Phase 7.

## Acceptance criteria

Phase 7 is complete only when:

1. A resident can submit and privately follow a structured signal.
2. A resident can attach an allowed photo/PDF and only authorized users can download it.
3. A manager/admin can assign the signal and change status with complete append-only history.
4. Assets, suppliers and contracts can be registered.
5. Maintenance events can be recorded against assets and optionally linked to supplier/contract/signal.
6. Warranty and inspection reminders are visible to management.
7. No Phase 7 route leaks another resident's signal or attachment.
8. Every mutation route has CSRF protection.
9. Doctrine/MariaDB schema round-trip, PHPUnit and PHPStan are green.
10. The final PR contains only Phase 7 scope.