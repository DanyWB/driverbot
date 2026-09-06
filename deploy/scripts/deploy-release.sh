#!/usr/bin/env bash

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

assert_safe_backup_root
require_command composer
require_command git
require_command npm
require_command php
require_command rsync
require_command sudo
require_command systemctl

if [[ "${ALLOW_PRE_CUTOVER_SMOKE:-false}" != "false" ]]; then
    printf 'ALLOW_PRE_CUTOVER_SMOKE is not permitted during deploy cutover.\n' >&2
    exit 2
fi

SOURCE_DIR="${1:-}"
if [[ -z "$SOURCE_DIR" || ! -d "$SOURCE_DIR/backend" || ! -d "$SOURCE_DIR/bot" ]]; then
    printf 'Usage: %s /path/to/checked-out-source\n' "$0" >&2
    exit 2
fi
SOURCE_DIR="$(cd "$SOURCE_DIR" && pwd)"
SOURCE_GIT_ROOT="$(git -C "$SOURCE_DIR" rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "$SOURCE_GIT_ROOT" || "$(cd "$SOURCE_GIT_ROOT" && pwd)" != "$SOURCE_DIR" ]]; then
    printf 'Release source must be the root of a Git checkout: %s\n' "$SOURCE_DIR" >&2
    exit 1
fi
if [[ -n "$(git -C "$SOURCE_DIR" status --porcelain --untracked-files=all)" ]]; then
    printf 'Release source contains tracked or untracked changes; deploy a clean reviewed revision.\n' >&2
    exit 1
fi
SOURCE_REVISION="$(git -C "$SOURCE_DIR" rev-parse --verify HEAD)"
SOURCE_REF="$(git -C "$SOURCE_DIR" symbolic-ref --quiet --short HEAD 2>/dev/null || printf 'detached')"

BACKEND_ENV="${SHARED}/backend/.env"
BOT_ENV="${SHARED}/bot/.env"
mkdir -p "${APP_ROOT}/releases" "${SHARED}/backend" "${SHARED}/bot" "$BACKUP_ROOT"
acquire_deploy_lock

RELEASE_ID="$(date -u +'%Y%m%dT%H%M%SZ')"
RELEASE_DIR="${APP_ROOT}/releases/${RELEASE_ID}"
PREVIOUS_RELEASE="$(readlink -f "$CURRENT" 2>/dev/null || true)"
CUTOVER_STARTED=false
RELEASE_DIR_CREATED=false
DEPLOY_SUCCEEDED=false

require_file "$BACKEND_ENV"
require_file "$BOT_ENV"

cleanup_failed_deploy() {
    local exit_code=$?
    local active_release

    trap - EXIT
    if [[ "$DEPLOY_SUCCEEDED" == "true" ]]; then
        exit "$exit_code"
    fi

    set +e

    if [[ "$CUTOVER_STARTED" == "true" && -n "$PREVIOUS_RELEASE" && -d "$PREVIOUS_RELEASE" ]]; then
        printf 'Deployment failed; restoring previous application symlink: %s\n' "$PREVIOUS_RELEASE" >&2
        sudo systemctl stop drive-phangan.target >/dev/null 2>&1 || true
        atomic_symlink "$PREVIOUS_RELEASE" "$CURRENT"
        php "${CURRENT}/backend/artisan" up >/dev/null 2>&1 || true
        sudo systemctl start drive-phangan.target >/dev/null 2>&1 || true
        sudo systemctl reload "${PHP_FPM_SERVICE:-php8.3-fpm}" >/dev/null 2>&1 || true
    fi

    active_release="$(readlink -f "$CURRENT" 2>/dev/null || true)"
    if [[ "$RELEASE_DIR_CREATED" == "true" && "$active_release" != "$RELEASE_DIR" ]]; then
        printf 'Removing incomplete release: %s\n' "$RELEASE_DIR" >&2
        remove_release_if_safe "$RELEASE_DIR" || true
    elif [[ "$RELEASE_DIR_CREATED" == "true" && "$active_release" == "$RELEASE_DIR" ]]; then
        printf 'No previous release exists; leaving failed current release for incident review: %s\n' \
            "$RELEASE_DIR" >&2
    fi

    if [[ "$CUTOVER_STARTED" == "true" ]]; then
        printf 'Database migrations are forward-only. Use the recorded backup before any database rollback.\n' >&2
    fi
    exit "$exit_code"
}
trap cleanup_failed_deploy EXIT

