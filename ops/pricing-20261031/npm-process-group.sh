#!/usr/bin/env bash
# No command starts here. Caller owns installer_pid from its direct setsid child.
price_group_running() {
    [[ -n "${installer_pid:-}" ]] || return 1
    ps -eo pgid=,sid=,stat= | awk -v group="$installer_pid" '$1==group && $3!~/^Z/ {found=1; if($2!=group)bad=1} END{exit !(found && !bad)}'
}
terminate_installer() {
    [[ -n "${installer_pid:-}" ]] || return 0
    if price_group_running; then
        kill -TERM -- "-$installer_pid" 2>/dev/null || true
        sleep 2
        if price_group_running; then kill -KILL -- "-$installer_pid" 2>/dev/null || true; fi
    elif [[ "$(ps -o ppid= -p "$installer_pid" | tr -d ' ')" == "$BASHPID" ]]; then
        # Only our still-direct child may be signalled before setsid has execed.
        kill -TERM "$installer_pid" 2>/dev/null || true
    fi
    wait "$installer_pid" 2>/dev/null || true
}
