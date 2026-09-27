<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  media.php — بثّ الملفات من أقراص Multi-Storage
 * ───────────────────────────────────────────────────────────────────────────
 *    /media.php/<storage_id>/<relative/path/file.mp4>
 *    /media.php?s=<storage_id>&p=<relative/path>          (احتياطي بلا PATH_INFO)
 *
 *  الأقراص الجديدة خارج مجلد الموقع (/mnt/storageN)، فلا يخدمها Apache
 *  مباشرة. هذه البوابة:
 *    • تحلّ المسار عبر جدول storages ⇒ تغيير نقطة الربط لا يغيّر أي رابط.
 *    • تدعم Range/206 (التقديم والتأخير في المشغّل) وHEAD وETag.
 *    • قرص Offline/Error/Maintenance ⇒ 503 لمحتواه وحده؛ الباقي يعمل.
 *    • تمنع الخروج من جذر القرص (.. والروابط الرمزية).
 *    • قرص غير مركّب فعلياً ⇒ 503 حتى لو لم تكتشفه المراقبة بعد.
 *  X-Sendfile اختياري (SHS_MEDIA_XSENDFILE=1 في .env + mod_xsendfile مع
 *  XSendFilePath لكل نقطة ربط) لإعفاء PHP من حمل البايتات.
 * ═══════════════════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/core/config.php';
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }   // لا قفل جلسة أثناء بثّ طويل
require_once __DIR__ . '/functions/storage/bootstrap.php';

while (ob_get_level()) { ob_end_clean(); }
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function mediaFail(int $code, string $msg, int $retry = 0): void
{
    http_response_code($code);
    if ($retry) header('Retry-After: ' . $retry);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') mediaFail(405, 'Method not allowed');

// ── المعرّف والمسار ──
$sid = 0; $rel = '';
$pi = (string) ($_SERVER['PATH_INFO'] ?? '');
if ($pi !== '' && preg_match('#^/(\d+)/(.+)$#', $pi, $m)) {
    $sid = (int) $m[1]; $rel = $m[2];
} else {
    $sid = (int) ($_GET['s'] ?? 0); $rel = (string) ($_GET['p'] ?? '');
}
$rel = str_replace('\\', '/', rawurldecode($rel));
if ($sid <= 0 || !storageRelSafe($rel)) mediaFail(400, 'Bad request');

$s = storageGet($sid);
if (!$s || $s['admin_state'] === 'removed') mediaFail(404, 'Not found');
if (!storageIsReadable($s)) {
    mediaFail(503, 'هذا المحتوى غير متاح مؤقتاً: قرص التخزين «' . $s['name'] . '» ' . $s['status'], 120);
}
if (empty($s['is_legacy']) && !storageMountOf((string) $s['mount_path'])) {
    mediaFail(503, 'قرص التخزين غير مركّب حالياً', 120);
}

$file = storageAbsExistingInside($s, $rel);
if ($file === null || !is_file($file) || !is_readable($file)) mediaFail(404, 'Not found');

/* انتهى كل عمل قاعدة البيانات. تنزيل فيلم طويل لا يجب أن يحتفظ باتصال
   MySQL محجوزاً حتى ينتهي المستخدم أو يغلق الصفحة. */
if (isset($pdo) && $pdo instanceof PDO) {
    $pdo = null;
}

// ── الرؤوس ──
$size  = (int) filesize($file);
$mtime = (int) filemtime($file);
$etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';
$ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mkv' => 'video/x-matroska', 'webm' => 'video/webm',
    'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo', 'ts' => 'video/mp2t', 'm2ts' => 'video/mp2t',
    'flv' => 'video/x-flv', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg', 'wmv' => 'video/x-ms-wmv',
    'm3u8' => 'application/vnd.apple.mpegurl', 'vtt' => 'text/vtt; charset=utf-8', 'srt' => 'application/x-subrip',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png', 'webp' => 'image/webp',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Accept-Ranges: bytes');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag && empty($_SERVER['HTTP_RANGE'])) {
    http_response_code(304);
    exit;
}

// ── Range ──
$start = 0; $end = $size - 1; $partial = false;
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
$ifRange = trim((string) ($_SERVER['HTTP_IF_RANGE'] ?? ''));
if ($range !== '' && ($ifRange === '' || $ifRange === $etag)) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $rm) || ($rm[1] === '' && $rm[2] === '')) {
        header('Content-Range: bytes */' . $size);
        mediaFail(416, 'Range Not Satisfiable');
    }
    if ($rm[1] === '') {                      // bytes=-500 ⇒ آخر 500 بايت
        $start = max(0, $size - (int) $rm[2]);
    } else {
        $start = (int) $rm[1];
        if ($rm[2] !== '') $end = min((int) $rm[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        mediaFail(416, 'Range Not Satisfiable');
    }
    $partial = true;
}
$length = $end - $start + 1;

// X-Sendfile: Apache يرسل الملف بنفسه (يدعم Range تلقائياً)
if (function_exists('env') && (string) env('SHS_MEDIA_XSENDFILE', '0') === '1') {
    header('X-Sendfile: ' . $file);
    exit;
}

if ($partial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . $length);
if ($method === 'HEAD') exit;

@set_time_limit(0);
ignore_user_abort(false);
$fp = @fopen($file, 'rb');
if (!$fp) mediaFail(500, 'Read error');
if ($start > 0) fseek($fp, $start);
$left = $length;
while ($left > 0 && !feof($fp)) {
    $buf = fread($fp, (int) min(1048576, $left));
    if ($buf === false || $buf === '') break;
    echo $buf;
    $left -= strlen($buf);
    flush();
    if (connection_aborted()) break;
}
fclose($fp);
exit;
