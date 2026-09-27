</script>

<!-- Admin Hover Prefetching Booster - مضاف برمجياً -->
<script>
document.addEventListener('mouseover', function(e) {
    if(e.target.tagName === 'A' && e.target.href && e.target.href.startsWith(window.location.origin) && e.target.href.indexOf('#') === -1) {
        let l = document.createElement('link');
        l.rel = 'prefetch'; l.href = e.target.href;
        try { document.head.appendChild(l); } catch(err){}
    }
});
</script>
<script>
// === TAILSCALE DYNAMIC ACTION HANDLER (FIXED & THEMED) ===
let _isTailscaleRunning = false;

function fetchTailscaleStatus() {
    const statusTxt = document.getElementById('ts_display_status');
    const btnBox = document.getElementById('ts_display_btn');
    const btnLbl = document.getElementById('ts_btn_label');
    const btnIcon = btnBox.querySelector('i');
    const ipWrap = document.getElementById('ts_ip_wrap');
    const ipVal = document.getElementById('ts_ip_val');

    api({ ajax_action: 'tailscale_command', ts_action: 'status' }).then(res => {
        // حالة التأكد القاطع بأن النظام قيد العمل في الخلفية
        if(res.success && res.state === 'Running') {
            _isTailscaleRunning = true;
            
            statusTxt.textContent = 'متصل ومحمي ONLINE';
            statusTxt.style.cssText = 'font-size:0.75rem; font-weight:800; padding:3px 10px; border-radius:100px; background: rgba(0,208,132,.15); color: #00D084; border: 1px solid rgba(0,208,132,.3); float:left; transition: 0.3s;';
            
            btnBox.className = 'btn btn-g'; 
            btnBox.style.borderColor = 'rgba(229,9,20, 0.6)';
            btnBox.style.color = '#ff6b6b';
            btnBox.style.background = 'rgba(229,9,20,.1)';
            
            btnLbl.textContent = 'إيقاف الاتصال';
            btnIcon.className = 'fas fa-stop-circle';
            btnBox.style.pointerEvents = 'auto'; // إعادة تشغيل الزر
            
            if(res.ip) {
                ipWrap.style.display = 'block';
                // اضافة الـ IP وعدّاد الاجهزة المتصلة اللي جلبها البايثون الذكي!
                let peerStr = (res.peers_count > 0) ? `   [ 🌐 متصل معك: ${res.peers_count} أجهزة ]` : '   [ 🌐 لا توجد أجهزة متصلة ]';
                ipVal.innerHTML = res.ip + `<span style="color:var(--gold);font-size:0.75rem;">${peerStr}</span>`;
            }
        } else {
            _isTailscaleRunning = false;
            
            statusTxt.textContent = 'مُعطل OFFLINE';
            statusTxt.style.cssText = 'font-size:0.75rem; font-weight:800; padding:3px 10px; border-radius:100px; background: rgba(229,9,20,.15); color: var(--red); border: 1px solid rgba(229,9,20,.3); float:left; transition: 0.3s;';
            
            btnBox.className = 'btn btn-g'; 
            btnBox.style.borderColor = 'rgba(255,255,255,.14)';
            btnBox.style.color = 'var(--t2)';
            btnBox.style.background = 'var(--s3)';

            btnLbl.textContent = 'بدء الاتصال السري';
            btnIcon.className = 'fas fa-power-off';
            btnBox.style.pointerEvents = 'auto';
            ipWrap.style.display = 'none';
        }
    }).catch(err => {
         statusTxt.textContent = 'ERROR / تأكد من الصلاحيات';
         statusTxt.style.color = '#ff9900';
         btnBox.style.pointerEvents = 'auto';
    });
}

function executeTailscaleAction() {
    const targetAction = _isTailscaleRunning ? 'stop' : 'start';
    const btnBox = document.getElementById('ts_display_btn');
    const btnLbl = document.getElementById('ts_btn_label');
    const btnIcon = btnBox.querySelector('i');
    
    // ستايل "الانتظار/التحميل" الجذاب مع قفل الزر لتفادي دبل كليك
    btnBox.style.pointerEvents = 'none';
    btnLbl.textContent = 'جار المعالجة...';
    btnIcon.className = 'fas fa-spinner fa-spin';

    api({ ajax_action: 'tailscale_command', ts_action: targetAction }).then(res => {
        // ننتظر 1.5 ثانية لاعطاء نظام شبكات أوبونتو وقته للاستيعاب، ثم نفحص!
        setTimeout(() => { fetchTailscaleStatus(); }, 1500); 
    }).catch(()=>{
         setTimeout(() => { fetchTailscaleStatus(); }, 1500); 
    });
}

// === START ADMIN MUSIC PLAYER LOGIC (intero.mp3 fixed + مكتبة مقطوعات مخصّصة) ===
const INTERO_URL = '/iptv/intero.mp3';
// دعم اختيار مقطوعة مرفوعة من المدير بدل المقطوعة الثابتة — يبقى التشغيل الافتراضي كما كان تماماً
// إن لم يتم اختيار أي مقطوعة (توافق كامل مع السلوك القديم).
let _savedMusicTrackUrl = null;
try { _savedMusicTrackUrl = localStorage.getItem('shashety_music_track_url') || null; } catch(e) {}
let adminMusic = new Audio(_savedMusicTrackUrl || INTERO_URL);
adminMusic.loop = true;
let isMusicPlaying = false;

