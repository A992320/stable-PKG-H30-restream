<?php
/**
 * API للتطبيق - Shashety IPTV
 * نقطة النهاية الرئيسية للبيانات
 * -------------------------------------------------------------
 * جميع الـ Endpoints والأسماء والحقول المُرجَعة محفوظة 100%:
 *   categories, channels, channel, search, featured, stats,
 *   increment_view, all_content, series, episodes, content_version
 *
 * التحسينات:
 *   • إزالة CREATE TABLE من الـAPI (انتقلت إلى install.php/migration.php)
 *   • Rate Limiting لكل نقطة
 *   • كاش + ETag + Compression + Cache-Control
 *   • Prepared Statements في كل مكان + تحقق من المدخلات
 *   • Pagination بحدود آمنة
 *   • تقليل الاستعلامات المكررة (COUNT عبر window function مع بديل)
 *   • رؤوس أمان كاملة
 *
 * @package Shashety\Api
 */

declare(strict_types=1);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/functions/stream_token.php';
require_once __DIR__ . '/functions/home_groups.php'; // مجموعات الصفحة الرئيسية (Home Groups)
require_once __DIR__ . '/functions/storage/bootstrap.php'; // Multi-Storage: تعطيل محتوى القرص غير المتاح وحده

// ══════════════════════════════════════════════════════════════
// رؤوس CORS ورؤوس الأمان
// ══════════════════════════════════════════════════════════════
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, If-None-Match');
header('Access-Control-Expose-Headers: ETag, X-Content-Version, X-Cache');
header('Access-Control-Max-Age: 86400');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (IS_HTTPS) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// التعامل مع طلبات OPTIONS
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// ══════════════════════════════════════════════════════════════
// ضغط الاستجابة (Compression) — يقلل زمن النقل بشكل كبير
// ══════════════════════════════════════════════════════════════
if (!ob_get_level()
    && extension_loaded('zlib')
    && !ini_get('zlib.output_compression')
    && strpos(strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip') !== false
) {
    @ob_start('ob_gzhandler');
} elseif (!ob_get_level()) {
    @ob_start();
}

// ══════════════════════════════════════════════════════════════
// حدّ المعدل العام — حماية الـAPI من الإساءة
// ══════════════════════════════════════════════════════════════
/* رُفع من 300 إلى 3000/60ث: البحث الشامل في الواجهة (_shsEnsureAllContent)
   يجلب كل قنوات/مسلسلات كل الأقسام مرة واحدة لبناء فهرس بحث محلي — على
   مكتبة كبيرة بعد استيراد أكثر من حساب Xtream (مئات إلى بضعة آلاف من
   الأقسام مجتمعة) قد يعني ذلك آلاف الطلبات المشروعة من نفس المتصفح خلال
   ثوانٍ قليلة. 300 كانت تُستهلَك بالكامل من هذا التحميل وحده، فيصطدم أي
   طلب آخر (حتى all_content العادي) بـ429 — وهذا العطل المُبلَّغ عنه.
   3000/60ث (~50 طلباً/ث) يبقي حماية حقيقية من إساءة آلية مستمرة، ويترك
   هامشاً فوق تحميل شامل واحد حتى لمكتبة ضخمة (٢٠٠٠ طلب لألف قسم في
   اختبار محاكاة) كي يبقى تصفّح طبيعي متزامن ممكناً أثناء ذلك التحميل. */
if (!rateLimit('api:global:v2', 3000, 60)) {
    header('Retry-After: 60');
    jsonResponse([
        'success' => false,
        'error'   => 'تم تجاوز الحد المسموح من الطلبات. حاول بعد قليل.',
    ], 429);
}

// ══════════════════════════════════════════════════════════════
// الحصول على الإجراء المطلوب
// ══════════════════════════════════════════════════════════════
$action = sanitizeInput($_GET['action'] ?? '');

// ══════════════════════════════════════════════════════════════
// حارس الاشتراك — يمنع سحب المحتوى مباشرةً من الـAPI عند تفعيل
// حماية الصفحة الرئيسية. بدونه تكون البوابة واجهةً فقط.
// ══════════════════════════════════════════════════════════════
require_once __DIR__ . '/functions/api_subscriber_guard.php';
apiSubscriberGuard($action);

/* لا نحتفظ بقفل ملف الجلسة أثناء استعلامات API.
   عند إيقاف حماية المشتركين كان الحارس يعود مبكراً والجلسة تبقى
   مفتوحة حتى نهاية الطلب، فتتصطف طلبات المتصفح الواحد بدلاً من أن
   تُنفّذ بالتوازي. بعد هذه النقطة لا يكتب api.php أي قيمة في الجلسة. */
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}

// تحديد الإجراءات المسموح بها
// ── أُضيفت: all_content, series, episodes ──
$allowedActions = [
    'categories',
    'channels',
    'channel',
    'search',
    'featured',
    'stats',
    'increment_view',
    'all_content',
    'notification_state',
    'series',
    'episodes',
    'content_version',
    // مجموعات الصفحة الرئيسية — كانت مفقودة من القائمة البيضاء
    // رغم وجود case لها في switch، فكان الطلب يُرفض قبل أن يصلها.
    'home_groups',
    // ── نظام الترجمات (OpenSubtitles) ──
    'episode_subtitle',
    'subtitle_options',
    'subtitle_download',
];

if (!in_array($action, $allowedActions, true)) {
    jsonResponse([
        'success' => false,
        'error'   => 'إجراء غير صالح',
    ], 400);
}

// ══════════════════════════════════════════════════════════════
// دوال البنية التحتية للاستجابة (كاش + ETag)
// ══════════════════════════════════════════════════════════════

/**
 * استبدال كل روابط البث في الاستجابة بروابط موقَّعة منتهية الصلاحية.
 *
 * يمرّ على البنية كاملةً مهما كان عمقها، فيغطّي:
 *   channels[], series[], episodes[], channel{}, results[], featured[] …
 *
 * @param mixed $node عقدة من بنية الاستجابة.
 * @return mixed العقدة بعد المعالجة.
 */
function apiProtectStreamUrls($node)
{
    static $enabled = null;
    static $base    = null;

    if ($enabled === null) {
        $enabled = function_exists('streamProtectionEnabled') && streamProtectionEnabled();
        // مسار القاعدة حتى يعمل الموقع داخل مجلد فرعي مثل /iptv
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    }

    if (!$enabled || !is_array($node)) {
        return $node;
    }

    foreach ($node as $k => $v) {
        if (is_array($v)) {
            $node[$k] = apiProtectStreamUrls($v);
        } elseif (is_string($v) && $v !== ''
                  && ($k === 'stream_url' || $k === 'audio_url' || $k === 'backup_url' || $k === 'url')) {
            $node[$k] = streamPublicUrl($v, (string) $base);
        }
    }

    return $node;
}

/**
 * إرسال استجابة JSON قابلة للتخزين المؤقت مع ETag.
 * إن تطابق ETag مع ما لدى المتصفح تُرجَع 304 بلا جسم (توفير كبير).
 *
 * @param array $data البيانات.
 * @param int   $ttl  مدة الكاش بالثواني (0 = بلا كاش عام).
 * @return never
 */
