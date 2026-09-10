# Audit log policy

Reviewed and implemented for Slice 10 on 2026-09-10.

## Purpose

The audit trail records security-sensitive and legally/financially meaningful actions. It is deliberately not a generic Doctrine/ORM change dump.

`AuditEntry` is append-only at application level: it exposes no mutation API and the application has no delete/edit workflow for audit rows. The review UI at `/management/audit` is read-only and restricted to `ROLE_ADMIN`.

## Recorded fields

Each entry stores:

- actor relation when the action came from an authenticated user;
- immutable actor identifier snapshot;
- explicit `system` actor snapshot for automated actions;
- stable action code;
- subject type and optional persisted subject id;
- UTC occurrence timestamp;
- a small allowlisted context payload containing operational identifiers/status values, not an ORM state dump.

The actor foreign key uses `RESTRICT` so historical audit rows cannot silently lose their recorded user relation. The identifier snapshot remains useful even if account attributes change later.

## Covered meaningful actions

The central log supplements existing domain-specific immutable history. Current coverage includes:

- condominium-book declaration acceptance;
- condominium registry updates, management mandates and recurring compliance completions;
- account creation through the administrative CLI;
- payment posting and payment reversal;
- official announcement publication;
- community post/comment visibility moderation and report resolution;
- General Assembly convening, start and close;
- formal vote corrections and resolution recording;
- minutes finalization and minutes corrections/addenda.

Existing domain rows remain authoritative for detailed facts such as individual votes, attendance changes, payment allocations, reversals, status histories and document contents. The central audit table points to those actions without duplicating sensitive payloads.

## Privacy and retention

Do not place passwords, authentication tokens, full document bodies, uploaded file contents, bank statement payloads, free-form personal notes or complete entity snapshots in audit context.

Audit entries are operational/history records and are retained with the corresponding condominium history. There is intentionally no hard-delete UI. Any future retention policy must be explicit and must preserve legally/financially meaningful history.

## Transaction rule

`AuditLogService::record()` persists but never calls `flush()`. The caller owns the transaction. This keeps the business mutation and its audit entry atomic: either both commit or neither commits.

Automated/idempotent workflows must emit an audit row only when a new meaningful mutation is actually committed. Replaying an already-applied idempotent operation must not create a duplicate audit event.
