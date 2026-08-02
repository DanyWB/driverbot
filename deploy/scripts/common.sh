#!/usr/bin/env bash

set -Eeuo pipefail

APP_ROOT="${APP_ROOT:-/srv/drive-phangan}"
CURRENT="${CURRENT:-${APP_ROOT}/current}"
SHARED="${SHARED:-${APP_ROOT}/shared}"
BACKUP_ROOT="${BACKUP_ROOT:-${APP_ROOT}/backups}"

require_command() {
    local command_name="$1"

    if ! command -v "$command_name" >/dev/null 2>&1; then
        printf 'Required command is missing: %s\n' "$command_name" >&2
        exit 1
    fi
}

require_file() {
    local path="$1"

    if [[ ! -f "$path" ]]; then
        printf 'Required file is missing: %s\n' "$path" >&2
        exit 1
    fi
}

read_env_value() {
    local key="$1"
    local file="$2"
    local value

    value="$(awk -v key="$key" '
        $0 !~ /^[[:space:]]*#/ && index($0, key "=") == 1 {
            sub(/^[^=]*=/, "");
            sub(/\r$/, "");
            print;
            exit;
        }
    ' "$file")"

    if [[ "$value" == \"*\" && "$value" == *\" ]]; then
        value="${value:1:${#value}-2}"
    elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
        value="${value:1:${#value}-2}"
    fi

    printf '%s' "$value"
}

require_env_value() {
    local key="$1"
    local file="$2"
    local value

    value="$(read_env_value "$key" "$file")"
    if [[ -z "$value" || "$value" == CHANGE_ME* ]]; then
        printf 'Required environment value is missing: %s in %s\n' "$key" "$file" >&2
        exit 1
    fi

    printf '%s' "$value"
}

assert_safe_app_root() {
    if [[ "$APP_ROOT" != /* || "$APP_ROOT" == "/" || ${#APP_ROOT} -lt 8 ]]; then
        printf 'Unsafe APP_ROOT: %s\n' "$APP_ROOT" >&2
        exit 1
    fi
}

assert_safe_backup_root() {
    assert_safe_app_root

    if [[ "$BACKUP_ROOT" != "${APP_ROOT}/"* || "$BACKUP_ROOT" == "$APP_ROOT" ]]; then
        printf 'BACKUP_ROOT must be a child of APP_ROOT: %s\n' "$BACKUP_ROOT" >&2
        exit 1
    fi
}

atomic_symlink() {
    local target="$1"
    local link="$2"
    local temporary="${link}.new.$$"

    ln -s "$target" "$temporary"
    mv -Tf "$temporary" "$link"
}

application_services() {
    printf '%s\n' \
        drive-phangan-worker-default.service \
        drive-phangan-worker-notifications.service \
        drive-phangan-scheduler.service \
        drive-phangan-bot.service
}