function apiCachedResponse(array $data, int $ttl = 60)
{
    /* 🔴 حماية روابط البث — تُطبَّق هنا عمداً في نقطة واحدة.
       سبب اختيار هذا الموضع: الاستجابات تخرج من تسع نقاط مختلفة في
       هذا الملف، وتعديل كل واحدة يدوياً يعني نسيان واحدة يوماً ما
       فتتسرّب بيانات الاشتراك من نقطة واحدة منسيّة. المعالجة في
       نقطة الخروج الموحّدة تضمن تغطية كل النقاط الحالية وأي نقطة
       تُضاف مستقبلاً. راجع functions/stream_token.php للتفاصيل. */
    $data = apiProtectStreamUrls($data);

    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $etag = '"' . md5((string) $body) . '"';

    /* ⚠ كان هنا: Cache-Control: public, max-age=$ttl, stale-while-revalidate=30
       وهو يعطّل التحديث اللحظي كلّه. السبب أن كاش المتصفح يُفهرَس بالرابط
       وحده، فحين يكتشف مستطلع content_version تغيّراً ويستدعي scInvalidate()
       — وهي تُفرغ كاش الجافاسكربت فقط — ثم يعيد الطلب على الرابط نفسه،
       يردّ المتصفح من ذاكرته دون أن يمسّ الشبكة. النتيجة: الواجهة تُعاد
       رسمها بنفس البيانات القديمة، فيبدو أن التعديل لم يصل (حتى ٣ دقائق،
       وأطول مع stale-while-revalidate).

       والبديل هنا ليس إلغاء الكاش: no-cache تعني «خزّن لكن تحقّق دائماً».
       مع ETag الموجود أصلاً يرسل المتصفح If-None-Match ويستقبل 304 بلا
       جسم — أي بضع مئات من البايتات بدل الحمولة كاملة — ويبقى كاش
       الجافاسكربت (١٠ دقائق) هو من يمتصّ الطلبات المتكرّرة. فنحتفظ
       بالتوفير ونضمن الصحّة معاً.

       و private بدل public مقصودة: هذه الاستجابات تحمل روابط بثّ موقّعة،
       ولا فائدة من تخزينها في وسيط مشترك — بينما ضررُ أن يخدم وسيطٌ نسخةً
       قديمة لكل الزوّار حقيقي. */
    if (!headers_sent()) {
        header('ETag: ' . $etag);
        if ($ttl > 0) {
            header('Cache-Control: private, no-cache, must-revalidate');
        } else {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        header('Vary: Accept-Encoding');
    }

    $clientEtag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($clientEtag !== '' && $clientEtag === $etag) {
        http_response_code(304);
        // إفراغ أي مخرجات لضمان جسم فارغ فعلاً
        while (ob_get_level()) {
            ob_end_clean();
        }
        exit();
    }

    http_response_code(200);
    echo $body;
    exit();
}

/**
 * تنفيذ استعلام مع كاش على مستوى النتيجة (Query Cache).
 *
 * @param string   $key      مفتاح الكاش.
 * @param int      $ttl      مدة الصلاحية.
 * @param callable $producer دالة تُنتج البيانات عند غياب الكاش.
 * @return mixed
 */
function apiRemember(string $key, int $ttl, callable $producer)
{
    $cached = cacheGet($key);
    if ($cached !== null) {
        if (!headers_sent()) {
            header('X-Cache: HIT');
        }
        return $cached;
    }

    /* انهيار الكاش تحت الضغط (cache stampede): عند انتهاء المفتاح قد تصل
       مئات الطلبات في اللحظة نفسها، وكانت كلها تنفّذ الاستعلام الثقيل.
       نقفل «دلو» صغيراً للمفتاح، ثم نعيد فحص الكاش؛ طلب واحد فقط ينتج
       القيمة والبقية تستعملها فوراً. 4096 دلواً تمنع أيضاً تراكم ملايين
       ملفات القفل بسبب كلمات البحث المختلفة. */
    $lockHandle = false;
    $lockHeld   = false;
    $lockDir    = defined('CACHE_DIR') ? CACHE_DIR . '/locks' : '';

    if ($lockDir !== ''
        && (is_dir($lockDir) || @mkdir($lockDir, 0750, true) || is_dir($lockDir))) {
        $lockFile   = $lockDir . '/api-' . substr(sha1($key), 0, 3) . '.lock';
        $lockHandle = @fopen($lockFile, 'c');
        if ($lockHandle !== false) {
            $lockHeld = @flock($lockHandle, LOCK_EX);
            if ($lockHeld) {
                $cached = cacheGet($key);
                if ($cached !== null) {
                    @flock($lockHandle, LOCK_UN);
                    @fclose($lockHandle);
                    if (!headers_sent()) {
                        header('X-Cache: HIT');
                    }
                    return $cached;
                }
            }
        }
    }

    if (!headers_sent()) {
        header('X-Cache: MISS');
    }

    try {
        $value = $producer();
        cacheSet($key, $value, $ttl);
        return $value;
    } finally {
        if ($lockHandle !== false) {
            if ($lockHeld) {
                @flock($lockHandle, LOCK_UN);
            }
            @fclose($lockHandle);
        }
    }
}

/**
 * بصمة المحتوى الحالية — تُستخدم كجزء من مفاتيح الكاش
 * حتى يبطل الكاش تلقائياً فور أي تعديل من لوحة الإدارة.
 *
 * @return string
 */
function apiContentStamp(): string
{
    $stamp = cacheGet('content_stamp');
    if (is_string($stamp) && $stamp !== '') {
        return $stamp;
    }

    $stamp = computeContentVersion();
    cacheSet('content_stamp', $stamp, 10); // نافذة قصيرة جداً
    return $stamp;
}

/**
 * حساب بصمة المحتوى فعلياً من قاعدة البيانات.
 *
 * COUNT(*) + MAX(id) معاً يكشفان:
 *   • الإضافة → يزيدان
 *   • الحذف   → COUNT ينقص
 *   • إضافة+حذف بنفس العدد → MAX(id) يكشفها
 *
 * @return string بصمة من 16 محرفاً.
 */
function computeContentVersion(): string
{
    $pdo   = db();
    $parts = [];

    // القنوات والمسلسلات: النشطة فقط + أعلى معرّف
    foreach (['channels' => 'is_active = 1', 'series' => 'is_active = 1'] as $tbl => $cond) {
        try {
            $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(MAX(id),0) AS m, COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0) AS u FROM `$tbl` WHERE $cond")
                       ->fetch(PDO::FETCH_ASSOC);
            $parts[] = $tbl . ':' . (int) $row['c'] . ':' . (int) $row['m'] . ':' . (int) ($row['u'] ?? 0);
        } catch (PDOException $e) {
            // إن لم يوجد عمود is_active نعيد المحاولة بلا شرط
            try {
                $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(MAX(id),0) AS m, COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0) AS u FROM `$tbl`")
                           ->fetch(PDO::FETCH_ASSOC);
                $parts[] = $tbl . ':' . (int) $row['c'] . ':' . (int) $row['m'] . ':' . (int) ($row['u'] ?? 0);
            } catch (PDOException $e2) {
                $parts[] = $tbl . ':0:0';
            }
        }
    }

    // الحلقات والأقسام: بلا شرط (الحلقات ليس فيها is_active)
    foreach (['episodes', 'categories'] as $tbl) {
        try {
            $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(MAX(id),0) AS m FROM `$tbl`")
                       ->fetch(PDO::FETCH_ASSOC);
            $parts[] = $tbl . ':' . (int) $row['c'] . ':' . (int) $row['m'] . ':' . (int) ($row['u'] ?? 0);
        } catch (PDOException $e) {
            $parts[] = $tbl . ':0:0';
        }
    }

    // Multi-Storage: قرص يتعطّل أو يعود ⇒ تتغيّر البصمة فتتحدّث الواجهة فوراً
    try {
        $__rev = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'storage_state_rev' LIMIT 1")->fetchColumn();
        $parts[] = 'stg:' . (string) $__rev;
    } catch (PDOException $e) { /* لا جدول إعدادات */ }

    // بصمة قصيرة للمقارنة فقط (ليست لغرض أمني)
    return substr(md5(implode('|', $parts)), 0, 16);
}

// ══════════════════════════════════════════════════════════════
// توجيه الطلبات حسب الإجراء
// ══════════════════════════════════════════════════════════════
switch ($action) {
    case 'categories':
        getCategories();
        break;

    case 'channels':
        getChannels();
        break;

    case 'channel':
        getChannel();
        break;

    case 'search':
        searchChannels();
        break;

    case 'featured':
        getFeaturedChannels();
        break;

    case 'stats':
        getStatistics();
        break;

    case 'increment_view':
        incrementViewCount();
        break;

    // ── جديد ──
    case 'all_content':
        getAllContent();
        break;

    case 'notification_state':
        getNotificationState();
        break;

    case 'series':
        getSeries();
        break;

    case 'episodes':
        getEpisodes();
        break;

    // ── بصمة المحتوى: للتحديث اللحظي بلا إعادة تحميل ──
    case 'content_version':
        getContentVersion();
        break;

    // ── مجموعات الصفحة الرئيسية (بطاقات index المنظّمة، بلا عشوائية) ──
    case 'home_groups':
        getHomeGroups();
        break;

    // ── الترجمات: قراءة المحفوظ / قائمة الاختيارات / تنزيل واحدة ──
    case 'episode_subtitle':
        getEpisodeSubtitle();
        break;

    case 'subtitle_options':
        getSubtitleOptions();
        break;

    case 'subtitle_download':
        downloadEpisodeSubtitle();
        break;

    default:
        jsonResponse([
            'success' => false,
            'error'   => 'إجراء غير معروف',
        ], 400);
}

/**
 * مجموعات الصفحة الرئيسية — بطاقات index المنظّمة التي يديرها المدير.
 *
 * تُرجع فقط المجموعات النشطة والمعلَّمة للعرض، مع أعداد محسوبة وغلاف تمثيلي
 * حتمي (لا عشوائية). اختيار قسم أب يضمّ فروعه تلقائياً عبر hgExpandIds.
 * لا تُرجع أي محتوى فعلي (قنوات/أفلام) — فقط بيانات البطاقات — فلا تحميل مسبق.
 *
 * @return never
 */
