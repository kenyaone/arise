<?php
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/pages/lang.php';

// ── Early intercept for pages that output their own full HTML ──
$_early_page = $_GET['p'] ?? '';
if ($_early_page === 'datapost') {
    include __DIR__.'/pages/datapost.php';
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
        $stmt = db()->prepare('SELECT l.*, m.slug AS module_slug, m.id AS module_id FROM lessons l JOIN modules m ON l.module_id=m.id WHERE l.slug=:s AND l.is_active=1');
        $stmt->bindValue(':s', $_slug);
        $lesson = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($lesson && ($lesson['lesson_type'] ?? '') === 'interactive' && !empty($lesson['file_path'])) {
            $vRow = db()->querySingle("SELECT file_path FROM lessons WHERE module_id=" . intval($lesson['module_id']) . " AND lesson_type='video' AND is_active=1 LIMIT 1", true);
            $videoUrl = ($vRow && !empty($vRow['file_path'])) ? '/arise/uploads/' . $vRow['file_path'] : '';
            $htmlFile = '/var/www/arise/data/uploads/' . $lesson['file_path'];
            if (file_exists($htmlFile)) {
                $html = file_get_contents($htmlFile);
                $inject = "<script>window.ARISE_LESSON_ID=" . intval($lesson['id']) . ";window.ARISE_MODULE_SLUG='" . addslashes($lesson['module_slug']) . "';window.ARISE_RESUME_SLIDE=0;window.ARISE_VIDEO_URL='" . addslashes($videoUrl) . "';</script>";
                $html = str_replace('</head>', $inject . '</head>', $html);
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

if (session_status() === PHP_SESSION_NONE) session_start();

// Student logout
if (isset($_GET['logout'])) {
    unset($_SESSION['arise_student_id']);
    session_destroy();
    header('Location: /arise/login');
    exit;
}

$student = getStudentBySession();

// Redirect to login if not logged in and page requires login
if (!$student && !in_array($page, ['login', 'register', '', 'datapost', 'donor_report'])) {
    header('Location: /arise/login');
    exit;
}

// Redirect root to login or home based on login status
if ($page === '') {
    if ($student) {
        $page = 'home';
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
    <meta name="theme-color" content="#0ea271">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <script>
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/arise/js/sw.js').catch(function(){});
      }
    </script>
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

// PWA Service Worker
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/arise/sw.js').catch(()=>{});
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
