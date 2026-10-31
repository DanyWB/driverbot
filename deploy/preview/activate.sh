#!/usr/bin/env bash
# Reviewed first activation only. No automatic retry; failure retains explicit state.
set -Eeuo pipefail
umask 0077
fail() { printf 'PREVIEW ACTIVATION REFUSED: %s\n' "$1" >&2; exit 1; }
[[ "$EUID" -eq 0 && "$#" -eq 1 && "$1" =~ ^[a-f0-9]{64}$ ]] || fail 'Expected root and reviewed artifact SHA256.'
artifact_sha="$1"
root=/srv/drive-phangan-preview
[[ "$(realpath -e "$root")" == "$root" && ! -L "$root" && -f "$root/.preview-root" ]] || fail 'Preview root boundary missing.'
state="$root/shared/private/prepared-state"
[[ -f "$state" && ! -L "$state" && "$(stat -c %a "$state")" == 600 ]] || fail 'Protected preparation state missing.'
mapfile -t prepared <"$state"
[[ "${#prepared[@]}" -eq 4 && "${prepared[0]}" == "$artifact_sha" && "${prepared[1]}" =~ ^[a-f0-9]{32}$ && "${prepared[2]}" =~ ^preview-20261031-[a-f0-9]{8}$ && "${prepared[3]}" =~ ^[a-f0-9]{64}$ ]] || fail 'Preparation identity mismatch.'
release="$root/releases/${prepared[2]}"
[[ "$(realpath -e "$release")" == "$release" && "$(readlink -f "$root/current")" == "$release" ]] || fail 'Preview release boundary changed.'
[[ "$(sha256sum "$release/artifact-manifest.json" | awk '{print $1}')" == "${prepared[3]}" ]] || fail 'Prepared manifest changed.'
php "$release/deploy/preview/verify-artifact.php" prepared "$release"
for file in "$root/shared/private/backend.env" /etc/drive-phangan-preview/htpasswd "$root/shared/private/probe.curl"; do
    [[ "$(realpath -e "$file")" == "$file" && ! -L "$file" ]] || fail 'Installed private configuration is linked/missing.'
done
[[ "$(stat -c %U "$root/shared/private/backend.env")" == drive-phangan-preview && "$(stat -c %a "$root/shared/private/backend.env")" == 600 ]] || fail 'Installed environment owner/mode invalid.'
[[ "$(stat -c %u /etc/drive-phangan-preview/htpasswd)" == 0 && "$(stat -c %G /etc/drive-phangan-preview/htpasswd)" == www-data && "$(stat -c %a /etc/drive-phangan-preview/htpasswd)" == 640 ]] || fail 'Installed Basic Auth owner/group/mode invalid.'
[[ "$(stat -c %u "$root/shared/private/probe.curl")" == 0 && "$(stat -c %a "$root/shared/private/probe.curl")" == 600 ]] || fail 'Installed probe owner/mode invalid.'
php -r '$m=json_decode(file_get_contents($argv[1]),true,30,JSON_THROW_ON_ERROR);foreach(["backend_env_sha256"=>$argv[2],"htpasswd_sha256"=>$argv[3],"probe_curl_sha256"=>$argv[4]]as$k=>$p){if(!hash_equals($m[$k],hash_file("sha256",$p)))exit(1);}' "$release/artifact-manifest.json" "$root/shared/private/backend.env" /etc/drive-phangan-preview/htpasswd "$root/shared/private/probe.curl" || fail 'Installed environment/auth/probe fingerprints changed before exposure.'
[[ ! -e "$root/shared/private/activated-state" ]] || fail 'Already activated; do not rerun mutation phases.'
[[ ! -e /etc/nginx/sites-available/drive-phangan-preview && ! -e /etc/nginx/sites-enabled/drive-phangan-preview ]] || fail 'Unexpected preview vhost already exists.'
[[ ! -e /etc/letsencrypt/live/drive-phangan-preview ]] || fail 'Unexpected preview certificate already exists.'
exec 9</srv/drive-phangan/.deploy.lock
flock -s -n 9 || fail 'Production deployment lock unavailable.'
production="$(readlink -f /srv/drive-phangan/current)"
[[ "$production" == /srv/drive-phangan/releases/20261029T181820Z ]] || fail 'Production baseline changed.'
[[ "$(systemctl is-enabled drive-phangan-backup.timer || true)" == disabled ]] || fail 'Backup timer state changed.'
for unit in nginx php8.3-fpm drive-phangan-bot drive-phangan-worker-default drive-phangan-worker-notifications drive-phangan-scheduler drive-phangan-preview-fpm; do
    systemctl is-active --quiet "$unit" || fail 'Expected service is not active.'
