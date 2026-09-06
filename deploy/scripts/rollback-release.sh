#!/usr/bin/env bash

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

assert_safe_app_root
require_command php
require_command sudo
require_command systemctl

if [[ "${ALLOW_PRE_CUTOVER_SMOKE:-false}" != "false" ]]; then
    printf 'ALLOW_PRE_CUTOVER_SMOKE is not permitted during rollback cutover.\n' >&2
    exit 2
fi
acquire_deploy_lock

TARGET_RELEASE="${1:-}"
if [[ -z "$TARGET_RELEASE" ]]; then
    printf 'Usage: %s /srv/drive-phangan/releases/<timestamp>\n' "$0" >&2
    exit 2
fi

TARGET_RELEASE="$(readlink -f "$TARGET_RELEASE" 2>/dev/null || true)"
case "$TARGET_RELEASE" in
    "${APP_ROOT}/releases/"*) ;;
    *)
        printf 'Rollback target must be an existing release under %s/releases.\n' "$APP_ROOT" >&2
        exit 1
        ;;
esac

require_file "${TARGET_RELEASE}/backend/artisan"
require_file "${TARGET_RELEASE}/deploy/scripts/smoke.sh"

ORIGINAL_RELEASE="$(readlink -f "$CURRENT" 2>/dev/null || true)"
if [[ -z "$ORIGINAL_RELEASE" || ! -d "$ORIGINAL_RELEASE" ]]; then
    printf 'Cannot roll back safely because current does not resolve to an existing release.\n' >&2
    exit 1
fi
require_file "${ORIGINAL_RELEASE}/backend/artisan"

ROLLBACK_STARTED=false
ROLLBACK_SUCCEEDED=false

restore_original_on_error() {
    local exit_code=$?

    trap - EXIT
    if [[ "$ROLLBACK_SUCCEEDED" == "true" ]]; then
        exit "$exit_code"
    fi

    set +e
    if [[ "$ROLLBACK_STARTED" == "true" ]]; then
        printf 'Rollback smoke failed; restoring original application symlink: %s\n' \
            "$ORIGINAL_RELEASE" >&2
        sudo systemctl stop drive-phangan.target >/dev/null 2>&1 || true
        atomic_symlink "$ORIGINAL_RELEASE" "$CURRENT"
        php "${CURRENT}/backend/artisan" up >/dev/null 2>&1 || true
        sudo systemctl start drive-phangan.target >/dev/null 2>&1 || true
        sudo systemctl reload "${PHP_FPM_SERVICE:-php8.3-fpm}" >/dev/null 2>&1 || true
    fi

    exit "$exit_code"
}
trap restore_original_on_error EXIT

ROLLBACK_STARTED=true
php "${CURRENT}/backend/artisan" down --retry=30 || true
sudo systemctl stop drive-phangan.target || true
atomic_symlink "$TARGET_RELEASE" "$CURRENT"
php "${CURRENT}/backend/artisan" up
sudo systemctl start drive-phangan.target
sudo systemctl reload "${PHP_FPM_SERVICE:-php8.3-fpm}"

sleep "${PROCESS_WARMUP_SECONDS:-5}"
SMOKE_BASE_URL="${SMOKE_BASE_URL:-$(read_env_value APP_URL "${CURRENT}/backend/.env")}" \
    "${CURRENT}/deploy/scripts/smoke.sh"

ROLLBACK_SUCCEEDED=true
trap - EXIT
printf 'Application code rolled back to: %s\n' "$TARGET_RELEASE"
printf 'No database rollback was attempted. Restore a matching backup only after explicit incident review.\n'