function initAdminMusic() {
    let savedPlay = localStorage.getItem('shashety_music_play');
    if(savedPlay === '1') {
        let pp = adminMusic.play();
        if(pp !== undefined) {
            pp.then(() => {
                isMusicPlaying = true;
                updateMusicMini(true);
            }).catch(() => {
                isMusicPlaying = false;
                updateMusicMini(false);
            });
        }
    } else {
        updateMusicMini(false);
    }
}

function playAdminMusic() {
    adminMusic.play().then(() => {
        isMusicPlaying = true;
        localStorage.setItem('shashety_music_play', '1');
        updateMusicMini(true);
    }).catch(e => {
        isMusicPlaying = false;
        localStorage.setItem('shashety_music_play', '0');
        updateMusicMini(false);
    });
}

function pauseAdminMusic() {
    adminMusic.pause();
    isMusicPlaying = false;
    localStorage.setItem('shashety_music_play', '0');
    updateMusicMini(false);
}

function toggleAdminMusic() {
    if(isMusicPlaying) pauseAdminMusic();
    else playAdminMusic();
}

function updateMusicMini(playing) {
    const eq = $('m_eq');
    if(!eq) return;
    if(playing) eq.classList.remove('paused');
    else eq.classList.add('paused');
}
// === END ADMIN MUSIC PLAYER LOGIC ===

// === START ADMIN MUSIC MANAGER (رفع / اختيار / حذف مقطوعات — يستخدم واجهات ajax الموجودة مسبقاً) ===
let _musicMgrLoaded = false;

function openMusicManager() {
    const m = $('musicManagerM');
    if(!m) return;
    m.classList.add('op');
    document.body.style.overflow = 'hidden';
    loadMusicTracks();
}

function _musicMgrEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function loadMusicTracks() {
    const box = $('musicTrackList');
    if(!box) return;
    box.innerHTML = '<div class="mm-empty"><span class="sp"></span></div>';
    api({ ajax_action: 'list_admin_music' }).then(d => {
        if(!d || !d.success) { box.innerHTML = '<div class="mm-empty">' + _musicMgrEsc(d && d.error || 'تعذّر تحميل قائمة المقطوعات') + '</div>'; return; }
        const tracks = d.tracks || [];
        if(!tracks.length) { box.innerHTML = '<div class="mm-empty">لا توجد مقطوعات مرفوعة بعد — استخدم زر الرفع أعلاه.</div>'; return; }
        let curUrl = null;
        try { curUrl = localStorage.getItem('shashety_music_track_url'); } catch(e) {}
        box.innerHTML = tracks.map(tr => {
            const active = curUrl && curUrl === tr.url;
            return `<div class="mm-track-item${active ? ' active' : ''}">
                <div class="mm-track-name" title="${_musicMgrEsc(tr.name)}"><i class="fas fa-music"></i> ${_musicMgrEsc(tr.name)}</div>
                <div class="mm-track-actions">
                    <button class="mm-btn-play" onclick="selectMusicTrack('${_musicMgrEsc(tr.url)}','${_musicMgrEsc(tr.name)}',this)" title="${active ? 'قيد الاستخدام' : 'استخدام كموسيقى خلفية'}"><i class="fas fa-${active ? 'check' : 'play'}"></i></button>
                    <button class="mm-btn-del" onclick="deleteMusicTrack('${_musicMgrEsc(tr.name)}')" title="حذف"><i class="fas fa-trash"></i></button>
                </div>
            </div>`;
        }).join('');
    }).catch(() => { box.innerHTML = '<div class="mm-empty">خطأ في الاتصال بالخادم</div>'; });
}

function selectMusicTrack(url, name) {
    if(!url) return;
    const wasPlaying = isMusicPlaying;
    try { adminMusic.pause(); } catch(e) {}
    adminMusic = new Audio(url);
    adminMusic.loop = true;
    try { localStorage.setItem('shashety_music_track_url', url); } catch(e) {}
    if(wasPlaying) playAdminMusic(); else updateMusicMini(false);
    al('musicMgrAlert', '✅ تم تعيين «' + _musicMgrEsc(name) + '» كموسيقى الخلفية', 's');
    loadMusicTracks();
}

