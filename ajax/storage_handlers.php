<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  إجراءات AJAX لنظام Multi-Storage (كلها بالبادئة stg_)
 * ───────────────────────────────────────────────────────────────────────────
 *  يُدرَج من ajax/handlers.php بعد فحص CSRF وتعريف jOk/jErr، ولا يعود أبداً:
 *  كل فرع ينتهي بـ jOk أو jErr.
 *  الصلاحية: administrator أو super فقط — هذه الصفحة تستطيع تهيئة الأقراص.
 *  العمليات المدمّرة تتطلب كتابة اسم الجهاز حرفياً (تأكيد ثانٍ على الخادم،
 *  لا في المتصفح وحده)، ثم تمرّ على حراسات أداة الجذر (طبقة ثالثة).
 * ═══════════════════════════════════════════════════════════════════════════
 */
require_once dirname(__DIR__) . '/functions/storage/bootstrap.php';

if (!in_array($_SESSION['admin_role'] ?? '', ['administrator', 'super'], true)) {
    jErr('إدارة التخزين متاحة لمدير النظام فقط');
}
storageWebBase();   // يحفظ مسار الموقع ليبني العامل (CLI) روابط صحيحة

$P = static function (string $k, $d = '') { return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $d; };
$who = (string) ($_SESSION['admin_username'] ?? 'admin');

/** شكل التخزين للواجهة. */
$present = static function (array $s): array {
    $total = (int) $s['total_bytes']; $used = (int) $s['used_bytes'];
    return [
        'id' => (int) $s['id'], 'name' => $s['name'], 'content_type' => $s['content_type'],
        'device' => $s['device'], 'fs_uuid' => $s['fs_uuid'], 'fs_type' => $s['fs_type'],
        'mount_path' => $s['mount_path'], 'is_legacy' => (int) $s['is_legacy'] === 1,
        'admin_state' => $s['admin_state'], 'status' => $s['status'], 'status_msg' => (string) $s['status_msg'],
        'health' => $s['health'], 'priority' => (int) $s['priority'],
        'total' => $total, 'used' => $used, 'free' => (int) $s['free_bytes'],
        'pct' => $total ? round($used / $total * 100, 1) : 0,
        'min_free_gb' => $s['min_free_gb'], 'min_free_pct' => $s['min_free_pct'],
        'min_free_bytes' => storageMinFreeBytes($s),
        'readable' => storageIsReadable($s), 'writable' => storageIsWritable($s),
        'last_check_at' => $s['last_check_at'],
        'total_h' => storageHumanBytes($total), 'used_h' => storageHumanBytes($used), 'free_h' => storageHumanBytes((int) $s['free_bytes']),
    ];
};

/** مسار ربط سليم لتخزين جديد. */
$validMount = static function (string $mp): ?string {
    $mp = rtrim(str_replace('\\', '/', $mp), '/');
    if (!preg_match('#^/[A-Za-z0-9._/-]{2,200}$#', $mp) || strpos($mp, '/..') !== false || strpos($mp, '//') !== false) return null;
    return $mp;
};

