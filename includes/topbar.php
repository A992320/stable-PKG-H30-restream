<?php
/* بيانات بطاقة الرخصة من بوابة التفعيل نفسها، بلا قيم تجريبية. */
$__licInfo       = is_array($_SESSION['license_info'] ?? null) ? $_SESSION['license_info'] : [];
$__licDays       = $_SESSION['license_days_left'] ?? ($__licInfo['days_left'] ?? 0);
$__licType       = (string) ($__licInfo['license_type_name'] ?? 'نشطة');
$__licExpiryRaw  = trim((string) ($__licInfo['expiry_date'] ?? $__licInfo['expiration'] ?? ''));
$__licTypeCode   = strtolower((string) ($__licInfo['license_type'] ?? ''));
$__licLifetime   = in_array($__licTypeCode, ['lifetime', 'unlimited'], true)
    || $__licDays === 'unlimited' || (is_numeric($__licDays) && (int) $__licDays > 9999);
$__licExpired    = !$__licLifetime && $__licExpiryRaw !== ''
    && (($__licExpiryTs = strtotime($__licExpiryRaw)) !== false) && $__licExpiryTs < time();
$__licOffline    = !empty($_SESSION['license_offline']) || !empty($__licInfo['offline_mode']);
$__licStatus     = $__licExpired ? 'expired' : ($__licOffline ? 'offline' : 'active');
$__licStatusText = $__licExpired ? 'Expired' : ($__licOffline ? 'Offline' : 'Active');
$__licExpiryText = $__licLifetime ? 'مدى الحياة' : ($__licExpiryRaw !== '' ? $__licExpiryRaw : 'غير محدد');
$__licMaxSites   = max(1, (int) ($__licInfo['max_sites'] ?? $__licInfo['sites_limit'] ?? $__licInfo['site_limit'] ?? 1));
$__licId         = function_exists('getLicenseKey') ? (string) (getLicenseKey() ?? 'غير متوفر') : 'غير متوفر';
$__licHwid       = function_exists('getMachineId') ? (string) getMachineId() : '';
$__licDaysText   = ($__licDays === 'unlimited' || (is_numeric($__licDays) && (int) $__licDays > 9999))
    ? '∞' : ((int) $__licDays . ' يوم');
