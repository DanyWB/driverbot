#!/usr/bin/env bash
# Candidate-only npm install. Production remains online; no backup/service action.
set -Eeuo pipefail
set +m
umask 0077
fail() { printf 'BOT CANDIDATE REFUSED: %s\n' "$1" >&2; exit 1; }
[[ "$EUID" -eq 0 && "$#" -eq 3 ]] || fail 'Expected root and three reviewed paths.'
bot="$1"; operation="$2"; source="$3"
[[ "$bot" =~ ^/srv/drive-phangan/releases/([0-9]{8}T[0-9]{6}Z)/bot$ ]] || fail 'Invalid candidate path.'
id="${BASH_REMATCH[1]}"
[[ "$operation" == "/srv/drive-phangan/price-release-20261031-operation-$id" && "$source" == /srv/drive-phangan/source-20261018 ]] || fail 'Operation/source boundary changed.'
[[ "$(realpath -e "$bot")" == "$bot" && "$(realpath -e "$operation")" == "$operation" && "$(realpath -e "$source")" == "$source" ]] || fail 'Path is linked/missing.'
[[ "$(readlink -f /srv/drive-phangan/current)" == /srv/drive-phangan/releases/20261029T181820Z && "$id" != 20261029T181820Z ]] || fail 'Only a new inactive candidate may be modified.'
for command in setsid setpriv timeout du ps; do command -v "$command" >/dev/null || fail 'Required standard Linux utility unavailable.'; done
/usr/bin/node -e 'if(process.platform!=="linux"||process.arch!=="x64"||!process.version.startsWith("v22.")||process.versions.modules!=="127")process.exit(1);console.log(JSON.stringify({platform:process.platform,arch:process.arch,node:process.version,abi:process.versions.modules}));' >"$operation/node-runtime.json"
[[ ! -e "$bot/.npmrc" && ! -e "$operation/inherited-bot-node_modules" && ! -e "$operation/npm-cache" && ! -e "$operation/npm-home" ]] || fail 'Unexpected config/reused preparation targets.'
[[ "$(realpath -e "$bot/node_modules")" == "$bot/node_modules" && ! -L "$bot/node_modules" ]] || fail 'Inherited modules are linked.'
for name in package.json package-lock.json; do
    install -o root -g root -m 0444 "$source/bot/$name" "$bot/$name"
done
before="$(sha256sum "$bot/package.json" "$bot/package-lock.json")"
mv -- "$bot/node_modules" "$operation/inherited-bot-node_modules"
install -d -o drive-phangan -g drive-phangan -m 0700 "$bot/node_modules" "$operation/npm-cache" "$operation/npm-home"
# npm rejects loading the SAME /dev/null path as both user and global config.
install -o root -g root -m 0444 /dev/null "$operation/npm-home/user.npmrc"
install -o root -g root -m 0444 /dev/null "$operation/npm-home/global.npmrc"
installer_pid=''
source "$source/ops/pricing-20261031/npm-process-group.sh"
trap terminate_installer EXIT
trap 'exit 130' INT TERM
check_budget() {
    [[ "$(realpath -e "$operation/npm-cache")" == "$operation/npm-cache" && "$(realpath -e "$bot/node_modules")" == "$bot/node_modules" ]] || fail 'Preparation directory was relinked.'
    cache_bytes="$(measure_tree "$operation/npm-cache")"
    module_bytes="$(measure_tree "$bot/node_modules")"
    available="$(df -B1 --output=avail /srv/drive-phangan | tail -n 1 | tr -d ' ')"
    [[ "$cache_bytes" -le 134217728 && "$module_bytes" -le 335544320 && "$available" -ge 1241513984 ]] || fail '128MiB cache/320MiB modules/768+384+32MiB reserved-space gate failed.'
}
measure_tree() {
    # npm atomically renames temporary cache entries; retry ONLY a failed read.
    local result attempt
    for attempt in 1 2 3; do
        if result="$(du -sB1 "$1" 2>/dev/null)"; then printf '%s\n' "$result" | awk '{print $1}'; return 0; fi
    done
    fail 'Cannot measure private preparation tree reliably.'
}
run_npm() {
    local log="$1"; shift
    local deadline=$((SECONDS+315))
    # setpriv execs directly (no sudo monitor/PTY). setsid + foreground timeout
    # keep exactly this child and its npm descendants in their own PID-named group.
    setsid setpriv --reuid=drive-phangan --regid=drive-phangan --init-groups --no-new-privs \
        env -i PATH=/usr/bin:/bin HOME="$operation/npm-home" NODE_ENV=production \
        timeout --foreground --signal=TERM --kill-after=15s 300s /usr/bin/npm \
        --prefix "$bot" --cache "$operation/npm-cache" --userconfig="$operation/npm-home/user.npmrc" --globalconfig="$operation/npm-home/global.npmrc" \
        --registry=https://registry.npmjs.org --fund=false --update-notifier=false --fetch-retries=0 --fetch-timeout=30000 --progress=false "$@" >"$log" 2>&1 &
    installer_pid="$!"
    while kill -0 "$installer_pid" 2>/dev/null; do
        [[ "$SECONDS" -lt "$deadline" ]] || fail 'Independent installer deadline exceeded.'
        check_budget
        sleep 1
    done
    if ! wait "$installer_pid"; then fail 'Bounded npm command failed; retain evidence, do not retry or back up.'; fi
    if price_group_running; then
        terminate_installer
        fail 'Unexpected npm descendant remained after its leader; owned group terminated.'
    fi
    installer_pid=''
    check_budget
}
check_budget
run_npm "$operation/npm-ci.log" ci --omit=dev --ignore-scripts --no-audit
run_npm "$operation/npm-audit.log" audit --omit=dev
run_npm "$operation/npm-graph.json" ls --omit=dev --all --json
[[ "$before" == "$(sha256sum "$bot/package.json" "$bot/package-lock.json")" ]] || fail 'npm modified reviewed package manifests.'
/usr/bin/node -e 'const f=require("fs");const p=process.argv[1];if(JSON.parse(f.readFileSync(p+"/node_modules/googleapis-common/node_modules/gaxios/package.json")).version!=="7.1.4")process.exit(1);' "$bot"
# Seal ONLY newly installed dependencies, never the old release or shared targets.
find -P "$bot/node_modules" -xdev -type l -exec chown -h root:root {} +
find -P "$bot/node_modules" -xdev ! -type l -exec chown root:root {} +
find -P "$bot/node_modules" -xdev -type f -exec chmod 0444 {} +
find -P "$bot/node_modules" -xdev -type d -exec chmod 0555 {} +
/usr/bin/php "$source/ops/pricing-20261031/runtime-tree.php" "$bot" >"$operation/bot-runtime-tree.json"
printf 'BOT_RUNTIME_PREPARED cache_allocated=%s modules_allocated=%s available=%s\n' "$cache_bytes" "$module_bytes" "$available"