switch ($act) {

/* ══════════════════════ إعداد العامل المجدول ══════════════════════ */
case 'stg_install':
    $r = storageInstallerRun(600);
    if (empty($r['ok'])) jErr($r['error'] ?? 'تعذّر إعداد التخزين');
    try { storageMonitorRun(false); } catch (Throwable $e) {}
    jOk(['message' => $r['message'] ?? 'تم إعداد التخزين']);

/* ═══════════════════════════ نظرة عامة ═══════════════════════════ */
case 'stg_overview':
    if ($P('refresh') === '1') {
        storageMonitorRun(false);
    } elseif ((int) storageSettings()['storage_monitor_at'] < time() - 120) {
        // لا cron بعد؟ نحدّث الحالة من الصفحة نفسها حتى لا تعرض أرقاماً قديمة
        storageRefreshAll();
        try { storageDetectDisks(); } catch (Throwable $e) {}
    }
    $list = [];
    $counts = [];
    try {
        foreach (db()->query("SELECT storage_id, COUNT(*) c, COALESCE(SUM(file_size),0) b FROM episodes WHERE storage_id IS NOT NULL GROUP BY storage_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $counts[(int) $r['storage_id']] = ['episodes' => (int) $r['c'], 'bytes' => (int) $r['b']];
        }
    } catch (Throwable $e) {}
    // التخزين المُزال لا يظهر ضمن بطاقات التخزين. تبقى سجلاته في قاعدة
    // البيانات فقط حتى لا تضيع معرفة مكان الملفات القديمة أو إمكانية استرجاعه.
    foreach (storageAll() as $s) {
        $row = $present($s);
        $row['episodes'] = $counts[(int) $s['id']]['episodes'] ?? 0;
        $row['paths'] = storagePaths((int) $s['id']);
        $list[] = $row;
    }
    $set = storageSettings(true);
    $newDisks = 0;
    try { $newDisks = (int) db()->query("SELECT COUNT(*) FROM storage_disks WHERE acknowledged = 0 AND present = 1")->fetchColumn(); } catch (Throwable $e) {}
    jOk([
        'storages' => $list,
        'alerts'   => storageAlertsActive(60),
        'jobs'     => storageJobsRecent(15),
        'helper'   => storageHelperStatus($P('refresh') === '1'),
        'settings' => [
            'alloc_mode' => $set['storage_alloc_mode'], 'default_id' => (int) $set['storage_default_id'],
            'min_free_gb' => (float) $set['storage_min_free_gb'], 'min_free_pct' => (float) $set['storage_min_free_pct'],
            'warn_pct' => (float) $set['storage_warn_pct'], 'publish_target' => $set['storage_publish_target'],
            'smart_minutes' => (int) $set['storage_smart_minutes'],
            'move_limit_mb' => (float) $set['storage_move_limit_mb'],
        ],
        'monitor_at' => (int) $set['storage_monitor_at'], 'worker_at' => (int) $set['storage_worker_at'],
        'cron_ok'    => (int) $set['storage_monitor_at'] > time() - 180,
        'php_cli'    => storagePhpCli(),
        'new_disks'  => $newDisks,
        'project_dir'=> dirname(__DIR__),
        'now'        => time(),
    ]);

/* ═══════════════════════════ الأقراص الفعلية ═══════════════════════════ */
case 'stg_disks':
    $disks = storageListDisks();
    storageDetectDisks($disks);
    $disks = storageListDisks();   // بعد التسجيل: حالة acknowledged محدّثة
    foreach ($disks as &$d) {
        $d['size_h'] = storageHumanBytes($d['size']);
        foreach ($d['partitions'] as &$p) { $p['size_h'] = storageHumanBytes($p['size']); }
        unset($p);
    }
    unset($d);
    jOk(['disks' => $disks, 'helper' => storageHelperStatus(), 'exec' => storageCanExec()]);

case 'stg_disk_ack':
    storageDiskAcknowledge((string) $P('key'));
    jOk();

case 'stg_smart_now':
    jOk(['smart' => storageSmartRefresh(null, true)]);

case 'stg_disk_init':
case 'stg_disk_wipe':
    $dev = (string) $P('device'); $confirm = (string) $P('confirm');
    if (!preg_match('#^/dev/[A-Za-z0-9]+$#', $dev)) jErr('جهاز غير صالح');
    $word = $act === 'stg_disk_init' ? 'FORMAT' : 'DELETE';
    if ($confirm !== $word . ' ' . $dev) jErr('التأكيد غير مطابق. اكتب حرفياً: ' . $word . ' ' . $dev);
    foreach (storageListDisks() as $d) {
        if ($d['path'] !== $dev) continue;
        if ($d['is_system']) jErr('مرفوض: هذا قرص النظام');
        if ($d['storage_ids']) jErr('القرص مرتبط بتخزين مسجّل — أزل التخزين من النظام أولاً (بعد نقل محتواه)');
    }
    $r = storageHelperRun([$act === 'stg_disk_init' ? 'init' : 'wipe', $dev, '--yes-destroy', $dev], 120);
    if (empty($r['ok'])) jErr($r['error'] ?? 'فشلت العملية');
    storageDeviceProbe($dev, true);
    if (!empty($r['partition'])) storageDeviceProbe((string) $r['partition'], true);
    if (function_exists('logTo')) logTo('storage', ($act === 'stg_disk_init' ? 'تهيئة جدول أقسام ' : 'حذف أقسام ') . $dev, ['by' => $who]);
    jOk($r);

case 'stg_part_format':
    $part = (string) $P('partition'); $fs = (string) $P('fs'); $label = (string) $P('label'); $confirm = (string) $P('confirm');
    if (!preg_match('#^/dev/[A-Za-z0-9]+$#', $part)) jErr('قسم غير صالح');
    if (!in_array($fs, ['ext4', 'xfs'], true)) jErr('اختر ext4 أو xfs');
    if (!preg_match('/^[A-Za-z0-9_-]{1,12}$/', $label)) jErr('التسمية: 1-12 حرفاً لاتينياً أو رقماً أو - _');
    if ($confirm !== 'FORMAT ' . $part) jErr('التأكيد غير مطابق. اكتب حرفياً: FORMAT ' . $part);
    foreach (storageListDisks() as $d) {
        foreach ($d['partitions'] as $p) {
            if ($p['path'] === $part && $p['storage_id']) jErr('هذا القسم مرتبط بالتخزين «' . $p['storage_name'] . '» — أزله أولاً');
        }
    }
    $r = storageHelperRun(['format', $part, $fs, $label, '--yes-destroy', $part], 600);
    if (empty($r['ok'])) jErr($r['error'] ?? 'فشلت التهيئة');
    storageDeviceProbe($part, true);
    if (function_exists('logTo')) logTo('storage', 'تهيئة ' . $part . ' بـ ' . $fs, ['by' => $who]);
    jOk($r);

case 'stg_suggest_mount':
    $n = 1;
    $used = array_map(static function ($s) { return rtrim((string) $s['mount_path'], '/'); }, storageAll());
    while ($n < 500 && (in_array('/mnt/storage' . $n, $used, true) || (is_dir('/mnt/storage' . $n) && count((array) @scandir('/mnt/storage' . $n)) > 2) || storageMountOf('/mnt/storage' . $n) && rtrim(storageMountOf('/mnt/storage' . $n)['target'], '/') === '/mnt/storage' . $n)) { $n++; }
    jOk(['mountpoint' => '/mnt/storage' . $n, 'index' => $n]);

case 'stg_part_mount':
    $part = (string) $P('partition'); $mp = $validMount((string) $P('mountpoint'));
    if (!preg_match('#^/dev/[A-Za-z0-9]+$#', $part)) jErr('قسم غير صالح');
    if ($mp === null) jErr('نقطة ربط غير صالحة');
    $r = storageHelperRun(['mount', $part, $mp], 60);
    if (empty($r['ok'])) jErr($r['error'] ?? 'فشل الربط');
    storageMountTable(true);
    // تخزين مسجّل على هذه النقطة؟ نعيد فحصه فيعود Online فوراً
    foreach (storageAll() as $s) { if (rtrim($s['mount_path'], '/') === $mp) { storageMountHold((int) $s['id'], false); storageRefresh($s); storageBumpRev(); } }
    jOk($r);

case 'stg_umount':
    $mp = $validMount((string) $P('mountpoint'));
    if ($mp === null) jErr('نقطة ربط غير صالحة');
    $linked = [];
    foreach (storageAll() as $s) {
        if (rtrim($s['mount_path'], '/') === $mp) $linked[] = $s;
    }
    foreach ($linked as $s) {
        if (!empty($s['is_legacy'])) jErr('لا يمكن فكّ ربط تخزين الموقع الداخلي من هنا');
    }
    // لا نفك الربط أثناء وجود نسخ/نقل. هذا يمنع قطع ملف مفتوح أو ترك مهمة
    // في حالة متناقضة، ويجعل زر Unmount عملية واحدة واضحة وآمنة.
    if ($linked) {
        try {
            $ids = array_map(static function ($s) { return (int) $s['id']; }, $linked);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $q = db()->prepare("SELECT COUNT(*) FROM storage_jobs WHERE status IN ('queued','running','cancelling') AND (source_storage_id IN ($in) OR dest_storage_id IN ($in))");
            $q->execute(array_merge($ids, $ids));
            if ((int) $q->fetchColumn() > 0) jErr('أوقف أو ألغِ مهام النقل المرتبطة بهذا القرص أولاً، ثم أعد Unmount');
        } catch (Throwable $e) { jErr('تعذّر التحقق من مهام النقل؛ لم يُنفذ فك الربط'); }
        foreach ($linked as $s) {
            if ($s['admin_state'] !== 'maintenance') {
                db()->prepare("UPDATE storages SET admin_state='maintenance', updated_at=NOW() WHERE id=?")->execute([(int) $s['id']]);
            }
        }
    }
    // بما أن العملية تزيل التخزين من الواجهة، نحذف أيضاً سطر fstab الخاص به.
    // وإلا قد يعيد النظام ربط قرص تمت تهيئته لاحقاً ببصمة قديمة عند الإقلاع.
    $r = storageHelperRun(['umount', $mp, '--forget'], 60);
    if (empty($r['ok'])) {
        foreach ($linked as $s) { storageRefresh(storageGet((int) $s['id'], true)); }
        jErr(($r['error'] ?? 'فشل فكّ الربط') . ' — بقي التخزين في وضع الصيانة لحمايته');
    }
    storageMountTable(true);
    // لا يكفي نجاح أمر الأداة وحده: قد تكون خدمة الويب في mount namespace
    // مختلف. لا نزيل التسجيل ولا نتيح Format إلا إذا اختفى الربط فعلاً من
    // نفس البيئة التي يقرأ ويكتب منها الموقع.
    if (storageMountOf($mp) !== null) {
        foreach ($linked as $s) { storageRefresh(storageGet((int) $s['id'], true)); }
        jErr('لم يكتمل فكّ الربط في بيئة الموقع؛ القرص ما زال مركّباً، لذلك لم يُزل من التخزين ولم تُتح التهيئة');
    }
    /* نجح فك الربط فعلياً: لا نبقيه كبطاقة Storage أو كقرص محجوز. إزالة
       التسجيل هنا «ناعمة»؛ لا نحذف السجل ولا نعدّل episodes، فتظل الملفات
       قابلة للاسترجاع إن أعيد القرص، لكن القرص يصبح حراً للتهيئة من إدارة
       الأقراص. */
    foreach ($linked as $s) {
        $id = (int) $s['id'];
        storageMountHold($id, true); // لا تعيده المراقبة تلقائياً
        db()->prepare("UPDATE storages SET admin_state='removed', status='offline', status_msg='فُكّ الربط وأُزيل من التخزين', updated_at=NOW() WHERE id=?")
            ->execute([$id]);
        storageAlertResolve("st:$id:down"); storageAlertResolve("st:$id:full");
        storageAlertResolve("st:$id:warn"); storageAlertResolve("st:$id:ro");
        if (function_exists('logTo')) logTo('storage', 'فكّ ربط وإزالة تخزين ' . $s['name'], ['by' => $who, 'mount_path' => $mp]);
    }
    storageBumpRev();
    jOk($r + ['removed_storage_ids' => array_map(static function ($s) { return (int) $s['id']; }, $linked)]);

/* ═══════════════════════════ التخزين ═══════════════════════════ */
case 'stg_save':
    $id   = (int) $P('id');
    $name = mb_substr(strip_tags((string) $P('name')), 0, 100);
    $type = (string) $P('content_type', 'mixed');
    if ($name === '') jErr('اكتب اسماً للتخزين (مثال: Movies-01)');
    if (!in_array($type, STORAGE_TYPES, true)) jErr('النوع: movies أو series أو mixed');
    $minGb  = $P('min_free_gb')  === '' ? null : max(0, (float) $P('min_free_gb'));
    $minPct = $P('min_free_pct') === '' ? null : max(0, min(90, (float) $P('min_free_pct')));
    $prio   = (int) $P('priority', 0);
    $pdo = db();

    if ($id > 0) {
        $s = storageGet($id, true);
        if (!$s) jErr('تخزين غير موجود');
        $mp = $s['mount_path'];
        if (!$s['is_legacy'] && (string) $P('mount_path') !== '' && $validMount((string) $P('mount_path')) !== rtrim($mp, '/')) {
            /* تغيير نقطة الربط: السجلات لا تتغيّر (مسارات نسبية)، فقط هذا الصفّ.
               نشترط أن يكون المسار الجديد مركّباً ويحوي نفس الـUUID إن كان معروفاً. */
            $new = $validMount((string) $P('mount_path'));
            if ($new === null) jErr('نقطة ربط غير صالحة');
            $m = storageMountOf($new);
            if (!$m || rtrim($m['target'], '/') !== $new) jErr('لا يوجد قرص مركّب على ' . $new . ' — اربطه أولاً');
            if ($s['fs_uuid'] && strpos($m['source'], '/dev/') === 0 && strcasecmp(storageDeviceUuid($m['source']), (string) $s['fs_uuid']) !== 0) {
                jErr('القرص المركّب على ' . $new . ' ليس قرص هذا التخزين (UUID مختلف)');
            }
            $mp = $new;
        }
        $pdo->prepare("UPDATE storages SET name=?, content_type=?, mount_path=?, min_free_gb=?, min_free_pct=?, priority=?, updated_at=NOW() WHERE id=?")
            ->execute([$name, $s['is_legacy'] ? 'mixed' : $type, $mp, $minGb, $minPct, $prio, $id]);
        storageRefresh(storageGet($id, true));
        storageBumpRev();
        jOk(['id' => $id]);
    }

    $mp = $validMount((string) $P('mount_path'));
    if ($mp === null) jErr('نقطة ربط غير صالحة');
    foreach (storageAll() as $s) {
        if (rtrim($s['mount_path'], '/') === $mp) jErr('هذا المسار مسجّل مسبقاً باسم «' . $s['name'] . '»' . ($s['admin_state'] === 'removed' ? ' (مُزال — استعده بدل إضافته من جديد)' : ''));
    }
    if (!is_dir($mp)) jErr('المسار غير موجود: ' . $mp);
    $m = storageMountOf($mp);
    if (!$m) jErr('المسار على قرص النظام نفسه وليس على قرص مستقل مركّب. اربط القرص أولاً من مدير الأقراص');
    // قرص واحد = تخزين واحد: لا نسجّل نفس نظام الملفات مرتين
    $uuid = strpos($m['source'], '/dev/') === 0 ? storageDeviceUuid($m['source']) : '';
    foreach (storageAll() as $s) {
        if ($uuid !== '' && strcasecmp((string) $s['fs_uuid'], $uuid) === 0) jErr('هذا القرص مسجّل مسبقاً باسم «' . $s['name'] . '»');
    }
    if (!is_writable($mp)) {
        $r = storageHelperRun(['chown', rtrim($m['target'], '/') === $mp ? $mp : rtrim($m['target'], '/')], 30);
        clearstatcache();
        if (!is_writable($mp)) jErr('مستخدم الويب لا يستطيع الكتابة على ' . $mp . (empty($r['ok']) ? ' — ' . ($r['error'] ?? '') : ''));
    }
    $pdo->prepare("INSERT INTO storages (name, content_type, device, fs_uuid, fs_type, mount_path, is_legacy, admin_state, status, priority, min_free_gb, min_free_pct, updated_at)
                   VALUES (?,?,?,?,?,?,0,'active','offline',?,?,?,NOW())")
        ->execute([$name, $type, $m['source'], $uuid ?: null, $m['fstype'], $mp, $prio, $minGb, $minPct]);
    $id = (int) $pdo->lastInsertId();
    $defaults = ['movies' => [['الأفلام', 'movies', 'movies', 1]], 'series' => [['المسلسلات', 'series', 'series', 1]],
                 'mixed' => [['الأفلام', 'movies', 'movies', 1], ['المسلسلات', 'series', 'series', 0]]];
    $ins = $pdo->prepare("INSERT INTO storage_paths (storage_id, name, rel_path, purpose, is_default) VALUES (?,?,?,?,?)");
    foreach (array_merge($defaults[$type], [['رفع', 'uploads', 'uploads', 0]]) as $p) {
        $ins->execute([$id, $p[0], $p[1], $p[2], $p[3]]);
        @mkdir($mp . '/' . $p[1], 0775, true);
    }
    storageRefresh(storageGet($id, true));
    storageBumpRev();
    if (function_exists('logTo')) logTo('storage', 'إضافة تخزين ' . $name . ' على ' . $mp, ['by' => $who]);
    jOk(['id' => $id, 'storage' => $present(storageGet($id, true))]);

case 'stg_set_state':
    $id = (int) $P('id'); $state = (string) $P('state');
    if (!in_array($state, ['active', 'maintenance', 'readonly'], true)) jErr('حالة غير صالحة');
    $s = storageGet($id, true);
    if (!$s || $s['admin_state'] === 'removed') jErr('تخزين غير موجود');
    db()->prepare("UPDATE storages SET admin_state=?, updated_at=NOW() WHERE id=?")->execute([$state, $id]);
    storageRefresh(storageGet($id, true));
    storageBumpRev();
    jOk(['storage' => $present(storageGet($id, true))]);

case 'stg_remove_preview':
    $id = (int) $P('id');
    $s = storageGet($id, true);
    if (!$s) jErr('تخزين غير موجود');
    $st = storageContentStats($id, true);
    jOk(['stats' => $st + ['used_h' => storageHumanBytes((int) $s['used_bytes']), 'bytes_db_h' => storageHumanBytes($st['bytes_db'])], 'storage' => $present($s)]);

case 'stg_remove':
    $id = (int) $P('id');
    $s = storageGet($id, true);
    if (!$s) jErr('تخزين غير موجود');
    if ($s['is_legacy']) jErr('التخزين الداخلي (uploads) جزء من الموقع ولا يُزال — انقل محتواه إلى الأقراص فقط');
    if ($P('confirm') !== 'REMOVE ' . $s['name']) jErr('التأكيد غير مطابق. اكتب حرفياً: REMOVE ' . $s['name']);
    $st = storageContentStats($id);
    if ($st['episodes'] > 0 && $P('force') !== '1') {
        jErr('على هذا التخزين ' . $st['episodes'] . ' ملف مرتبط بأفلام ومسلسلات. انقلها أولاً، أو فعّل «إزالة رغم وجود محتوى» — وسيصبح ذلك المحتوى غير متاح');
    }
    /* إزالة ناعمة: الصفّ يبقى بحالة removed فتبقى روابط المحتوى قابلة للاستعادة
       إن أُعيد القرص. لا يُمسّ أي ملف على القرص ولا تُحذف أي حلقة. */
    db()->prepare("UPDATE storages SET admin_state='removed', status='offline', status_msg='أُزيل من النظام', updated_at=NOW() WHERE id=?")->execute([$id]);
    storageAlertResolve("st:$id:down"); storageAlertResolve("st:$id:full"); storageAlertResolve("st:$id:warn"); storageAlertResolve("st:$id:ro");
    storageBumpRev();
    if (function_exists('logTo')) logTo('storage', 'إزالة تخزين ' . $s['name'], ['by' => $who, 'episodes' => $st['episodes']]);
    jOk(['removed' => $id, 'orphaned' => $st['episodes']]);

case 'stg_restore':
    $id = (int) $P('id');
    $s = storageGet($id, true);
    if (!$s || $s['admin_state'] !== 'removed') jErr('لا يوجد تخزين مُزال بهذا المعرّف');
    db()->prepare("UPDATE storages SET admin_state='active', updated_at=NOW() WHERE id=?")->execute([$id]);
    storageRefresh(storageGet($id, true));
    storageBumpRev();
    jOk(['storage' => $present(storageGet($id, true))]);

/* ═══════════════════════════ المسارات ═══════════════════════════ */
case 'stg_path_save':
    $sid = (int) $P('storage_id'); $pid = (int) $P('id');
    $s = storageGet($sid, true);
    if (!$s || $s['admin_state'] === 'removed') jErr('تخزين غير موجود');
    $rel = storagePathNormalize((string) $P('rel_path'));
    if ($rel === null) jErr('مسار غير صالح — أحرف وأرقام و . _ - ومسافات فقط، بلا ..');
    $name = mb_substr(strip_tags((string) $P('name')), 0, 100) ?: $rel;
    $purpose = (string) $P('purpose', 'other');
    if (!in_array($purpose, STORAGE_PURPOSES, true)) $purpose = 'other';
    $pdo = db();
    $dup = $pdo->prepare("SELECT id FROM storage_paths WHERE storage_id=? AND rel_path=? AND id<>?");
    $dup->execute([$sid, $rel, $pid]);
    if ($dup->fetchColumn()) jErr('هذا المسار موجود مسبقاً في هذا التخزين');
    if ($pid > 0) {
        $old = $pdo->prepare("SELECT * FROM storage_paths WHERE id=? AND storage_id=?"); $old->execute([$pid, $sid]);
        $o = $old->fetch(PDO::FETCH_ASSOC);
        if (!$o) jErr('مسار غير موجود');
        if ($o['rel_path'] !== $rel) {
            // إعادة تسمية مجلد فيه ملفات مرتبطة تكسر روابطها — نمنعها صراحة
            $c = $pdo->prepare("SELECT COUNT(*) FROM episodes WHERE storage_id=? AND relative_path LIKE ?");
            $c->execute([$sid, str_replace(['%', '_'], ['\\%', '\\_'], $o['rel_path']) . '/%']);
            if ((int) $c->fetchColumn() > 0) jErr('في هذا المسار ملفات مرتبطة بأفلام/مسلسلات — لا يمكن تغيير مساره. أنشئ مساراً جديداً وانقل المحتوى إليه');
        }
        $pdo->prepare("UPDATE storage_paths SET name=?, rel_path=?, purpose=? WHERE id=?")->execute([$name, $rel, $purpose, $pid]);
    } else {
        $pdo->prepare("INSERT INTO storage_paths (storage_id, name, rel_path, purpose, is_default) VALUES (?,?,?,?,0)")->execute([$sid, $name, $rel, $purpose]);
        $pid = (int) $pdo->lastInsertId();
    }
    if ($P('is_default') === '1') {
        $pdo->prepare("UPDATE storage_paths SET is_default = (id = ?) WHERE storage_id = ?")->execute([$pid, $sid]);
    }
    $made = storageEnsureDir($s, $rel);
    jOk(['id' => $pid, 'dir_created' => $made, 'paths' => storagePaths($sid)]);

case 'stg_path_delete':
    $pid = (int) $P('id');
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM storage_paths WHERE id=?"); $st->execute([$pid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) jErr('مسار غير موجود');
    $c = $pdo->prepare("SELECT COUNT(*) FROM episodes WHERE storage_id=? AND relative_path LIKE ?");
    $c->execute([(int) $p['storage_id'], str_replace(['%', '_'], ['\\%', '\\_'], $p['rel_path']) . '/%']);
    $n = (int) $c->fetchColumn();
    if ($n > 0) jErr('في هذا المسار ' . $n . ' ملف مرتبط — انقله أولاً');
    $pdo->prepare("DELETE FROM storage_paths WHERE id=?")->execute([$pid]);
    // المجلد يُحذف فقط إن كان فارغاً فعلاً
    $s = storageGet((int) $p['storage_id'], true);
    $removed = false;
    if ($s && empty($s['is_legacy'])) { $abs = storageAbs($s, (string) $p['rel_path']); if ($abs && is_dir($abs)) $removed = @rmdir($abs); }
    jOk(['dir_removed' => $removed, 'paths' => storagePaths((int) $p['storage_id'])]);

/* ═══════════════════════════ المحتوى ═══════════════════════════ */
case 'stg_content':
    $sid = (int) $P('storage_id');
    $q = (string) $P('q'); $page = max(1, (int) $P('page', 1)); $per = 30;
    $where = 'e.storage_id = ?'; $args = [$sid];
    if ($q !== '') { $where .= ' AND (s.name LIKE ? OR e.title LIKE ? OR e.relative_path LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like); }
    $pdo = db();
    $c = $pdo->prepare("SELECT COUNT(*) FROM episodes e LEFT JOIN series s ON s.id = e.series_id WHERE $where"); $c->execute($args);
    $total = (int) $c->fetchColumn();
    $st = $pdo->prepare("SELECT e.id, e.series_id, e.title, e.episode_number, e.relative_path, e.file_size, e.stream_url, s.name AS series_name,
                                (SELECT COUNT(*) FROM episodes e2 WHERE e2.series_id = e.series_id) AS ep_count
                           FROM episodes e LEFT JOIN series s ON s.id = e.series_id
                          WHERE $where ORDER BY s.name, e.episode_number, e.id LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $s = storageGet($sid, true);
    foreach ($rows as &$r) {
        $abs = $s ? storageAbs($s, (string) $r['relative_path']) : null;
        $r['exists'] = $abs && is_file($abs);
        if (!$r['file_size'] && $r['exists']) $r['file_size'] = (int) @filesize($abs);
        $r['size_h'] = storageHumanBytes((int) $r['file_size']);
        $r['kind'] = (int) $r['ep_count'] <= 1 ? 'movie' : 'series';
    }
    unset($r);
    jOk(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $per)]);

case 'stg_series_search':
    $sid = (int) $P('storage_id'); $q = '%' . (string) $P('q') . '%';
    $st = db()->prepare("SELECT s.id, s.name, COUNT(e.id) AS files, COALESCE(SUM(e.file_size),0) AS bytes,
                                (SELECT COUNT(*) FROM episodes e2 WHERE e2.series_id = s.id) AS ep_total
                           FROM episodes e JOIN series s ON s.id = e.series_id
                          WHERE e.storage_id = ? AND s.name LIKE ? GROUP BY s.id ORDER BY s.name LIMIT 40");
    $st->execute([$sid, $q]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['kind'] = (int) $r['ep_total'] <= 1 ? 'movie' : 'series'; $r['bytes_h'] = storageHumanBytes((int) $r['bytes']); }
    unset($r);
    jOk(['rows' => $rows]);

case 'stg_folders':
    $sid = (int) $P('storage_id');
    $s = storageGet($sid, true);
    if (!$s) jErr('تخزين غير موجود');
    $out = [];
    $root = realpath((string) $s['mount_path']);
    if ($root && is_dir($root) && ($s['is_legacy'] || storageMountOf($root))) {
        foreach ((array) @scandir($root) as $a) {
            if ($a === '.' || $a === '..' || $a === 'lost+found' || !is_dir($root . '/' . $a)) continue;
            $out[] = $a;
            foreach ((array) @scandir($root . '/' . $a) as $b) {
                if ($b === '.' || $b === '..' || !is_dir($root . '/' . $a . '/' . $b)) continue;
                $out[] = $a . '/' . $b;
                if (count($out) > 400) break 2;
            }
        }
    }
    jOk(['folders' => $out]);

case 'stg_content_delete':
    /* حذف فيلم/حلقة مع ملفه — نفس ترتيب delete_video: السجل أولاً ثم الملف،
       ثم العمل إن أفرغه الحذف. */
    $eid = (int) $P('episode_id');
    $pdo = db();
    $st = $pdo->prepare("SELECT id, series_id, storage_id, relative_path FROM episodes WHERE id=?"); $st->execute([$eid]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) jErr('غير موجود');
    $pdo->prepare("DELETE FROM episodes WHERE id=?")->execute([$eid]);
    $left = $pdo->prepare("SELECT COUNT(*) FROM episodes WHERE series_id=?"); $left->execute([(int) $e['series_id']]);
    $seriesGone = false;
    if ((int) $left->fetchColumn() === 0 && $P('drop_empty_series', '1') === '1') {
        $pdo->prepare("DELETE FROM series WHERE id=?")->execute([(int) $e['series_id']]); $seriesGone = true;
    }
    $fileGone = false;
    if ($e['storage_id'] && $e['relative_path']) {
        $still = $pdo->prepare("SELECT COUNT(*) FROM episodes WHERE storage_id=? AND relative_path=?");
        $still->execute([(int) $e['storage_id'], $e['relative_path']]);
        if ((int) $still->fetchColumn() === 0) $fileGone = storageDeleteFile((int) $e['storage_id'], (string) $e['relative_path']);
    }
    if (function_exists('cacheDelete')) cacheDelete('content_stamp');
    jOk(['file_deleted' => $fileGone, 'series_deleted' => $seriesGone]);

/* ═══════════════════════════ النقل ═══════════════════════════ */
case 'stg_move_create':
    $r = storageJobCreate([
        'source_storage_id' => (int) $P('source'), 'dest_storage_id' => (int) $P('dest'),
        'dest_path_id' => (int) $P('dest_path_id'), 'scope' => (string) $P('scope', 'all'),
        'scope_ref' => (string) $P('scope_ref'), 'scope_label' => (string) $P('scope_label'),
        'remove_source_after' => $P('remove_source_after') === '1', 'created_by' => $who, 'type' => 'move',
    ]);
    if (empty($r['ok'])) jErr($r['error']);
    $kicked = storageWorkerKick();
    jOk($r + ['worker_started' => $kicked, 'job' => storageJobGet((int) $r['job_id'])]);

case 'stg_jobs':
    $jobs = storageJobsRecent((int) $P('limit', 15) ?: 15);
    foreach ($jobs as &$j) {
        $j['pct'] = (int) $j['total_bytes'] > 0 ? round($j['done_bytes'] / $j['total_bytes'] * 100, 1) : ((int) $j['total_files'] ? round($j['done_files'] / $j['total_files'] * 100, 1) : 0);
        $j['done_h'] = storageHumanBytes((int) $j['done_bytes']); $j['total_h'] = storageHumanBytes((int) $j['total_bytes']);
        $j['stale'] = $j['status'] === 'running' && $j['heartbeat_at'] && strtotime($j['heartbeat_at']) < time() - 120;
    }
    unset($j);
    // مهمة منتظرة ولا عامل يعمل؟ نحاول تشغيله من هنا
    foreach ($jobs as $j) { if ($j['status'] === 'queued' || $j['stale']) { storageWorkerKick(); break; } }
    jOk(['jobs' => $jobs, 'worker_at' => (int) storageSettings(true)['storage_worker_at']]);

case 'stg_job_items':
    $st = db()->prepare("SELECT id, episode_id, src_rel, dst_rel, bytes, status, error FROM storage_job_items WHERE job_id=? ORDER BY FIELD(status,'failed','copying','verifying','pending','done'), id LIMIT 300");
    $st->execute([(int) $P('job_id')]);
    jOk(['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);

case 'stg_job_cancel':
    if (!storageJobCancel((int) $P('job_id'))) jErr('المهمة ليست قيد الانتظار أو التشغيل');
    jOk();

case 'stg_job_retry':
    $jid = (int) $P('job_id');
    $pdo = db();
    $pdo->prepare("UPDATE storage_job_items SET status='pending', error=NULL WHERE job_id=? AND status='failed'")->execute([$jid]);
    $pdo->prepare("UPDATE storage_jobs SET status='queued', failed_files=0, finished_at=NULL, error=NULL WHERE id=? AND status IN ('failed','partial','cancelled')")->execute([$jid]);
    storageWorkerKick();
    jOk();

/* ═══════════════════════════ الإعدادات والتنبيهات ═══════════════════════════ */
case 'stg_settings_save':
    // يختار النظام دائماً القرص، ولا نقبل قرصاً افتراضياً أو اختياراً من الواجهة.
    storageSettingSet('storage_alloc_mode', 'auto');
    storageSettingSet('storage_default_id', '0');
    storageSettingSet('storage_min_free_gb', (string) max(0, (float) $P('min_free_gb', 20)));
    storageSettingSet('storage_min_free_pct', (string) max(0, min(90, (float) $P('min_free_pct', 5))));
    storageSettingSet('storage_warn_pct', (string) max(50, min(99, (float) $P('warn_pct', 90))));
    storageSettingSet('storage_publish_target', 'auto');
    storageSettingSet('storage_smart_minutes', (string) max(5, (int) $P('smart_minutes', 30)));
    if ($P('move_limit_mb') !== '') storageSettingSet('storage_move_limit_mb', (string) max(0, (float) $P('move_limit_mb')));
    storageRefreshAll();
    jOk();

case 'stg_pick_preview':
    storageRefreshAll();
    $r = storagePick((string) $P('kind', 'movies'), (int) ((float) $P('size_gb', 0) * 1073741824), (int) $P('manual') ?: null);
    jOk(['ok_pick' => $r['ok'], 'reason' => $r['reason'], 'storage' => $r['storage'] ? $present($r['storage']) : null,
         'order' => array_map(static function ($s) { return ['id' => (int) $s['id'], 'name' => $s['name'], 'pct' => round($s['_used_pct'], 1)]; }, $r['candidates'])]);

case 'stg_alert_read':
    $id = (int) $P('id');
    if ($id > 0) db()->prepare("UPDATE storage_alerts SET is_read=1 WHERE id=?")->execute([$id]);
    else db()->exec("UPDATE storage_alerts SET is_read=1 WHERE is_active=1");
    jOk();

case 'stg_alert_dismiss':
    // كل نسخ التنبيه نفسه (نفس المفتاح) — لا تبقى نسخة مكررة بعد الإغلاق
    db()->prepare("UPDATE storage_alerts a JOIN (SELECT alert_key FROM storage_alerts WHERE id=?) k ON k.alert_key = a.alert_key
                   SET a.is_active=0, a.resolved_at=NOW() WHERE a.is_active=1")->execute([(int) $P('id')]);
    jOk();

case 'stg_remount':
    $s = storageGet((int) $P('id'), true);
    if (!$s || $s['admin_state'] === 'removed') jErr('تخزين غير موجود');
    $r = storageRemount($s);
    if (empty($r['ok'])) jErr($r['error'] ?? 'فشل الربط');
    storageRefresh(storageGet((int) $s['id'], true));
    storageBumpRev();
    jOk(['storage' => $present(storageGet((int) $s['id'], true)), 'already' => !empty($r['already'])]);

case 'stg_monitor_now':
    $r = storageMonitorRun($P('smart') === '1');
    jOk(['result' => $r]);

default:
    jErr('إجراء تخزين غير معروف: ' . $act);
}
