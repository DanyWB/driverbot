#!/usr/bin/env bash
# First-time isolated preview provisioning ONLY. Root review/execution GO required.
# Never automatically retry this mutating script; inspect retained state on failure.
set -Eeuo pipefail
umask 0077
fail() { printf 'PREVIEW REFUSED: %s\n' "$1" >&2; exit 1; }
[[ "$EUID" -eq 0 && "$#" -eq 2 ]] || fail 'Expected root, exact archive path and reviewed SHA256.'
artifact="$1"
artifact_sha="$2"
[[ "$artifact_sha" =~ ^[a-f0-9]{64}$ ]] || fail 'Invalid reviewed archive fingerprint.'
incoming="/root/drive-phangan-preview-incoming-${artifact_sha}"
[[ "$(realpath -e "$incoming")" == "$incoming" && ! -L "$incoming" && "$(stat -c %u "$incoming")" == 0 && "$(stat -c %a "$incoming")" == 700 ]] || fail 'Incoming directory must be created root-owned 0700 BEFORE upload.'
[[ "$artifact" == "$incoming/website-preview.tar.gz" ]] || fail 'Unexpected private transfer archive path.'
[[ "$(realpath -e "$artifact")" == "$artifact" && ! -L "$artifact" ]] || fail 'Transfer archive is linked.'
[[ "$(stat -c %u "$artifact")" == 0 && "$(stat -c %a "$artifact")" == 600 ]] || fail 'Transfer archive must be root-owned 0600.'
[[ "$(sha256sum "$artifact" | awk '{print $1}')" == "$artifact_sha" ]] || fail 'Archive fingerprint mismatch.'
[[ "$(stat -c %s "$artifact")" -le 50331648 ]] || fail 'Archive exceeds 48 MiB cap.'
env_transfer="$incoming/backend.env"
auth_transfer="$incoming/htpasswd"
probe_transfer="$incoming/probe.curl"
[[ "$(realpath -e "$env_transfer")" == "$env_transfer" && ! -L "$env_transfer" ]] || fail 'Environment transfer is linked/missing.'
[[ "$(realpath -e "$auth_transfer")" == "$auth_transfer" && ! -L "$auth_transfer" ]] || fail 'Auth transfer is linked/missing.'
[[ "$(realpath -e "$probe_transfer")" == "$probe_transfer" && ! -L "$probe_transfer" && "$(stat -c %a "$probe_transfer")" == 600 ]] || fail 'Probe credential transfer must be regular 0600.'
[[ "$(stat -c %a "$env_transfer")" == 600 && "$(stat -c %a "$auth_transfer")" == 600 ]] || fail 'Secret transfer mode must be 0600.'
for file in "$env_transfer" "$auth_transfer" "$probe_transfer"; do [[ "$(stat -c %u "$file")" == 0 ]] || fail 'Secret transfer owner must be root.'; done
[[ ! -e /srv/drive-phangan-preview ]] || fail 'Preview root already exists; no automatic retry or overwrite.'
[[ ! -e /etc/drive-phangan-preview && ! -e /etc/nginx/sites-available/drive-phangan-preview && ! -e /etc/nginx/sites-enabled/drive-phangan-preview ]] || fail 'Preview config names already exist.'
[[ ! -e /etc/logrotate.d/drive-phangan-preview ]] || fail 'Preview log rotation name already exists.'
! id -u drive-phangan-preview >/dev/null 2>&1 || fail 'Preview Unix identity already exists.'
[[ "$(sudo -u postgres psql -Atqc "SELECT count(*) FROM pg_roles WHERE rolname = 'drive_phangan_preview'" postgres)" == 0 ]] || fail 'Preview PostgreSQL role already exists.'
[[ "$(sudo -u postgres psql -Atqc "SELECT count(*) FROM pg_database WHERE datname = 'drive_phangan_preview'" postgres)" == 0 ]] || fail 'Preview PostgreSQL database already exists.'
[[ "$(systemctl is-enabled drive-phangan-backup.timer || true)" == disabled ]] || fail 'Production backup timer state changed.'
exec 9</srv/drive-phangan/.deploy.lock
flock -s -n 9 || fail 'A production deployment holds its lock.'
production="$(readlink -f /srv/drive-phangan/current)"
[[ "$production" == /srv/drive-phangan/releases/20261029T181820Z ]] || fail 'Production baseline changed.'
[[ ! -L "$production/backend/vendor" && "$(realpath -e "$production/backend/vendor")" == "$production/backend/vendor" ]] || fail 'Production vendor source is linked.'
for unit in nginx php8.3-fpm drive-phangan-bot drive-phangan-worker-default drive-phangan-worker-notifications drive-phangan-scheduler; do
    systemctl is-active --quiet "$unit" || fail 'Production service is not active.'
