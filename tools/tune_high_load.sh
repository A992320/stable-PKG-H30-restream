#!/usr/bin/env bash
# Shashety IPTV — conservative, repeatable high-load server tuning.
#
#   --check       read-only report (default)
#   --apply       OPcache/APCu + Apache keep-alive/limits; no MPM switch
#   --enable-fpm  applies the safe profile, then switches Apache to
#                 event MPM + PHP-FPM with rollback on configuration failure
#
# The default installer uses --apply.  The FPM switch remains explicit because
# third-party Apache modules may depend on prefork/mod_php on older servers.
set -Eeuo pipefail

MODE="${1:---check}"
APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

case "$MODE" in
    --check|--apply|--enable-fpm) ;;
    *) printf 'Usage: %s [--check|--apply|--enable-fpm]\n' "$0" >&2; exit 2 ;;
esac

say()  { printf '%-31s %s\n' "$1" "$2"; }
info() { printf '[INFO] %s\n' "$*"; }
ok()   { printf '[ OK ] %s\n' "$*"; }
die()  { printf '[FAIL] %s\n' "$*" >&2; exit 1; }

command -v php >/dev/null 2>&1 || die 'PHP CLI is required.'
command -v apache2ctl >/dev/null 2>&1 || die 'Apache is required.'

PHP_MM="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
CPU="$(nproc 2>/dev/null || printf 2)"
MEM_MB="$(awk '/MemTotal:/ {printf "%d", $2/1024}' /proc/meminfo)"
[[ "$CPU" =~ ^[0-9]+$ ]] || CPU=2
[[ "$MEM_MB" =~ ^[0-9]+$ ]] || MEM_MB=2048

# Keep at least 35% of RAM (and never less than 2 GB) for MySQL, filesystem
# cache, Node, FFmpeg and the kernel. A PHP worker is budgeted conservatively
# at 128 MB. Static HLS viewers are handled by Apache threads, not FPM workers.
RESERVE_MB=$(( MEM_MB * 35 / 100 ))
(( RESERVE_MB < 2048 )) && RESERVE_MB=2048
PHP_BUDGET_MB=$(( MEM_MB - RESERVE_MB ))
(( PHP_BUDGET_MB < 1024 )) && PHP_BUDGET_MB=1024
FPM_CHILDREN=$(( PHP_BUDGET_MB / 128 ))
(( FPM_CHILDREN < 16 )) && FPM_CHILDREN=16
(( FPM_CHILDREN > 256 )) && FPM_CHILDREN=256

FPM_START=$(( CPU * 2 ))
(( FPM_START < 4 )) && FPM_START=4
(( FPM_START > 24 )) && FPM_START=24
(( FPM_START > FPM_CHILDREN )) && FPM_START=$FPM_CHILDREN
FPM_MIN=$(( FPM_START / 2 ))
(( FPM_MIN < 2 )) && FPM_MIN=2
FPM_MAX=$(( FPM_START * 2 ))
(( FPM_MAX > FPM_CHILDREN )) && FPM_MAX=$FPM_CHILDREN

APACHE_WORKERS=$(( CPU * 100 ))
(( APACHE_WORKERS < 400 )) && APACHE_WORKERS=400
(( APACHE_WORKERS > 2000 )) && APACHE_WORKERS=2000
# ThreadsPerChild is 25; keep MaxRequestWorkers divisible by it.
APACHE_WORKERS=$(( (APACHE_WORKERS / 25) * 25 ))
SERVER_LIMIT=$(( (APACHE_WORKERS + 24) / 25 ))

CURRENT_MPM="$(apache2ctl -V 2>/dev/null | awk -F': ' '/Server MPM/ {print $2; exit}')"

