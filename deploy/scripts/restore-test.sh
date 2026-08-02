#!/usr/bin/env bash

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

assert_safe_app_root
require_command createdb
require_command dropdb
require_command pg_restore
require_command psql
require_command tar

if [[ "${ALLOW_RESTORE_TEST:-false}" != "true" ]]; then
    printf 'Set ALLOW_RESTORE_TEST=true to run a destructive restore against a disposable database.\n' >&2
    exit 1
fi

BACKUP_DIR="${1:-}"
if [[ -z "$BACKUP_DIR" || ! -d "$BACKUP_DIR" ]]; then
    printf 'Usage: ALLOW_RESTORE_TEST=true %s /path/to/backup\n' "$0" >&2
    exit 2
fi

"${SCRIPT_DIR}/verify-backup.sh" "$BACKUP_DIR"

ENV_FILE="${BACKEND_ENV_FILE:-${CURRENT}/backend/.env}"
require_file "$ENV_FILE"

SOURCE_DATABASE="$(require_env_value DB_DATABASE "$ENV_FILE")"
APP_DATABASE_USER="$(require_env_value DB_USERNAME "$ENV_FILE")"
RESTORE_DB_HOST="${RESTORE_DB_HOST:-127.0.0.1}"
RESTORE_DB_PORT="${RESTORE_DB_PORT:-5432}"
RESTORE_ADMIN_USER="${RESTORE_ADMIN_USER:-postgres}"
RESTORE_ADMIN_PASSWORD="${RESTORE_ADMIN_PASSWORD:-}"
RESTORE_TEST_DATABASE="${RESTORE_TEST_DATABASE:-drive_phangan_restore_test}"

if [[ ! "$RESTORE_TEST_DATABASE" =~ ^[a-z][a-z0-9_]*_restore_test$ ]]; then
    printf 'RESTORE_TEST_DATABASE must end with _restore_test and contain only lowercase SQL identifier characters.\n' >&2
    exit 1
fi

if [[ "$RESTORE_TEST_DATABASE" == "$SOURCE_DATABASE" ]]; then
    printf 'Restore target must not match the application database.\n' >&2
    exit 1
fi

RESTORED_FILES_DIR="$(mktemp -d)"
cleanup() {
    rm -rf -- "$RESTORED_FILES_DIR"

    if [[ "${KEEP_RESTORE_TEST:-false}" != "true" ]]; then
        PGPASSWORD="$RESTORE_ADMIN_PASSWORD" dropdb \
            --if-exists \
            --host="$RESTORE_DB_HOST" \
            --port="$RESTORE_DB_PORT" \
            --username="$RESTORE_ADMIN_USER" \
            "$RESTORE_TEST_DATABASE" >/dev/null
    fi
}
trap cleanup EXIT

PGPASSWORD="$RESTORE_ADMIN_PASSWORD" dropdb \
    --if-exists \
    --host="$RESTORE_DB_HOST" \
    --port="$RESTORE_DB_PORT" \
    --username="$RESTORE_ADMIN_USER" \
    "$RESTORE_TEST_DATABASE"

PGPASSWORD="$RESTORE_ADMIN_PASSWORD" createdb \
    --host="$RESTORE_DB_HOST" \
    --port="$RESTORE_DB_PORT" \
    --username="$RESTORE_ADMIN_USER" \
    --owner="$APP_DATABASE_USER" \
    "$RESTORE_TEST_DATABASE"

PGPASSWORD="$RESTORE_ADMIN_PASSWORD" pg_restore \
    --host="$RESTORE_DB_HOST" \
    --port="$RESTORE_DB_PORT" \
    --username="$RESTORE_ADMIN_USER" \
    --dbname="$RESTORE_TEST_DATABASE" \
    --role="$APP_DATABASE_USER" \
    --no-owner \
    --no-privileges \
    --exit-on-error \
    --single-transaction \
    "${BACKUP_DIR}/database.dump"

TABLE_COUNT="$(PGPASSWORD="$RESTORE_ADMIN_PASSWORD" psql \
    --host="$RESTORE_DB_HOST" \
    --port="$RESTORE_DB_PORT" \
    --username="$RESTORE_ADMIN_USER" \
    --dbname="$RESTORE_TEST_DATABASE" \
    --tuples-only \
    --no-align \
    --command="select count(*) from information_schema.tables where table_schema = 'public';")"

tar --extract --gzip --file="${BACKUP_DIR}/persistent-files.tar.gz" --directory="$RESTORED_FILES_DIR"
test -d "${RESTORED_FILES_DIR}/public"
test -d "${RESTORED_FILES_DIR}/private"

printf 'Restore test passed: database=%s, public tables=%s\n' "$RESTORE_TEST_DATABASE" "$TABLE_COUNT"
