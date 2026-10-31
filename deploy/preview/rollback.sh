#!/usr/bin/env bash
# Disable ONLY the newly created preview endpoints/processes; retain all data for review.
set -Eeuo pipefail
umask 0077
fail() { printf 'PREVIEW ROLLBACK REFUSED: %s\n' "$1" >&2; exit 1; }
[[ "$EUID" -eq 0 && "$#" -eq 1 && "$1" =~ ^[a-f0-9]{64}$ ]] || fail 'Expected root and reviewed artifact SHA256.'
root=/srv/drive-phangan-preview
[[ "$(realpath -e "$root")" == "$root" && ! -L "$root" ]] || fail 'Preview root boundary changed.'
[[ "$(sed -n '1p' "$root/.preview-root")" == 'WEBSITE PREVIEW ONLY' && "$(sed -n '2p' "$root/.preview-root")" == "$1" ]] || fail 'Preview marker fingerprint mismatch.'
exec 9</srv/drive-phangan/.deploy.lock
flock -s -n 9 || fail 'Production deployment lock unavailable.'
production="$(readlink -f /srv/drive-phangan/current)"
if [[ -L /etc/nginx/sites-enabled/drive-phangan-preview ]]; then
    [[ "$(readlink /etc/nginx/sites-enabled/drive-phangan-preview)" == /etc/nginx/sites-available/drive-phangan-preview ]] || fail 'Preview vhost link points elsewhere.'
    unlink /etc/nginx/sites-enabled/drive-phangan-preview
    nginx -t || fail 'Nginx test failed; do not reload. Investigate separately.'
    systemctl reload nginx
elif [[ -e /etc/nginx/sites-enabled/drive-phangan-preview ]]; then
    fail 'Unexpected non-link preview vhost; refusing removal.'
fi
systemctl disable --now drive-phangan-preview-expiry.timer drive-phangan-preview-fpm.service
systemctl stop drive-phangan-preview-expiry.service || true
[[ "$(readlink -f /srv/drive-phangan/current)" == "$production" ]] || fail 'Production current changed.'
for unit in nginx php8.3-fpm drive-phangan-bot drive-phangan-worker-default drive-phangan-worker-notifications drive-phangan-scheduler; do
    systemctl is-active --quiet "$unit" || fail 'Production service is not active.'
done
printf 'Preview vhost/FPM/expiry disabled only. Preview DB, files, certificate, logs and all production releases/backups retained.\n'
exit 0