report() {
    printf 'Shashety high-load profile\n'
    printf '%s\n' '----------------------------------------'
    say 'Application' "$APP_DIR"
    say 'CPU cores' "$CPU"
    say 'Memory' "${MEM_MB} MB"
    say 'PHP version' "$PHP_MM"
    say 'Apache MPM' "${CURRENT_MPM:-unknown}"
    say 'Calculated FPM children' "$FPM_CHILDREN"
    say 'Calculated Apache workers' "$APACHE_WORKERS"
    say 'OPcache' "$(php -r 'echo extension_loaded("Zend OPcache") && ini_get("opcache.enable") ? "available" : "missing/off";' 2>/dev/null || printf missing)"
    say 'APCu' "$(php -r 'echo extension_loaded("apcu") ? "available" : "missing";' 2>/dev/null || printf missing)"
    say 'Application syntax' "$(php -l "$APP_DIR/api.php" 2>&1 | tail -1)"
    printf '%s\n' '----------------------------------------'
}

if [[ "$MODE" == '--check' ]]; then
    report
    exit 0
fi

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die 'Run apply modes as root.'
command -v apt-get >/dev/null 2>&1 || die 'This tool currently supports Debian/Ubuntu (apt-get).'

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="/var/backups/shashety-high-load-$STAMP"
install -d -m 0700 "$BACKUP_DIR"
tar -C / -czf "$BACKUP_DIR/apache2.tgz" etc/apache2
if [[ -d "/etc/php/$PHP_MM" ]]; then
    tar -C / -czf "$BACKUP_DIR/php.tgz" "etc/php/$PHP_MM"
fi
printf '%s\n' "$CURRENT_MPM" > "$BACKUP_DIR/previous-mpm.txt"

rollback() {
    local code=$?
    trap - ERR
    printf '[ROLLBACK] Restoring Apache/PHP configuration from %s\n' "$BACKUP_DIR" >&2
    rm -f /etc/apache2/conf-enabled/shashety-performance.conf
    rm -f /etc/apache2/conf-available/shashety-performance.conf
    if command -v phpdismod >/dev/null 2>&1; then
        phpdismod -v "$PHP_MM" shashety-performance >/dev/null 2>&1 || true
    fi
    rm -f "/etc/php/$PHP_MM/mods-available/shashety-performance.ini"
    tar -C / -xzf "$BACKUP_DIR/apache2.tgz" || true
    [[ -f "$BACKUP_DIR/php.tgz" ]] && tar -C / -xzf "$BACKUP_DIR/php.tgz" || true
    systemctl daemon-reload || true
    systemctl restart "php${PHP_MM}-fpm" 2>/dev/null || true
    systemctl restart apache2 2>/dev/null || true
    exit "$code"
}
trap rollback ERR

apt-get update -qq

pkg_for() {
    local specific="php${PHP_MM}-$1" generic="php-$1"
    if apt-cache show "$specific" >/dev/null 2>&1; then printf '%s' "$specific"; else printf '%s' "$generic"; fi
}

PKG_OPCACHE="$(pkg_for opcache)"
PKG_APCU="$(pkg_for apcu)"
apt-get install -y "$PKG_OPCACHE" "$PKG_APCU"

MOD_DIR="/etc/php/$PHP_MM/mods-available"
install -d -m 0755 "$MOD_DIR"
cat > "$MOD_DIR/shashety-performance.ini" <<'EOF'
; Shashety IPTV — generated by tools/tune_high_load.sh
expose_php = Off
realpath_cache_size = 4096K
realpath_cache_ttl = 600
session.lazy_write = 1
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 50000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
opcache.save_comments = 1
opcache.jit = 0
apc.enabled = 1
apc.shm_size = 128M
apc.ttl = 600
apc.entries_hint = 20000
EOF

if command -v phpenmod >/dev/null 2>&1; then
    phpenmod -v "$PHP_MM" opcache apcu shashety-performance
fi

a2enmod headers expires deflate >/dev/null
cat > /etc/apache2/conf-available/shashety-performance.conf <<'EOF'
# Shashety IPTV — safe high-load defaults
KeepAlive On
MaxKeepAliveRequests 1000
KeepAliveTimeout 2
Timeout 60

