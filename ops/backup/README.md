# Vhod encrypted backup and restore runbook

The backup set contains both application data stores that matter for recovery:

- the MariaDB database;
- private files under `var/storage/documents`.

The scripts deliberately do not read secrets from repository files and do not print secret values. Production credentials and the backup passphrase must be supplied by the deployment secret store or a root-owned environment file with mode `0600`.

## Prerequisites

Install `mariadb-dump`, `mariadb`, `gpg`, `tar`, `sha256sum`, `mktemp`, PHP 8.4 and the deployed Vhod application. The database account used for normal backup needs read access. The account used for restore drills must be allowed to create/drop only disposable restore databases when practical.

## Create an encrypted backup

Set these environment variables outside the repository:

```text
VHOD_DB_HOST
VHOD_DB_PORT             # optional, defaults to 3306
VHOD_DB_NAME
VHOD_DB_USER
VHOD_DB_PASSWORD
VHOD_BACKUP_DIR
VHOD_BACKUP_PASSPHRASE
VHOD_PROJECT_DIR         # optional, auto-detected from the script location
```

Then run the repository script explicitly with Bash (this does not depend on the checkout preserving executable mode):

```bash
bash ops/backup/create-backup.sh
```

The output directory receives only an encrypted `*.tar.gz.gpg` file and its `*.sha256` sidecar. Plain database/document payloads exist only inside a mode-0700 temporary directory and are removed by the exit trap.

Copy the encrypted artifact and checksum to an **off-host** destination after every successful backup. A backup kept only on the application server does not protect against host or disk loss. Restrict access to both the encrypted archive and the passphrase; never store the passphrase next to the backup.

An operational retention baseline for this private installation can be 7 daily, 4 weekly and 12 monthly encrypted backups. This is an operational policy, not a statutory retention claim; adjust it to available storage and the condominium's data-retention decisions.

## Verify a restore

A restore drill must use an isolated database whose name matches `vhod_restore_test_[A-Za-z0-9_]+`. The verifier refuses any other database name.

Required variables:

```text
VHOD_RESTORE_DB_HOST
VHOD_RESTORE_DB_PORT     # optional, defaults to 3306
VHOD_RESTORE_DB_NAME     # must start with vhod_restore_test_
VHOD_RESTORE_DB_USER
VHOD_RESTORE_DB_PASSWORD
VHOD_RESTORE_DATABASE_URL
VHOD_BACKUP_PASSPHRASE
VHOD_PROJECT_DIR         # optional
VHOD_KEEP_RESTORE_DB     # optional; 1 keeps the scratch DB, default removes it
```

`VHOD_RESTORE_DATABASE_URL` must point to the exact same scratch database named by `VHOD_RESTORE_DB_NAME`; the script checks this before creating anything.

Run:

```bash
bash ops/backup/verify-restore.sh /secure/backups/vhod-YYYYMMDDTHHMMSSZ.tar.gz.gpg
```

The verifier checks SHA-256, decrypts only into a temporary directory, restores MariaDB into the scratch database, expands private documents into a scratch directory and runs `doctrine:schema:validate`. The scratch database is dropped automatically unless `VHOD_KEEP_RESTORE_DB=1` is explicitly set.

## Schedule and review

Run the encrypted backup at least daily with a systemd timer or cron under a dedicated account, invoking it through `bash`. Alert if the job exits non-zero or if the newest **off-host** backup age exceeds the monitoring threshold.

Perform a restore drill at least monthly and after material changes to database/storage infrastructure. Record the date, tested backup identifier, result and corrective action if a drill fails. A successful backup job without a periodically proven restore is not a verified recovery process.
