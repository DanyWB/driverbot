#!/usr/bin/env bash

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

assert_safe_backup_root
require_command composer
require_command npm
require_command php
require_command rsync
require_command sudo
require_command systemctl

SOURCE_DIR="${1:-}"
if [[ -z "$SOURCE_DIR" || ! -d "$SOURCE_DIR/backend" || ! -d "$SOURCE_DIR/bot" ]]; then
    printf 'Usage: %s /path/to/checked-out-source\n' "$0" >&2
    exit 2
fi
SOURCE_DIR="$(cd "$SOURCE_DIR" && pwd)"

RELEASE_ID="$(date -u +'%Y%m%dT%H%M%SZ')"
RELEASE_DIR="${APP_ROOT}/releases/${RELEASE_ID}"
PREVIOUS_RELEASE="$(readlink -f "$CURRENT" 2>/dev/null || true)"
BACKEND_ENV="${SHARED}/backend/.env"
BOT_ENV="${SHARED}/bot/.env"
CUTOVER_STARTED=false

require_file "$BACKEND_ENV"
require_file "$BOT_ENV"
mkdir -p "${APP_ROOT}/releases" "${SHARED}/backend" "${SHARED}/bot" "$BACKUP_ROOT"

rollback_on_error() {
    local exit_code=$?
    set +e

    if [[ "$CUTOVER_STARTED" == "true" && -n "$PREVIOUS_RELEASE" && -d "$PREVIOUS_RELEASE" ]]; then
        printf 'Deployment failed; restoring previous application symlink: %s\n' "$PREVIOUS_RELEASE" >&2
        sudo systemctl stop drive-phangan.target >/dev/null 2>&1 || true
        atomic_symlink "$PREVIOUS_RELEASE" "$CURRENT"
        php "${CURRENT}/backend/artisan" up >/dev/null 2>&1 || true
        sudo systemctl start drive-phangan.target >/dev/null 2>&1 || true
        sudo systemctl reload "${PHP_FPM_SERVICE:-php8.3-fpm}" >/dev/null 2>&1 || true
    fi

    printf 'Database migrations are forward-only. Use the recorded backup before any database rollback.\n' >&2
    exit "$exit_code"
}
trap rollback_on_error ERR

mkdir "$RELEASE_DIR"
rsync -a \
    --exclude='.git/' \
    --exclude='.runtime/' \
    --exclude='.tmp/' \
    --exclude='node_modules/' \
    --exclude='vendor/' \
    --exclude='.env' \
    --exclude='backend/public/build/' \
    --exclude='backend/public/storage' \
    --exclude='backend/storage/' \
    "$SOURCE_DIR/" "$RELEASE_DIR/"
chmod 0750 "${RELEASE_DIR}/deploy/scripts/"*.sh

if [[ ! -d "${SHARED}/backend/storage" ]]; then
    mkdir -p "${SHARED}/backend/storage"
    rsync -a "${SOURCE_DIR}/backend/storage/" "${SHARED}/backend/storage/"
fi

mkdir -p \
    "${SHARED}/backend/storage/app/private" \
    "${SHARED}/backend/storage/app/public" \
    "${SHARED}/backend/storage/framework/cache/data" \
    "${SHARED}/backend/storage/framework/sessions" \
    "${SHARED}/backend/storage/framework/views" \
    "${SHARED}/backend/storage/logs"

ln -s "${SHARED}/backend/storage" "${RELEASE_DIR}/backend/storage"
ln -s "$BACKEND_ENV" "${RELEASE_DIR}/backend/.env"
ln -s "$BOT_ENV" "${RELEASE_DIR}/bot/.env"

composer --working-dir="${RELEASE_DIR}/backend" install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --classmap-authoritative

npm --prefix "${RELEASE_DIR}/backend" ci
npm --prefix "${RELEASE_DIR}/backend" run build
npm --prefix "${RELEASE_DIR}/backend" prune --omit=dev
npm --prefix "${RELEASE_DIR}/bot" ci --omit=dev

php "${RELEASE_DIR}/backend/artisan" storage:link

if [[ -n "$PREVIOUS_RELEASE" && -f "${PREVIOUS_RELEASE}/backend/artisan" ]]; then
    php "${PREVIOUS_RELEASE}/backend/artisan" down --retry=30
fi

sudo systemctl stop drive-phangan.target || true
CUTOVER_STARTED=true

BACKEND_DIR="${RELEASE_DIR}/backend" \
BACKEND_ENV_FILE="$BACKEND_ENV" \
APP_ROOT="$APP_ROOT" \
BACKUP_ROOT="$BACKUP_ROOT" \
    "${RELEASE_DIR}/deploy/scripts/backup.sh"

php "${RELEASE_DIR}/backend/artisan" migrate --force
php "${RELEASE_DIR}/backend/artisan" optimize
php "${RELEASE_DIR}/backend/artisan" operations:release-preflight --strict

atomic_symlink "$RELEASE_DIR" "$CURRENT"
php "${CURRENT}/backend/artisan" up

sudo systemctl start drive-phangan.target
sudo systemctl reload "${PHP_FPM_SERVICE:-php8.3-fpm}"

sleep "${PROCESS_WARMUP_SECONDS:-5}"
SMOKE_BASE_URL="${SMOKE_BASE_URL:-$(read_env_value APP_URL "$BACKEND_ENV")}" \
    "${CURRENT}/deploy/scripts/smoke.sh"

CUTOVER_STARTED=false
trap - ERR
printf 'Release deployed: %s\n' "$RELEASE_DIR"
