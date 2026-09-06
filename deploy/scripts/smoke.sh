#!/usr/bin/env bash

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

require_command curl
require_command php

ALLOW_PRE_CUTOVER_SMOKE="${ALLOW_PRE_CUTOVER_SMOKE:-false}"
case "$ALLOW_PRE_CUTOVER_SMOKE" in
    true|false) ;;
    *)
        printf 'ALLOW_PRE_CUTOVER_SMOKE must be exactly true or false.\n' >&2
        exit 2
        ;;
esac

if [[ "$ALLOW_PRE_CUTOVER_SMOKE" == "false" ]]; then
    require_command npm
    require_command systemctl
else
    printf 'WARNING: pre-cutover smoke enabled; runtime services and online bot checks are skipped.\n' >&2
fi

BASE_URL="${SMOKE_BASE_URL:-${1:-}}"
if [[ ! "$BASE_URL" =~ ^https?:// ]]; then
    printf 'Set SMOKE_BASE_URL or pass an absolute HTTP(S) URL.\n' >&2
    exit 2
fi
BASE_URL="${BASE_URL%/}"

curl --fail --silent --show-error "${BASE_URL}/health/live" >/dev/null

if [[ "$ALLOW_PRE_CUTOVER_SMOKE" == "false" ]]; then
    READY_WAIT_SECONDS="${SMOKE_READY_WAIT_SECONDS:-90}"
    if [[ ! "$READY_WAIT_SECONDS" =~ ^[0-9]+$ || "$READY_WAIT_SECONDS" -lt 1 ]]; then
        printf 'SMOKE_READY_WAIT_SECONDS must be a positive integer.\n' >&2
        exit 2
    fi
    READY_DEADLINE=$((SECONDS + READY_WAIT_SECONDS))
    until curl --fail --silent --show-error "${BASE_URL}/health/ready" >/dev/null 2>&1; do
        if (( SECONDS >= READY_DEADLINE )); then
            printf 'Readiness did not become healthy before the smoke timeout.\n' >&2
            exit 1
        fi
        sleep 2
    done
fi

curl --fail --silent --show-error "${BASE_URL}/login" >/dev/null

UNAUTHORIZED_STATUS="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    "${BASE_URL}/api/v1/bot/configuration")"
if [[ "$UNAUTHORIZED_STATUS" != "401" ]]; then
    printf 'Unauthenticated Bot API check returned HTTP %s instead of 401.\n' "$UNAUTHORIZED_STATUS" >&2
    exit 1
fi

(
    cd "${CURRENT}/backend"
    php artisan operations:release-preflight --strict
    if [[ "$ALLOW_PRE_CUTOVER_SMOKE" == "true" ]]; then
        php artisan operations:runtime-status --skip-queue --skip-scheduler
    else
        php artisan operations:runtime-status --wait="${RUNTIME_QUEUE_WAIT_SECONDS:-20}"
    fi
)

if [[ "$ALLOW_PRE_CUTOVER_SMOKE" == "false" ]]; then
    while IFS= read -r service; do
        if ! systemctl is-active --quiet "$service"; then
            printf 'Required runtime service is not active: %s\n' "$service" >&2
            exit 1
        fi
    done < <(application_services)

    npm --prefix "${CURRENT}/bot" run release:smoke
fi

printf 'Release smoke passed: %s\n' "$BASE_URL"