prune_old_releases
assert_minimum_free_space "$APP_ROOT" "${DEPLOY_MIN_FREE_MB:-2048}" "release build"

mkdir "$RELEASE_DIR"
RELEASE_DIR_CREATED=true
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
    --exclude='/driverbot.sql' \
    --exclude='/bd.sql' \
    --exclude='/insert.sql' \
    --exclude='/bot/bd.sql' \
    --exclude='/bot/insert.sql' \
    --exclude='*.dump' \
    --exclude='*.backup' \
    --exclude='*.sql.gz' \
    "$SOURCE_DIR/" "$RELEASE_DIR/"
chmod 0750 "${RELEASE_DIR}/deploy/scripts/"*.sh
{
    printf 'FORMAT_VERSION=1\n'
    printf 'RELEASE_ID=%s\n' "$RELEASE_ID"
    printf 'CREATED_AT=%s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
    printf 'SOURCE_REVISION=%s\n' "$SOURCE_REVISION"
    printf 'SOURCE_REF=%s\n' "$SOURCE_REF"
    printf 'SOURCE_DIRTY=false\n'
} >"${RELEASE_DIR}/release-manifest.env"
chmod 0644 "${RELEASE_DIR}/release-manifest.env"

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
rm -rf -- "${RELEASE_DIR}/backend/node_modules"
npm --prefix "${RELEASE_DIR}/bot" ci --omit=dev

composer --working-dir="${RELEASE_DIR}/backend" audit --locked --no-dev --no-interaction
npm --prefix "${RELEASE_DIR}/backend" audit --omit=dev
npm --prefix "${RELEASE_DIR}/bot" audit --omit=dev

php "${RELEASE_DIR}/backend/artisan" storage:link
assert_minimum_free_space "$APP_ROOT" "${DEPLOY_CUTOVER_MIN_FREE_MB:-768}" "release cutover"

CUTOVER_STARTED=true
if [[ -n "$PREVIOUS_RELEASE" && -f "${PREVIOUS_RELEASE}/backend/artisan" ]]; then
    php "${PREVIOUS_RELEASE}/backend/artisan" down --retry=30
fi

sudo systemctl stop drive-phangan.target || true

BACKEND_DIR="${RELEASE_DIR}/backend" \
BACKEND_ENV_FILE="$BACKEND_ENV" \
APP_ROOT="$APP_ROOT" \
BACKUP_ROOT="$BACKUP_ROOT" \
BACKUP_OWNER="${APP_SERVICE_USER:-drive-phangan}" \
    "${RELEASE_DIR}/deploy/scripts/backup.sh"

php "${RELEASE_DIR}/backend/artisan" migrate --force
php "${RELEASE_DIR}/backend/artisan" optimize
php "${RELEASE_DIR}/backend/artisan" operations:release-preflight --strict
seal_release_read_only "$RELEASE_DIR"

atomic_symlink "$RELEASE_DIR" "$CURRENT"
php "${CURRENT}/backend/artisan" up

sudo systemctl start drive-phangan.target
sudo systemctl reload "${PHP_FPM_SERVICE:-php8.3-fpm}"

sleep "${PROCESS_WARMUP_SECONDS:-5}"
SMOKE_BASE_URL="${SMOKE_BASE_URL:-$(read_env_value APP_URL "$BACKEND_ENV")}" \
    "${CURRENT}/deploy/scripts/smoke.sh"

CUTOVER_STARTED=false
DEPLOY_SUCCEEDED=true
prune_old_releases
trap - EXIT
printf 'Release deployed: %s\n' "$RELEASE_DIR"
