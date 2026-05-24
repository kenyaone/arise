<?php
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/pages/lang.php';

// ── Early intercept for pages that output their own full HTML ──
$_early_page = $_GET['p'] ?? '';
if ($_early_page === 'datapost' || $_early_page === 'pwa_datapost') {
    include __DIR__.'/pages/pwa_datapost.php';
    exit;
}
if ($_early_page === 'donor_report') {
    include __DIR__.'/pages/donor_report.php';
    exit;
}
if ($_early_page === 'lesson') {
    $_slug = $_GET['slug'] ?? '';
    if ($_slug) {
        require_once __DIR__ . '/../includes/config.php';
        $stmt = db()->prepare('SELECT l.*, m.slug AS module_slug, m.id AS module_id, m.title AS module_title FROM lessons l JOIN modules m ON l.module_id=m.id WHERE l.slug=:s AND l.is_active=1');
        $stmt->bindValue(':s', $_slug);
        $lesson = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($lesson && ($lesson['lesson_type'] ?? '') === 'interactive' && !empty($lesson['file_path'])) {
            $isRefusal = strpos($lesson['slug'], 'refusal') !== false;
            $vRow = (!$isRefusal) ? db()->querySingle("SELECT file_path FROM lessons WHERE module_id=" . intval($lesson['module_id']) . " AND lesson_type='video' AND is_active=1 LIMIT 1", true) : null;
            $videoUrl = ($vRow && !empty($vRow['file_path'])) ? UPLOAD_URL . $vRow['file_path'] : '';
            $htmlFile = UPLOAD_PATH . $lesson['file_path'];
            if (file_exists($htmlFile)) {
                $html = file_get_contents($htmlFile);
                if ($isRefusal) {
                    $html = preg_replace('/<!-- SLIDE 9: Video -->.*?<!-- SLIDE 10:/s', '<!-- SLIDE 9:', $html);
                    $html = str_replace('>1 / 10<', '>1 / 9<', $html);
                }
                $hash = getSessionHash();
                $resumeSlide = 0;
                if ($hash) {
                    $prog = db()->querySingle("SELECT last_slide FROM lesson_progress WHERE session_hash='" . SQLite3::escapeString($hash) . "' AND lesson_id=" . intval($lesson['id']));
                    if ($prog) $resumeSlide = intval($prog);
                }
                $modId    = intval($lesson['module_id']);
                $modSlug  = addslashes($lesson['module_slug']);
                $modTitle = htmlspecialchars($lesson['module_title'] ?? $lesson['module_slug'], ENT_QUOTES);
                $alreadyVoted = $hash ? (bool)db()->querySingle("SELECT id FROM module_feedback WHERE module_id=$modId AND session_hash='" . SQLite3::escapeString($hash) . "'") : false;
                if ($alreadyVoted) {
                    $existingRating = (int)db()->querySingle("SELECT rating FROM module_feedback WHERE module_id=$modId AND session_hash='" . SQLite3::escapeString($hash) . "'");
                    $pollHtml = '<p style="color:#166534;font-weight:600;margin-bottom:10px;">✅ You already rated this module (' . $existingRating . '/5 ⭐). Thanks!</p>';
                } else {
                    $pollHtml = '<form id="arise-poll-form" style="margin-bottom:12px;"><p style="font-size:.88rem;color:#374151;margin-bottom:8px;font-weight:600;">Rate this module:</p><div id="star-row" style="display:flex;gap:8px;margin-bottom:10px;font-size:1.6rem;cursor:pointer;"><span class="star" data-v="1">☆</span><span class="star" data-v="2">☆</span><span class="star" data-v="3">☆</span><span class="star" data-v="4">☆</span><span class="star" data-v="5">☆</span></div><input type="hidden" id="poll-rating" name="poll_rating" value=""><textarea id="poll-useful" name="most_useful" placeholder="What was most useful? (optional)" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;margin-bottom:8px;resize:none;height:60px;box-sizing:border-box;"></textarea><label style="display:flex;align-items:center;gap:8px;font-size:.85rem;margin-bottom:10px;cursor:pointer;"><input type="checkbox" id="poll-rec" name="would_recommend" value="1"> I would recommend this module</label><button id="poll-submit" type="submit" disabled style="background:#7c3aed;color:white;border:none;padding:9px 22px;border-radius:8px;font-weight:700;cursor:pointer;opacity:.5;">Submit Feedback</button></form><div id="poll-thanks" style="display:none;color:#166534;font-weight:600;">✅ Thanks for your feedback!</div><script>(function(){var stars=document.querySelectorAll(\'#arise-poll-form .star\');var ratingInput=document.getElementById(\'poll-rating\');var submitBtn=document.getElementById(\'poll-submit\');stars.forEach(function(s){s.addEventListener(\'click\',function(){var v=parseInt(s.getAttribute(\'data-v\'));ratingInput.value=v;stars.forEach(function(x,i){x.textContent=i<v?\'★\':\'☆\';x.style.color=i<v?\'#f59e0b\':\'#9ca3af\';});submitBtn.disabled=false;submitBtn.style.opacity=\'1\';});});document.getElementById(\'arise-poll-form\').addEventListener(\'submit\',function(e){e.preventDefault();var rating=ratingInput.value;if(!rating)return;var useful=document.getElementById(\'poll-useful\').value;var rec=document.getElementById(\'poll-rec\').checked?1:0;var fd=new FormData();fd.append(\'poll_rating\',rating);fd.append(\'most_useful\',useful);fd.append(\'would_recommend\',rec);fd.append(\'module_slug\',window.ARISE_MODULE_SLUG||\'\');fetch(\'/arise/?p=module&slug=\'+(window.ARISE_MODULE_SLUG||\'\'),{method:\'POST\',body:fd}).then(function(){document.getElementById(\'arise-poll-form\').style.display=\'none\';document.getElementById(\'poll-thanks\').style.display=\'block\';}).catch(function(){});});})();</script>';
                }
                // Check if behavioral survey already done for this session + module
                $surveydone = $hash ? (bool)db()->querySingle("SELECT id FROM behavioral_surveys WHERE session_hash='" . SQLite3::escapeString($hash) . "' AND module_id=$modId") : false;
                $surveyBlock = $surveydone
                    ? '<div style="background:#dcfce7;border:2px solid #34d399;border-radius:12px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:10px;"><span style="font-size:1.4rem;">✅</span><div><div style="font-size:.92rem;font-weight:800;color:#166534;">Impact Survey Complete</div><div style="font-size:.78rem;color:#4ade80;margin-top:1px;">Your voice has been recorded — thank you!</div></div></div>'
                    : '<div style="margin-bottom:16px;"><style>@keyframes pulse-survey{0%,100%{box-shadow:0 0 0 0 rgba(220,38,38,.45)}50%{box-shadow:0 0 0 10px rgba(220,38,38,0)}}</style><a href="/arise/?p=survey&module=' . $modSlug . '" style="display:block;background:linear-gradient(135deg,#dc2626,#b91c1c);color:white;padding:16px 18px;border-radius:12px;text-decoration:none;animation:pulse-survey 2s infinite;"><div style="display:flex;align-items:center;gap:10px;"><span style="font-size:1.6rem;">🔴</span><div style="flex:1;"><div style="font-size:1rem;font-weight:900;letter-spacing:.01em;">Did This Change You? Tell Us Now</div><div style="font-size:.78rem;opacity:.9;margin-top:3px;font-weight:600;">3 questions · 60 seconds · Required before you continue</div></div><span style="font-size:1.2rem;">→</span></div></a></div>';

                // Next module lookup (for end-of-lesson CTAs)
                $_curSo = (int)db()->querySingle("SELECT sort_order FROM modules WHERE id=$modId");
                $_nmStmt = db()->prepare('SELECT slug, title, icon FROM modules WHERE is_active = 1 AND sort_order > :so ORDER BY sort_order ASC LIMIT 1');
                $_nmStmt->bindValue(':so', $_curSo);
                $_nm = $_nmStmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
                if ($_nm) {
                    $nmSlugE  = htmlspecialchars($_nm['slug'], ENT_QUOTES);
                    $nmTitleE = htmlspecialchars($_nm['title'], ENT_QUOTES);
                    $nmIconE  = $_nm['icon'] ?: '📘';
                    $nextModuleCard = '<a href="/arise/?p=module&slug=' . $nmSlugE . '" style="margin-top:14px;display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,#0a5e2a,#1a8a40);color:#fff;border-radius:14px;padding:14px 18px;text-decoration:none;box-shadow:0 6px 18px rgba(10,94,42,.3);">'
                        . '<span style="font-size:1.7rem;flex-shrink:0;">' . $nmIconE . '</span>'
                        . '<div style="flex:1;min-width:0;"><div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:#fcd34d;margin-bottom:3px;">Next Module</div>'
                        . '<div style="font-size:.95rem;font-weight:800;color:#fff;line-height:1.3;">' . $nmTitleE . '</div></div>'
                        . '<span style="flex-shrink:0;background:rgba(255,255,255,.18);padding:8px 14px;border-radius:10px;font-size:.78rem;font-weight:900;white-space:nowrap;">Proceed &rarr;</span></a>';
                    $nextFloatBtn = '<a id="arise-next-module-btn" href="/arise/?p=module&slug=' . $nmSlugE . '" title="Proceed to: ' . $nmTitleE . '" style="position:fixed;bottom:14px;right:14px;z-index:99998;display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#0a5e2a,#1a8a40);color:#fff;border-radius:999px;padding:10px 18px;font-size:.85rem;font-weight:800;text-decoration:none;box-shadow:0 6px 20px rgba(10,94,42,.45);"><span>Next Module</span><span style="font-size:1.05rem;">&rarr;</span></a>';
                } else {
                    $nextModuleCard = '<div style="margin-top:14px;background:linear-gradient(135deg,#f5a623,#e8891a);border-radius:14px;padding:14px 18px;color:#fff;display:flex;align-items:center;gap:12px;box-shadow:0 6px 18px rgba(245,166,35,.3);"><span style="font-size:1.7rem;">🎉</span><div style="flex:1;"><div style="font-size:.95rem;font-weight:800;">You\'ve reached the final module!</div><div style="font-size:.78rem;opacity:.9;margin-top:2px;">Complete this one to finish the full ARISE journey.</div></div></div>';
                    $nextFloatBtn = '<a id="arise-next-module-btn" href="/arise/?p=modules" style="position:fixed;bottom:14px;right:14px;z-index:99998;display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#f5a623,#e8891a);color:#fff;border-radius:999px;padding:10px 18px;font-size:.85rem;font-weight:800;text-decoration:none;box-shadow:0 6px 20px rgba(245,166,35,.45);">🎉 All Modules</a>';
                }

                // Community section injected INSIDE score-box — appears automatically when quiz is submitted, no JS trigger needed
                $communityInside = '<div id="arise-community" style="margin-top:18px;padding-top:16px;border-top:2px solid #c4b5fd;">'
                    . '<h3 style="color:#7c3aed;margin:0 0 10px;font-size:.95rem;font-weight:700;">🎉 How was <em>' . $modTitle . '</em>?</h3>'
                    . $surveyBlock
                    . $pollHtml
                    . '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">'
                    . '<a href="/arise/?p=forum&module=' . $modSlug . '" style="background:#0ea271;color:white;padding:8px 14px;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;">💬 Discuss this Module</a>'
                    . '<a href="/arise/?p=ask&module=' . $modSlug . '" style="background:#6b7280;color:white;padding:8px 14px;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;">🔒 Ask Anonymously</a>'
                    . '</div>'
                    . $nextModuleCard
                    . '</div>';
                $inject = "<style>
body{margin:0!important;}
.slides-wrap,.lang-bar-inner,.nav-inner{max-width:100%!important;width:100%!important;box-sizing:border-box!important;}
.lang-bar,.nav-bar,.top-bar{width:100%!important;left:0!important;right:0!important;box-sizing:border-box!important;}
#arise-fs-btn{position:fixed;top:8px;right:10px;z-index:99999;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:8px;padding:7px 13px;font-size:.8rem;font-weight:700;cursor:pointer;letter-spacing:.02em;}
</style>
<script>
window.ARISE_LESSON_ID=" . intval($lesson['id']) . ";
window.ARISE_LESSON_SLUG='" . addslashes($lesson['slug']) . "';
window.ARISE_MODULE_SLUG='" . $modSlug . "';
window.ARISE_RESUME_SLIDE=" . $resumeSlide . ";
window.ARISE_VIDEO_URL='" . addslashes($videoUrl) . "';
(function(){
  function tryFS(el){if(!el)return;var fn=el.requestFullscreen||el.webkitRequestFullscreen||el.mozRequestFullScreen;if(fn)fn.call(el).catch(function(){});}
  function exitFS(){var fn=document.exitFullscreen||document.webkitExitFullscreen;if(fn)fn.call(document);}
  function isFS(){return !!(document.fullscreenElement||document.webkitFullscreenElement);}
  document.addEventListener('DOMContentLoaded',function(){
    var btn=document.createElement('button');
    btn.id='arise-fs-btn';
    btn.textContent='⛶ Fullscreen';
    btn.onclick=function(){isFS()?exitFS():tryFS(document.documentElement);};
    document.body.appendChild(btn);
    function onFSChange(){btn.textContent=isFS()?'✕ Exit Fullscreen':'⛶ Fullscreen';}
    document.addEventListener('fullscreenchange',onFSChange);
    document.addEventListener('webkitfullscreenchange',onFSChange);
    var v=document.getElementById('lessonVideo');
    if(v){
      v.removeAttribute('playsinline');
      v.addEventListener('play',function(){if(!isFS())tryFS(v);},{once:false});
      var vKey='arise_vpos_'+(window.ARISE_LESSON_SLUG||'unknown');
      v.addEventListener('loadedmetadata',function(){
        var saved=parseFloat(localStorage.getItem(vKey)||'0');
        if(saved>5&&saved<v.duration-5)v.currentTime=saved;
      });
      v.addEventListener('timeupdate',function(){
        if(v.currentTime>5)localStorage.setItem(vKey,v.currentTime);
      });
      v.addEventListener('ended',function(){localStorage.removeItem(vKey);});
    }
  });
})();
</script>";
                $html = str_replace('</head>', $inject . '</head>', $html);
                // Inject community section inside score-box, right after score-detail
                $html = str_replace(
                    '<div id="score-detail" style="margin-top:6px;color:#374151;font-size:.88rem;"></div>',
                    '<div id="score-detail" style="margin-top:6px;color:#374151;font-size:.88rem;"></div>' . $communityInside,
                    $html
                );
                // Persistent floating "Next Module" button
                $html = str_replace('</body>', $nextFloatBtn . '</body>', $html);
                ob_end_clean();
                echo $html;
                exit;
            }
        }
    }
}
if ($_early_page === 'api_lesson') {
    include __DIR__.'/pages/api_lesson.php';
    exit;
}
if ($_early_page === 'certificate') {
    include __DIR__.'/pages/certificate.php';
    exit;
}
if ($_early_page === 'map') {
    include __DIR__.'/pages/map.php';
    exit;
}

