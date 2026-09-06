#!/usr/bin/env bash

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

assert_safe_backup_root
require_command find
require_command pg_dump
require_command tar
require_command sha256sum

BACKEND_DIR="${BACKEND_DIR:-${CURRENT}/backend}"
ENV_FILE="${BACKEND_ENV_FILE:-${BACKEND_DIR}/.env}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_OWNER="${BACKUP_OWNER:-drive-phangan}"
BACKUP_ID="$(date -u +'%Y%m%dT%H%M%SZ')"
STAGING_DIR="${BACKUP_ROOT}/.${BACKUP_ID}.tmp"
FINAL_DIR="${BACKUP_ROOT}/${BACKUP_ID}"

require_file "$ENV_FILE"
require_positive_integer "BACKUP_RETENTION_DAYS" "$RETENTION_DAYS"
if [[ -L "$BACKUP_ROOT" ]]; then
    printf 'BACKUP_ROOT must not be a symlink: %s\n' "$BACKUP_ROOT" >&2
    exit 1
fi

if [[ "$(id -u)" == "0" ]]; then
    require_command install
    if ! id -u "$BACKUP_OWNER" >/dev/null 2>&1; then
        printf 'Backup owner does not exist: %s\n' "$BACKUP_OWNER" >&2
        exit 1
    fi
    BACKUP_GROUP="$(id -gn "$BACKUP_OWNER")"
    install -d -m 0700 -o "$BACKUP_OWNER" -g "$BACKUP_GROUP" "$BACKUP_ROOT"
else
    mkdir -p "$BACKUP_ROOT"
    chmod 0700 "$BACKUP_ROOT"
fi

LOCK_DIRECTORY_CREATED=false
if command -v flock >/dev/null 2>&1; then
    exec 9>"${BACKUP_ROOT}/.backup.lock"
    if [[ "$(id -u)" == "0" ]]; then
        chown "${BACKUP_OWNER}:${BACKUP_GROUP}" "${BACKUP_ROOT}/.backup.lock"
        chmod 0600 "${BACKUP_ROOT}/.backup.lock"
    fi
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
trap cleanup EXIT INT TERM

if [[ "$(id -u)" == "0" ]]; then
    FIRST_BACKUP_SYMLINK="$(find "$BACKUP_ROOT" -xdev -type l -print -quit)"
    if [[ -n "$FIRST_BACKUP_SYMLINK" ]]; then
        printf 'Refusing to normalize backup tree containing a symlink: %s\n' \
            "$FIRST_BACKUP_SYMLINK" >&2
        cleanup
        exit 1
    fi
    chown -R "${BACKUP_OWNER}:${BACKUP_GROUP}" "$BACKUP_ROOT"
    find "$BACKUP_ROOT" -xdev -type d -exec chmod 0700 {} +
    find "$BACKUP_ROOT" -xdev -type f -exec chmod 0600 {} +
fi

case "${ENABLE_BACKUP_PRUNE:-false}" in
    true|false) ;;
    *)
        printf 'ENABLE_BACKUP_PRUNE must be exactly true or false.\n' >&2
        exit 2
        ;;
esac

prune_expired_backups() {
    local old_backup

    if [[ "${ENABLE_BACKUP_PRUNE:-false}" != "true" ]]; then
        return 0
    fi

    while IFS= read -r -d '' old_backup; do
        printf 'Removing expired backup: %s\n' "$old_backup"
        remove_backup_if_safe "$old_backup"
    done < <(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d \
        -name '????????T??????Z' -mtime "+${RETENTION_DAYS}" -print0)
}

DB_HOST="$(require_env_value DB_HOST "$ENV_FILE")"
DB_PORT="$(require_env_value DB_PORT "$ENV_FILE")"
DB_DATABASE="$(require_env_value DB_DATABASE "$ENV_FILE")"
DB_USERNAME="$(require_env_value DB_USERNAME "$ENV_FILE")"
DB_PASSWORD="$(require_env_value DB_PASSWORD "$ENV_FILE")"

# Prune under the backup lock before allocating a new dump, so an otherwise
# healthy backup can recover from a disk filled by already-expired generations.
prune_expired_backups

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
if [[ "$(id -u)" == "0" ]]; then
    chown -R "${BACKUP_OWNER}:${BACKUP_GROUP}" "$FINAL_DIR"
fi
if [[ "$LOCK_DIRECTORY_CREATED" == "true" ]]; then
    rmdir "${BACKUP_ROOT}/.backup.lock.d"
    LOCK_DIRECTORY_CREATED=false
fi

prune_expired_backups
trap - EXIT INT TERM

printf '%s\n' "$FINAL_DIR"
