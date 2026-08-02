#!/usr/bin/env bash

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

assert_safe_app_root
require_command php
require_command sudo
require_command systemctl

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

php "${CURRENT}/backend/artisan" down --retry=30 || true
sudo systemctl stop drive-phangan.target || true
atomic_symlink "$TARGET_RELEASE" "$CURRENT"
php "${CURRENT}/backend/artisan" up
sudo systemctl start drive-phangan.target
sudo systemctl reload "${PHP_FPM_SERVICE:-php8.3-fpm}"

sleep "${PROCESS_WARMUP_SECONDS:-5}"
SMOKE_BASE_URL="${SMOKE_BASE_URL:-$(read_env_value APP_URL "${CURRENT}/backend/.env")}" \
    "${CURRENT}/deploy/scripts/smoke.sh"

printf 'Application code rolled back to: %s\n' "$TARGET_RELEASE"
printf 'No database rollback was attempted. Restore a matching backup only after explicit incident review.\n'
