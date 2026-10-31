#!/usr/bin/env bash
# NEW release only. Exact-SHA CI and root execution GO required before invocation.
# Never retry after an import-started checkpoint or create a second backup blindly.
set -Eeuo pipefail
umask 0077
fail() { printf 'PRICE RELEASE REFUSED: %s\n' "$1" >&2; exit 1; }
[[ "$EUID" -eq 0 && "$#" -eq 2 && "$1" =~ ^[a-f0-9]{40}$ && "$2" =~ ^[1-9][0-9]*$ ]] || fail 'Expected root, reviewed exact commit and explicit existing audit-admin ID.'
revision="$1"; admin_id="$2"
root=/srv/drive-phangan
source="$root/source-20261018"
previous="$root/releases/20261029T181820Z"
helpers="$source/ops/pricing-20261031"
incoming=/root/price-release-20261031-inputs
[[ "$(realpath -e "$source")" == "$source" && "$(realpath -e "$helpers")" == "$helpers" ]] || fail 'Reviewed source boundary changed.'
[[ "$(git -C "$source" rev-parse HEAD)" == "$revision" && -z "$(git -C "$source" status --porcelain --untracked-files=all)" ]] || fail 'Source is not exact clean reviewed commit.'
source_ref="$(git -C "$source" symbolic-ref --quiet --short HEAD || printf detached)"
git -C "$source" merge-base --is-ancestor 6792e28929cfe20b1cf3c9678929e27bfaf61e80 "$revision" || fail 'Wrong clean-history parent.'
while IFS= read -r changed; do
    case "$changed" in
        backend/app/Domain/Pricing/Services/PricingSettings.php|backend/tests/Feature/PricingCalculatorToolsTest.php|backend/tests/Feature/ReviewedPriceImportTest.php|backend/tests/Feature/PostgresReviewedPriceImportTest.php|backend/tests/Unit/PriceReleaseEvidenceTest.php|docs/PRICES_RELEASE_2026_10_31.md|ops/pricing-20261031/*) ;;
        *) fail 'Unrelated change in proposed pricing release.' ;;
    esac
done < <(git -C "$source" diff --name-only 6792e28929cfe20b1cf3c9678929e27bfaf61e80 "$revision")
[[ "$(readlink -f "$root/current")" == "$previous" && ! -L "$previous" ]] || fail 'Production current changed.'
grep -Fxq SOURCE_REVISION=6792e28929cfe20b1cf3c9678929e27bfaf61e80 "$previous/release-manifest.env" || fail 'Previous source identity changed.'
[[ "$(realpath -e "$incoming")" == "$incoming" && ! -L "$incoming" && "$(stat -c %u "$incoming")" == 0 && "$(stat -c %a "$incoming")" == 700 ]] || fail 'Private input transfer directory must be real root0700.'
for file in pricing-source.json mapping.json catalog-snapshot.json; do
    [[ "$(realpath -e "$incoming/$file")" == "$incoming/$file" && ! -L "$incoming/$file" && "$(stat -c %u "$incoming/$file")" == 0 && "$(stat -c %a "$incoming/$file")" == 600 ]] || fail 'Private input transfer file boundary changed.'
done
[[ "$(sha256sum "$incoming/pricing-source.json" | awk '{print $1}')" == 304a31270256c2d36e08b3c90128dc91bca31b215ea4750df2bc9b0b4707bfc2 ]] || fail 'Original price source hash mismatch.'
[[ "$(sha256sum "$incoming/mapping.json" | awk '{print $1}')" == 02791d6c6b9422079eade97db90ff3c32f7963594e4b18180fcc48b48c0291b3 ]] || fail 'Exact reviewed mapping hash mismatch.'
[[ "$(sha256sum "$incoming/catalog-snapshot.json" | awk '{print $1}')" == cc6a931d8d24e7e667bbde7d948dd9748a6a86bb75b2087cb9b0a9e671922304 ]] || fail 'Public identity reference hash mismatch.'
[[ "$(systemctl is-enabled drive-phangan-backup.timer || true)" == disabled && "$(systemctl is-active drive-phangan-backup.timer || true)" == inactive && "$(systemctl is-active drive-phangan-backup.service || true)" == inactive ]] || fail 'Backup automation changed.'
[[ "$(realpath -e "$root/backups")" == "$root/backups" && ! -L "$root/backups" && "$(stat -c %U "$root/backups")" == drive-phangan && "$(stat -c %a "$root/backups")" == 700 ]] || fail 'Existing protected backup root changed.'
[[ "$(realpath -e "$root/backups/.backup.lock")" == "$root/backups/.backup.lock" && ! -L "$root/backups/.backup.lock" && "$(stat -c %U "$root/backups/.backup.lock")" == drive-phangan && "$(stat -c %a "$root/backups/.backup.lock")" == 600 ]] || fail 'Existing protected backup lock changed.'
[[ "$(realpath -e "$root/.deploy.lock")" == "$root/.deploy.lock" && ! -L "$root/.deploy.lock" && -f "$root/.deploy.lock" ]] || fail 'Existing deployment lock is linked/missing/not regular.'
exec 9<"$root/.deploy.lock"
flock -x -n 9 || fail 'Exclusive production deployment lock unavailable.'
[[ "$(git -C "$source" rev-parse HEAD)" == "$revision" && -z "$(git -C "$source" status --porcelain --untracked-files=all)" && "$(readlink -f "$root/current")" == "$previous" ]] || fail 'Reviewed source/current changed before exclusive lock.'
[[ "$(systemctl is-enabled drive-phangan-backup.timer || true)" == disabled && "$(systemctl is-active drive-phangan-backup.timer || true)" == inactive && "$(systemctl is-active drive-phangan-backup.service || true)" == inactive ]] || fail 'Backup automation changed before lock.'
free_bytes() { df -B1 --output=avail "$root" | tail -n 1 | tr -d ' '; }
[[ "$(free_bytes)" -ge 2147483648 ]] || fail 'Established2048MiB pre-preparation gate failed.'
release_bytes="$(du -sB1 "$previous" | awk '{print $1}')"
#384MiB backup reserve exceeds measured257MiB old backup and291MiB persistent tree.
[[ "$(free_bytes)" -ge "$((805306368+release_bytes+402653184+33554432))" ]] || fail 'Conservative independent-copy/backup/32MiB budget cannot retain768MiB.'
units=(drive-phangan-bot drive-phangan-worker-default drive-phangan-worker-notifications drive-phangan-scheduler)
health() {
    for unit in nginx php8.3-fpm "${units[@]}"; do
        systemctl is-active --quiet "$unit" || fail 'Expected production service inactive.'
        [[ "$(systemctl show "$unit" -p NRestarts --value)" == 0 ]] || fail 'Unexpected production automatic restart counter.'
    done
    for url in https://drivephangan.com https://admin.drivephangan.com/health/live https://admin.drivephangan.com/health/ready https://admin.drivephangan.com/login; do
        deadline=$((SECONDS+60))
        until [[ "$(curl --silent --show-error --proto '=https' --tlsv1.2 --max-time 10 --output /dev/null --write-out '%{http_code}' "$url")" == 200 ]]; do
            [[ "$SECONDS" -lt "$deadline" ]] || fail 'Production strict HTTPS readiness deadline failed.'
            sleep 2
        done
    done
    [[ "$(curl --silent --show-error --proto '=https' --tlsv1.2 --max-time 10 --output /dev/null --write-out '%{http_code}' https://admin.drivephangan.com/api/v1/bot/configuration)" == 401 ]] || fail 'Anonymous Bot API boundary changed.'
}
health
id="$(date -u +%Y%m%dT%H%M%SZ)"
release="$root/releases/$id"
operation="$root/price-release-20261031-operation-$id"
[[ ! -e "$release" && ! -L "$release" && ! -e "$operation" && ! -L "$operation" ]] || fail 'Unique release/operation names already exist.'
latch="$root/price-release-20261031.latch"
[[ ! -e "$latch" && ! -L "$latch" ]] || fail 'This exact release was already attempted; inspect retained state, never create another backup blindly.'
(set -o noclobber; printf 'revision=%s\noperation=%s\nphase=preparing\n' "$revision" "$operation" >"$latch") || fail 'Cannot create once-only release latch.'
install -d -o drive-phangan -g drive-phangan -m 0700 "$operation" "$operation/inputs"
phase() { printf '%s\n' "$1" >"$operation/phase"; printf 'revision=%s\noperation=%s\nphase=%s\n' "$revision" "$operation" "$1" >"$latch"; }
for file in pricing-source.json mapping.json catalog-snapshot.json; do install -o drive-phangan -g drive-phangan -m 0600 "$incoming/$file" "$operation/inputs/$file"; done
phase preparing
cp -a --reflink=never "$previous" "$release"
install -o root -g root -m 0444 "$source/backend/app/Domain/Pricing/Services/PricingSettings.php" "$release/backend/app/Domain/Pricing/Services/PricingSettings.php"
install -d -o root -g root -m 0755 "$release/ops/pricing-20261031"
for file in "$helpers"/*.php "$helpers"/*.sh; do install -o root -g root -m 0444 "$file" "$release/ops/pricing-20261031/"; done
printf 'FORMAT_VERSION=1\nRELEASE_ID=%s\nCREATED_AT=%s\nSOURCE_REVISION=%s\nSOURCE_REF=%s\nSOURCE_DIRTY=false\nBUILD_KIND=independent-copy-php-only\nINHERITED_BASE_RELEASE=%s\n' "$id" "$(date -u +%FT%TZ)" "$revision" "$source_ref" "$previous" >"$release/release-manifest.env"
chmod 0444 "$release/release-manifest.env"
php "$helpers/compare-copy.php" "$previous" "$release" "$source" >"$operation/copy-before.json"
[[ "$(free_bytes)" -ge "$((805306368+402653184+33554432))" ]] || fail 'Measured copy leaves insufficient backup reserve.'
runtime() { sudo -u drive-phangan env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/srv/drive-phangan APP_ENV=production /usr/bin/php "$@"; }
runtime "$release/ops/pricing-20261031/run.php" inspect "$release/backend" "$operation/inputs" >"$operation/preview.json"
# Verify append ownership WITHOUT creating/truncating any shared log as root.
log_directory="$root/shared/backend/storage/logs"
for day in "$(TZ=Asia/Bangkok date +%F)" "$(TZ=Asia/Bangkok date -d tomorrow +%F)"; do
    log="$log_directory/laravel-$day.log"
    [[ ! -L "$log" ]] || fail 'Runtime log is linked.'
    if [[ -e "$log" ]]; then [[ "$(stat -c %U "$log")" == drive-phangan ]] || fail 'Existing runtime log owner differs.'; fi
    sudo -u drive-phangan bash -c 'umask 0027; : >>"$1"' _ "$log"
done
runtime "$previous/backend/artisan" operations:release-preflight --strict --accept-risk=admin.two_factor --risk-reason='Owner previously deferred two-factor enrollment; narrow final-price release only.' --json >"$operation/preflight.json"
before_backups="$(find "$root/backups" -mindepth 1 -maxdepth 1 -type d | wc -l)"
phase 'quiesce-started; failure may leave maintenance or partially stopped units'
runtime "$previous/backend/artisan" down --retry=30
systemctl stop "${units[@]}"
for unit in "${units[@]}"; do [[ "$(systemctl is-active "$unit" || true)" == inactive ]] || fail 'Writer did not stop.'; done
phase 'writers-stopped; failure now needs reviewed availability recovery'
runtime "$release/ops/pricing-20261031/run.php" fingerprint "$previous/backend" "$operation/inputs" >"$operation/baseline.tmp"
install -o drive-phangan -g drive-phangan -m 0600 "$operation/baseline.tmp" "$operation/baseline.json"
export APP_ROOT="$root" CURRENT="$root/current" SHARED="$root/shared" BACKUP_ROOT="$root/backups" ENABLE_BACKUP_PRUNE=false BACKUP_RETENTION_DAYS=14
phase 'backup-started; inspect retained backups before any manual recovery, never retry'
backup="$(sudo -u drive-phangan env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/srv/drive-phangan APP_ROOT="$root" CURRENT="$root/current" SHARED="$root/shared" BACKUP_ROOT="$root/backups" BACKEND_DIR="$previous/backend" BACKEND_ENV_FILE="$root/shared/backend/.env" ENABLE_BACKUP_PRUNE=false BACKUP_RETENTION_DAYS=14 bash "$source/deploy/scripts/backup.sh")"
[[ "$backup" =~ ^/srv/drive-phangan/backups/[0-9]{8}T[0-9]{6}Z$ && "$(realpath -e "$backup")" == "$backup" ]] || fail 'Backup returned unexpected target.'
exec 8<"$root/backups/.backup.lock"
flock -x -n 8 || fail 'Exclusive backup lock unavailable after backup; no import.'
[[ "$(find "$root/backups" -mindepth 1 -maxdepth 1 -type d | wc -l)" -eq "$((before_backups+1))" ]] || fail 'Locked backup count is not exactly+1.'
phase 'backup-created-count-locked; verification pending'
sudo -u drive-phangan env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/srv/drive-phangan APP_ROOT="$root" BACKUP_ROOT="$root/backups" bash "$source/deploy/scripts/verify-backup.sh" "$backup" >"$operation/backup-verify.log"
[[ "$(free_bytes)" -ge 805306368 ]] || fail 'Established768MiB post-backup gate failed.'
php -r '$r=["format"=>"PREPRICE-VERIFIED-BACKUP-1","path"=>$argv[1],"source_revision"=>"6792e28929cfe20b1cf3c9678929e27bfaf61e80","baseline_sha256"=>hash_file("sha256",$argv[2]),"sha256sums_sha256"=>hash_file("sha256",$argv[1]."/SHA256SUMS")];echo json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";' "$backup" "$operation/baseline.json" >"$operation/receipt.tmp"
install -o drive-phangan -g drive-phangan -m 0600 "$operation/receipt.tmp" "$operation/verified-backup.json"
phase backup-verified
proof="$(sha256sum "$operation/verified-backup.json" | awk '{print $1}')"
[[ "$(free_bytes)" -ge 805306368 ]] || fail '768MiB immediate pre-import gate failed; no database mutation.'
phase 'import-started; NEVER automatically resume legacy code or restore DB'
sudo -u drive-phangan env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/srv/drive-phangan APP_ENV=production FINAL_PRICES_EXECUTION_GO=304a31270256c2d36e08b3c90128dc91bca31b215ea4750df2bc9b0b4707bfc2 FINAL_PRICES_BACKUP_VERIFIED="$proof" /usr/bin/php "$release/ops/pricing-20261031/run.php" apply "$release/backend" "$operation/inputs" "$admin_id" >"$operation/import.json"
phase 'import-committed; forward-only reviewed recovery'
[[ "$(readlink -f "$root/current")" == "$previous" ]] || fail 'Current changed before cutover.'
[[ "$(free_bytes)" -ge 805306368 ]] || fail '768MiB immediate cutover gate failed AFTER import; stay in maintenance for reviewed forward recovery, never legacy rollback.'
temporary="$root/.current-price-$id"
[[ ! -e "$temporary" && ! -L "$temporary" ]] || fail 'Unexpected atomic symlink temporary.'
ln -s "$release" "$temporary"
mv -Tf "$temporary" "$root/current"
systemctl reload php8.3-fpm
runtime "$release/ops/pricing-20261031/run.php" inspect "$release/backend" "$operation/inputs" >"$operation/final-pricing.json"
php -r '$a=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);$b=json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR)["fingerprint"];foreach(["bookings","booking_price_snapshots","pricing_seasons","pricing_season_months","rental_policies","categories","vehicle_photos"]as$t){if($a[$t]!==$b[$t])exit(1);}' "$operation/baseline.json" "$operation/final-pricing.json" || fail 'Historical/reference baseline changed before reopening.'
runtime "$release/backend/artisan" up
systemctl start "${units[@]}"
health
[[ "$(readlink -f "$root/current")" == "$release" && "$(free_bytes)" -ge 805306368 ]] || fail 'Final current/space gate failed.'
php "$helpers/compare-copy.php" "$previous" "$release" "$source" >"$operation/copy-after.json"
cmp -s "$operation/copy-before.json" "$operation/copy-after.json" || fail 'Old release changed during operation.'
phase complete
printf 'NARROW_PRICE_RELEASE_PASS revision=%s operation=%s backup=%s\n' "$revision" "$operation" "$backup"
df -Pm "$root"
