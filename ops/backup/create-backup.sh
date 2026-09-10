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

for name in VHOD_DB_HOST VHOD_DB_NAME VHOD_DB_USER VHOD_DB_PASSWORD VHOD_BACKUP_DIR VHOD_BACKUP_PASSPHRASE; do
    require_env "$name"
done

for command_name in mariadb-dump gpg tar sha256sum mktemp; do
    require_command "$command_name"
done

VHOD_DB_PORT="${VHOD_DB_PORT:-3306}"
VHOD_PROJECT_DIR="${VHOD_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
DOCUMENT_DIR="$VHOD_PROJECT_DIR/var/storage/documents"

if [[ ! -d "$DOCUMENT_DIR" ]]; then
    printf 'Private document directory not found: %s\n' "$DOCUMENT_DIR" >&2
    exit 66
fi

mkdir -p "$VHOD_BACKUP_DIR"
work_dir="$(mktemp -d)"
cleanup() {
    rm -rf "$work_dir"
}
trap cleanup EXIT INT TERM

timestamp="$(date -u +'%Y%m%dT%H%M%SZ')"
archive_name="vhod-${timestamp}.tar.gz.gpg"
final_path="$VHOD_BACKUP_DIR/$archive_name"
partial_path="$work_dir/$archive_name.partial"
payload_path="$work_dir/vhod-${timestamp}.tar.gz"

MYSQL_PWD="$VHOD_DB_PASSWORD" mariadb-dump \
    --host="$VHOD_DB_HOST" \
    --port="$VHOD_DB_PORT" \
    --user="$VHOD_DB_USER" \
    --single-transaction \
    --quick \
    --hex-blob \
    --skip-lock-tables \
    --default-character-set=utf8mb4 \
    --no-tablespaces \
    "$VHOD_DB_NAME" > "$work_dir/database.sql"

tar -C "$VHOD_PROJECT_DIR" -cf "$work_dir/documents.tar" var/storage/documents
cat > "$work_dir/manifest.txt" <<EOF
format=vhod-backup-v1
created_at_utc=${timestamp}
database=${VHOD_DB_NAME}
documents=var/storage/documents
EOF

tar -C "$work_dir" -czf "$payload_path" database.sql documents.tar manifest.txt

gpg --batch --yes --pinentry-mode loopback \
    --symmetric \
    --cipher-algo AES256 \
    --passphrase-fd 3 \
    --output "$partial_path" \
    "$payload_path" \
    3<<<"$VHOD_BACKUP_PASSPHRASE"

mv "$partial_path" "$final_path"
(
    cd "$VHOD_BACKUP_DIR"
    sha256sum "$archive_name" > "$archive_name.sha256"
)

printf 'Encrypted backup created: %s\n' "$final_path"
