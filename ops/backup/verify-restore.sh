#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

require_env() {
    local name="$1"
    if [[ -z "${!name:-}" ]]; then
        printf 'Missing required environment variable: %s\n' "$name" >&2
        exit 64
    fi
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || {
        printf 'Required command not found: %s\n' "$1" >&2
        exit 69
    }
}

if [[ $# -ne 1 ]]; then
    printf 'Usage: %s /path/to/vhod-backup.tar.gz.gpg\n' "$0" >&2
    exit 64
fi

for name in VHOD_RESTORE_DB_HOST VHOD_RESTORE_DB_NAME VHOD_RESTORE_DB_USER VHOD_RESTORE_DB_PASSWORD VHOD_RESTORE_DATABASE_URL VHOD_BACKUP_PASSPHRASE; do
    require_env "$name"
done

for command_name in mariadb gpg tar sha256sum mktemp php; do
    require_command "$command_name"
done

if [[ ! "$VHOD_RESTORE_DB_NAME" =~ ^vhod_restore_test_[A-Za-z0-9_]+$ ]]; then
    printf 'Refusing restore: database name must match vhod_restore_test_[A-Za-z0-9_]+.\n' >&2
    exit 65
fi

backup_path="$1"
checksum_path="${backup_path}.sha256"
if [[ ! -f "$backup_path" || ! -f "$checksum_path" ]]; then
    printf 'Backup or checksum sidecar not found.\n' >&2
    exit 66
fi

VHOD_RESTORE_DB_PORT="${VHOD_RESTORE_DB_PORT:-3306}"
VHOD_PROJECT_DIR="${VHOD_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
VHOD_KEEP_RESTORE_DB="${VHOD_KEEP_RESTORE_DB:-0}"
work_dir="$(mktemp -d)"
database_created=0

cleanup() {
    local exit_code=$?

    if [[ "$database_created" -eq 1 && "$VHOD_KEEP_RESTORE_DB" != "1" ]]; then
        MYSQL_PWD="$VHOD_RESTORE_DB_PASSWORD" mariadb \
            --host="$VHOD_RESTORE_DB_HOST" \
            --port="$VHOD_RESTORE_DB_PORT" \
            --user="$VHOD_RESTORE_DB_USER" \
            --execute="DROP DATABASE IF EXISTS \`$VHOD_RESTORE_DB_NAME\`;" >/dev/null 2>&1 || true
    fi

    rm -rf "$work_dir"
    exit "$exit_code"
}
trap cleanup EXIT INT TERM

(
    cd "$(dirname "$backup_path")"
    sha256sum -c "$(basename "$checksum_path")"
)

payload_path="$work_dir/backup.tar.gz"
gpg --batch --yes --pinentry-mode loopback \
    --passphrase-fd 3 \
    --output "$payload_path" \
    --decrypt "$backup_path" \
    3<<<"$VHOD_BACKUP_PASSPHRASE"

tar -C "$work_dir" -xzf "$payload_path"

if [[ ! -s "$work_dir/database.sql" || ! -f "$work_dir/documents.tar" || ! -s "$work_dir/manifest.txt" ]]; then
    printf 'Backup payload is incomplete.\n' >&2
    exit 65
fi

mkdir -p "$work_dir/restored-documents"
tar -C "$work_dir/restored-documents" -xf "$work_dir/documents.tar"
if [[ ! -d "$work_dir/restored-documents/var/storage/documents" ]]; then
    printf 'Private document archive is incomplete.\n' >&2
    exit 65
fi

export VHOD_RESTORE_DATABASE_URL
url_database="$(php -r '$url = parse_url((string) getenv("VHOD_RESTORE_DATABASE_URL")); $path = $url["path"] ?? ""; echo ltrim($path, "/");')"
if [[ "$url_database" != "$VHOD_RESTORE_DB_NAME" ]]; then
    printf 'Refusing restore: VHOD_RESTORE_DATABASE_URL must target the same scratch database.\n' >&2
    exit 65
fi

MYSQL_PWD="$VHOD_RESTORE_DB_PASSWORD" mariadb \
    --host="$VHOD_RESTORE_DB_HOST" \
    --port="$VHOD_RESTORE_DB_PORT" \
    --user="$VHOD_RESTORE_DB_USER" \
    --execute="DROP DATABASE IF EXISTS \`$VHOD_RESTORE_DB_NAME\`; CREATE DATABASE \`$VHOD_RESTORE_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
database_created=1

MYSQL_PWD="$VHOD_RESTORE_DB_PASSWORD" mariadb \
    --host="$VHOD_RESTORE_DB_HOST" \
    --port="$VHOD_RESTORE_DB_PORT" \
    --user="$VHOD_RESTORE_DB_USER" \
    "$VHOD_RESTORE_DB_NAME" < "$work_dir/database.sql"

(
    cd "$VHOD_PROJECT_DIR"
    DATABASE_URL="$VHOD_RESTORE_DATABASE_URL" APP_ENV=prod php bin/console doctrine:schema:validate --env=prod
)

printf 'Restore verification succeeded for scratch database %s.\n' "$VHOD_RESTORE_DB_NAME"
if [[ "$VHOD_KEEP_RESTORE_DB" == "1" ]]; then
    printf 'Scratch database retained because VHOD_KEEP_RESTORE_DB=1.\n'
fi