<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE application/json application/javascript text/css text/html text/plain text/xml image/svg+xml
</IfModule>

<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpeg "access plus 7 days"
    ExpiresByType image/png  "access plus 7 days"
    ExpiresByType image/webp "access plus 7 days"
    ExpiresByType image/svg+xml "access plus 7 days"
    ExpiresByType text/css "access plus 1 day"
    ExpiresByType application/javascript "access plus 1 day"
</IfModule>
EOF
a2enconf shashety-performance >/dev/null

install -d -m 0755 /etc/systemd/system/apache2.service.d
cat > /etc/systemd/system/apache2.service.d/shashety-limits.conf <<'EOF'
[Service]
LimitNOFILE=131072
TasksMax=infinity
EOF

if [[ "$MODE" == '--enable-fpm' ]]; then
    PKG_FPM="$(pkg_for fpm)"
    apt-get install -y "$PKG_FPM"

    FPM_POOL_DIR="/etc/php/$PHP_MM/fpm/pool.d"
    install -d -m 0755 "$FPM_POOL_DIR"
    cat > "$FPM_POOL_DIR/zz-shashety-performance.conf" <<EOF
[www]
pm = dynamic
pm.max_children = $FPM_CHILDREN
pm.start_servers = $FPM_START
pm.min_spare_servers = $FPM_MIN
pm.max_spare_servers = $FPM_MAX
pm.max_requests = 500
rlimit_files = 131072
request_slowlog_timeout = 10s
slowlog = /var/log/php${PHP_MM}-fpm-shashety-slow.log
EOF

    cat > /etc/apache2/mods-available/mpm_event.conf <<EOF
<IfModule mpm_event_module>
    StartServers             3
    MinSpareThreads          75
    MaxSpareThreads          250
    ThreadLimit              64
    ThreadsPerChild          25
    ServerLimit              $SERVER_LIMIT
    MaxRequestWorkers        $APACHE_WORKERS
    MaxConnectionsPerChild   10000
</IfModule>
EOF

    a2dismod "php${PHP_MM}" >/dev/null 2>&1 || true
    a2dismod mpm_prefork >/dev/null 2>&1 || true
    a2dismod mpm_worker >/dev/null 2>&1 || true
    a2enmod mpm_event proxy_fcgi setenvif >/dev/null
    a2enconf "php${PHP_MM}-fpm" >/dev/null

    install -d -m 0755 "/etc/systemd/system/php${PHP_MM}-fpm.service.d"
    cat > "/etc/systemd/system/php${PHP_MM}-fpm.service.d/shashety-limits.conf" <<'EOF'
[Service]
LimitNOFILE=131072
TasksMax=infinity
EOF

    FPM_BIN="$(command -v "php-fpm${PHP_MM}" || true)"
    [[ -n "$FPM_BIN" ]] || die "php-fpm${PHP_MM} was not found after installation."
    "$FPM_BIN" -t
fi

apache2ctl configtest
php -l "$APP_DIR/api.php" >/dev/null
systemctl daemon-reload

if [[ "$MODE" == '--enable-fpm' ]]; then
    systemctl enable --now "php${PHP_MM}-fpm"
    systemctl restart "php${PHP_MM}-fpm"
fi
systemctl restart apache2

trap - ERR
CURRENT_MPM="$(apache2ctl -V 2>/dev/null | awk -F': ' '/Server MPM/ {print $2; exit}')"
ok "High-load profile applied. Backup: $BACKUP_DIR"
report

if [[ "$MODE" == '--apply' && "${CURRENT_MPM,,}" != *event* ]]; then
    printf '\nOptional next step after a maintenance window:\n'
    printf '  sudo APP_DIR=%q bash %q --enable-fpm\n' "$APP_DIR" "$APP_DIR/tools/tune_high_load.sh"
fi
