#!/usr/bin/env bash

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

assert_safe_backup_root
require_command pg_dump
require_command tar
require_command sha256sum

BACKEND_DIR="${BACKEND_DIR:-${CURRENT}/backend}"
ENV_FILE="${BACKEND_ENV_FILE:-${BACKEND_DIR}/.env}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_ID="$(date -u +'%Y%m%dT%H%M%SZ')"
STAGING_DIR="${BACKUP_ROOT}/.${BACKUP_ID}.tmp"
FINAL_DIR="${BACKUP_ROOT}/${BACKUP_ID}"

require_file "$ENV_FILE"
mkdir -p "$BACKUP_ROOT"
chmod 0700 "$BACKUP_ROOT"

LOCK_DIRECTORY_CREATED=false
if command -v flock >/dev/null 2>&1; then
    exec 9>"${BACKUP_ROOT}/.backup.lock"
    if ! flock -n 9; then
        printf 'Another backup is already running.\n' >&2
        exit 1
    fi
else
    if ! mkdir "${BACKUP_ROOT}/.backup.lock.d" 2>/dev/null; then
        printf 'Another backup is already running.\n' >&2
        exit 1
    fi
    LOCK_DIRECTORY_CREATED=true
fi

cleanup() {
    rm -rf -- "$STAGING_DIR"
    if [[ "$LOCK_DIRECTORY_CREATED" == "true" ]]; then
        rmdir "${BACKUP_ROOT}/.backup.lock.d" 2>/dev/null || true
    fi
}
trap cleanup ERR INT TERM

DB_HOST="$(require_env_value DB_HOST "$ENV_FILE")"
DB_PORT="$(require_env_value DB_PORT "$ENV_FILE")"
DB_DATABASE="$(require_env_value DB_DATABASE "$ENV_FILE")"
DB_USERNAME="$(require_env_value DB_USERNAME "$ENV_FILE")"
DB_PASSWORD="$(require_env_value DB_PASSWORD "$ENV_FILE")"

mkdir -p "$STAGING_DIR"
chmod 0700 "$STAGING_DIR"

PGPASSWORD="$DB_PASSWORD" pg_dump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --username="$DB_USERNAME" \
    --dbname="$DB_DATABASE" \
    --format=custom \
    --compress=9 \
    --no-owner \
    --no-privileges \
    --file="${STAGING_DIR}/database.dump"

tar \
    --create \
    --gzip \
    --file="${STAGING_DIR}/persistent-files.tar.gz" \
    --directory="${BACKEND_DIR}/storage/app" \
    public private

cat >"${STAGING_DIR}/manifest.env" <<EOF
BACKUP_FORMAT_VERSION=1
CREATED_AT=${BACKUP_ID}
DATABASE_NAME=${DB_DATABASE}
RELEASE_PATH=$(readlink -f "$CURRENT" 2>/dev/null || printf 'unknown')
EOF

(
    cd "$STAGING_DIR"
    sha256sum database.dump persistent-files.tar.gz manifest.env >SHA256SUMS
)

chmod 0600 "$STAGING_DIR"/*
mv "$STAGING_DIR" "$FINAL_DIR"
trap - ERR INT TERM
if [[ "$LOCK_DIRECTORY_CREATED" == "true" ]]; then
    rmdir "${BACKUP_ROOT}/.backup.lock.d"
fi

if [[ "${ENABLE_BACKUP_PRUNE:-false}" == "true" ]]; then
    while IFS= read -r -d '' old_backup; do
        [[ "$old_backup" == "${BACKUP_ROOT}/"????????T??????Z ]] || continue
        rm -rf -- "$old_backup"
    done < <(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -name '????????T??????Z' -mtime "+${RETENTION_DAYS}" -print0)
fi

printf '%s\n' "$FINAL_DIR"
