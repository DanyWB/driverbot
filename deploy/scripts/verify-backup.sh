#!/usr/bin/env bash

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "${SCRIPT_DIR}/common.sh"

require_command pg_restore
require_command tar
require_command sha256sum

BACKUP_DIR="${1:-}"
if [[ -z "$BACKUP_DIR" || ! -d "$BACKUP_DIR" ]]; then
    printf 'Usage: %s /path/to/backup\n' "$0" >&2
    exit 2
fi

require_file "${BACKUP_DIR}/database.dump"
require_file "${BACKUP_DIR}/persistent-files.tar.gz"
require_file "${BACKUP_DIR}/manifest.env"
require_file "${BACKUP_DIR}/SHA256SUMS"

(
    cd "$BACKUP_DIR"
    sha256sum --check SHA256SUMS
)

pg_restore --list "${BACKUP_DIR}/database.dump" >/dev/null
tar --list --gzip --file="${BACKUP_DIR}/persistent-files.tar.gz" >/dev/null

printf 'Backup verified: %s\n' "$BACKUP_DIR"