function deleteMusicTrack(name) {
    if(!confirm('حذف هذه المقطوعة نهائياً؟')) return;
    api({ ajax_action: 'delete_admin_music', file: name }).then(d => {
        if(d && d.success) {
            let curUrl = null;
            try { curUrl = localStorage.getItem('shashety_music_track_url'); } catch(e) {}
            // إن كانت المقطوعة المحذوفة هي المستخدمة حالياً، ارجع للمقطوعة الافتراضية الثابتة (توافق كامل)
            if(curUrl && curUrl.indexOf(name) !== -1) {
                try { localStorage.removeItem('shashety_music_track_url'); } catch(e) {}
                const wasPlaying = isMusicPlaying;
                try { adminMusic.pause(); } catch(e) {}
                adminMusic = new Audio(INTERO_URL);
                adminMusic.loop = true;
                if(wasPlaying) playAdminMusic(); else updateMusicMini(false);
            }
            al('musicMgrAlert', '🗑️ تم حذف المقطوعة', 's');
            loadMusicTracks();
        } else {
            al('musicMgrAlert', (d && d.error) || 'تعذّر حذف الملف', 'e');
        }
    }).catch(() => al('musicMgrAlert', 'خطأ في الاتصال بالخادم', 'e'));
}

function uploadMusicFile(inp) {
    const f = inp.files && inp.files[0];
    if(!f) return;
    if(!/\.mp3$/i.test(f.name)) { al('musicMgrAlert', 'عذراً، يُقبل امتداد mp3 فقط', 'e'); inp.value=''; return; }
    const status = $('musicUploadStatus');
    if(status) status.innerHTML = '<span class="sp"></span>';
    const fd = new FormData();
    if(window.csrfToken) fd.append('csrf_token', window.csrfToken);
    fd.append('ajax_action', 'upload_admin_music');
    fd.append('music_file', f);
    fetch(location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if(status) status.innerHTML = '';
            inp.value = '';
            if(d && d.success) { al('musicMgrAlert', '✅ تم رفع المقطوعة بنجاح', 's'); loadMusicTracks(); }
            else al('musicMgrAlert', (d && d.error) || 'فشل الرفع', 'e');
        })
        .catch(() => { if(status) status.innerHTML=''; inp.value=''; al('musicMgrAlert', 'انقطع الاتصال بالخادم أثناء الرفع', 'e'); });
}
// === END ADMIN MUSIC MANAGER ===

document.addEventListener("DOMContentLoaded", () => {
    setTimeout(()=>{
        let activeSec = sessionStorage.getItem('active_sec');
        if(activeSec && activeSec !== 'dashboard') {
            let btn = document.querySelector(`.si[onclick*="S('${activeSec}')"]`);
            if(btn) { btn.click(); } else { S(activeSec); }
        }
    }, 150);
    initAdminMusic();
    fetchTailscaleStatus();
    if(typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});

document.querySelectorAll(".si[onclick*='system-tools']").forEach(n => {
    n.addEventListener("click", () => setTimeout(fetchTailscaleStatus, 400));
});
// === END TAILSCALE HANDLER ===</script>
<script src="https://unpkg.com/lucide@latest"></script>

<?php
/* ══════════════════════════════════════════════════════════════
   التحديث اللحظي (WebSocket) — كان معطلاً ويُحدث خطأين في كل طلب
   ──────────────────────────────────────────────────────────────
   كان هذان السطران يُحمَّلان **بلا أي شرط**:

       <script src="/socket.io/socket.io.js"></script>
       <script src="/iptv/assets/js/websocket_client.js"></script>

   وينتج عنهما عطلان في كل فتح لصفحة اللوحة:

   ① المسار /socket.io/socket.io.js لا يوجد على القرص إطلاقاً —
      يوفّره خادم Node عبر وسيط. وبما أن الخادم غير مشغَّل ولا يوجد
      وسيط في Apache، فالنتيجة **404 في كل مرة** + خطأ في وحدة
      تحكم المتصفح + طلب شبكي ضائع.

   ② المسار الثاني مكتوب بـ /iptv/ ثابتاً داخل الكود، فينكسر فوراً
      إن نُقل الموقع إلى جذر النطاق أو إلى مجلد آخر.

   وأهم من ذلك: الميزة **غير موصولة من الأساس**. فحصت المشروع كاملاً:
     • الدالة broadcast_ws_event لا تُستدعى من أي مكان (صفر استدعاء)
     • الملف core/websocket_helper.php لا يُضمَّن في أي ملف
   أي أن لا شيء يرسل أحداثاً، فلا شيء يُستقبل حتى لو شغّلت الخادم.

   الحل: نفس النمط الصحيح المستخدم في الواجهة العامة
   (site/includes/footer.php) — لا تُحمَّل إلا عند تفعيلها صراحةً،
   مع مسار قاعدة ديناميكي بدل /iptv/ الثابت.

   لتفعيلها لاحقاً: ضع ENABLE_WEBSOCKET=1 في .env بعد تشغيل خادم
   Node وإعداد وسيط /socket.io/ في Apache.
   ══════════════════════════════════════════════════════════════ */
$__wsOn   = function_exists('env') && (string) env('ENABLE_WEBSOCKET', '0') === '1';
$__wsBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
?>
<?php if ($__wsOn): ?>
<script src="<?= htmlspecialchars($__wsBase, ENT_QUOTES, 'UTF-8') ?>/socket.io/socket.io.js" defer></script>
<script src="<?= htmlspecialchars($__wsBase, ENT_QUOTES, 'UTF-8') ?>/assets/js/websocket_client.js" defer></script>
<?php endif; ?>