function getHomeGroups()
{
    try {
        $pdo = db();
        // نستخدم طبقة roo: home_groups (accent_color) + home_group_categories (ربط)
        // + بصمة home_groups_revision المستقلة حتى يظهر أي تعديل فوراً.
        $rev = '0';
        try {
            $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='home_groups_revision' LIMIT 1");
            $st->execute();
            $rev = (string)($st->fetchColumn() ?: '0');
        } catch (Throwable $e) {}

        $key = 'hg2:' . $rev . ':' . apiContentStamp();

        $payload = apiRemember($key, 120, static function () use ($pdo): array {
            if (!function_exists('homeGroupsRead')) return ['configured' => false, 'groups' => []];
            $state = homeGroupsRead($pdo, true); // النشطة فقط

            /* الوضع التلقائي (لم يعتمد المدير تنظيماً يدوياً بعد، أو ضغط
               «استعادة التلقائي»): نبني البطاقات من الأقسام في كل طلب، فأي
               قسم جديد يظهر تلقائياً في الصفحة الرئيسية بلا أي خطوة إضافية. */
            if (empty($state['configured'])) {
                return [
                    'configured' => false,
                    'groups'     => function_exists('hgAutoGroups') ? hgAutoGroups($pdo) : [],
                ];
            }

            $out = [];
            foreach (($state['groups'] ?? []) as $g) {
                $ids      = array_values(array_map('intval', $g['category_ids'] ?? []));
                $expanded = function_exists('hgExpandIds') ? hgExpandIds($pdo, $ids) : $ids;
                $counts   = function_exists('hgGroupCounts') ? hgGroupCounts($pdo, $expanded) : ['categories'=>count($ids),'items'=>0];
                $cover    = function_exists('hgGroupCover') ? hgGroupCover($pdo, $expanded, (string)$g['content_type']) : '';
                $out[] = [
                    'id'             => (int)$g['id'],
                    'name'           => $g['name'],
                    'description'    => $g['description'] ?? '',
                    'color'          => $g['accent_color'] ?? '#38bdf8',
                    'content_type'   => $g['content_type'] ?? 'auto',
                    'category_ids'   => $ids,
                    'cover'          => $cover,
                    'category_count' => $counts['categories'],
                    'item_count'     => $counts['items'],
                ];
            }
            return ['configured' => !empty($state['configured']), 'groups' => $out];
        });

        apiCachedResponse([
            'success'    => true,
            'configured' => !empty($payload['configured']),
            'count'      => count($payload['groups'] ?? []),
            'groups'     => $payload['groups'] ?? [],
        ], 120);

    } catch (Throwable $e) {
        error_log('API home_groups: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'تعذّر جلب المجموعات'], 500);
    }
}

/**
 * الحصول على جميع الأقسام مع عدد القنوات.
 *
 * الاستجابة: success, count, categories
 *
 * @return never
 */
function getCategories()
{
    try {
        $categories = apiRemember('cat2:' . apiContentStamp(), 120, static function (): array {
            $stmt = db()->prepare("
                SELECT 
                    c.id,
                    c.name,
                    c.slug,
                    c.icon,
                    c.description,
                    c.parent_id,
                    COUNT(ch.id) as channel_count
                FROM categories c
                LEFT JOIN channels ch ON c.id = ch.category_id AND ch.is_active = 1
                WHERE c.is_active = 1
                GROUP BY c.id
                ORDER BY c.display_order ASC, c.name ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        });

        apiCachedResponse([
            'success'    => true,
            'count'      => count($categories),
            'categories' => $categories,
        ], 120);

    } catch (PDOException $e) {
        error_log('API Error - getCategories: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error'   => 'حدث خطأ في جلب الأقسام',
        ], 500);
    }
}

/**
 * الحصول على القنوات حسب القسم.
 *
 * الاستجابة: success, count, total, limit, offset, channels
 *
 * @return never
 */
function getChannels()
{
    try {
        $category_id = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
        $limit       = safeInt($_GET['limit']  ?? null, 1, 500,     100);
        $offset      = safeInt($_GET['offset'] ?? null, 0, 1000000, 0);
        $after_id    = safeInt($_GET['after_id'] ?? null, 0, 2147483647, 0);

        if ($category_id <= 0) {
            jsonResponse([
                'success' => false,
                'error'   => 'معرف القسم غير صالح',
            ], 400);
        }

        $key = "chs:{$category_id}:{$limit}:{$offset}:{$after_id}:" . apiContentStamp();

        $payload = apiRemember($key, 90, static function () use ($category_id, $limit, $offset, $after_id): array {
            $pdo = db();

            if ($after_id > 0) {
                $stmt = $pdo->prepare("
                    SELECT ch.*, c.name as category_name, c.icon as category_icon
                    FROM channels ch
                    JOIN categories c ON ch.category_id = c.id
                    WHERE ch.category_id = ? AND ch.is_active = 1 AND ch.id > ?
                    ORDER BY ch.id DESC
                    LIMIT ?
                ");
                $stmt->execute([$category_id, $after_id, $limit]);
                $rows = $stmt->fetchAll();
                return ['rows' => $rows, 'total' => count($rows)];
            }

            // استعلام واحد يجلب الصفوف والعدد الكلي معاً (MySQL 8+)
            // بدلاً من استعلامين منفصلين.
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        ch.*,
                        c.name as category_name,
                        c.icon as category_icon,
                        COUNT(*) OVER() as __total
                    FROM channels ch
                    JOIN categories c ON ch.category_id = c.id
                    WHERE ch.category_id = ? AND ch.is_active = 1
                    ORDER BY ch.display_order ASC, ch.name ASC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$category_id, $limit, $offset]);
                $rows = $stmt->fetchAll();

                $total = 0;
                if ($rows) {
                    $total = (int) $rows[0]['__total'];
                    foreach ($rows as &$r) {
                        unset($r['__total']);
                    }
                    unset($r);
                } else {
                    // لا صفوف في هذه الصفحة → نحتاج العدد الكلي
                    $c = $pdo->prepare(
                        'SELECT COUNT(*) FROM channels WHERE category_id = ? AND is_active = 1'
                    );
                    $c->execute([$category_id]);
                    $total = (int) $c->fetchColumn();
                }

                return ['rows' => $rows, 'total' => $total];

            } catch (PDOException $e) {
                // بديل متوافق مع MySQL 5.7 — نفس السلوك الأصلي تماماً
                $stmt = $pdo->prepare("
                    SELECT 
                        ch.*,
                        c.name as category_name,
                        c.icon as category_icon
                    FROM channels ch
                    JOIN categories c ON ch.category_id = c.id
                    WHERE ch.category_id = ? AND ch.is_active = 1
                    ORDER BY ch.display_order ASC, ch.name ASC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$category_id, $limit, $offset]);
                $rows = $stmt->fetchAll();

                $countStmt = $pdo->prepare("
                    SELECT COUNT(*) as total 
                    FROM channels 
                    WHERE category_id = ? AND is_active = 1
                ");
                $countStmt->execute([$category_id]);

                return ['rows' => $rows, 'total' => (int) $countStmt->fetch()['total']];
            }
        });

        apiCachedResponse([
            'success'  => true,
            'count'    => count($payload['rows']),
            'total'    => $payload['total'],
            'limit'    => $limit,
            'offset'   => $offset,
            'channels' => $payload['rows'],
        ], 90);

    } catch (PDOException $e) {
        error_log('API Error - getChannels: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error'   => 'حدث خطأ في جلب القنوات',
        ], 500);
    }
}

/**
 * الحصول على قناة واحدة.
 *
 * الاستجابة: success, channel
 *
 * @return never
 */
function getChannel()
{
    try {
        $channel_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        if ($channel_id <= 0) {
            jsonResponse([
                'success' => false,
                'error'   => 'معرف القناة غير صالح',
            ], 400);
        }

        $channel = apiRemember(
            "ch:{$channel_id}:" . apiContentStamp(),
            120,
            static function () use ($channel_id) {
                $stmt = db()->prepare("
                    SELECT 
                        ch.*,
                        c.name as category_name,
                        c.icon as category_icon
                    FROM channels ch
                    JOIN categories c ON ch.category_id = c.id
                    WHERE ch.id = ? AND ch.is_active = 1
                    LIMIT 1
                ");
                $stmt->execute([$channel_id]);
                return $stmt->fetch() ?: false;
            }
        );

        if (!$channel) {
            jsonResponse([
                'success' => false,
                'error'   => 'القناة غير موجودة',
            ], 404);
        }

        apiCachedResponse([
            'success' => true,
            'channel' => $channel,
        ], 120);

    } catch (PDOException $e) {
        error_log('API Error - getChannel: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error'   => 'حدث خطأ في جلب القناة',
        ], 500);
    }
}

/**
 * البحث في القنوات.
 * ── مُحسَّن: يبحث أيضاً في المسلسلات ──
 *
 * الاستجابة: success, query, count, channels, series
 *
 * @return never
 */
function searchChannels()
{
    try {
        // حدّ معدل أخصّ للبحث (الأثقل على قاعدة البيانات)
        if (!rateLimit('api:search', 60, 60)) {
            header('Retry-After: 30');
            jsonResponse([
                'success' => false,
                'error'   => 'طلبات بحث كثيرة جداً. حاول بعد قليل.',
            ], 429);
        }

        $query = sanitizeInput($_GET['q'] ?? '');
        $limit = safeInt($_GET['limit'] ?? null, 1, 200, 50);

        if ($query === '' || mb_strlen($query) < 2) {
            jsonResponse([
                'success' => false,
                'error'   => 'يجب إدخال حرفين على الأقل للبحث',
            ], 400);
        }

        // تهريب محارف LIKE الخاصة لمنع أنماط مكلفة (ReDoS-like) وسلوك غير متوقع
        $escaped    = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query);
        $searchTerm = '%' . $escaped . '%';

        $key = 'srch:' . md5($query . '|' . $limit) . ':' . apiContentStamp();

        $payload = apiRemember($key, 60, static function () use ($searchTerm, $limit): array {
            $pdo = db();

            // البحث في القنوات (الكود الأصلي)
            $stmt = $pdo->prepare("
                SELECT 
                    ch.*,
                    c.name as category_name,
                    c.icon as category_icon
                FROM channels ch
                JOIN categories c ON ch.category_id = c.id
                WHERE ch.is_active = 1 
                AND (ch.name LIKE ? OR ch.description LIKE ?)
                ORDER BY ch.views_count DESC, ch.name ASC
                LIMIT ?
            ");
            $stmt->execute([$searchTerm, $searchTerm, $limit]);
            $channels = $stmt->fetchAll();

            // البحث في المسلسلات (جديد)
            $series = [];
            try {
                $srStmt = $pdo->prepare("
                    SELECT s.*, c.name as cat_name, COUNT(e.id) as ep_count
                    FROM series s
                    LEFT JOIN categories c ON s.category_id = c.id
                    LEFT JOIN episodes e ON e.series_id = s.id
                    WHERE s.is_active = 1 AND s.name LIKE ?
                    GROUP BY s.id
                    ORDER BY s.name ASC
                    LIMIT ?
                ");
                $srStmt->execute([$searchTerm, (int) ceil($limit / 2)]);
                $series = $srStmt->fetchAll();
            } catch (PDOException $e) {
                // الجدول غير موجود — تجاهل
            }

            return ['channels' => $channels, 'series' => $series];
        });

        apiCachedResponse([
            'success'  => true,
            'query'    => $query,
            'count'    => count($payload['channels']),
            'channels' => $payload['channels'],
            'series'   => $payload['series'],
        ], 60);

    } catch (PDOException $e) {
        error_log('API Error - searchChannels: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error'   => 'حدث خطأ في البحث',
        ], 500);
    }
}

