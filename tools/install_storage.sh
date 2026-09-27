#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
#  تثبيت نظام Multi-Storage — مرة واحدة على السيرفر
#    sudo bash tools/install_storage.sh [--web-user www-data] [--allow-loop]
# ───────────────────────────────────────────────────────────────────────────
#  ما يفعله بالضبط (ولا شيء غيره):
#    1) ينسخ tools/shs-storage-helper إلى /usr/local/sbin (root:root 0755)
#    2) يكتب /etc/shs-storage.conf (مجلدات الربط المسموحة ومستخدم الويب)
#    3) سطر sudoers واحد يسمح لمستخدم الويب بتشغيل تلك الأداة وحدها
#       (يُفحص بـ visudo قبل التفعيل؛ ملف معيب لا يُكتب أبداً)
#    4) cron كل دقيقة للمراقبة ونقل الملفات
#    5) الحزم الناقصة فقط: parted smartmontools xfsprogs gdisk
#  لا يلمس أي قرص. لا يهيّئ ولا يربط شيئاً.
# ═══════════════════════════════════════════════════════════════════════════
set -e
[ "$(id -u)" -eq 0 ] || { echo "شغّله بـ sudo"; exit 1; }

DIR="$(cd "$(dirname "$0")/.." && pwd)"
WEB_USER="www-data"
ALLOW_LOOP=0
while [ $# -gt 0 ]; do
    case "$1" in
        --web-user) WEB_USER="$2"; shift 2 ;;
        --allow-loop) ALLOW_LOOP=1; shift ;;
        *) echo "خيار غير معروف: $1"; exit 1 ;;
    esac
done
id "$WEB_USER" >/dev/null 2>&1 || { echo "المستخدم $WEB_USER غير موجود (حدّد --web-user)"; exit 1; }

echo "▶ الحزم"
MISSING=""
for pair in parted:parted smartctl:smartmontools mkfs.xfs:xfsprogs sgdisk:gdisk; do
    bin=${pair%%:*}; pkg=${pair##*:}
    command -v "$bin" >/dev/null 2>&1 || MISSING="$MISSING $pkg"
done
# عامل التخزين يعمل من cron عبر PHP CLI. في التثبيت الأول قد توجد PHP
# للويب فقط أو لا توجد نسخة CLI في PATH، لذلك نثبت الحزمة المناسبة هنا.
command -v php >/dev/null 2>&1 || MISSING="$MISSING php-cli"
if [ -n "$MISSING" ]; then
    if command -v apt-get >/dev/null 2>&1; then DEBIAN_FRONTEND=noninteractive apt-get install -y $MISSING
    elif command -v dnf >/dev/null 2>&1; then dnf install -y $MISSING
    else echo "  ثبّت يدوياً:$MISSING"; fi
fi

echo "▶ أداة الجذر"
install -o root -g root -m 0755 "$DIR/tools/shs-storage-helper" /usr/local/sbin/shs-storage-helper

# زر «إعداد التخزين» يعيد تشغيل هذا المثبّت من لوحة المدير فقط. قبل إضافة
# الاستثناء المحدد في sudo نتأكد أن ملفات المصدر مملوكة لـroot ولا يستطيع
# مستخدم الويب تعديلها؛ لا توجد هنا صلاحية sudo عامة أو أمر من المتصفح.
chown root:root "$DIR/tools/install_storage.sh" "$DIR/tools/shs-storage-helper"
chmod 0750 "$DIR/tools/install_storage.sh" "$DIR/tools/shs-storage-helper"

echo "▶ الإعداد"
if [ ! -f /etc/shs-storage.conf ]; then
    cat > /etc/shs-storage.conf <<EOF
# مجلدات الربط المسموحة لأقراص التخزين (مفصولة بمسافات، تنتهي بـ /)
MOUNT_PREFIXES="/mnt/ /srv/storage/ /media/storage/"
# مستخدم خادم الويب — يملك جذر كل قرص تخزين ليكتب PHP عليه
WEB_USER="$WEB_USER"
# أجهزة loop (للاختبار فقط) — اتركه 0 على سيرفر حقيقي
ALLOW_LOOP=$ALLOW_LOOP
EOF
fi
chmod 0644 /etc/shs-storage.conf

echo "▶ sudoers"
TMP=$(mktemp)
printf '%s ALL=(root) NOPASSWD: /usr/local/sbin/shs-storage-helper, /bin/bash %s --web-user %s\n' "$WEB_USER" "$DIR/tools/install_storage.sh" "$WEB_USER" > "$TMP"
if visudo -cf "$TMP" >/dev/null; then
    install -o root -g root -m 0440 "$TMP" /etc/sudoers.d/shs-storage
else
    echo "  ✗ سطر sudoers لم يجتز الفحص — لم يُكتب شيء"; rm -f "$TMP"; exit 1
fi
rm -f "$TMP"

echo "▶ cron"
PHP=$(command -v php || true)
if [ -n "$PHP" ]; then
    echo "* * * * * $WEB_USER $PHP $DIR/tools/storage_worker.php cron >/dev/null 2>&1" > /etc/cron.d/shs-storage
    chmod 0644 /etc/cron.d/shs-storage
else
    echo "  ✗ php (سطر الأوامر) غير موجود — ثبّت php-cli ثم أعد التشغيل"
fi

echo "▶ فحص"
sudo -u "$WEB_USER" sudo -n /usr/local/sbin/shs-storage-helper version || exit 1

# أقراص رُبطت بنسخة قديمة من الأداة من داخل خدمة الويب بقيت محبوسة في فضائها
# (يراها الموقع مركّبة ويراها النظام وcron غير مركّبة). المراقبة تعيد ربطها الآن
# في فضاء النظام ببصمتها (mount فقط — لا تهيئة ولا حذف).
if [ -n "$PHP" ]; then
    echo "▶ إعادة ربط أقراص التخزين الغائبة (إن وُجدت)"
    sudo -u "$WEB_USER" "$PHP" "$DIR/tools/storage_worker.php" monitor 2>&1 | tail -n 8 || true
fi
echo "✔ تم — افتح لوحة الإدارة ← إدارة التخزين"