?>
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sbrand" style="display:flex; align-items:center; gap:12px;">
    <div class="sbrand-icon" style="width: 38px; height: 38px; background: linear-gradient(135deg, #E50914, #9a050d); color: #fff; border-radius: 10px; margin:0; box-shadow: 0 4px 15px rgba(229,9,20,0.4); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <i data-lucide="layout-dashboard" style="width:1.2rem; height:1.2rem; stroke-width: 2.5;"></i>
    </div>
    <div class="sbrand-text" style="flex:1; display:flex; flex-direction:column; justify-content:center; text-align:left;">
        <div class="sbrand-name" style="font-family: 'Inter', 'Tajawal', sans-serif; font-size: 1.1rem; font-weight: 800; letter-spacing: 1.5px; color: var(--t1); line-height: 1.1;">DASHBOARD</div>
        <div style="font-size: 0.65rem; color: #E50914; font-weight: 800; letter-spacing: 2px; margin-top: 3px;">SH PRO V2.0</div>
    </div>
    <button class="desktop-toggle-btn" onclick="toggleDesktopSidebar()" title="<?= htmlspecialchars($t["tip_collapse"] ?? "طي / توسيع القائمة") ?>">
      <i data-lucide="chevron-right" id="dtoggle-icon"></i>
    </button>
  </div>
  <nav class="snav">
    <div class="snl"><?= $t["nav_main"] ?? "الرئيسية" ?></div>
    <button class="si on" onclick="S('dashboard');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="home"></i></span><?= $t["dashboard"] ?? "لوحة التحكم" ?></button>
    <div class="snl"><?= $t["nav_content"] ?? "المحتوى" ?></div>
    <button class="si" onclick="S('categories');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="layout-grid"></i></span><?= $t["categories"] ?? "الأقسام" ?></button>
    <button class="si" onclick="S('channels');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="tv"></i></span><?= $t["channels"] ?? "القنوات" ?></button>
    <button class="si" onclick="S('m3u-import');m3uLoadPlaylists();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="file-up"></i></span><?= $t["m3u_import"] ?? "استيراد M3U" ?></button>
    <!-- [XTREAM-NAV-START] -->
    <button class="si" onclick="S('xtream');xtreamLoadAccounts();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="satellite-dish" style="color:#F5A623"></i></span><?= $t["xtream_account"] ?? "حساب Xtream" ?></button>
    <!-- [XTREAM-NAV-END] -->
    <button class="si" onclick="S('series');loadSeries();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="film"></i></span><?= $t["series"] ?? "شاشتي" ?></button>
    <button class="si" onclick="S('vupload');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="upload-cloud"></i></span><?= $t["upload"] ?? "رفع الأفلام" ?></button>
    <button class="si" onclick="S('vmanage');vmLoad();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="video"></i></span><?= $t["manage"] ?? "إدارة الفيديوهات" ?></button>
    <button class="si" onclick="S('storage-mgmt');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="hard-drive" style="color:#38bdf8"></i></span><?= $t["storage_management"] ?? "إدارة التخزين" ?></button>
    <!-- [SUBS-NAV-START] نظام الاشتراكات -->
    <div class="snl"><?= $t["nav_subscriptions"] ?? "الاشتراكات" ?></div>
    <button class="si" onclick="S('subscriptions');loadSubscriptions();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="crown" style="color:#F5A623"></i></span><?= $t["nav_plans"] ?? "خطط الاشتراك" ?></button>
    <button class="si" onclick="S('coupons');loadCoupons();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="ticket" style="color:#00D084"></i></span><?= $t["nav_coupons"] ?? "أكواد التفعيل" ?></button>
    <button class="si" onclick="S('subscribers');loadSubscribers();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="user-check" style="color:#4CC9F0"></i></span><?= $t["nav_subscribers"] ?? "المشتركون" ?></button>
    <!-- [SUBS-NAV-END] -->
    <div class="snl"><?= $t["nav_management"] ?? "الإدارة" ?></div>
    <button class="si" onclick="window.location.href='update.php'"><span class="si-ic"><i data-lucide="refresh-cw"></i></span>التحديثات والنظام</button>
    <button class="si" onclick="S('api-settings');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="plug"></i></span><?= $t["api_settings"] ?? "إعدادات API" ?></button>
    <button class="si" onclick="S('site-settings');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="settings"></i></span><?= $t["settings"] ?? "إعدادات الموقع" ?></button>
    <button class="si" onclick="S('change-password');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="key"></i></span><?= $t["password"] ?? "كلمة المرور" ?></button>
    <button class="si" onclick="S('system-tools');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="wrench"></i></span><?= $t["tools"] ?? "صيانة النظام" ?></button>
    <button class="si" onclick="S('backup');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="database"></i></span><?= $t["backup"] ?? "النسخ الاحتياطي" ?></button>
    <button class="si" onclick="S('users');loadUsers();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="users"></i></span><?= $t["users"] ?? "إدارة المستخدمين" ?></button>
    <button class="si" onclick="S('login-logs');loadLoginLogs();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="shield"></i></span><?= $t["login_logs"] ?? "سجل الدخول" ?></button>
        <!-- Theme Button in Sidebar -->
    <div class="snl"><?= $t["customization"] ?? "التخصيص" ?></div>
    <button class="si" onclick="S('general-settings');loadGeneralSettings();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="sliders-horizontal" style="color:#F5A623"></i></span>⚙️ <?= $t["general_settings"] ?? "الإعدادات العامة" ?></button>
    <button class="si" onclick="toggleThemePanel();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="palette" style="color:#B36BFF"></i></span>🎨 <?= $t["themes_colors"] ?? "الثيمات والألوان" ?></button>
    <button class="si" onclick="S('frontend-control');loadFrontendToggles();closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="layout-dashboard" style="color:#00D084"></i></span><?= $t["frontend_control"] ?? "التحكم بالواجهة الأمامية" ?></button>
    <div class="snl"><?= $t["about_system"] ?? "حول النظام" ?></div>
    <button class="si" onclick="S('company-info');closeSidebar();addRipple(event,this)"><span class="si-ic"><i data-lucide="info" style="color:#4CC9F0"></i></span><?= $t["company_info"] ?? "حول الشركة" ?></button>
  </nav>

</aside>