/**
 * الحصول على القنوات المميزة.
 *
 * الاستجابة: success, count, channels
 *
 * @return never
 */
function getFeaturedChannels()
{
    try {
        $limit = safeInt($_GET['limit'] ?? null, 1, 100, 10);

        $channels = apiRemember(
            "feat:{$limit}:" . apiContentStamp(),
            180,
            static function () use ($limit): array {
                $stmt = db()->prepare("
                    SELECT 
                        ch.*,
                        c.name as category_name,
                        c.icon as category_icon
                    FROM channels ch
                    JOIN categories c ON ch.category_id = c.id
                    WHERE ch.is_active = 1 AND ch.is_featured = 1
                    ORDER BY ch.display_order ASC, ch.views_count DESC
                    LIMIT ?
                ");
                $stmt->execute([$limit]);
                return $stmt->fetchAll();
            }
        );

        apiCachedResponse([
            'success'  => true,
            'count'    => count($channels),
            'channels' => $channels,
        ], 180);

    } catch (PDOException $e) {
        error_log('API Error - getFeaturedChannels: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error'   => 'حدث خطأ في جلب القنوات المميزة',
        ], 500);
    }
}

/**
 * الحصول على الإحصائيات العامة.
 * ── مُحسَّن: يشمل إحصائيات المسلسلات والحلقات ──
 *
 * جميع الحقول الأصلية والمختصرة محفوظة كما هي.
 *
 * @return never
 */
function getStatistics()
{
    try {
        $stats = apiRemember('stats:' . apiContentStamp(), 120, static function (): array {
            $pdo = db();

            // استعلام واحد بدل ثلاثة للقنوات والأقسام والمشاهدات
            $row = $pdo->query("
                SELECT
                    (SELECT COUNT(*)          FROM channels   WHERE is_active = 1) AS total_channels,
                    (SELECT COUNT(*)          FROM categories WHERE is_active = 1) AS total_categories,
                    (SELECT COALESCE(SUM(views_count),0) FROM channels)            AS total_views
            ")->fetch(PDO::FETCH_ASSOC);

            $totalChannels   = (int) ($row['total_channels'] ?? 0);
            $totalCategories = (int) ($row['total_categories'] ?? 0);
            $totalViews      = (int) ($row['total_views'] ?? 0);

            // إحصائيات المسلسلات والحلقات (جديد)
            $totalSeries   = 0;
            $totalEpisodes = 0;
            try {
                $r2 = $pdo->query("
                    SELECT
                        (SELECT COUNT(*) FROM series WHERE is_active = 1) AS s,
                        (SELECT COUNT(*) FROM episodes)                   AS e
                ")->fetch(PDO::FETCH_ASSOC);
                $totalSeries   = (int) ($r2['s'] ?? 0);
                $totalEpisodes = (int) ($r2['e'] ?? 0);
            } catch (PDOException $e) {
                // الجدول غير موجود — تجاهل
            }

            return [
                // الحقول الأصلية — محفوظة
                'total_channels'   => $totalChannels,
                'total_categories' => $totalCategories,
                'total_views'      => $totalViews,
                'online'           => true,
                // الحقول الجديدة
                'total_series'     => $totalSeries,
                'total_episodes'   => $totalEpisodes,
                // أسماء مختصرة يستخدمها index.php
                'channels'         => $totalChannels,
                'categories'       => $totalCategories,
                'series'           => $totalSeries,
                'episodes'         => $totalEpisodes,
            ];
        });

        apiCachedResponse([
            'success' => true,
            'stats'   => $stats,
        ], 60);

    } catch (PDOException $e) {
        error_log('API Error - getStatistics: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error'   => 'حدث خطأ في جلب الإحصائيات',
        ], 500);
    }
}

/**
 * زيادة عداد المشاهدات.
 * ── مُحسَّن: يدعم ?type=channel|series|episode ──
 *
 * @return never
 */
function incrementViewCount()
{
    try {
        // حماية من الإساءة على عمليات الكتابة
        if (!rateLimit('api:view', 120, 60)) {
            header('Retry-After: 30');
            jsonResponse([
                'success' => false,
                'error'   => 'طلبات كثيرة جداً. حاول بعد قليل.',
            ], 429);
        }

        // لا كاش على عمليات الكتابة
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }

        $pdo = db();

        // قبول GET أو POST
        $channel_id = isset($_GET['id'])
            ? (int) $_GET['id']
            : (isset($_POST['channel_id']) ? (int) $_POST['channel_id'] : 0);
        $type = sanitizeInput($_GET['type'] ?? 'channel');

        if ($channel_id <= 0) {
            jsonResponse([
                'success' => false,
                'error'   => 'معرف القناة غير صالح',
            ], 400);
            return;
        }

        // مسلسل (جديد)
        if ($type === 'series') {
            try {
                $pdo->prepare('UPDATE series SET views_count = views_count + 1 WHERE id = ?')
                    ->execute([$channel_id]);
            } catch (PDOException $e) {
                error_log('API Error - incrementViewCount(series): ' . $e->getMessage());
            }
            jsonResponse([
                'success'   => true,
                'message'   => 'تم تسجيل المشاهدة',
                'series_id' => $channel_id,
            ]);
            return;
        }

        // حلقة (جديد)
        if ($type === 'episode') {
            try {
                $pdo->prepare('UPDATE episodes SET views_count = views_count + 1 WHERE id = ?')
                    ->execute([$channel_id]);
            } catch (PDOException $e) {
                error_log('API Error - incrementViewCount(episode): ' . $e->getMessage());
            }
            jsonResponse([
                'success'    => true,
                'message'    => 'تم تسجيل المشاهدة',
                'episode_id' => $channel_id,
            ]);
            return;
        }

        // قناة — الكود الأصلي
        // التحقق من وجود القناة
        $checkStmt = $pdo->prepare('SELECT id FROM channels WHERE id = ? AND is_active = 1');
        $checkStmt->execute([$channel_id]);

        if (!$checkStmt->fetch()) {
            jsonResponse([
                'success' => false,
                'error'   => 'القناة غير موجودة',
            ], 404);
            return;
        }

        // زيادة عداد المشاهدات - هذا الأهم!
        $stmt    = $pdo->prepare('UPDATE channels SET views_count = views_count + 1 WHERE id = ?');
        $success = $stmt->execute([$channel_id]);

        if (!$success) {
            jsonResponse([
                'success' => false,
                'error'   => 'فشل تحديث العداد',
            ], 500);
            return;
        }

        // تسجيل المشاهدة (اختياري - فقط إذا كان الجدول موجود)
        try {
            $ip         = clientIp();
            $user_agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);

            $viewStmt = $pdo->prepare("
                INSERT INTO view_stats (channel_id, ip_address, user_agent, viewed_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $viewStmt->execute([$channel_id, $ip, $user_agent]);
        } catch (PDOException $e) {
            // الجدول غير موجود - تجاهل الخطأ
        }

        jsonResponse([
            'success'    => true,
            'message'    => 'تم تسجيل المشاهدة بنجاح',
            'channel_id' => $channel_id,
        ]);

    } catch (PDOException $e) {
        error_log('API Error - incrementViewCount: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error'   => 'حدث خطأ في تسجيل المشاهدة',
        ], 500);
    }
}

/* ══════════════════════════════════════════════════════════════
   الدوال الجديدة — المسلسلات والحلقات
══════════════════════════════════════════════════════════════ */

/**
 * all_content
 * يعيد الأقسام مع عدد القنوات والمسلسلات معاً.
 * يستخدمه index.php لعرض شارة "X مسلسل" على بطاقة القسم.
 *
 * @return never
 */