done
sudo -u drive-phangan-preview env -i PATH=/usr/local/bin:/usr/bin:/bin APP_ENV=production LARAVEL_STORAGE_PATH="$root/shared/storage" /usr/bin/php "$release/backend/artisan" website:preview-check || fail 'Full preview integrity check failed.'
host=drive-preview.2-26-60-62.sslip.io
[[ "$(getent ahostsv4 "$host" | awk 'NR==1 {print $1}')" == 2.26.60.62 ]] || fail 'Free preview DNS no longer points to the reviewed server.'
[[ "$(df -B1 --output=avail "$root" | tail -n 1 | tr -d ' ')" -ge 2147483648 ]] || fail 'Mandatory 2048 MiB free-space floor not met.'
install -d -o root -g root -m 0755 "$root/acme" "$root/acme/.well-known" "$root/acme/.well-known/acme-challenge"
sed "s/__PREVIEW_HOST__/$host/g" "$release/deploy/preview/nginx-http.conf.template" >/etc/nginx/sites-available/drive-phangan-preview
chmod 0644 /etc/nginx/sites-available/drive-phangan-preview
ln -s /etc/nginx/sites-available/drive-phangan-preview /etc/nginx/sites-enabled/drive-phangan-preview
nginx -t || fail 'New HTTP vhost invalid; no reload performed.'
systemctl reload nginx
# Existing Certbot account, webroot only; no nginx plugin, standalone listener or TLS bypass.
certbot certonly --webroot --webroot-path "$root/acme" --cert-name drive-phangan-preview --domains "$host" --non-interactive --agree-tos --keep-until-expiring \
    >"$root/shared/private/certbot.log" 2>&1 || fail 'Certificate issuance failed; inspect state, no automatic retry.'
[[ -f /etc/letsencrypt/live/drive-phangan-preview/fullchain.pem && -f /etc/letsencrypt/live/drive-phangan-preview/privkey.pem ]] || fail 'Issued certificate missing.'
sed "s/__PREVIEW_HOST__/$host/g" "$release/deploy/preview/nginx-https.conf.template" >/etc/nginx/sites-available/drive-phangan-preview
nginx -t || fail 'New HTTPS vhost invalid; HTTP configuration remains loaded until reviewed recovery.'
systemctl reload nginx
probe="$root/shared/private/probe.curl"
[[ "$(stat -c %a "$probe")" == 600 && ! -L "$probe" ]] || fail 'Private HTTP probe file unsafe.'
expected_probe="$(php -r '$m=json_decode(file_get_contents($argv[1]),true,30,JSON_THROW_ON_ERROR);echo $m["probe_curl_sha256"];' "$release/artifact-manifest.json")"
[[ "$(sha256sum "$probe" | awk '{print $1}')" == "$expected_probe" ]] || fail 'Private HTTP probe changed.'
# Graceful reload returns before all new workers accept the new SNI certificate.
# Bounded, verified-TLS reads only; never repeat config/certificate mutations.
tls_ready=false
for attempt in 1 2 3 4 5; do
    if [[ "$(curl --silent --show-error --proto '=https' --tlsv1.2 --max-time 3 --output /dev/null --write-out '%{http_code}' "https://$host/en" 2>"$root/shared/private/tls-readiness.log")" == 401 ]]; then tls_ready=true; break; fi
    sleep 1
done
[[ "$tls_ready" == true ]] || fail 'Verified-TLS preview readiness did not pass; inspect without rerunning mutations.'
headers="$root/shared/private/probe-headers"
body="$root/shared/private/probe-body"
curl_code() { curl --silent --show-error --proto '=https' --tlsv1.2 --max-time 30 --output "$body" --dump-header "$headers" --write-out '%{http_code}' "$@"; }
[[ "$(curl_code "https://$host/en")" == 401 ]] || fail 'Unauthenticated site is not protected.'
[[ "$(curl_code --config "$probe" "https://$host/en")" == 200 ]] || fail 'Authenticated website failed.'
grep -Eiq '^X-Robots-Tag:.*noindex' "$headers" || fail 'Preview noindex header missing.'
grep -Eiq '^Cache-Control:.*no-store' "$headers" || fail 'Preview no-store header missing.'
for path in /login /admin /api/health /en/login; do
    [[ "$(curl_code --config "$probe" "https://$host$path")" == 404 ]] || fail 'Non-website preview route is reachable.'
done
[[ "$(curl_code --config "$probe" "https://$host/robots.txt")" == 200 ]] || fail 'Preview robots endpoint failed.'
grep -Fxq 'Disallow: /' "$body" || fail 'Preview robots is not closed.'
systemctl enable --now drive-phangan-preview-fpm.service drive-phangan-preview-expiry.timer
[[ "$(readlink -f /srv/drive-phangan/current)" == "$production" ]] || fail 'Production current changed unexpectedly.'
for unit in nginx php8.3-fpm drive-phangan-bot drive-phangan-worker-default drive-phangan-worker-notifications drive-phangan-scheduler; do
    systemctl is-active --quiet "$unit" || fail 'Production health changed.'
done
for url in https://drivephangan.com https://admin.drivephangan.com/health/live https://admin.drivephangan.com/health/ready; do
    [[ "$(curl --silent --show-error --proto '=https' --tlsv1.2 --max-time 30 --output /dev/null --write-out '%{http_code}' "$url")" == 200 ]] || fail 'Production HTTPS health changed.'
done
printf '%s\n%s\n' "$artifact_sha" "$(date -u +%FT%TZ)" >"$root/shared/private/activated-state"
df -Pm "$root"
printf 'Isolated authenticated preview activated; production current/services retained. Credentials remain in the protected local handoff file.\n'
exit 0
