#!/usr/bin/env bash
# Finalize a Shashety IPTV installation embedded in a Linux ISO.
# This script finalizes the PHP IPTV application and does not publish a
# second frontend.
set -Eeuo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
HLS_DIR="${HLS_DIR:-/dev/shm/shs_hls}"

[[ "${1:-}" != "--help" ]] || { printf 'Usage: sudo APP_DIR=/var/www/html/iptv bash tools/iso_finalize.sh\n'; exit 0; }
[[ $# -eq 0 ]] || { printf 'This finalizer accepts no options.\n' >&2; exit 2; }

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
note() { printf '• %s\n' "$*"; }

[[ $EUID -eq 0 ]] || fail 'Run this script with sudo or as root.'
[[ -f "$APP_DIR/api.php" && -f "$APP_DIR/index.php" ]] || fail "IPTV application not found at $APP_DIR"
command -v apache2ctl >/dev/null 2>&1 || fail 'Apache is not installed.'

WEB_USER="${WEB_USER:-www-data}"
id -u "$WEB_USER" >/dev/null 2>&1 || WEB_USER="apache"
id -u "$WEB_USER" >/dev/null 2>&1 || fail 'No Apache service user was found.'

note 'Preparing writable application directories'
for dir in storage storage/logs storage/cache storage/vod uploads cache; do
    install -d -o "$WEB_USER" -g "$WEB_USER" -m 0775 "$APP_DIR/$dir"
done
install -d -o "$WEB_USER" -g "$WEB_USER" -m 0755 "$HLS_DIR"

if [[ -f "$APP_DIR/.env" ]]; then
    chown "$WEB_USER:$WEB_USER" "$APP_DIR/.env"
    chmod 0640 "$APP_DIR/.env"
fi

note 'Applying PHP session hardening'
install -d -m 0755 /etc/php/8.1/apache2/conf.d
cat >/etc/php/8.1/apache2/conf.d/99-shashety-hardening.ini <<'EOF'
expose_php = Off
session.cookie_httponly = 1
session.use_strict_mode = 1
session.use_only_cookies = 1
session.cookie_samesite = Lax
EOF

note 'Applying Apache security defaults'
a2enmod headers rewrite >/dev/null
cat >/etc/apache2/conf-available/shashety-security.conf <<'EOF'
ServerTokens Prod
ServerSignature Off
<IfModule mod_headers.c>
    Header always setifempty X-Content-Type-Options "nosniff"
    Header always setifempty X-Frame-Options "SAMEORIGIN"
    Header always setifempty Referrer-Policy "strict-origin-when-cross-origin"
    Header always setifempty Permissions-Policy "camera=(), microphone=(), geolocation=()"
</IfModule>
EOF
cat >/etc/apache2/conf-available/shashety-servername.conf <<'EOF'
# A stable local name suppresses Apache's startup warning. It does not change routing.
ServerName localhost
EOF
a2enconf shashety-security shashety-servername >/dev/null

note 'Validating Apache configuration'
apache2ctl configtest
systemctl reload apache2

if [[ -x "$APP_DIR/tools/iptv_healthcheck.sh" ]]; then
    APP_DIR="$APP_DIR" bash "$APP_DIR/tools/iptv_healthcheck.sh"
fi

note 'Finalization completed. PHP is the only active /iptv/ interface.'