function getAllContent()
{
    try {
        $pdo = db();

        /* بصمة مستقلة لمجموعات الصفحة الرئيسية حتى ينعكس أي تعديل فوراً */
        $rev = '0';
        try {
            $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='home_groups_revision' LIMIT 1");
            $st->execute();
            $rev = (string)($st->fetchColumn() ?: '0');
        } catch (Throwable $e) {}

        $payload = apiRemember('allc5:' . $rev . ':' . apiContentStamp(), 120, static function () use ($pdo): array {
            /* غلاف تمثيلي حتمي لكل قسم (بوستر عمل أو شعار قناة) — يُمكّن الواجهة
               من بناء بطاقات الصفحة الرئيسية بالكامل من هذه الاستجابة الواحدة،
               بلا طلب إضافي وبلا أي عشوائية. */
            /* وسم أقسام Xtream — تحتاجه الواجهة لتستثنيها من بطاقات
               الصفحة الرئيسية التلقائية: استيراد حساب واحد يُنشئ عشرات
               الأقسام، فكانت تغرق index دفعةً واحدة بلا ترتيب. تُعرض الآن
               عبر مجموعات الصفحة الرئيسية التي يحدّدها المدير فقط.

               العمودان يُضافان بمسار Xtream وحده، فقد لا يوجدا على نسخة
               لم تستورد شيئاً — نتحقق مرة واحدة ونبني التعبير وفقاً لذلك
               بدل أن يسقط الاستعلام كله بخطأ 42S22. */
            $xtCols = "0 AS is_xtream, 0 AS is_imported, '' AS import_src, NULL AS xtream_kind";
            try {
                $__catCols = [];
                foreach ($pdo->query("SHOW COLUMNS FROM categories")->fetchAll(PDO::FETCH_ASSOC) as $__c) {
                    $__catCols[strtolower((string) $__c['Field'])] = true;
                }
                $__hasXt = isset($__catCols['xtream_account_id']);
                $__hasPl = isset($__catCols['playlist_id']);
                if ($__hasXt || $__hasPl) {
                    $__xtExpr   = $__hasXt ? "(CASE WHEN c.xtream_account_id IS NULL THEN 0 ELSE 1 END)" : "0";
                    $__plExpr   = $__hasPl ? "(CASE WHEN c.playlist_id IS NULL THEN 0 ELSE 1 END)" : "0";
                    $__kindExpr = isset($__catCols['xtream_kind']) ? 'c.xtream_kind' : 'NULL';
                    /* import_src يميّز المصدر للواجهة (وسم البطاقة لاحقاً)،
                       وis_imported هو ما يُبنى عليه الاستثناء فعلاً — فالحكم
                       واحد لكل مستورد: Xtream أو M3U. */
                    $xtCols = $__xtExpr . " AS is_xtream, "
                            . "(CASE WHEN " . $__xtExpr . " = 1 OR " . $__plExpr . " = 1 THEN 1 ELSE 0 END) AS is_imported, "
                            . "(CASE WHEN " . $__xtExpr . " = 1 THEN 'xtream' WHEN " . $__plExpr . " = 1 THEN 'm3u' ELSE '' END) AS import_src, "
                            . $__kindExpr . " AS xtream_kind";
                }
            } catch (Throwable $e) { /* نبقى على التعبير المحايد */ }

            $coverCols = "(SELECT s2.poster_url FROM series s2
                              WHERE s2.category_id = c.id AND s2.is_active = 1
                                AND s2.poster_url IS NOT NULL AND s2.poster_url <> ''
                              ORDER BY s2.display_order ASC, s2.id DESC LIMIT 1) AS cover_series,
                          (SELECT h2.logo_url FROM channels h2
                              WHERE h2.category_id = c.id AND h2.is_active = 1
                                AND h2.logo_url IS NOT NULL AND h2.logo_url <> ''
                              ORDER BY h2.display_order ASC, h2.id DESC LIMIT 1) AS cover_channel";

            try {
                $cats = $pdo->query("
                    SELECT
                        c.id, c.name, c.slug, c.icon, c.description, c.display_order, c.parent_id,
                        COUNT(DISTINCT ch.id) as channel_count,
                        COUNT(DISTINCT s.id)  as series_count,
                        MAX(ch.id) as max_ch_id,
                        MAX(s.id)  as max_sr_id,
                        {$xtCols},
                        {$coverCols}
                    FROM categories c
                    LEFT JOIN channels ch ON ch.category_id = c.id AND ch.is_active = 1
                    LEFT JOIN series   s  ON s.category_id  = c.id AND s.is_active  = 1
                    WHERE c.is_active = 1
                    GROUP BY c.id
                    ORDER BY c.display_order ASC, c.name ASC
                ")->fetchAll();

            } catch (PDOException $e) {
                // احتياط: إذا فشل JOIN مع series نرجع للأقسام بدون series_count
                $cats = $pdo->query("
                    SELECT c.id, c.name, c.slug, c.icon, c.description, c.display_order, c.parent_id,
                           COUNT(ch.id) as channel_count, 0 as series_count,
                           MAX(ch.id) as max_ch_id, 0 as max_sr_id,
                           {$xtCols},
                           '' AS cover_series, '' AS cover_channel
                    FROM categories c
                    LEFT JOIN channels ch ON ch.category_id = c.id AND ch.is_active = 1
                    WHERE c.is_active = 1
                    GROUP BY c.id
                    ORDER BY c.display_order ASC, c.name ASC
                ")->fetchAll();
            }

            // تعريفات المجموعات اليدوية + وضع الصفحة (تلقائي/يدوي) — بنفس أسلوب roo
            $home = function_exists('homeGroupsRead')
                ? homeGroupsRead($pdo, true)
                : ['configured' => false, 'groups' => []];

            return ['categories' => $cats, 'home' => $home];
        });

        apiCachedResponse([
            'success'                => true,
            'count'                  => count($payload['categories'] ?? []),
            'categories'             => $payload['categories'] ?? [],
            'home_groups'            => $payload['home']['groups'] ?? [],
            'home_groups_configured' => !empty($payload['home']['configured']),
        ], 120);

    } catch (Throwable $e) {
        error_log('API Error - getAllContent: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'حدث خطأ في جلب المحتوى'], 500);
    }
}

/**
 * A deliberately small, uncached summary for the browser notification check.
 * It avoids relying on a potentially stale full-catalogue response and never
 * transfers the films, series, or channels themselves.
 *
 * @return never
 */
function getNotificationState()
{
    try {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }

        $pdo = db();
        try {
            $categories = $pdo->query("
                SELECT c.id, c.name,
                       COUNT(DISTINCT ch.id) AS channel_count,
                       COUNT(DISTINCT s.id)  AS series_count,
                       COALESCE(MAX(ch.id), 0) AS max_ch_id,
                       COALESCE(MAX(s.id), 0)  AS max_sr_id
                FROM categories c
                LEFT JOIN channels ch ON ch.category_id = c.id AND ch.is_active = 1
                LEFT JOIN series s ON s.category_id = c.id AND s.is_active = 1
                WHERE c.is_active = 1
                GROUP BY c.id
                ORDER BY c.display_order ASC, c.name ASC
            ")->fetchAll();
        } catch (PDOException $e) {
            $categories = $pdo->query("
                SELECT c.id, c.name,
                       COUNT(ch.id) AS channel_count,
                       0 AS series_count,
                       COALESCE(MAX(ch.id), 0) AS max_ch_id,
                       0 AS max_sr_id
                FROM categories c
                LEFT JOIN channels ch ON ch.category_id = c.id AND ch.is_active = 1
                WHERE c.is_active = 1
                GROUP BY c.id
                ORDER BY c.display_order ASC, c.name ASC
            ")->fetchAll();
        }

        jsonResponse([
            'success' => true,
            'categories' => $categories,
        ]);
    } catch (PDOException $e) {
        error_log('API Error - getNotificationState: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Unable to load notification state'], 500);
    }
}

/**
 * series
 * جلب المسلسلات — كل المسلسلات أو حسب قسم محدد عبر ?category_id=X
 *
 * الاستجابة: success, count, series
 *
 * @return never
 */
function getSeries()
{
    try {
        $series_id   = safeInt($_GET['id'] ?? null, 0, 2147483647, 0);
        $category_id = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
        $limit       = safeInt($_GET['limit']  ?? null, 1, 500,     200);
        $offset      = safeInt($_GET['offset'] ?? null, 0, 1000000, 0);
        $after_id    = safeInt($_GET['after_id'] ?? null, 0, 2147483647, 0);

        $key = "srs:{$series_id}:{$category_id}:{$limit}:{$offset}:{$after_id}:" . apiContentStamp();

        $series = apiRemember(
            $key,
            120,
            static function () use ($series_id, $category_id, $limit, $offset, $after_id): array {
                $pdo = db();

                if ($series_id > 0) {
                    $stmt = $pdo->prepare("
                        SELECT s.*, c.name as cat_name, c.icon as cat_icon, COUNT(e.id) as ep_count
                        FROM series s
                        LEFT JOIN categories c ON s.category_id = c.id
                        LEFT JOIN episodes   e ON e.series_id   = s.id
                        WHERE s.id = ? AND s.is_active = 1
                        GROUP BY s.id
                        LIMIT 1
                    ");
                    $stmt->execute([$series_id]);
                    return $stmt->fetchAll();
                }

                if ($after_id > 0) {
                    $stmt = $pdo->prepare("
                        SELECT s.*, c.name as cat_name, c.icon as cat_icon, COUNT(e.id) as ep_count
                        FROM series s
                        LEFT JOIN categories c ON s.category_id = c.id
                        LEFT JOIN episodes   e ON e.series_id   = s.id
                        WHERE s.category_id = ? AND s.is_active = 1 AND s.id > ?
                        GROUP BY s.id
                        ORDER BY s.id DESC
                        LIMIT ?
                    ");
                    $stmt->execute([$category_id, $after_id, $limit]);
                    return $stmt->fetchAll();
                }

                if ($category_id > 0) {
                    $stmt = $pdo->prepare("
                        SELECT s.*, c.name as cat_name, c.icon as cat_icon, COUNT(e.id) as ep_count
                        FROM series s
                        LEFT JOIN categories c ON s.category_id = c.id
                        LEFT JOIN episodes   e ON e.series_id   = s.id
                        WHERE s.category_id = ? AND s.is_active = 1
                        GROUP BY s.id
                        ORDER BY s.display_order ASC, s.id DESC
                        LIMIT ? OFFSET ?
                    ");
                    $stmt->execute([$category_id, $limit, $offset]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT s.*, c.name as cat_name, c.icon as cat_icon, COUNT(e.id) as ep_count
                        FROM series s
                        LEFT JOIN categories c ON s.category_id = c.id
                        LEFT JOIN episodes   e ON e.series_id   = s.id
                        WHERE s.is_active = 1
                        GROUP BY s.id
                        ORDER BY s.display_order ASC, s.id DESC
                        LIMIT ? OFFSET ?
                    ");
                    $stmt->execute([$limit, $offset]);
                }

                return $stmt->fetchAll();
            }
        );

        apiCachedResponse([
            'success' => true,
            'count'   => count($series),
            'series'  => $series,
        ], 120);

    } catch (PDOException $e) {
        error_log('API Error - getSeries: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'حدث خطأ في جلب المسلسلات'], 500);
    }
}

/**
 * episodes
 * جلب حلقات مسلسل محدد عبر ?series_id=X
 *
 * الاستجابة: success, series_id, series_name, count, episodes
 *
 * @return never
 */
function getEpisodes()
{
    try {
        $series_id = isset($_GET['series_id']) ? (int) $_GET['series_id'] : 0;

        if ($series_id <= 0) {
            jsonResponse(['success' => false, 'error' => 'معرّف المسلسل غير صالح'], 400);
            return;
        }

        /* ⚠️ عطل «أفتح البوستر فلا يظهر محتوى، وبعد تحديث الصفحة يظهر»:
           كانت نتيجة «المسلسل غير موجود» (false) تُخزَّن في الكاش
           لمدة 120 ثانية مثل أي نتيجة ناجحة. فإذا صادف الطلب لحظةً
           كان فيها الصفّ قيد الاستيراد من Xtream (أو لم تُضبط قيمة
           is_active بعد)، بقي الرد «لا يوجد محتوى» ثابتاً دقيقتين
           كاملتين لكل من يفتح ذلك العمل — حتى لو اكتملت البيانات
           بعدها بثانية. وهذا يفسّر تماماً الطابع المتقطّع للمشكلة
           وأن إعادة التحميل بعد قليل «تُصلحها».
           الحل: لا نخزّن النتائج السلبية إلا لثوانٍ قليلة جداً. */
        $cacheKey = "eps:{$series_id}:" . apiContentStamp();
        $payload  = cacheGet($cacheKey);

        if ($payload === null) {
            $payload = (static function () use ($series_id) {
                $pdo = db();

                /* is_active قد لا يكون موجوداً في تركيبات أُنشئ فيها
                   جدول series من functions/helpers.php (تعريفه هناك
                   لا يحتوي العمود إطلاقاً، بخلاف database.sql).
                   لذلك نتحقق من وجود العمود قبل استخدامه بدل أن يفشل
                   الاستعلام كله ويظهر «تعذر تحميل الحلقات». */
                $hasActive = false;
                try {
                    $hasActive = (bool) $pdo->query("SHOW COLUMNS FROM `series` LIKE 'is_active'")->fetch();
                } catch (PDOException $e) {
                    $hasActive = false;
                }

                /* ⚠ poster_url مطلوب هنا وليس زائداً.
                   كان الاستعلام يجلب id و name فقط. وعند فتح مسلسل
                   بالنقر يصل البوستر إلى الواجهة كوسيط من البطاقة
                   المضغوطة، فيظهر. أمّا عند تحديث الصفحة فلا بطاقة
                   ولا وسيط: تستعيد الواجهة الحالة من الهاش وتسأل هذه
                   النقطة، فيعود البوستر فارغاً — ويسقط معه احتياطي
                   صور الحلقات (renderEpisodes يستعمل بوستر المسلسل
                   حين لا تملك الحلقة صورة)، فتظهر البطاقات سوداء.
                   عطلٌ يظهر بعد التحديث فقط، وهذا ما جعله محيّراً. */
                $sql   = 'SELECT id, name, poster_url FROM series WHERE id = ?'
                       . ($hasActive ? ' AND is_active = 1' : '');
                $check = $pdo->prepare($sql);
                $check->execute([$series_id]);
                $sr = $check->fetch();

                if (!$sr) {
                    return false;
                }

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM episodes
                    WHERE series_id = ?
                    ORDER BY episode_number ASC, display_order ASC, id ASC
                ");
                $stmt->execute([$series_id]);

                return [
                    'name'     => $sr['name'],
                    'poster'   => (string) ($sr['poster_url'] ?? ''),
                    'episodes' => $stmt->fetchAll(),
                ];
            })();

            /* نتيجة ناجحة → كاش عادي (دقيقتان).
               نتيجة سلبية أو قائمة حلقات فارغة → 5 ثوانٍ فقط، حتى
               لا تُثبَّت حالة «لا يوجد محتوى» على المستخدمين. */
            $isNegative = ($payload === false) || empty($payload['episodes']);
            cacheSet($cacheKey, $payload, $isNegative ? 5 : 120);

            if (!headers_sent()) {
                header('X-Cache: MISS');
            }
        } elseif (!headers_sent()) {
            header('X-Cache: HIT');
        }

        if ($payload === false) {
            jsonResponse(['success' => false, 'error' => 'المسلسل غير موجود'], 404);
            return;
        }

        /* Multi-Storage: قرص Offline/Error/Maintenance يُعطّل محتواه وحده مؤقتاً.
           التصفية هنا بعد الكاش لا قبله: الكاش يبقى محايداً تجاه حالة الأقراص،
           والقائمة المعروضة تتبع الحالة الحالية دائماً (كاشها 20 ثانية). */
        $__unavail = 0;
        if (function_exists('storageUnreadableIds') && ($__bad = storageUnreadableIds())) {
            $__keep = [];
            foreach ($payload['episodes'] as $__ep) {
                if (isset($__ep['storage_id']) && $__ep['storage_id'] !== null && in_array((int) $__ep['storage_id'], $__bad, true)) { $__unavail++; continue; }
                $__keep[] = $__ep;
            }
            $payload['episodes'] = $__keep;
        }

        apiCachedResponse([
            'success'     => true,
            'series_id'   => $series_id,
            'unavailable_count' => $__unavail,
            'series_name' => $payload['name'],
            /* ?? '' مقصود: الحمولات المخزّنة قبل هذا التعديل لا تحمل
               المفتاح، فبدونه ينهار الطلب حتى تنتهي مهلة الكاش. */
            'series_poster' => (string) ($payload['poster'] ?? ''),
            'count'       => count($payload['episodes']),
            'episodes'    => $payload['episodes'],
        ], 120);

    } catch (PDOException $e) {
        error_log('API Error - getEpisodes: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'حدث خطأ في جلب الحلقات'], 500);
    }
}

/**
 * content_version
 * بصمة صغيرة تتغيّر عند أي إضافة/حذف في المحتوى.
 * تستعملها index.php للتحديث اللحظي بلا إعادة تحميل الصفحة.
 *
 * مهم: نحسب القنوات/المسلسلات النشطة فقط (is_active = 1) ليتطابق
 * ما نراقبه مع ما تعرضه بقية نقاط الـAPI فعلاً؛ فتفعيل قناة أو
 * تعطيلها من لوحة التحكم يُعتبر تغييراً أيضاً.
 *
 * @return never
 */
function getContentVersion()
{
    // لا كاش إطلاقاً على هذه النقطة، وإلا لن يصل التغيير للمتصفح
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    $version = computeContentVersion();

    // تحديث بصمة الكاش الداخلية فوراً حتى تبطل النتائج القديمة
    cacheSet('content_stamp', $version, 10);

    if (!headers_sent()) {
        header('X-Content-Version: ' . $version);
    }

    jsonResponse([
        'success' => true,
        'version' => $version,
        'ts'      => time(),
    ]);
}


// ══════════════════════════════════════════════════════════════
// نظام الترجمات — OpenSubtitles (واجهة المشغّل العامة)
// ══════════════════════════════════════════════════════════════
/*
 * لماذا هنا وليس في ajax/handlers.php؟
 *   لوحة التحكم لديها بالفعل بحثٌ عن الترجمات (search_subtitles)، لكنه
 *   يتطلّب جلسة مدير ويكتب النتيجة في حلقة بعينها يدوياً. المشاهد لا يملك
 *   ذلك: هو يضغط زر الترجمة داخل المشغّل فيحتاج ثلاث نقاط عامة محدودة:
 *     • episode_subtitle  → يقرأ المحفوظ فقط (بلا أي طلب خارجي)
 *     • subtitle_options  → قائمة اختيارات ليقرّر هو، لا فرض أول نتيجة
 *     • subtitle_download → ينزّل ما اختاره ويحفظه محلياً مرة واحدة
 *
 *   الحفظ المحلي هو جوهر التصميم: الطلب الخارجي يحدث مرة واحدة لكل حلقة،
 *   ثم يخدم الخادم ملف .vtt محلياً لكل المشاهدين التاليين. بدون ذلك يُستنفد
 *   رصيد التنزيل اليومي في OpenSubtitles خلال دقائق.
 *
 *   بيانات الاعتماد تُقرأ من settings: os_api_key / os_username / os_password،
 *   واللغة من mv_subtitle_language ثم sub_default_language (افتراضي: ar).
 *   إن لم تُهيَّأ، تُعيد النقاط "لا ترجمة" بهدوء ولا تُظهر خطأً للمشاهد.
 */

/**
 * episode_subtitle — ترجمة الحلقة/الفيلم.
 *
 * تعيد الرابط المحفوظ فوراً إن وُجد. وضع mode=saved يتوقف عند هذا الحد
 * (وهو ما يستدعيه المشغّل أولاً) حتى تُعرض للمشاهد قائمة الاختيارات بدل
 * فرض أول نتيجة تلقائياً. أمّا البحث التلقائي فمحصور بالمحتوى المستورد من
 * Xtream، ويتحوّل بهدوء إلى "لا ترجمة" حين يغيب العمود xtream_account_id
 * من قاعدة البيانات (نسخ لم تمرّ بمسار استيراد Xtream).
 *
 * @return never
 */
function getEpisodeSubtitle()
{
    $episodeId = isset($_GET['episode_id']) ? (int) $_GET['episode_id'] : 0;
    if ($episodeId <= 0) {
        jsonResponse(['success' => false, 'error' => 'معرّف الفيلم غير صالح'], 400);
        return;
    }

    try {
        $pdo = db();

        /* العمود xtream_account_id يُضاف عبر مسار استيراد Xtream فقط وليس
           في database.sql، فقد لا يوجد أصلاً. الاستعلام يسقط عندئذ بخطأ
           42S22 — نتحمّله باستعلام بديل بدل أن تتعطّل النقطة كاملةً. */
        $sqlBase = "SELECT e.id, e.title AS episode_title, e.subtitle_url, s.name AS series_name, %s AS xtream_account_id
                    FROM episodes e INNER JOIN series s ON s.id = e.series_id
                    WHERE e.id = ? LIMIT 1";
        try {
            $stmt = $pdo->prepare(sprintf($sqlBase, 'COALESCE(s.xtream_account_id, 0)'));
            $stmt->execute([$episodeId]);
            $episode = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $stmt = $pdo->prepare(sprintf($sqlBase, '0'));
            $stmt->execute([$episodeId]);
            $episode = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$episode) {
            jsonResponse(['success' => false, 'error' => 'الفيلم غير موجود'], 404);
            return;
        }

        $savedUrl = trim((string) ($episode['subtitle_url'] ?? ''));
        if ($savedUrl !== '') {
            jsonResponse(['success' => true, 'subtitle_url' => $savedUrl, 'source' => 'saved']);
            return;
        }

        // المشغّل يستدعي هذا الوضع أولاً لقراءة المحفوظ فقط
        if (($_GET['mode'] ?? '') === 'saved') {
            jsonResponse(['success' => true, 'subtitle_url' => '', 'source' => 'none']);
            return;
        }

        if ((int) ($episode['xtream_account_id'] ?? 0) <= 0) {
            jsonResponse(['success' => true, 'subtitle_url' => '', 'source' => 'none']);
            return;
        }

        if (!rateLimit('api:xtream-subtitle:' . clientIp(), 6, 60)) {
            jsonResponse(['success' => false, 'error' => 'يرجى الانتظار قليلاً قبل طلب ترجمة أخرى'], 429);
            return;
        }

        $svc = shsSubtitleServiceSession();
        if (!$svc) {
            jsonResponse(['success' => true, 'subtitle_url' => '', 'source' => 'none', 'message' => 'خدمة الترجمة غير مهيأة']);
            return;
        }

        $title = shsSubtitleSearchTitle($episode);
        if ($title === '') {
            jsonResponse(['success' => true, 'subtitle_url' => '', 'source' => 'none']);
            return;
        }

        $query = http_build_query([
            'query'           => $title,
            'languages'       => $svc['lang'],
            'order_by'        => 'download_count',
            'order_direction' => 'desc',
            'per_page'        => 1,
        ]);
        [$status, $raw] = shsXtreamSubtitleRequest('https://api.opensubtitles.com/api/v1/subtitles?' . $query, 'GET', $svc['headers']);
        $search = json_decode($raw, true);
        $fileId = (is_array($search) && !empty($search['data'][0]['attributes']['files'][0]['file_id']))
            ? (int) $search['data'][0]['attributes']['files'][0]['file_id'] : 0;
        if ($status !== 200 || $fileId <= 0) {
            jsonResponse(['success' => true, 'subtitle_url' => '', 'source' => 'none']);
            return;
        }

        $localUrl = shsSubtitleFetchAndStore($svc, $fileId, $episodeId, 'xtsub_', true);
        if ($localUrl === '') {
            jsonResponse(['success' => true, 'subtitle_url' => '', 'source' => 'none']);
            return;
        }

        jsonResponse(['success' => true, 'subtitle_url' => $localUrl, 'source' => 'opensubtitles']);
    } catch (Throwable $e) {
        error_log('Xtream subtitle lookup failed: ' . $e->getMessage());
        jsonResponse(['success' => true, 'subtitle_url' => '', 'source' => 'none']);
    }
}

/**
 * طلب HTTP إلى OpenSubtitles.
 *
 * نبدأ بمسار DNS الطبيعي. وإن تعذّر الاتصال أصلاً (status = 0 — أي لم يصل
 * الطلب إلى الخادم) نعيد المحاولة مرة واحدة بتثبيت عنوان Cloudflare، وهو
 * ما تحتاجه الخوادم التي يحجب مزوّدها الاسم على مستوى DNS. تثبيت العنوان
 * دائماً — كما في roo — يعمل اليوم لكنه يتعطّل يوم يغيّر Cloudflare عنوانه،
 * فجعلناه مخرجَ طوارئ لا المسار الأول. التحقق من الشهادة يبقى مفعّلاً في
 * الحالتين (اسم المضيف لا يتغيّر، فالشهادة تُطابَق كالمعتاد).
 *
 * @param array<int,string>        $headers
 * @param array<string,mixed>|null $body
 * @return array{0:int,1:string}
 */
function shsXtreamSubtitleRequest(string $url, string $method, array $headers, ?array $body = null): array
{
    if (!function_exists('curl_init')) { return [0, '']; }
    $headers[] = 'Content-Type: application/json';

    $send = static function (array $extra) use ($url, $method, $headers, $body): array {
        $curl = curl_init($url);
        if ($curl === false) { return [0, '']; }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'ShashetyIPTV/2.0',
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        foreach ($extra as $k => $v) { $opts[$k] = $v; }
        curl_setopt_array($curl, $opts);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body ?? [], JSON_UNESCAPED_UNICODE));
        }
        $raw    = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return [$status, is_string($raw) ? $raw : ''];
    };

    [$status, $raw] = $send([]);
    if ($status === 0 && strtolower((string) parse_url($url, PHP_URL_HOST)) === 'api.opensubtitles.com') {
        [$status, $raw] = $send([CURLOPT_RESOLVE => ['api.opensubtitles.com:443:104.21.75.83']]);
    }
    return [$status, $raw];
}

/**
 * تنزيل ملف الترجمة من الرابط المؤقّت الذي تعيده OpenSubtitles.
 *
 * الرابط يأتي من طرف خارجي، لذا يمرّ بحارس SSRF: HTTPS فقط، ورفض العناوين
 * الخاصة والمحجوزة، وسقف 10 ميغابايت.
 */
function shsXtreamSubtitleDownload(string $url): string
{
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $url) || !function_exists('curl_init')) { return ''; }
    $host = (string) parse_url($url, PHP_URL_HOST);
    $ip   = $host !== '' ? @gethostbyname($host) : '';
    if ($ip === '' || $ip === $host || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return ''; }
    $curl = curl_init($url);
    if ($curl === false) { return ''; }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 35,
        CURLOPT_CONNECTTIMEOUT  => 8,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 3,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE     => 10485760,
        CURLOPT_USERAGENT       => 'ShashetyIPTV/2.0',
        CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
    ]);
    $raw    = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return ($status >= 200 && $status < 300 && is_string($raw) && strlen(trim($raw)) > 5) ? $raw : '';
}

/**
 * بيانات الحلقة اللازمة للبحث عن ترجمة.
 *
 * @return array<string,mixed>|null
 */
function shsSubtitleEpisodeContext(int $episodeId): ?array
{
    $stmt = db()->prepare("SELECT e.id, e.title AS episode_title, e.subtitle_url, s.name AS series_name
                           FROM episodes e INNER JOIN series s ON s.id = e.series_id
                           WHERE e.id = ? LIMIT 1");
    $stmt->execute([$episodeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * عنوان البحث — نظّفه من الوسوم وكيانات HTML.
 *
 * السبب: العناوين تُحفظ من اللوحة عبر htmlspecialchars، فيصل الاسم
 * «فيلم &amp; رحلة» حرفياً إلى OpenSubtitles فلا يطابق شيئاً.
 *
 * @param array<string,mixed> $episode
 */
function shsSubtitleSearchTitle(array $episode): string
{
    $raw = (string) ($episode['episode_title'] ?? '');
    if (trim($raw) === '') { $raw = (string) ($episode['series_name'] ?? ''); }
    return trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * جلسة خدمة الترجمة: مفتاح API + توكن الدخول + اللغة الافتراضية.
 *
 * @return array{headers:array<int,string>,lang:string}|null
 */
function shsSubtitleServiceSession(): ?array
{
    $cfg = db()->query("SELECT setting_key, setting_value FROM settings
                        WHERE setting_key IN ('os_api_key','os_username','os_password','sub_default_language','mv_subtitle_language')")
               ->fetchAll(PDO::FETCH_KEY_PAIR);

    $key  = trim((string) ($cfg['os_api_key'] ?? ''));
    $user = trim((string) ($cfg['os_username'] ?? ''));
    $pass = trim((string) ($cfg['os_password'] ?? ''));
    if ($key === '' || $user === '' || $pass === '') { return null; }

    $headers = ['Accept: application/json', 'Api-Key: ' . $key, 'User-Agent: ShashetyIPTV/2.0'];
    [$status, $raw] = shsXtreamSubtitleRequest('https://api.opensubtitles.com/api/v1/login', 'POST', $headers, ['username' => $user, 'password' => $pass]);
    $login = json_decode($raw, true);
    $token = is_array($login) ? (string) ($login['token'] ?? '') : '';
    if ($status !== 200 || $token === '') { return null; }

    $lang = strtolower(trim((string) ($cfg['mv_subtitle_language'] ?? $cfg['sub_default_language'] ?? 'ar')));
    if (!preg_match('/^[a-z]{2,3}$/', $lang)) { $lang = 'ar'; }

    return ['headers' => array_merge($headers, ['Authorization: Bearer ' . $token]), 'lang' => $lang];
}

/**
 * ينزّل ملفاً بعينه ويحفظه محلياً بصيغة WEBVTT ثم يربطه بالحلقة.
 *
 * التحويل ضروري: OpenSubtitles تعيد SRT، و<track> في المتصفح لا يقبل إلا
 * WEBVTT. وتصحيح الترميز ضروري كذلك لأن كثيراً من ملفات الترجمة العربية
 * مخزّنة بـWindows-1256 فتظهر طلاسمَ لو مرّت كما هي.
 *
 * @param array{headers:array<int,string>,lang:string} $svc
 * @return string رابط الملف المحفوظ، أو '' عند الفشل.
 */
function shsSubtitleFetchAndStore(array $svc, int $fileId, int $episodeId, string $prefix = 'sub_', bool $onlyIfEmpty = false): string
{
    [$status, $raw] = shsXtreamSubtitleRequest('https://api.opensubtitles.com/api/v1/download', 'POST', $svc['headers'], ['file_id' => $fileId, 'sub_format' => 'srt']);
    $payload   = json_decode($raw, true);
    $remoteUrl = is_array($payload) ? trim((string) ($payload['link'] ?? '')) : '';
    $text      = ($status === 200 && $remoteUrl !== '') ? shsXtreamSubtitleDownload($remoteUrl) : '';
    if ($text === '') { return ''; }

    if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
        $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1256, ISO-8859-6, Windows-1252');
    }
    $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
    if (stripos(ltrim($text), 'WEBVTT') !== 0) {
        $text = "WEBVTT\n\n" . (string) preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $text);
    }

    $dir = __DIR__ . '/uploads/subtitles/';
    if ((!is_dir($dir) && !@mkdir($dir, 0755, true)) || !is_writable($dir)) { return ''; }

    /* المجلد يُنشأ هنا على الخوادم الحيّة، فقد لا يصلها ملف .htaccess المرفق.
       نكتبه عند أول ترجمة: بدون AddType يُرسَل .vtt بنوع text/plain فيرفضه
       <track> بصمت — ترجمة لا تظهر بلا أي رسالة خطأ. */
    $htaccess = $dir . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "AddType text/vtt .vtt\n\n"
            . "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n\n"
            . "<FilesMatch \"\\.(php|phtml|phar|php[0-9]|cgi|pl|py|sh)$\">\n"
            . "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n"
            . "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n"
            . "</FilesMatch>\n",
            LOCK_EX
        );
    }

    $name = $prefix . bin2hex(random_bytes(12)) . '.vtt';
    if (@file_put_contents($dir . $name, $text, LOCK_EX) === false) { return ''; }

    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/');
    $url  = ($base === '' ? '' : $base) . '/uploads/subtitles/' . $name;

    $sql = $onlyIfEmpty
        ? "UPDATE episodes SET subtitle_url = ? WHERE id = ? AND (subtitle_url IS NULL OR subtitle_url = '')"
        : "UPDATE episodes SET subtitle_url = ? WHERE id = ?";
    db()->prepare($sql)->execute([$url, $episodeId]);

    return $url;
}

/**
 * subtitle_options — قائمة الترجمات المتاحة ليختار المشاهد.
 *
 * @return never
 */
function getSubtitleOptions()
{
    $episodeId = isset($_GET['episode_id']) ? (int) $_GET['episode_id'] : 0;
    if ($episodeId <= 0) {
        jsonResponse(['success' => false, 'error' => 'معرّف الفيلم غير صالح'], 400);
        return;
    }
    if (!rateLimit('api:subtitle-options:' . clientIp(), 4, 60)) {
        jsonResponse(['success' => false, 'error' => 'يرجى الانتظار قليلاً ثم أعد المحاولة'], 429);
        return;
    }

    try {
        $episode = shsSubtitleEpisodeContext($episodeId);
        $svc     = $episode ? shsSubtitleServiceSession() : null;
        if (!$episode || !$svc) {
            jsonResponse(['success' => true, 'options' => [], 'message' => 'خدمة الترجمة غير مهيأة']);
            return;
        }

        $title = shsSubtitleSearchTitle($episode);
        if ($title === '') {
            jsonResponse(['success' => true, 'options' => [], 'message' => 'لا توجد نتائج ترجمة مطابقة']);
            return;
        }

        $query = http_build_query([
            'query'           => $title,
            'languages'       => $svc['lang'],
            'order_by'        => 'download_count',
            'order_direction' => 'desc',
            'per_page'        => 12,
        ]);
        [$status, $raw] = shsXtreamSubtitleRequest('https://api.opensubtitles.com/api/v1/subtitles?' . $query, 'GET', $svc['headers']);
        $data    = json_decode($raw, true);
        $options = [];

        foreach ((array) ($data['data'] ?? []) as $item) {
            $attr = (array) (($item['attributes'] ?? []) ?: []);
            $file = $attr['files'][0] ?? [];
            $fid  = (int) ($file['file_id'] ?? 0);
            if ($fid <= 0) { continue; }
            $options[] = [
                'file_id'   => $fid,
                'title'     => (string) ($attr['feature_details']['title'] ?? $attr['release'] ?? 'ترجمة'),
                'year'      => (string) ($attr['feature_details']['year'] ?? ''),
                'language'  => (string) ($attr['language'] ?? ''),
                'downloads' => (int) ($attr['download_count'] ?? 0),
                'release'   => (string) ($attr['release'] ?? ''),
            ];
        }

        jsonResponse([
            'success' => true,
            'options' => $options,
            'message' => empty($options) ? 'لا توجد نتائج ترجمة مطابقة لهذا الفيلم' : '',
        ]);
    } catch (Throwable $e) {
        error_log('Subtitle options failed: ' . $e->getMessage());
        jsonResponse(['success' => true, 'options' => []]);
    }
}

/**
 * subtitle_download — تنزيل الترجمة المختارة وحفظها محلياً.
 *
 * @return never
 */
function downloadEpisodeSubtitle()
{
    $episodeId = isset($_POST['episode_id']) ? (int) $_POST['episode_id'] : 0;
    $fileId    = isset($_POST['file_id'])    ? (int) $_POST['file_id']    : 0;
    if ($episodeId <= 0 || $fileId <= 0) {
        jsonResponse(['success' => false, 'error' => 'طلب غير صالح'], 400);
        return;
    }
    if (!rateLimit('api:subtitle-download:' . clientIp(), 4, 60)) {
        jsonResponse(['success' => false, 'error' => 'يرجى الانتظار قليلاً ثم أعد المحاولة'], 429);
        return;
    }

    try {
        $episode = shsSubtitleEpisodeContext($episodeId);
        $svc     = $episode ? shsSubtitleServiceSession() : null;
        if (!$episode || !$svc) {
            jsonResponse(['success' => false, 'error' => 'خدمة الترجمة غير مهيأة'], 400);
            return;
        }

        $url = shsSubtitleFetchAndStore($svc, $fileId, $episodeId, 'sub_', false);
        if ($url === '') {
            jsonResponse(['success' => false, 'error' => 'تعذّر تنزيل الترجمة المختارة'], 502);
            return;
        }

        jsonResponse(['success' => true, 'subtitle_url' => $url]);
    } catch (Throwable $e) {
        error_log('Subtitle download failed: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'تعذّر حفظ الترجمة'], 500);
    }
}