<div class="main">
<header class="topbar">
  <button class="mob-menu-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="القائمة">
    <div class="ham-icon">
      <span></span><span></span><span></span>
    </div>
  </button>
  <span class="tbtitle" id="tbTitle"><?= $t["dashboard_word"] ?? "لوحة التحكم" ?></span>
  <div class="tbr">

    <!-- Theme Center (نُقل من الزرّ العائم أسفل الصفحة إلى الشريط العلوي) -->
    <button class="mode-toggle theme-top-btn" id="themeTopBtn" onclick="toggleThemePanel()" title="<?= htmlspecialchars($t["themes_colors"] ?? "الثيمات والألوان") ?>" aria-label="<?= htmlspecialchars($t["themes_colors"] ?? "الثيمات والألوان") ?>">
      <i class="fas fa-palette"></i>
    </button>

    <!-- Check Updates Button -->
    <button class="mode-toggle" id="checkUpdatesBtn" onclick="checkForUpdatesAjax()" title="البحث عن تحديثات" aria-label="البحث عن تحديثات" style="color: var(--t3);">
      <i class="fas fa-sync-alt" id="checkUpdatesIcon"></i>
    </button>

    <!-- Music Mini Player -->
    <div class="music-p-wrap">
      <div class="music-p-mini" onclick="toggleAdminMusic()" title="<?= htmlspecialchars($t["tip_music"] ?? "إيقاف / تشغيل موسيقى الخلفية") ?>">
          <i class="fas fa-music" style="color:var(--t3); font-size:0.8rem;"></i>
          <div class="m-eq paused" id="m_eq"><span></span><span></span><span></span></div>
      </div>
      <button class="music-p-manage" onclick="openMusicManager();event.stopPropagation()" title="<?= htmlspecialchars($t["tip_music_manage"] ?? "إدارة مقطوعات الموسيقى") ?>" aria-label="<?= htmlspecialchars($t["tip_music_manage"] ?? "إدارة مقطوعات الموسيقى") ?>">
        <i class="fas fa-chevron-down"></i>
      </button>
    </div>

    <!-- Day/Night Mode Toggle (إضافة) -->
    <button class="mode-toggle" id="modeToggle" onclick="toggleDayNight()" title="<?= htmlspecialchars($t["tip_darkmode"] ?? "تبديل الوضع الليلي / النهاري") ?>" aria-label="تبديل الوضع">
      <i class="fas fa-moon" id="modeIcon"></i>
    </button>

    <!-- Language Switcher -->
    <div class="lang-sw">
      <button class="lang-btn" onclick="document.getElementById('langDrop').classList.toggle('op'); event.stopPropagation();">
        <i class="fas fa-globe" style="color:var(--t3)"></i> <span><?= strtoupper($__cur_lang) ?></span>
      </button>
      <div class="lang-drop" id="langDrop">
        <a class="lang-opt" href="?lang=ar">
            <span class="lang-flag">🇸🇦</span><span><?= $t["lang_arabic"] ?? "العربية" ?></span>
        </a>
        <a class="lang-opt" href="?lang=en">
            <span class="lang-flag">🇬🇧</span><span>English</span>
        </a>
        <a class="lang-opt" href="?lang=tr">
            <span class="lang-flag">🇹🇷</span><span>Türkçe</span>
        </a>
      </div>
    </div>
    <!-- End Language Switcher -->
    
    <div class="lic-sw" id="licenseSw">
      <button type="button" class="lic-b" id="licenseBadge"
              onclick="toggleLicensePanel(event)" aria-expanded="false"
              aria-controls="licenseDrop" title="عرض معلومات الرخصة">
        <span class="lic-dot" id="licenseDot" data-status="<?= htmlspecialchars($__licStatus, ENT_QUOTES, 'UTF-8') ?>"></span>
        <span id="licenseBadgeText"><?= htmlspecialchars($__licType . ' · ' . $__licDaysText, ENT_QUOTES, 'UTF-8') ?></span>
        <i data-lucide="chevron-down" class="lic-caret" aria-hidden="true"></i>
      </button>

      <div class="lic-drop" id="licenseDrop" role="dialog" aria-label="معلومات الرخصة">
        <div class="lic-drop-head">
          <div>
            <strong>System License</strong>
            <small id="licenseCheckedAt">بيانات الرخصة الحالية</small>
          </div>
          <span class="lic-seal"><i data-lucide="badge-check"></i></span>
        </div>

        <div class="lic-info-list">
          <div class="lic-info-row">
            <span>Hardware ID</span>
            <b class="lic-hwid" id="licenseHardwareId" dir="ltr"><?= htmlspecialchars($__licHwid, ENT_QUOTES, 'UTF-8') ?></b>
          </div>
          <div class="lic-info-row">
            <span>License ID</span>
            <b class="lic-hwid" id="licenseId" dir="ltr"><?= htmlspecialchars($__licId, ENT_QUOTES, 'UTF-8') ?></b>
          </div>
          <div class="lic-info-row">
            <span>Status</span>
            <b class="lic-status lic-status--<?= htmlspecialchars($__licStatus, ENT_QUOTES, 'UTF-8') ?>" id="licenseStatus"><?= htmlspecialchars($__licStatusText, ENT_QUOTES, 'UTF-8') ?></b>
          </div>
          <div class="lic-info-row">
            <span>Expiration</span>
            <b id="licenseExpiration" dir="ltr"><?= htmlspecialchars($__licExpiryText, ENT_QUOTES, 'UTF-8') ?></b>
          </div>
          <div class="lic-info-row">
            <span>Max Sites</span>
            <b id="licenseMaxSites"><?= (int) $__licMaxSites ?></b>
          </div>
        </div>

        <div class="lic-server-note">
          <i data-lucide="server"></i>
          <span>الرخصة مرتبطة بسيرفر واحد لكل حساب.</span>
        </div>
        <a class="lic-renew" href="http://shshadmin.duckdns.org:8080/iptv/mgmt.php"
           target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()">
          <i data-lucide="key-round"></i>
          <span>Renew License</span>
        </a>
        <button type="button" class="lic-reload" id="licenseReloadBtn" onclick="refreshLicenseInfo(event)">
          <i data-lucide="refresh-cw" id="licenseReloadIcon"></i>
          <span>إعادة تحميل الرخصة</span>
        </button>
      </div>
    </div>

    <!-- ══ قائمة البروفايل المنسدلة (مع صورة + تسجيل خروج) ══ -->
    <div class="prof-sw" id="profSw">
      <button class="prof-btn" onclick="document.getElementById('profSw').classList.toggle('op'); event.stopPropagation();" aria-label="حساب المستخدم">
        <img class="prof-avt-img" src="assets/22.png" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="uavt" style="display:none"><?php echo strtoupper(substr($_admin_display ?? ($_SESSION['admin_username']??'A'),0,1)); ?></div>
        <span class="prof-name"><?php echo htmlspecialchars($_admin_display ?? ($_SESSION['admin_username']??'المدير')); ?></span>
        <i class="fas fa-chevron-down prof-caret"></i>
      </button>
      <div class="prof-drop">
        <div class="prof-head">
          <img class="prof-avt-img" src="assets/22.png" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
          <div class="uavt" style="display:none"><?php echo strtoupper(substr($_admin_display ?? ($_SESSION['admin_username']??'A'),0,1)); ?></div>
          <div class="prof-head-info">
            <b><?php echo htmlspecialchars($_admin_display ?? ($_SESSION['admin_username']??'المدير')); ?></b>
            <small><?php echo htmlspecialchars($_SESSION['admin_username']??''); ?></small>
          </div>
        </div>
        <button class="prof-logout" onclick="if(confirm('تسجيل الخروج؟'))location.href='logout.php'">
          <i data-lucide="log-out"></i><span><?= $t["logout"] ?? "تسجيل الخروج" ?></span>
        </button>
      </div>
    </div>
  </div>