done
# Inspect all archive names/types before extraction; only regular files/directories.
declare -A archive_paths=()
while IFS= read -r path; do
    [[ "$path" =~ ^[A-Za-z0-9_./-]+$ ]] || fail 'Unsupported archive path characters.'
    [[ "$path" != /* && "$path" != *..* && "$path" != *\\* ]] || fail 'Unsafe tar path.'
    [[ "$path" == backend/* || "$path" == deploy/* || "$path" == inputs/* || "$path" == media/* || "$path" == artifact-manifest.json || "$path" == backend/ || "$path" == deploy/ || "$path" == inputs/ || "$path" == media/ ]] || fail 'Unexpected tar root.'
    [[ ! -v "archive_paths[$path]" ]] || fail 'Duplicate tar entry.'
    archive_paths["$path"]=1
    [[ "${#archive_paths[@]}" -le 10000 ]] || fail 'Too many archive entries.'
done < <(tar -tzf "$artifact")
archive_bytes="$(LC_ALL=C tar -tvzf "$artifact" | awk 'substr($0,1,1)!="-" && substr($0,1,1)!="d" {exit 1} substr($0,1,1)=="-" {if($3 !~ /^[0-9]+$/ || $3>25000000)exit 1; total+=$3;if(total>69108864)exit 1} END {print total+0}')" || fail 'Tar types or independent unpacked size bound invalid.'
[[ "$archive_bytes" =~ ^[0-9]+$ && "$archive_bytes" -le 69108864 ]] || fail 'Independent tar byte bound invalid.'
mapfile -t metadata < <(tar -xOf "$artifact" artifact-manifest.json | php -r '
$m=json_decode(stream_get_contents(STDIN),true,30,JSON_THROW_ON_ERROR);
if(($m["format"]??null)!=="WEBSITE-PREVIEW-ARTIFACT-1" || ($m["source_reviewed_local_changes"]??null)!==true)exit(1);
foreach(["instance_id","preview_release_id","host","package_bytes","media_bytes","composer_lock_sha256","backend_env_sha256","htpasswd_sha256","probe_curl_sha256"]as$k)echo $m[$k],"\n";')
[[ "${#metadata[@]}" -eq 9 ]] || fail 'Artifact metadata is incomplete.'
instance="${metadata[0]}"; release_id="${metadata[1]}"; host="${metadata[2]}"
package_bytes="${metadata[3]}"; media_bytes="${metadata[4]}"; lock_sha="${metadata[5]}"
[[ "$instance" =~ ^[a-f0-9]{32}$ && "$release_id" =~ ^preview-20261031-[a-f0-9]{8}$ && "$host" == drive-preview.2-26-60-62.sslip.io ]] || fail 'Artifact identity is invalid.'
[[ "$package_bytes" =~ ^[0-9]+$ && "$package_bytes" -le 67108864 && "$media_bytes" =~ ^[0-9]+$ && "$media_bytes" -le 25000000 ]] || fail 'Unpacked/media budget invalid.'
manifest_bytes="$(tar -xOf "$artifact" artifact-manifest.json | wc -c | tr -d ' ')"
[[ "$manifest_bytes" -le 2000000 && "$archive_bytes" -eq "$((package_bytes+manifest_bytes))" ]] || fail 'Actual tar sizes do not match the hash-bound payload budget.'
[[ "$lock_sha" =~ ^[a-f0-9]{64}$ && "$(sha256sum "$production/backend/composer.lock" | awk '{print $1}')" == "$lock_sha" ]] || fail 'Independent Linux vendor lock mismatch.'
[[ "$(sha256sum "$env_transfer" | awk '{print $1}')" == "${metadata[6]}" && "$(sha256sum "$auth_transfer" | awk '{print $1}')" == "${metadata[7]}" ]] || fail 'Protected transfer fingerprints mismatch.'
[[ "$(sha256sum "$probe_transfer" | awk '{print $1}')" == "${metadata[8]}" ]] || fail 'Private HTTP probe fingerprint mismatch.'
available="$(df -B1 --output=avail /srv | tail -n 1 | tr -d ' ')"
vendor_bytes="$(du -sB1 "$production/backend/vendor" | awk '{print $1}')"
required=$(( 2048*1024*1024 + package_bytes + media_bytes + vendor_bytes + 32*1024*1024 ))
[[ "$available" -ge "$required" ]] || fail 'Insufficient peak space while retaining the mandatory 2048 MiB floor.'
printf 'Peak gate passed: available=%s planned_extra=%s retained_floor=2147483648 bytes.\n' "$available" "$((required-2147483648))"

# Mutations below are confined to the NEW preview identity/tree/database/config names.
install -d -m 0755 /srv/drive-phangan-preview /srv/drive-phangan-preview/releases
release="/srv/drive-phangan-preview/releases/${release_id}"
install -d -m 0700 "$release"
printf 'WEBSITE PREVIEW ONLY\n%s\n' "$artifact_sha" >/srv/drive-phangan-preview/.preview-root
tar -xzf "$artifact" --no-same-owner --no-same-permissions -C "$release"
php "$release/deploy/preview/verify-artifact.php" verify "$release"
find "$release" -type d -exec chmod 0755 {} +
find "$release" -type f -exec chmod 0644 {} +
chmod 0700 "$release/inputs"
find "$release/inputs" -type f -exec chmod 0600 {} +
useradd --system --user-group --home-dir /srv/drive-phangan-preview --no-create-home --shell /usr/sbin/nologin drive-phangan-preview
install -d -m 0755 /srv/drive-phangan-preview/shared /srv/drive-phangan-preview/shared/storage /srv/drive-phangan-preview/shared/storage/app
install -d -o drive-phangan-preview -g drive-phangan-preview -m 0700 /srv/drive-phangan-preview/shared/private /srv/drive-phangan-preview/shared/private/inputs
install -d -o drive-phangan-preview -g drive-phangan-preview -m 0700 "/srv/drive-phangan-preview/shared/private/inputs/${instance}" /srv/drive-phangan-preview/shared/private/composer-home /srv/drive-phangan-preview/shared/private/composer-cache
for directory in framework framework/cache framework/cache/data framework/sessions framework/views logs app/private; do
    install -d -o drive-phangan-preview -g drive-phangan-preview -m 0700 "/srv/drive-phangan-preview/shared/storage/${directory}"
done
install -d -o drive-phangan-preview -g drive-phangan-preview -m 0755 /srv/drive-phangan-preview/shared/storage/app/public
install -d -o drive-phangan-preview -g drive-phangan-preview -m 0750 /srv/drive-phangan-preview/shared/logs
for file in catalog-snapshot.json pricing-source.json mapping.json optimized-manifest.json; do
    install -o drive-phangan-preview -g drive-phangan-preview -m 0600 "$release/inputs/$file" "/srv/drive-phangan-preview/shared/private/inputs/${instance}/$file"
done
cp -a "$release/media/vehicles" /srv/drive-phangan-preview/shared/storage/app/public/
find /srv/drive-phangan-preview/shared/storage/app/public -type d -exec chmod 0755 {} +
find /srv/drive-phangan-preview/shared/storage/app/public -type f -exec chmod 0644 {} +
install -o drive-phangan-preview -g drive-phangan-preview -m 0600 "$env_transfer" /srv/drive-phangan-preview/shared/private/backend.env
install -o root -g root -m 0600 "$probe_transfer" /srv/drive-phangan-preview/shared/private/probe.curl
install -d -o root -g www-data -m 0750 /etc/drive-phangan-preview
install -o root -g www-data -m 0640 "$auth_transfer" /etc/drive-phangan-preview/htpasswd
ln -s /srv/drive-phangan-preview/shared/private/backend.env "$release/backend/.env"
ln -s "$release" /srv/drive-phangan-preview/current
install -d -o drive-phangan-preview -g drive-phangan-preview -m 0750 "$release/backend/bootstrap/cache"
chmod 0755 "$release"
cp -a "$production/backend/vendor" "$release/backend/vendor"
[[ "$(realpath -e "$release/backend/vendor")" == "$release/backend/vendor" && ! -L "$release/backend/vendor" ]] || fail 'Copied vendor is not independent.'
while IFS= read -r link; do
    [[ "$(realpath -e "$link")" == "$release/backend/"* ]] || fail 'Copied vendor link escapes preview backend.'
done < <(find "$release/backend/vendor" -type l)
chown -hR drive-phangan-preview:drive-phangan-preview "$release/backend/vendor"
find "$release/backend/vendor" -type d -exec chmod u+rwx {} +
find "$release/backend/vendor" -type f -exec chmod u+rw {} +
sudo -u drive-phangan-preview env -i PATH=/usr/local/bin:/usr/bin:/bin HOME=/srv/drive-phangan-preview COMPOSER_DISABLE_NETWORK=1 COMPOSER_HOME=/srv/drive-phangan-preview/shared/private/composer-home COMPOSER_CACHE_DIR=/srv/drive-phangan-preview/shared/private/composer-cache \
    composer --working-dir="$release/backend" dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts --no-plugins \
    >/srv/drive-phangan-preview/shared/private/composer-metadata.log 2>&1 || fail 'Offline metadata-only autoload generation failed.'
[[ "$(sha256sum "$release/backend/composer.lock" | awk '{print $1}')" == "$lock_sha" ]] || fail 'Lock changed during autoload generation.'
chown -hR root:root "$release/backend/vendor"
find "$release/backend/vendor" -type d -exec chmod 0555 {} +
find "$release/backend/vendor" -type f -exec chmod 0444 {} +
php "$release/deploy/preview/verify-vendor.php" "$release/backend" "$lock_sha"

database_password="$(awk -F= '$1=="DB_PASSWORD" {sub(/^[^=]*=/,"");print;exit}' /srv/drive-phangan-preview/shared/private/backend.env)"
[[ "$database_password" =~ ^[a-f0-9]{64}$ ]] || fail 'Preview database secret shape invalid.'
if ! sudo -u postgres psql -q -v ON_ERROR_STOP=1 postgres >/dev/null 2>&1 <<SQL
CREATE ROLE drive_phangan_preview LOGIN PASSWORD '${database_password}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION CONNECTION LIMIT 5;
CREATE DATABASE drive_phangan_preview OWNER drive_phangan_preview;
REVOKE ALL ON DATABASE drive_phangan_preview FROM PUBLIC;
SQL
then fail 'New preview PostgreSQL initialization failed; inspect state before any retry.'; fi
unset database_password
sudo -u drive-phangan-preview env -i PATH=/usr/local/bin:/usr/bin:/bin APP_ENV=production LARAVEL_STORAGE_PATH=/srv/drive-phangan-preview/shared/storage /usr/bin/php "$release/deploy/preview/verify-runtime.php" "$release/backend" || fail 'Actual preview runtime identity failed before migrations.'
run_preview() { sudo -u drive-phangan-preview env -i PATH=/usr/local/bin:/usr/bin:/bin APP_ENV=production LARAVEL_STORAGE_PATH=/srv/drive-phangan-preview/shared/storage /usr/bin/php "$release/backend/artisan" "$@"; }
run_preview package:discover >/srv/drive-phangan-preview/shared/private/package-discover.log 2>&1 || fail 'Isolated package discovery failed.'
run_preview migrate --force >/srv/drive-phangan-preview/shared/private/migrate.log 2>&1 || fail 'Preview-only migrations failed.'
run_preview config:cache >/srv/drive-phangan-preview/shared/private/config-cache.log 2>&1 || fail 'Preview configuration cache failed.'
run_preview route:cache >/srv/drive-phangan-preview/shared/private/route-cache.log 2>&1 || fail 'Preview route cache failed.'
run_preview view:cache >/srv/drive-phangan-preview/shared/private/view-cache.log 2>&1 || fail 'Preview view cache failed.'
run_preview website:preview-import || fail 'Authoritative new preview dataset import failed.'
run_preview website:preview-check || fail 'Full preview integrity check failed.'
sudo -u drive-phangan-preview test ! -r /srv/drive-phangan/shared/backend/.env || fail 'Preview Unix user can read production environment.'
install -o root -g root -m 0644 "$release/deploy/preview/php-fpm.conf" /etc/drive-phangan-preview/php-fpm.conf
install -o root -g root -m 0644 "$release/deploy/preview/logrotate.conf" /etc/logrotate.d/drive-phangan-preview
logrotate --debug /etc/logrotate.d/drive-phangan-preview >/srv/drive-phangan-preview/shared/private/logrotate-check.log 2>&1 || fail 'Preview log rotation check failed.'
logrotate --debug /etc/logrotate.conf >/srv/drive-phangan-preview/shared/private/logrotate-global-check.log 2>&1 || fail 'Global log rotation debug found overlap/invalid configuration; nothing rotated.'
for file in drive-phangan-preview-fpm.service drive-phangan-preview-expiry.service drive-phangan-preview-expiry.timer; do
    [[ ! -e "/etc/systemd/system/$file" ]] || fail 'Unexpected existing preview unit.'
    install -o root -g root -m 0644 "$release/deploy/preview/$file" "/etc/systemd/system/$file"
done
/usr/sbin/php-fpm8.3 --test --fpm-config /etc/drive-phangan-preview/php-fpm.conf
systemd-analyze verify /etc/systemd/system/drive-phangan-preview-fpm.service /etc/systemd/system/drive-phangan-preview-expiry.service /etc/systemd/system/drive-phangan-preview-expiry.timer
systemctl daemon-reload
systemctl start drive-phangan-preview-fpm.service
systemctl is-active --quiet drive-phangan-preview-fpm.service || fail 'New preview FPM did not start.'
printf '%s\n%s\n%s\n%s\n' "$artifact_sha" "$instance" "$release_id" "$(sha256sum "$release/artifact-manifest.json" | awk '{print $1}')" >/srv/drive-phangan-preview/shared/private/prepared-state
[[ "$(readlink -f /srv/drive-phangan/current)" == "$production" ]] || fail 'Production current changed unexpectedly.'
df -Pm /srv/drive-phangan-preview
printf 'Preview prepared only; no public vhost/certificate activation or production changes. Review activate.sh separately.\n'
exit 0