trackSession();
try { runAutoBackup(); } catch (Exception $e) {}

$modules = getModules();
$page = $_GET['p'] ?? '';

// Student logout
if (isset($_GET['logout'])) {
    unset($_SESSION['arise_student_id']);
    session_destroy();
    // Clear persistent session cookies
    setcookie('arise_ph',  '', ['expires' => time() - 3600, 'path' => '/arise/', 'httponly' => true, 'samesite' => 'Lax']);
    setcookie('arise_uid', '', ['expires' => time() - 3600, 'path' => '/arise/', 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: /arise/login');
    exit;
}

$student = getStudentBySession();

// Prevent caching of authenticated pages
if ($student || !empty($_SESSION['arise_admin_id'])) {
    header('Cache-Control: no-cache, no-store, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Admins and teachers can view student-facing pages (for review/preview)
$isAdminSession = !empty($_SESSION['arise_admin_id']);

// Redirect to login if not logged in and page requires login
if (!$student && !$isAdminSession && !in_array($page, ['login', 'register', 'register_submit', '', 'datapost', 'donor_report', 'forum', 'ask', 'ask_submit', 'survey'])) {
    header('Location: /arise/login');
    exit;
}

// Redirect root to login or home based on login status
if ($page === '') {
    if ($student) {
        $page = 'home';
    } elseif ($isAdminSession) {
        header('Location: /arise/admin/');
        exit;
    } else {
        header('Location: /arise/login');
        exit;
    }
}
$studentName = $student ? $student['full_name'] : null;
if ($student) {
    $stmt = db()->prepare('UPDATE students SET last_seen=CURRENT_TIMESTAMP WHERE id=:id');
    $stmt->bindValue(':id', $student['id']);
    $stmt->execute();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARISE — Health Education</title>
    <link rel="stylesheet" href="/arise/css/style.css">
    <link rel="manifest" href="/arise/manifest.json">
    <meta name="theme-color" content="#3D6318">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ARISE">
    <link rel="apple-touch-icon" sizes="180x180" href="/arise/css/apple-touch-icon-180.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/arise/css/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/arise/css/favicon-16.png">
    <script src="/arise/js/arise-sync.js" defer></script>
    <script src="/arise/js/session-guard.js" defer></script>
</head>
<body>
<?php $logoUrl = getLogoUrl(); ?>
<nav class="navbar">
    <div class="navbar-inner">
        <a href="/arise/" class="navbar-brand">
            <?php if ($logoUrl): ?>
                <img src="<?= $logoUrl ?>" alt="ARISE Logo">
            <?php else: ?>
                <div class="brand-icon">💜</div>
            <?php endif; ?>
            <div><h1>ARISE</h1><span>Health Education Platform</span></div>
        </a>
        <button class="menu-toggle" onclick="document.querySelector('.nav-links').classList.toggle('open')">&#9776;</button>
        <ul class="nav-links">
            <li><a href="/arise/" class="<?= $page==='home'?'active':'' ?>">🏠 <?= t('home') ?></a></li>
            <li><a href="/arise/?p=modules" class="<?= in_array($page,['modules','module','lesson'])?'active':'' ?>">📚 <?= t('modules') ?></a></li>
            <li><a href="/arise/?p=forum" class="<?= in_array($page,['forum','ask'])?'active':'' ?>" title="Community &amp; discussions">💬 Community</a></li>
            <li><a href="/arise/?p=challenge" class="<?= $page==='challenge'?'active':'' ?>" title="Weekly Challenge">💪 Challenge</a></li>
            <?php if ($studentName): ?>
                <li><a href="/arise/?p=dashboard" class="<?= in_array($page,['dashboard','my_progress'])?'active':'' ?>" style="background:rgba(245,230,66,.15);color:var(--acc);">⭐ <?= e(explode(' ',$studentName)[0]) ?></a></li>
                <li><a href="/arise/?logout=1" style="color:rgba(255,255,255,.5);font-size:.8rem;"><?= t('sign_out') ?></a></li>
            <?php else: ?>
                <li><a href="/arise/?p=login" class="<?= $page==='login'?'active':'' ?>" style="color:rgba(255,255,255,.8);"><?= t('sign_in') ?></a></li>
                <li><a href="/arise/?p=register" style="background:var(--acc);color:var(--pri-deep);font-weight:800;">✍️ <?= t('register') ?></a></li>
            <?php endif; ?>
            <li>
              <a href="/arise/?p=set_lang&lang=<?= ($_SESSION['arise_lang']??'en')==='sw'?'en':'sw' ?>"
                 style="font-size:.75rem;color:rgba(255,255,255,.6);padding:4px 8px;">
                <?= ($_SESSION['arise_lang']??'en')==='sw' ? '🇬🇧 EN' : '🇰🇪 SW' ?>
              </a>
            </li>
        </ul>
    </div>
</nav>

<?php
switch ($page) {
    case 'home':            include __DIR__.'/pages/home.php'; include __DIR__.'/pages/pwa_install.php'; break;
    case 'modules':         include __DIR__.'/pages/modules.php'; break;
    case 'module':          include __DIR__.'/pages/module_detail.php'; break;
    case 'lesson':          include __DIR__.'/pages/lesson.php'; break;
    case 'forum':           include __DIR__.'/pages/forum.php'; break;
    case 'ask':             include __DIR__.'/pages/ask.php'; break;
    case 'ask_submit':      include __DIR__.'/pages/ask_submit.php'; break;
    case 'resources':       include __DIR__.'/pages/resources.php'; break;
    case 'login':           include __DIR__.'/pages/login.php'; break;
    case 'register':        include __DIR__.'/pages/register.php'; break;
    case 'register_submit': include __DIR__.'/pages/register_submit.php'; break;
    case 'certificate':     include __DIR__.'/pages/certificate.php'; break;
    case 'certificates':    include __DIR__.'/pages/certificates.php'; break;
    case 'my_progress':     include __DIR__.'/pages/my_progress.php'; break;
    case 'dashboard':       include __DIR__.'/pages/learner_dashboard.php'; break;
    case 'api_lesson': include __DIR__.'/pages/api_lesson.php'; exit;
    case 'datapost': include __DIR__.'/pages/datapost.php'; exit;
    case 'teacher':         include __DIR__.'/pages/teacher_dashboard.php'; break;
    case 'pdf_viewer':      include __DIR__.'/pages/pdf_viewer.php'; break;
    case 'search':          include __DIR__.'/pages/search.php'; break;
    case 'challenge':       include __DIR__.'/pages/challenge.php'; break;
    case 'pre_test':        include __DIR__.'/pages/pre_test.php'; break;
    case 'quiz_review':     include __DIR__.'/pages/quiz_review.php'; break;
    case 'survey':          include __DIR__.'/pages/behavioral_survey.php'; break;
    case 'retention':       include __DIR__.'/pages/retention_test.php'; break;
    case 'facilitator':     include __DIR__.'/pages/facilitator_dashboard.php'; exit;
    case 'manual_user':     include __DIR__.'/pages/manual_user.php';           exit;
    case 'manual_impact':   include __DIR__.'/pages/manual_impact.php';         exit;
    case 'set_lang':        include __DIR__.'/pages/set_lang.php'; exit;
    default:                include __DIR__.'/pages/home.php';
}
?>
<footer class="footer">
    <strong>ARISE</strong> &mdash; Adolescent Reproductive Health Information Support &amp; Empowerment<br>
    <small>&copy; <?= date('Y') ?> ARISE &middot; v<?= ARISE_VERSION ?> &middot; Running Offline</small>
</footer>
<script src="/arise/js/app.js"></script>

<!-- Floating Safety Buttons -->
<div class="float-btns" id="floatBtns">
  <button class="safe-exit-btn" onclick="safeExit()" title="Quick exit this page">&#128682; Safe Exit</button>
  <div>
    <button class="sos-btn" onclick="toggleSOS()" title="Emergency helplines">&#128680; SOS</button>
    <div class="sos-panel" id="sosPanel">
      <h4>&#128681; Emergency Helplines</h4>
      <div class="sos-line"><span>&#128222;</span><div><div style="font-weight:700">Childline Kenya</div><div class="sos-num">116</div><div style="font-size:.7rem;color:#999">24/7 · Free</div></div></div>
      <div class="sos-line"><span>&#128222;</span><div><div style="font-weight:700">GBV Hotline</div><div class="sos-num">1195</div><div style="font-size:.7rem;color:#999">Free & confidential</div></div></div>
      <div class="sos-line"><span>&#128222;</span><div><div style="font-weight:700">HIV/AIDS Helpline</div><div class="sos-num">0800 723 253</div><div style="font-size:.7rem;color:#999">Free · Safaricom</div></div></div>
      <div class="sos-line"><span>&#128222;</span><div><div style="font-weight:700">Kenya Red Cross</div><div class="sos-num">1199</div></div></div>
      <div style="margin-top:10px"><a href="/arise/?p=resources" style="font-size:.76rem;color:var(--green);font-weight:700">View all resources →</a></div>
    </div>
  </div>
</div>

<!-- PWA Install Banner -->
<div class="pwa-banner" id="pwaBanner">
  <div class="pwa-banner-text">&#128241; Add ARISE to your home screen for offline access</div>
  <div class="pwa-banner-btns">
    <button onclick="installPWA()" style="background:var(--orange);color:#fff;border:none;border-radius:8px;padding:8px 14px;font-weight:700;font-size:.8rem;cursor:pointer">Install</button>
    <button onclick="document.getElementById('pwaBanner').classList.remove('show')" style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:8px;padding:8px 12px;font-size:.8rem;cursor:pointer">✕</button>
  </div>
</div>

<script>
// Safe Exit
function safeExit() {
  window.location.replace('https://www.google.com/search?q=Kenya+weather+today');
}
// SOS toggle
function toggleSOS() {
  document.getElementById('sosPanel').classList.toggle('open');
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.float-btns')) document.getElementById('sosPanel').classList.remove('open');
});

// PWA Service Worker — single registration at /arise/ scope.
// Also unregisters any legacy /arise/js/sw.js registration from older builds
// so phones that installed v1 don't keep serving stale content.
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.getRegistrations().then(regs => {
    regs.forEach(r => {
      if (r.active && /\/arise\/js\/sw\.js$/.test(r.active.scriptURL)) {
        r.unregister();
      }
    });
  });
  navigator.serviceWorker.register('/arise/sw.js', { scope: '/arise/' }).catch(()=>{});
}
// PWA install prompt
let deferredPrompt;
window.addEventListener('beforeinstallprompt', e => {
  e.preventDefault(); deferredPrompt = e;
  setTimeout(() => document.getElementById('pwaBanner').classList.add('show'), 3000);
});
function installPWA() {
  if (deferredPrompt) { deferredPrompt.prompt(); deferredPrompt.userChoice.then(()=>{deferredPrompt=null;}); }
  document.getElementById('pwaBanner').classList.remove('show');
}
</script>
</body>
</html>
