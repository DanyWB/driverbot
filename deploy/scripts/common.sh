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

is_managed_backup_path() {
    local path="$1"
    local prefix="${BACKUP_ROOT}/"
    local name

    [[ "$path" == "${prefix}"* ]] || return 1
    name="${path#"$prefix"}"
    [[ "$name" != */ && "$name" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]
}

remove_backup_if_safe() {
    local backup_dir="$1"

    assert_safe_backup_root
    if ! is_managed_backup_path "$backup_dir" || [[ ! -d "$backup_dir" || -L "$backup_dir" ]]; then
        printf 'Refusing to remove unmanaged backup path: %s\n' "$backup_dir" >&2
        return 1
    fi

    rm -rf -- "$backup_dir"
}

require_positive_integer() {
    local name="$1"
    local value="$2"

    if [[ ! "$value" =~ ^[1-9][0-9]*$ ]]; then
        printf '%s must be a positive integer, got: %s\n' "$name" "$value" >&2
        exit 2
    fi
}

acquire_deploy_lock() {
    local lock_file="${DEPLOY_LOCK_FILE:-${APP_ROOT}/.deploy.lock}"

    assert_safe_app_root
    require_command flock
    mkdir -p "$APP_ROOT"

    if [[ "$lock_file" != "${APP_ROOT}/"* ]]; then
        printf 'DEPLOY_LOCK_FILE must be a child of APP_ROOT: %s\n' "$lock_file" >&2
        exit 1
    fi

    if [[ -L "$lock_file" ]]; then
        printf 'Deployment lock must not be a symlink: %s\n' "$lock_file" >&2
        exit 1
    fi

    if [[ ! -e "$lock_file" ]]; then
        (umask 0022 && : >"$lock_file")
    fi
    if [[ ! -f "$lock_file" ]]; then
        printf 'Deployment lock is not a regular file: %s\n' "$lock_file" >&2
        exit 1
    fi

    # A read-only descriptor lets deploys run after an administrator created the
    # persistent lock file, while flock still provides an exclusive process lock.
    exec 8<"$lock_file"
    if ! flock -n 8; then
        printf 'Another deploy or rollback is already running (lock: %s).\n' "$lock_file" >&2
        exit 1
    fi
}

assert_minimum_free_space() {
    local path="$1"
    local required_mb="$2"
    local label="${3:-deployment}"
    local available_kb

    require_command df
    require_positive_integer "${label} minimum free space (MB)" "$required_mb"

    available_kb="$(df -Pk "$path" | awk 'NR == 2 { print $4 }')"
    if [[ ! "$available_kb" =~ ^[0-9]+$ ]]; then
        printf 'Unable to determine free disk space for %s.\n' "$path" >&2
        exit 1
    fi

    if (( available_kb < required_mb * 1024 )); then
        printf '%s requires at least %s MB free on %s; only %s MB is available.\n' \
            "$label" "$required_mb" "$path" "$((available_kb / 1024))" >&2
        exit 1
    fi
}

is_managed_release_path() {
    local path="$1"
    local prefix="${APP_ROOT}/releases/"
    local name

    [[ "$path" == "${prefix}"* ]] || return 1
    name="${path#"$prefix"}"
    [[ "$name" != */ && "$name" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]
}

remove_release_if_safe() {
    local release_dir="$1"
    local current_release

    if ! is_managed_release_path "$release_dir" || [[ ! -d "$release_dir" || -L "$release_dir" ]]; then
        printf 'Refusing to remove unmanaged release path: %s\n' "$release_dir" >&2
        return 1
    fi

    current_release="$(readlink -f "$CURRENT" 2>/dev/null || true)"
    if [[ -n "$current_release" && "$current_release" == "$(readlink -f "$release_dir")" ]]; then
        printf 'Refusing to remove the current release: %s\n' "$release_dir" >&2
        return 1
    fi

    if [[ "$(id -u)" == "0" || -w "$release_dir" ]]; then
        rm -rf -- "$release_dir"
    else
        require_command sudo
        sudo rm -rf -- "$release_dir"
    fi
}

seal_release_read_only() {
    local release_dir="$1"

    if ! is_managed_release_path "$release_dir" || [[ ! -d "$release_dir" || -L "$release_dir" ]]; then
        printf 'Refusing to seal unmanaged release path: %s\n' "$release_dir" >&2
        exit 1
    fi

    require_command sudo
    # -P prevents traversal into shared storage/.env symlinks. Runtime users can
    # read/execute code, but only the shared symlink targets remain writable.
    sudo find -P "$release_dir" -xdev ! -type l -exec chown root:root {} +
    sudo find -P "$release_dir" -xdev -type f -exec chmod 0444 {} +
    sudo find -P "${release_dir}/deploy/scripts" -xdev -type f -name '*.sh' -exec chmod 0555 {} +
    sudo find -P "$release_dir" -xdev -type d -exec chmod 0555 {} +
}

prune_old_releases() {
    local retention_count="${RELEASE_RETENTION_COUNT:-2}"
    local current_release
    local candidate
    local kept=0
    local releases=()

    require_positive_integer "RELEASE_RETENTION_COUNT" "$retention_count"
    if (( retention_count < 2 )); then
        printf 'RELEASE_RETENTION_COUNT must be at least 2 to preserve rollback capacity.\n' >&2
        exit 2
    fi

    mkdir -p "${APP_ROOT}/releases"
    current_release="$(readlink -f "$CURRENT" 2>/dev/null || true)"
    if [[ -n "$current_release" ]] && is_managed_release_path "$current_release"; then
        kept=1
    fi

    shopt -s nullglob
    for candidate in "${APP_ROOT}/releases/"*; do
        if is_managed_release_path "$candidate" && [[ -d "$candidate" && ! -L "$candidate" ]]; then
            releases+=("$candidate")
        fi
    done
    shopt -u nullglob

    if (( ${#releases[@]} == 0 )); then
        return 0
    fi

    while IFS= read -r candidate; do
        if [[ -n "$current_release" && "$(readlink -f "$candidate")" == "$current_release" ]]; then
            continue
        fi
        if (( kept < retention_count )); then
            kept=$((kept + 1))
            continue
        fi

        printf 'Removing expired release: %s\n' "$candidate"
        remove_release_if_safe "$candidate"
    done < <(printf '%s\n' "${releases[@]}" | sort -r)
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
