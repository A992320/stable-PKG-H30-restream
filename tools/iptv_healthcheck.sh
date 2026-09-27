#!/usr/bin/env bash
# Read-only operational check for a deployed Shashety IPTV instance.
set -Eeuo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
BASE_PATH="${BASE_PATH:-/iptv}"
HOST="${HOST:-127.0.0.1}"

say() { printf '%-30s %s\n' "$1" "$2"; }
state() { systemctl is-active "$1" 2>/dev/null || true; }
http_status() { curl -sS -o /dev/null -w '%{http_code}' --max-time 8 "http://$HOST$1" || printf 'unreachable'; }

[[ -f "$APP_DIR/api.php" ]] || { printf 'Application was not found at %s\n' "$APP_DIR" >&2; exit 1; }

printf 'Shashety IPTV health check\n'
printf '%s\n' '----------------------------------------'
say 'Apache service' "$(state apache2)"
say 'Database service' "$(state mysql)"
say 'PHP player endpoint' "HTTP $(http_status "$BASE_PATH/index.php")"
say 'API endpoint' "HTTP $(http_status "$BASE_PATH/api.php?action=all_content")"
say 'Apache configuration' "$(apache2ctl configtest 2>&1 | tail -1)"
say 'PHP entry syntax' "$(php -l "$APP_DIR/api.php" 2>&1 | tail -1)"
say 'Apache MPM' "$(apache2ctl -V 2>/dev/null | awk -F': ' '/Server MPM/ {print $2; exit}')"
say 'PHP OPcache' "$(php -r 'echo extension_loaded("Zend OPcache") && ini_get("opcache.enable") ? "available" : "missing/off";' 2>/dev/null || printf missing)"
say 'PHP APCu' "$(php -r 'echo extension_loaded("apcu") ? "available" : "missing";' 2>/dev/null || printf missing)"
PHP_MM="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;' 2>/dev/null || true)"
if [[ -n "$PHP_MM" ]]; then
    say 'PHP-FPM service' "$(state "php${PHP_MM}-fpm")"
fi
say 'HLS memory directory' "$(test -d /dev/shm/shs_hls && printf ready || printf missing)"
say 'Disk free at application' "$(df -h "$APP_DIR" | awk 'NR==2 {print $4}')"
say 'Memory available' "$(free -h | awk '/Mem:/ {print $7}')"

if [[ -f "$APP_DIR/.env" ]]; then
    mode="$(stat -c '%a' "$APP_DIR/.env")"
    say 'Environment file permissions' "$mode (expected 640)"
fi

printf '%s\n' '----------------------------------------'
printf 'This check does not start a channel or expose stream URLs. Test a real Live and VOD item in the browser after deployment.\n'
