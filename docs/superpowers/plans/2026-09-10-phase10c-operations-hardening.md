# Phase 10C Operations Hardening Plan

**Goal:** Complete the remaining Slice 10 requirements: encrypted backups, restore verification, security headers and rate limiting, and production monitoring.

**Principles:** Keep runtime dependencies unchanged; use application-level response hardening plus reverse-proxy rate limiting. Backups must include MariaDB and private document storage, be encrypted before leaving temporary storage, and be restorable only into an explicitly named scratch database during verification. Monitoring must expose no sensitive details.

### Task 1 — Security headers and health endpoint
- [ ] Write failing functional tests for defensive response headers, HTTPS-only HSTS, and public `/healthz`.
- [ ] Add `SecurityHeadersSubscriber` and minimal DB-backed `HealthController`.
- [ ] Allow `/healthz` through the firewall before the authenticated catch-all rule.
- [ ] Verify focused tests.

### Task 2 — Encrypted backup and restore verification
- [ ] Add `ops/backup/create-backup.sh` using MariaDB consistent dump + private document archive + GnuPG symmetric encryption + SHA-256 sidecar.
- [ ] Add `ops/backup/verify-restore.sh` that refuses non-scratch database names, verifies checksum, decrypts into a temporary directory, restores into a disposable MariaDB database, validates Doctrine schema, and cleans up by default.
- [ ] Add operational documentation with secret handling, off-host storage, retention and restore-drill procedure.
- [ ] Add CI shell syntax checks.

### Task 3 — Reverse-proxy rate limiting and production monitoring
- [ ] Add Nginx hardening template with POST-login brute-force limiting, general dynamic-request limiting, 429 responses, HTTPS headers and proxy deployment notes.
- [ ] Document external monitoring of `/healthz`, 5xx/error rate, disk/storage, MariaDB and backup age.
- [ ] Add a static operational regression test that ensures the required safeguards remain present.

### Task 4 — Final project verification
- [ ] Run full PHPUnit, PHPStan, container lint, Doctrine validation and MariaDB migration up/down/up.
- [ ] Review final diff for secrets, public document paths, unsafe restore targets, missing CSRF/access boundaries or unrelated changes.
- [ ] Mark Slice 10 complete in the roadmap only after exact-head CI succeeds.
- [ ] Merge only the exact verified head.
