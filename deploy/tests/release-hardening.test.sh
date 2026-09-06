#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
COMMON_SH="${PROJECT_ROOT}/deploy/scripts/common.sh"
TEST_ROOT="$(mktemp -d)"

cleanup() {
    rm -rf -- "$TEST_ROOT"
}
trap cleanup EXIT

APP_ROOT="${TEST_ROOT}/app"
CURRENT="${APP_ROOT}/current"
SHARED="${APP_ROOT}/shared"
BACKUP_ROOT="${APP_ROOT}/backups"
# shellcheck source=../scripts/common.sh
source "$COMMON_SH"

mkdir -p "${APP_ROOT}/releases" "$SHARED" "$BACKUP_ROOT"
for release_id in \
    20260801T000000Z \
    20260802T000000Z \
    20260803T000000Z \
    20260804T000000Z; do
    mkdir "${APP_ROOT}/releases/${release_id}"
done
ln -s "${APP_ROOT}/releases/20260804T000000Z" "$CURRENT"
if [[ ! -L "$CURRENT" ]]; then
    # Git Bash without native symlink support copies the directory. Keep the
    # safety scenario runnable there; Linux CI exercises the real symlink path.
    rm -rf -- "$CURRENT"
    CURRENT="${APP_ROOT}/releases/20260804T000000Z"
fi

RELEASE_RETENTION_COUNT=2 prune_old_releases
[[ -d "${APP_ROOT}/releases/20260804T000000Z" ]]
[[ -d "${APP_ROOT}/releases/20260803T000000Z" ]]
[[ ! -e "${APP_ROOT}/releases/20260802T000000Z" ]]
[[ ! -e "${APP_ROOT}/releases/20260801T000000Z" ]]

if remove_release_if_safe "${APP_ROOT}/releases/20260804T000000Z" >/dev/null 2>&1; then
    printf 'Current release deletion was not rejected.\n' >&2
    exit 1
fi

assert_minimum_free_space "$APP_ROOT" 1 "test release"
AVAILABLE_KB="$(df -Pk "$APP_ROOT" | awk 'NR == 2 { print $4 }')"
UNAVAILABLE_MB="$((AVAILABLE_KB / 1024 + 1))"
if (assert_minimum_free_space "$APP_ROOT" "$UNAVAILABLE_MB" "oversized test release") \
    >/dev/null 2>&1; then
    printf 'Insufficient disk space was not rejected.\n' >&2
    exit 1
fi

mkdir "${TEST_ROOT}/unmanaged-release"
if (seal_release_read_only "${TEST_ROOT}/unmanaged-release") >/dev/null 2>&1; then
    printf 'Unmanaged release sealing was not rejected.\n' >&2
    exit 1
fi

mkdir "${BACKUP_ROOT}/20260701T000000Z"
remove_backup_if_safe "${BACKUP_ROOT}/20260701T000000Z"
[[ ! -e "${BACKUP_ROOT}/20260701T000000Z" ]]
if remove_backup_if_safe "${BACKUP_ROOT}/not-a-managed-backup" >/dev/null 2>&1; then
    printf 'Unmanaged backup deletion was not rejected.\n' >&2
    exit 1
fi

if command -v flock >/dev/null 2>&1; then
    acquire_deploy_lock
    if APP_ROOT="$APP_ROOT" CURRENT="$CURRENT" SHARED="$SHARED" BACKUP_ROOT="$BACKUP_ROOT" \
        bash -c 'source "$1"; acquire_deploy_lock' _ "$COMMON_SH" >/dev/null 2>&1; then
        printf 'Concurrent deployment lock acquisition was not rejected.\n' >&2
        exit 1
    fi
else
    printf 'Skipping lock contention scenario: flock is unavailable.\n' >&2
fi

printf 'Release hardening shell tests passed.\n'