</header>
<script>
(function(){
  'use strict';
  function el(id){ return document.getElementById(id); }

  window.toggleLicensePanel = function(event){
    if(event) event.stopPropagation();
    var wrap = el('licenseSw');
    var badge = el('licenseBadge');
    if(!wrap || !badge) return;
    var open = !wrap.classList.contains('op');
    wrap.classList.toggle('op', open);
    badge.setAttribute('aria-expanded', open ? 'true' : 'false');
    var lang = el('langDrop');
    var profile = el('profSw');
    if(open && lang) lang.classList.remove('op');
    if(open && profile) profile.classList.remove('op');
  };

  function setStatus(status, label){
    var statusEl = el('licenseStatus');
    var dot = el('licenseDot');
    status = ['active','expired','offline'].indexOf(status) >= 0 ? status : 'offline';
    if(statusEl){
      statusEl.textContent = label || (status === 'active' ? 'Active' : status === 'expired' ? 'Expired' : 'Offline');
      statusEl.className = 'lic-status lic-status--' + status;
    }
    if(dot) dot.setAttribute('data-status', status);
  }

  window.refreshLicenseInfo = function(event){
    if(event) event.stopPropagation();
    var button = el('licenseReloadBtn');
    var icon = el('licenseReloadIcon');
    if(!button || button.disabled) return;
    button.disabled = true;
    button.classList.add('is-loading');
    if(icon) icon.classList.add('lic-spin');

    var request = (typeof window.api === 'function')
      ? window.api({ajax_action:'reload_license_info'}, {noCache:true})
      : Promise.reject(new Error('API unavailable'));

    request.then(function(data){
      if(!data || typeof data !== 'object') throw new Error('Invalid response');
      if(data.hardware_id != null && el('licenseHardwareId')) el('licenseHardwareId').textContent = String(data.hardware_id);
      if(data.license_id != null && el('licenseId')) el('licenseId').textContent = String(data.license_id);
      if(data.expiration != null && el('licenseExpiration')) el('licenseExpiration').textContent = String(data.expiration);
      if(data.max_sites != null && el('licenseMaxSites')) el('licenseMaxSites').textContent = String(data.max_sites);
      if(data.checked_at && el('licenseCheckedAt')) el('licenseCheckedAt').textContent = 'آخر تحقق: ' + data.checked_at;
      setStatus(String(data.status || (data.success ? 'active' : 'offline')), String(data.status_label || ''));

      if(data.success){
        var type = String(data.license_type_name || 'الرخصة');
        var days = data.days_left;
        var daysText = (days === 'unlimited' || Number(days) > 9999) ? '∞' : (days != null ? String(days) + ' يوم' : '');
        if(el('licenseBadgeText')) el('licenseBadgeText').textContent = type + (daysText ? ' · ' + daysText : '');
      }
      if(typeof window.toast === 'function'){
        window.toast(data.message || (data.success ? 'تم تحديث بيانات الرخصة' : 'تعذّر تحديث الرخصة'), data.success ? 's' : 'w');
      }
    }).catch(function(){
      setStatus('offline', 'Offline');
      if(typeof window.toast === 'function') window.toast('تعذّر الاتصال بسيرفر الرخص', 'e');
    }).finally(function(){
      button.disabled = false;
      button.classList.remove('is-loading');
      if(icon) icon.classList.remove('lic-spin');
    });
  };

  document.addEventListener('click', function(event){
    var wrap = el('licenseSw');
    var badge = el('licenseBadge');
    if(wrap && !wrap.contains(event.target)){
      wrap.classList.remove('op');
      if(badge) badge.setAttribute('aria-expanded', 'false');
    }
  });
  document.addEventListener('keydown', function(event){
    if(event.key !== 'Escape') return;
    var wrap = el('licenseSw');
    var badge = el('licenseBadge');
    if(wrap) wrap.classList.remove('op');
    if(badge) badge.setAttribute('aria-expanded', 'false');
  });
})();
</script>

