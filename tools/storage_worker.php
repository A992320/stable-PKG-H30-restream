<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  عامل Multi-Storage (سطر الأوامر فقط)
 * ───────────────────────────────────────────────────────────────────────────
 *    php tools/storage_worker.php run       ينفّذ مهام النقل المعلّقة حتى تنتهي
 *    php tools/storage_worker.php monitor   فحص الأقراص + الكشف + SMART + التنبيهات
 *    php tools/storage_worker.php cron      الاثنان معاً بميزانية 50 ثانية (لـ cron كل دقيقة)
 *    php tools/storage_worker.php status    ملخّص نصّي
 *
 *  cron (يثبّته tools/install_storage.sh تلقائياً):
 *    * * * * * www-data php /path/to/tools/storage_worker.php cron
 * ═══════════════════════════════════════════════════════════════════════════
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

@set_time_limit(0);
@ini_set('memory_limit', '256M');
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/functions/storage/bootstrap.php';

$cmd = $argv[1] ?? 'cron';
$log = static function ($m) { fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $m . PHP_EOL); };

switch ($cmd) {
    case 'run':
        $n = storageWorkerRun(0, $log);
        $log("نُقل $n ملف");
        break;

    case 'monitor':
        $r = storageMonitorRun(in_array('--smart', $argv, true));
        $log('مراقبة: تغيّر الحالة=' . (!empty($r['storages']['changed']) ? 'نعم' : 'لا') . ' | أقراص جديدة=' . $r['new_disks']);
        break;

    case 'cron':
        $r = storageMonitorRun(false);
        storageWorkerRun(50, $log);
        break;

    case 'status':
        foreach (storageAll() as $s) {
            $pct = $s['total_bytes'] ? round($s['used_bytes'] / $s['total_bytes'] * 100, 1) : 0;
            $log(sprintf('#%d %-28s %-11s %6s%%  %s', $s['id'], $s['name'], $s['status'], $pct, $s['mount_path']));
        }
        foreach (storageAlertsActive(20) as $a) { $log('[' . $a['level'] . '] ' . $a['message']); }
        break;

    default:
        fwrite(STDERR, "أمر غير معروف: $cmd\n");
        exit(1);
}