<!-- ══ نافذة إدارة موسيقى الخلفية (رفع / اختيار / حذف مقطوعات mp3) — Admin Music Manager ══ -->
<div class="mbd" id="musicManagerM">
  <div class="mbox">
    <div class="mhd">
      <div class="mhd-title"><i class="fas fa-music"></i> <?= htmlspecialchars($t["music_manager_title"] ?? "إدارة موسيقى الخلفية") ?></div>
      <button class="mclose" onclick="CM('musicManagerM')"><i class="fas fa-times"></i></button>
    </div>
    <div class="mbody">
      <div id="musicMgrAlert"></div>
      <div class="mm-upload-row">
        <label class="btn btn-g mm-upload-btn" for="musicUploadInp">
          <i class="fas fa-upload"></i> <?= htmlspecialchars($t["music_upload"] ?? "رفع مقطوعة MP3") ?>
        </label>
        <input type="file" id="musicUploadInp" accept="audio/mpeg,.mp3" style="display:none" onchange="uploadMusicFile(this)">
        <span id="musicUploadStatus" class="mm-upload-status"></span>
      </div>
      <div id="musicTrackList" class="mm-track-list">
        <div class="mm-empty"><span class="sp"></span></div>
      </div>
      <p class="mm-hint"><?= htmlspecialchars($t["music_hint"] ?? "الملفات المدعومة: MP3 فقط. اختر مقطوعة لتشغيلها كموسيقى خلفية للوحة التحكم.") ?></p>
    </div>
  </div>
</div>

<div class="pcont">
