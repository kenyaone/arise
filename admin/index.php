<?php
/**
 * ARISE Teacher & Admin Panel v2.1
 * - No quiz/assessment builder (interactives have built-in quizzes)
 * - Auto title/description from filename
 * - Video upload progress bar
 * - Bulk upload via Excel/CSV
 * - Save success toasts
 * - Student self-enroll instructions
 * - Interactive lesson viewer fix
 */
require_once dirname(__DIR__) . '/includes/config.php';

session_start();

// ── Early intercept for API endpoints ──
$_api_page = $_GET['p'] ?? '';
if ($_api_page === 'api_toggle_content') {
    include __DIR__.'/pages/api_toggle_content.php';
    exit;
}
if ($_api_page === 'datapost' && isset($_GET['action'])) {
    include __DIR__.'/pages/datapost.php';
    exit;
}

$isLoggedIn = isset($_SESSION['arise_admin_id']);
$page = $_GET['p'] ?? '';
if (!$page) {
    $page = $isLoggedIn ? 'dashboard' : 'login';
}

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $user = trim($_POST['username'] ?? '');
    $pass = trim($_POST['password'] ?? '');
    $row = db()->querySingle("SELECT * FROM admin_users WHERE username='" . SQLite3::escapeString($user) . "' AND is_active=1", true);
    if ($row && password_verify($pass, $row['password_hash'])) {
        $_SESSION['arise_admin_id']   = $row['id'];
        $_SESSION['arise_admin_name'] = $row['full_name'] ?: $row['username'];
        $_SESSION['arise_admin_role'] = $row['role'];
        $perms = [];
        $pr = db()->query("SELECT permission FROM admin_permissions WHERE user_id=".$row['id']);
        while ($p = $pr->fetchArray(SQLITE3_ASSOC)) $perms[] = $p['permission'];
        $_SESSION['arise_permissions'] = $perms;
        header('Location: /arise/admin/dashboard'); exit;
    } else { $loginError = 'Invalid username or password.'; }
}

if (isset($_GET['logout'])) { session_destroy(); header('Location: /arise/login'); exit; }
if (!$isLoggedIn && $page !== 'login') { header('Location: /arise/login'); exit; }

// Prevent caching of authenticated pages
if ($isLoggedIn) {
    header('Cache-Control: no-cache, no-store, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function showMsg(string $m): string {
    if (!$m) return '';
    $cls = str_starts_with($m,'✅') ? 'alert-success' : 'alert-danger';
    return "<div class='alert $cls' id='saveMsg'>$m</div>
    <script>setTimeout(()=>{var el=document.getElementById('saveMsg');if(el)el.style.opacity='0';},3500);</script>";
}

// ── Login page ────────────────────────────────────────────────
if ($page === 'login'):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>ARISE — Login</title>
    <link rel="stylesheet" href="/arise/css/style.css">
    <style>
        body{background:linear-gradient(135deg,#064e3b,#0ea271 60%,#f59e0b);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
        .box{background:#fff;border-radius:24px;padding:44px 40px;width:100%;max-width:400px;box-shadow:0 24px 60px rgba(0,0,0,.2);}
        .logo{text-align:center;margin-bottom:28px;}
        .logo .icon{width:68px;height:68px;background:linear-gradient(135deg,#0ea271,#f59e0b);border-radius:18px;display:inline-flex;align-items:center;justify-content:center;font-size:2.2rem;margin-bottom:12px;box-shadow:0 8px 24px rgba(14,162,113,.35);}
        .logo h1{font-size:1.6rem;font-weight:800;color:#064e3b;letter-spacing:-.5px;}
        .logo p{font-size:.85rem;color:#6b7280;margin-top:4px;}
        .field{margin-bottom:18px;}
        .field label{display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;}
        .field input{width:100%;padding:14px 16px;border:2px solid #e5e7eb;border-radius:12px;font-size:.95rem;transition:.2s;font-family:inherit;}
        .field input:focus{outline:none;border-color:#0ea271;box-shadow:0 0 0 3px rgba(14,162,113,.12);}
        .btn-login{width:100%;padding:15px;background:linear-gradient(135deg,#0ea271,#059669);color:#fff;border:none;border-radius:12px;font-size:1rem;font-weight:700;cursor:pointer;margin-top:8px;transition:.2s;font-family:inherit;}
        .btn-login:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(14,162,113,.35);}
        .err{background:#fef2f2;color:#991b1b;padding:12px 16px;border-radius:10px;font-size:.88rem;margin-bottom:16px;border-left:4px solid #ef4444;font-weight:500;}
        .hint{text-align:center;margin-top:20px;font-size:.78rem;color:#9ca3af;}
    </style>
</head>
<body>
<div class="box">
    <div class="logo">
        <div class="icon">🌟</div>
        <h1>ARISE Panel</h1>
        <p>Teacher &amp; Admin Dashboard</p>
    </div>
    <?php if (!empty($loginError)): ?>
        <div class="err">⚠️ <?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="hidden" name="login" value="1">
        <div class="field"><label>Username</label><input type="text" name="username" required autofocus placeholder="Enter username"></div>
        <div class="field"><label>Password</label><input type="password" name="password" required placeholder="Enter password"></div>
        <button type="submit" class="btn-login">Sign In →</button>
    </form>
    <p class="hint">Default: admin / arise2026</p>
</div>
</body>
</html>
<?php exit; endif;

// Nav
// Nav groups: label => [items]
$navGroups = [
    '' => [
        ['p'=>'dashboard','icon'=>'📊','label'=>'Dashboard','perm'=>'dashboard'],
    ],
    'Content' => [
        ['p'=>'content',    'icon'=>'📚','label'=>'Modules',     'perm'=>'content_view'],
        ['p'=>'teacher_content_publish','icon'=>'📤','label'=>'Publish Content', 'perm'=>'content_manage'],
        ['p'=>'quiz',       'icon'=>'🧠','label'=>'Quiz Builder', 'perm'=>'content_manage'],
        ['p'=>'admin_question_difficulty','icon'=>'📊','label'=>'Question Performance', 'perm'=>'dashboard'],
        ['p'=>'challenges', 'icon'=>'💪','label'=>'Challenges',  'perm'=>'content_manage'],
        ['p'=>'bulk_upload','icon'=>'📦','label'=>'Bulk Upload',  'perm'=>'content_manage'],
    ],
    'People' => [
        ['p'=>'clusters',    'icon'=>'📁','label'=>'Clusters',        'perm'=>'content_manage'],
        ['p'=>'schools',     'icon'=>'🏫','label'=>'Projects',        'perm'=>'content_manage'],
        ['p'=>'students',    'icon'=>'👥','label'=>'Learners',        'perm'=>'students_view'],
        ['p'=>'certificates','icon'=>'🎓','label'=>'Certificates',    'perm'=>'students_view'],
        ['p'=>'questions',   'icon'=>'❓','label'=>'Anon Questions',  'perm'=>'questions_view'],
    ],
    'Insights' => [
        ['p'=>'analytics',     'icon'=>'📈','label'=>'Analytics',        'perm'=>'dashboard'],
        ['p'=>'reports',       'icon'=>'📋','label'=>'Reports',           'perm'=>'students_view'],
        ['p'=>'poll_results',  'icon'=>'📊','label'=>'Module Feedback',   'perm'=>'dashboard'],
    ],
    'Resources' => [
        ['url'=>'/arise/?p=datapost','icon'=>'💾','label'=>'DataPost API','perm'=>'dashboard','target'=>'_blank'],
        ['url'=>'/arise/?p=manual_user','icon'=>'📖','label'=>'User Manual','perm'=>'dashboard','target'=>'_blank'],
        ['url'=>'/arise/?p=manual_impact','icon'=>'📊','label'=>'Impact Guide','perm'=>'students_view','target'=>'_blank'],
        ['url'=>'/arise/downloads/ARISE-Marketing.html','icon'=>'🎯','label'=>'Marketing PDF','perm'=>'dashboard','target'=>'_blank'],
    ],
    'System' => [
        ['p'=>'users',       'icon'=>'👤','label'=>'Admin Users',    'perm'=>'users_manage'],
        ['p'=>'facilitator', 'icon'=>'📡','label'=>'Facilitator',    'perm'=>'dashboard'],
        ['p'=>'audit',       'icon'=>'🔍','label'=>'Audit Log',      'perm'=>'setup'],
        ['p'=>'recycle',     'icon'=>'♻️','label'=>'Recycle Bin',    'perm'=>'setup'],
    ],
];
$adminName = $_SESSION['arise_admin_name'] ?? 'Admin';
$adminRole = $_SESSION['arise_admin_role'] ?? '';
$adminPerms = $_SESSION['arise_permissions'] ?? [];
function canSee($perm) {
    global $adminRole, $adminPerms;
    return $adminRole === 'superadmin' || in_array($perm, $adminPerms);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>ARISE — <?= htmlspecialchars(ucfirst($page)) ?></title>
    <link rel="stylesheet" href="/arise/css/style.css">
    <style>
        /* ── Shell ───────────────────────────────────── */
        *{box-sizing:border-box;margin:0;padding:0;}
        body{background:#f1f5f2;font-family:'Segoe UI',sans-serif;display:flex;min-height:100vh;}

        /* ── Sidebar ─────────────────────────────────── */
        .sidebar{width:220px;min-height:100vh;background:#052e16;display:flex;flex-direction:column;flex-shrink:0;position:fixed;top:0;left:0;bottom:0;z-index:200;overflow-y:auto;}
        .sidebar-brand{padding:20px 18px 14px;border-bottom:1px solid rgba(255,255,255,.08);}
        .sidebar-brand .logo-row{display:flex;align-items:center;gap:10px;}
        .sidebar-brand .logo-icon{width:36px;height:36px;background:linear-gradient(135deg,#0ea271,#f59e0b);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
        .sidebar-brand .logo-text{font-size:1.1rem;font-weight:800;color:#fff;letter-spacing:-.3px;}
        .sidebar-brand .user-row{margin-top:10px;font-size:.75rem;color:rgba(255,255,255,.5);display:flex;align-items:center;gap:6px;}
        .sidebar-brand .user-badge{background:rgba(14,162,113,.3);color:#6ee7b7;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600;}

        .nav-group-label{font-size:.65rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:rgba(255,255,255,.3);padding:14px 18px 4px;}
        .sidebar a{display:flex;align-items:center;gap:10px;padding:9px 18px;color:rgba(255,255,255,.7);text-decoration:none;font-size:.85rem;font-weight:500;border-left:3px solid transparent;transition:.15s;}
        .sidebar a:hover{background:rgba(255,255,255,.06);color:#fff;}
        .sidebar a.active{background:rgba(14,162,113,.2);color:#6ee7b7;border-left-color:#0ea271;font-weight:700;}
        .sidebar a .nav-icon{font-size:1rem;width:20px;text-align:center;flex-shrink:0;}

        .sidebar-footer{margin-top:auto;padding:14px 18px;border-top:1px solid rgba(255,255,255,.08);display:flex;flex-direction:column;gap:6px;}
        .sidebar-footer a{font-size:.8rem;color:rgba(255,255,255,.5);text-decoration:none;display:flex;align-items:center;gap:8px;padding:6px 0;}
        .sidebar-footer a:hover{color:#fff;}
        .sidebar-footer .btn-teacher{background:rgba(245,158,11,.15);color:#fcd34d;padding:8px 12px;border-radius:8px;font-size:.8rem;font-weight:600;}

        /* ── Main content ────────────────────────────── */
        .main-wrap{margin-left:220px;flex:1;display:flex;flex-direction:column;min-height:100vh;}
        .topbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:0 24px;height:52px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,.06);}
        .topbar-title{font-size:.95rem;font-weight:700;color:#111;}
        .topbar-right{display:flex;align-items:center;gap:12px;}
        .topbar-badge{background:#f0fdf4;color:#166534;border:1px solid #86efac;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600;}

        .page-content{padding:24px;flex:1;}

        /* ── Stats row ───────────────────────────────── */
        .stat-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:20px;}
        .stat-tile{background:#fff;border-radius:12px;padding:16px;border:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;}
        .stat-tile .ico{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
        .stat-tile .val{font-size:1.35rem;font-weight:800;color:#111;line-height:1;}
        .stat-tile .lbl{font-size:.72rem;color:#6b7280;margin-top:2px;}

        /* ── Two-col layout ──────────────────────────── */
        .dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
        @media(max-width:900px){.dash-grid{grid-template-columns:1fr;}}

        /* ── Quick access card ───────────────────────── */
        .quick-url{background:linear-gradient(135deg,#052e16,#0a5e2a);border-radius:12px;padding:16px 20px;color:#fff;margin-bottom:20px;}
        .quick-url .url-label{font-size:.72rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
        .quick-url code{background:rgba(255,255,255,.12);color:#6ee7b7;padding:6px 14px;border-radius:8px;font-size:.9rem;font-weight:700;letter-spacing:.5px;display:inline-block;}

        /* ── Action needed ───────────────────────────── */
        .action-list{display:flex;flex-direction:column;gap:8px;}
        .action-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:.84rem;}
        .action-item.none{background:#f0fdf4;border-color:#86efac;color:#166534;}

        /* ── Mobile ──────────────────────────────────── */
        #sidebarToggle{display:none;position:fixed;top:12px;left:12px;z-index:300;background:#052e16;color:#fff;border:none;border-radius:8px;padding:8px 11px;font-size:1.1rem;cursor:pointer;}
        @media(max-width:900px){
            .sidebar{transform:translateX(-100%);transition:.25s;}
            .sidebar.open{transform:translateX(0);}
            .main-wrap{margin-left:0;}
            #sidebarToggle{display:block;}
            .topbar{padding-left:56px;}
        }

        /* ── Misc ────────────────────────────────────── */
        .alert{transition:opacity 1s ease;}
        .progress-wrap{display:none;margin-top:12px;}
        .progress-wrap .bar{height:10px;background:#e5e7eb;border-radius:50px;overflow:hidden;}
        .progress-wrap .fill{height:100%;background:linear-gradient(90deg,#0ea271,#f59e0b);border-radius:50px;width:0%;transition:width .3s;}
        .progress-wrap .label{font-size:.82rem;color:#6b7280;margin-top:6px;text-align:center;}
        .admin-body{max-width:none!important;width:100%!important;}
    </style>
    <script src="/arise/js/session-guard.js" defer></script>
</head>
<body>

<button id="sidebarToggle" onclick="document.querySelector('.sidebar').classList.toggle('open')">&#9776;</button>

<!-- ── Sidebar ──────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="logo-row">
            <div class="logo-icon">&#127775;</div>
            <div class="logo-text">ARISE</div>
        </div>
        <div class="user-row">
            <span>&#128075; <?= htmlspecialchars($adminName) ?></span>
            <span class="user-badge"><?= htmlspecialchars($adminRole ?: 'admin') ?></span>
        </div>
    </div>

    <?php foreach ($navGroups as $groupLabel => $items): ?>
        <?php if ($groupLabel): ?><div class="nav-group-label"><?= $groupLabel ?></div><?php endif; ?>
        <?php foreach ($items as $n):
            if (!canSee($n['perm'])) continue;
            if (isset($n['role']) && $adminRole !== $n['role']) continue;
            $href = isset($n['url']) ? $n['url'] : ('?p=' . $n['p']);
            $target = $n['target'] ?? '';
            $active = (isset($n['p']) && $page === $n['p']) ? 'active' : '';
        ?>
            <a href="<?= $href ?>" class="<?= $active ?>" <?php if($target) echo "target=\"$target\""; ?>>
                <span class="nav-icon"><?= $n['icon'] ?></span><?= $n['label'] ?>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="sidebar-footer">
        <a href="/arise/?p=teacher" target="_blank" class="btn-teacher">&#128105;&#8205;&#127979; Teacher View</a>
        <a href="?logout=1">&#128274; Sign Out</a>
    </div>
</aside>

<!-- ── Main wrap ─────────────────────────────────────────── -->
<div class="main-wrap">
<div class="topbar">
    <div class="topbar-title">
        <?php
        $pageLabels = ['dashboard'=>'Dashboard','content'=>'Modules','schools'=>'Projects & Clusters','students'=>'Learners','questions'=>'Anonymous Questions','certificates'=>'Certificates','users'=>'Admin Users','analytics'=>'Analytics & Impact','quiz'=>'Quiz Builder','admin_question_difficulty'=>'Question Performance','teacher_content_publish'=>'Publish Content','challenges'=>'Challenges','audit'=>'Audit Log','bulk_upload'=>'Bulk Upload','reports'=>'Reports','datapost'=>'DataPost & Sync','recycle'=>'Recycle Bin','facilitator'=>'Facilitator Sessions','facilitator_report'=>'Session Report'];
        echo htmlspecialchars($pageLabels[$page] ?? ucfirst($page));
        ?>
    </div>
    <div class="topbar-right">
        <span class="topbar-badge">&#127760; arise.local</span>
    </div>
</div>
<div class="admin-body page-content">
<?php

// ═══════════════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════════════
if ($page === 'dashboard'):
    $today = date('Y-m-d');
    $week  = date('Y-m-d', strtotime('-7 days'));
    $studs      = db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1") ?? 0;
    $dToday     = db()->querySingle("SELECT COUNT(DISTINCT session_hash) FROM page_views WHERE DATE(viewed_at)='$today'") ?? 0;
    $dWeek      = db()->querySingle("SELECT COUNT(DISTINCT session_hash) FROM page_views WHERE DATE(viewed_at)>='$week'") ?? 0;
    $quizTotal  = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts") ?? 0;
    $quizWeek   = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts WHERE DATE(completed_at)>='$week'") ?? 0;
    $avg        = db()->querySingle("SELECT ROUND(AVG(percentage),1) FROM quiz_attempts") ?? 0;
    $passRate   = db()->querySingle("SELECT ROUND(COUNT(CASE WHEN percentage>=60 THEN 1 END)*100.0/MAX(COUNT(*),1),0) FROM quiz_attempts") ?? 0;
    $certs      = db()->querySingle("SELECT COUNT(*) FROM certificates") ?? 0;
    $preTests   = db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='pre'") ?? 0;
    $postTests  = db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='post'") ?? 0;
    $essays     = db()->querySingle("SELECT COUNT(*) FROM essay_responses WHERE is_graded=0") ?? 0;
    $qs         = db()->querySingle("SELECT COUNT(*) FROM anonymous_questions WHERE is_answered=0") ?? 0;
    $projects   = db()->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1") ?? 0;

    // Knowledge gain: avg post - avg pre
    $avgPre  = db()->querySingle("SELECT ROUND(AVG(percentage),1) FROM pretest_attempts WHERE test_type='pre'") ?? 0;
    $avgPost = db()->querySingle("SELECT ROUND(AVG(percentage),1) FROM pretest_attempts WHERE test_type='post'") ?? 0;
    $gain = round($avgPost - $avgPre, 1);
?>

<!-- Stat tiles -->
<div class="stat-row">
<?php foreach([
    ['#dcfce7','#166534','&#128101;', $studs,     'Learners',      ''],
    ['#dbeafe','#1e40af','&#127968;', $projects,  'Projects',      ''],
    ['#fef9c3','#92400e','&#128197;', $dToday,    'Active Today',  ''],
    ['#ede9fe','#5b21b6','&#128202;', $avg.'%',   'Avg Score',     ''],
    ['#dcfce7','#166534','&#9989;',   $passRate.'%','Pass Rate',   '>=60%'],
    ['#fce7f3','#9d174d','&#128218;', $preTests,  'Pre-Tests',     'done'],
    ['#ecfdf5','#065f46','&#128200;', $gain>0?'+'.  $gain.'%':$gain.'%','Knowledge Gain','pre→post'],
    ['#fff7ed','#92400e','&#127891;', $certs,     'Certs Issued',  ''],
] as [$bg,$col,$ico,$val,$lbl,$sub]): ?>
<div class="stat-tile" style="background:<?=$bg?>22;border-color:<?=$bg?>;">
    <div class="ico" style="background:<?=$bg?>;color:<?=$col?>"><?=$ico?></div>
    <div><div class="val" style="color:<?=$col?>"><?=$val?></div><div class="lbl"><?=$lbl?><?=$sub?" <span style='color:#9ca3af'>($sub)</span>":''?></div></div>
</div>
<?php endforeach; ?>
</div>

<!-- Access URL + Actions needed -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
    <div class="quick-url">
        <div class="url-label">&#127760; Learner Access URL</div>
        <code>http://arise.local/arise/</code>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
            <button onclick="showAccessHelper('http://arise.local/arise/')"
                    style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:6px 14px;font-size:.8rem;font-weight:700;cursor:pointer;">
                &#128481; Project on Screen
            </button>
            <a href="/arise/?p=facilitator" target="_blank"
               style="background:rgba(14,162,113,.3);color:#6ee7b7;border:1px solid rgba(14,162,113,.4);border-radius:8px;padding:6px 14px;font-size:.8rem;font-weight:700;text-decoration:none;">
                &#128225; Live Facilitator View
            </a>
        </div>
        <div class="url-label" style="margin-top:14px;">&#128240; DataPost API</div>
        <code>http://192.168.0.10/arise/?p=datapost</code>
        <div style="font-size:.72rem;color:rgba(255,255,255,.35);margin-top:4px;">Full data export &middot; learner names anonymised</div>
        <div class="url-label" style="margin-top:14px;">&#128218; Platform Manuals</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">
            <a href="/arise/?p=manual_user" target="_blank"
               style="background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:7px;padding:5px 12px;font-size:.77rem;font-weight:700;text-decoration:none;">
                &#128100; User Manual
            </a>
            <a href="/arise/?p=manual_impact" target="_blank"
               style="background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:7px;padding:5px 12px;font-size:.77rem;font-weight:700;text-decoration:none;">
                &#128200; Impact Assessment
            </a>
        </div>
    </div>
    <div class="dp-card" style="margin:0;">
        <div style="font-weight:700;font-size:.88rem;margin-bottom:10px;color:#374151;">&#9888;&#65039; Action Needed</div>
        <div class="action-list">
            <?php if($qs>0): ?>
            <div class="action-item"><span>&#10067; <?=$qs?> unanswered question<?=$qs>1?'s':''?></span><a href="?p=questions" style="font-size:.78rem;font-weight:700;color:#d97706;">Answer</a></div>
            <?php endif; ?>
            <?php if($essays>0): ?>
            <div class="action-item"><span>&#9998;&#65039; <?=$essays?> essay<?=$essays>1?'s':''?> to grade</span><a href="?p=students" style="font-size:.78rem;font-weight:700;color:#d97706;">Grade</a></div>
            <?php endif; ?>
            <?php if($qs==0 && $essays==0): ?>
            <div class="action-item none">&#10003; All caught up — nothing pending</div>
            <?php endif; ?>
            <?php if($quizWeek>0): ?>
            <div class="action-item" style="background:#f0fdf4;border-color:#86efac;color:#166534;"><span>&#128200; <?=$quizWeek?> quiz attempt<?=$quizWeek>1?'s':''?> this week</span><a href="?p=reports" style="font-size:.78rem;font-weight:700;color:#166534;">View</a></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Two-col: module engagement + recent activity -->
<div class="dash-grid">
<?php
$mods = db()->query("SELECT m.title,m.icon,COUNT(DISTINCT qa.session_hash) AS quizzes,ROUND(AVG(qa.percentage),1) AS avg,COUNT(DISTINCT c.id) AS certs FROM modules m LEFT JOIN quiz_attempts qa ON qa.module_id=m.id LEFT JOIN certificates c ON c.module_id=m.id WHERE m.is_active=1 GROUP BY m.id ORDER BY quizzes DESC LIMIT 8");
$modRows=[];while($m=$mods->fetchArray(SQLITE3_ASSOC))$modRows[]=$m;
?>
<div class="dp-card" style="margin:0;">
    <div style="font-weight:700;font-size:.88rem;margin-bottom:12px;color:#374151;">&#128218; Module Activity</div>
    <?php if($modRows): ?>
    <table class="arise-table" style="font-size:.8rem;">
        <thead><tr><th>Module</th><th>Quizzes</th><th>Avg</th><th>Certs</th></tr></thead>
        <tbody>
        <?php foreach($modRows as $m):
            $sc=floatval($m['avg']??0);
            $col=$sc>=60?'#166534':($sc>0?'#92400e':'#9ca3af');
        ?>
        <tr>
            <td><?=$m['icon']?> <strong><?=e(substr($m['title'],0,22))?></strong></td>
            <td><?=$m['quizzes']?></td>
            <td style="font-weight:700;color:<?=$col?>"><?=$sc>0?"$sc%":'—'?></td>
            <td><?=$m['certs']?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?><div style="color:#9ca3af;font-size:.85rem;">No activity yet.</div><?php endif; ?>
</div>

<?php
$rq=db()->query("SELECT qa.percentage,qa.completed_at,m.title,m.icon,s.full_name,s.class_name FROM quiz_attempts qa JOIN modules m ON qa.module_id=m.id LEFT JOIN students s ON qa.student_id=s.id ORDER BY qa.completed_at DESC LIMIT 10");
$rqRows=[];while($r=$rq->fetchArray(SQLITE3_ASSOC))$rqRows[]=$r;
?>
<div class="dp-card" style="margin:0;">
    <div style="font-weight:700;font-size:.88rem;margin-bottom:12px;color:#374151;">&#9201;&#65039; Recent Quiz Attempts</div>
    <?php if($rqRows): foreach($rqRows as $q):
        $col=$q['percentage']>=60?'#166534':'#dc2626';
        $name=$q['full_name']?e(substr($q['full_name'],0,16)):'Anon';
        $time=date('M j, g:ia',strtotime($q['completed_at']));
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f3f4f6;font-size:.82rem;">
        <div>
            <div style="font-weight:600;"><?=$q['icon']?> <?=e(substr($q['title'],0,20))?></div>
            <div style="color:#9ca3af;font-size:.74rem;"><?=$name?> &bull; <?=$time?></div>
        </div>
        <span style="font-weight:700;color:<?=$col?>;font-size:.9rem;"><?=$q['percentage']?>%</span>
    </div>
    <?php endforeach; else: ?>
    <div style="color:#9ca3af;font-size:.85rem;">No quiz attempts yet.</div>
    <?php endif; ?>
</div>
</div>

<?php
// ═══════════════════════════════════════════════════════
// MODULES & LESSONS
// ═══════════════════════════════════════════════════════
elseif ($page === 'content'):
    $msg = '';
    $action = $_GET['action'] ?? 'list';
    $uploadDir = dirname(__DIR__) . '/data/uploads/';
    foreach(['interactive','videos','pdfs','lessons'] as $d) {
        if (!is_dir($uploadDir.$d)) mkdir($uploadDir.$d, 0775, true);
    }

    // Create module
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_module'])) {
        $title = trim($_POST['title']??'');
        if ($title) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/','-',$title)).'-'.time();
            $sort = (db()->querySingle("SELECT MAX(sort_order) FROM modules")??0)+1;
            $stmt = db()->prepare('INSERT INTO modules (title,slug,description,icon,sort_order,created_by) VALUES (:t,:s,:d,:i,:o,:b)');
            $stmt->bindValue(':t',$title); $stmt->bindValue(':s',$slug);
            $stmt->bindValue(':d',trim($_POST['description']??''));
            $stmt->bindValue(':i',trim($_POST['icon']??'📚'));
            $stmt->bindValue(':o',$sort); $stmt->bindValue(':b',$_SESSION['arise_admin_id']);
            $stmt->execute();
            $msg = "✅ Module '".e($title)."' created successfully!";
        }
    }

    // Edit module
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_module'])) {
        $id = intval($_POST['module_id']);
        $stmt = db()->prepare('UPDATE modules SET title=:t,description=:d,icon=:i,is_active=:a WHERE id=:id');
        $stmt->bindValue(':t',trim($_POST['title'])); $stmt->bindValue(':d',trim($_POST['description']??''));
        $stmt->bindValue(':i',trim($_POST['icon']??'📚')); $stmt->bindValue(':a',isset($_POST['is_active'])?1:0);
        $stmt->bindValue(':id',$id); $stmt->execute();
        $msg = '✅ Module updated successfully!'; $action = 'list';
    }

    // Add lesson — auto-title from filename if not provided
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_lesson'])) {
        $modId = intval($_POST['module_id']??0);
        $type  = $_POST['lesson_type']??'text';
        $filePath = $fileName = $fileSize = null;

        if ($type !== 'text' && isset($_FILES['lesson_file']) && $_FILES['lesson_file']['error']===UPLOAD_ERR_OK) {
            $file = $_FILES['lesson_file'];
            $ext  = strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
            $validExts = ['video'=>['mp4','webm','ogg','avi'],'pdf'=>['pdf'],'interactive'=>['html','htm']];
            if (in_array($ext, $validExts[$type]??[])) {
                $subDir = $type==='video'?'videos':($type==='pdf'?'pdfs':'interactive');
                $safeName = time().'_'.preg_replace('/[^a-z0-9._-]/','',strtolower($file['name']));
                $dest = $uploadDir.$subDir.'/'.$safeName;
                move_uploaded_file($file['tmp_name'],$dest);
                chmod($dest,0664);
                $filePath = $subDir.'/'.$safeName;
                $fileName = $file['name'];
                $fileSize = round($file['size']/1024,1);
            } else { $msg = "❌ Invalid file type for $type."; }
        }

        // Auto-generate title from filename if left blank
        $rawTitle = trim($_POST['title']??'');
        if (!$rawTitle && $fileName) {
            $rawTitle = ucwords(str_replace(['-','_','.'],[' ',' ',' '],pathinfo($fileName,PATHINFO_FILENAME)));
            // Clean up common patterns like "lesson 04 drug avoidance"
            $rawTitle = preg_replace('/^lesson\s*\d+\s*/i','',$rawTitle);
            $rawTitle = trim($rawTitle);
        }
        if (!$rawTitle) $rawTitle = 'Lesson '.date('d/m/Y H:i');

        // Auto-description
        $rawDesc = trim($_POST['content']??'');
        if (!$rawDesc && $type !== 'text') {
            $typeLabels = ['interactive'=>'Interactive lesson','video'=>'Video lesson','pdf'=>'PDF resource'];
            $rawDesc = ($typeLabels[$type]??'Lesson').': '.$rawTitle;
        }

        if (!$msg && $modId) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/','-',$rawTitle)).'-'.time();
            $stmt = db()->prepare('INSERT INTO lessons (module_id,title,slug,content,lesson_type,file_path,file_name,file_size_kb,sort_order) VALUES (:m,:t,:s,:c,:ty,:fp,:fn,:fs,:so)');
            $stmt->bindValue(':m',$modId); $stmt->bindValue(':t',$rawTitle); $stmt->bindValue(':s',$slug);
            $stmt->bindValue(':c',$rawDesc); $stmt->bindValue(':ty',$type);
            $stmt->bindValue(':fp',$filePath); $stmt->bindValue(':fn',$fileName); $stmt->bindValue(':fs',$fileSize);
            $stmt->bindValue(':so',intval($_POST['sort_order']??0));
            $stmt->execute();
            $msg = "✅ Lesson '".e($rawTitle)."' added successfully!";
            $action = 'list';
        }
    }

    // Bulk upload — Excel/CSV list of lessons (metadata only, or with files)
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bulk_upload'])) {
        $modId = intval($_POST['bulk_module_id']??0);
        $raw = !empty($_FILES['bulk_file']['tmp_name']) ? file_get_contents($_FILES['bulk_file']['tmp_name']) : ($_POST['bulk_text']??'');
        $imported = $errors = 0;
        foreach(preg_split('/\r?\n/',trim($raw)) as $line) {
            $line = trim($line);
            if (!$line || str_starts_with($line,'#') || str_starts_with($line,'Title')) continue;
            $p = str_getcsv($line);
            if (count($p) >= 1 && trim($p[0])) {
                $t = trim($p[0]);
                $ty = strtolower(trim($p[1]??'text'));
                if (!in_array($ty,['text','interactive','video','pdf'])) $ty='text';
                $desc = trim($p[2]??'');
                $slug = strtolower(preg_replace('/[^a-z0-9]+/','-',$t)).'-'.time().'-'.$imported;
                try {
                    $stmt = db()->prepare('INSERT INTO lessons (module_id,title,slug,content,lesson_type,sort_order) VALUES (:m,:t,:s,:c,:ty,:so)');
                    $stmt->bindValue(':m',$modId); $stmt->bindValue(':t',$t); $stmt->bindValue(':s',$slug);
                    $stmt->bindValue(':c',$desc); $stmt->bindValue(':ty',$ty); $stmt->bindValue(':so',$imported);
                    $stmt->execute(); $imported++;
                } catch(Exception $e) { $errors++; }
            }
        }
        $msg = "✅ Bulk imported $imported lessons.".($errors?" ($errors skipped)":'');
    }

    // Delete lesson
    if (isset($_GET['del_lesson'])) {
        db()->exec("UPDATE lessons SET is_active=0 WHERE id=".intval($_GET['del_lesson']));
        $msg = '✅ Lesson removed.';
    }

    echo '<h1 class="page-title">📚 Modules & Lessons</h1>';
    echo showMsg($msg);

    if ($action === 'add_lesson'):
        $modId = intval($_GET['mod']??0);
        $modTitle = db()->querySingle("SELECT title FROM modules WHERE id=$modId");
?>
<div class="dp-card">
    <h2 class="section-title">➕ Add Lesson to: <span style="color:var(--primary)"><?= e($modTitle) ?></span></h2>
    <form method="POST" enctype="multipart/form-data" id="lessonForm">
        <input type="hidden" name="add_lesson" value="1">
        <input type="hidden" name="module_id" value="<?= $modId ?>">

        <!-- Lesson type selector -->
        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">Lesson Type *</label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;">
                <?php foreach([
                    ['interactive','🎮','Interactive','10-slide .html lesson'],
                    ['video','🎬','Video','MP4 video file'],
                    ['pdf','📄','PDF','PDF document'],
                    ['text','📝','Text Notes','Write content here'],
                ] as [$val,$ic,$lb,$desc]): ?>
                <label class="type-card <?= $val==='interactive'?'selected':'' ?>" id="tc-<?= $val ?>" onclick="selectType('<?= $val ?>')">
                    <input type="radio" name="lesson_type" value="<?= $val ?>" style="display:none" <?= $val==='interactive'?'checked':'' ?>>
                    <div class="type-icon"><?= $ic ?></div>
                    <h4><?= $lb ?></h4>
                    <p><?= $desc ?></p>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Title (optional — auto-generated from filename) -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;">
                Lesson Title <span style="font-weight:400;font-style:italic;">(optional — auto-filled from filename)</span>
            </label>
            <input type="text" name="title" id="lessonTitle" placeholder="Leave blank to auto-generate from filename">
        </div>

        <!-- File upload -->
        <div id="sec-file" style="margin-bottom:14px;">
            <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;">Upload File</label>
            <div style="border:2px dashed #d1fae5;border-radius:12px;padding:24px;text-align:center;background:#f0fdf4;" id="dropzone">
                <div style="font-size:2.2rem;margin-bottom:8px;" id="upload-icon">🎮</div>
                <p id="upload-hint" style="color:#065f46;font-size:.9rem;font-weight:600;margin-bottom:12px;">Upload .html interactive lesson file</p>
                <input type="file" name="lesson_file" id="lesson_file" accept=".html,.htm,.mp4,.webm,.pdf" style="width:auto;padding:8px 16px;">
            </div>
            <!-- Video progress bar -->
            <div class="progress-wrap" id="progressWrap">
                <div class="bar"><div class="fill" id="progressFill"></div></div>
                <div class="label" id="progressLabel">Uploading... 0%</div>
            </div>
        </div>

        <!-- Text content -->
        <div id="sec-text" style="display:none;margin-bottom:14px;">
            <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;">Content (HTML allowed)</label>
            <textarea name="content" rows="8" placeholder="Write lesson content here..."></textarea>
        </div>

        <!-- Description (optional) -->
        <div id="sec-desc" style="margin-bottom:14px;">
            <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;">
                Description <span style="font-weight:400;font-style:italic;">(optional — auto-filled)</span>
            </label>
            <input type="text" name="content" id="lessonDesc" placeholder="Brief description (auto-generated if blank)">
        </div>

        <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;">
            <div>
                <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;">Sort Order</label>
                <input type="number" name="sort_order" value="0" style="width:90px;">
            </div>
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary" id="submitBtn">💾 Save Lesson</button>
            <a href="?p=content" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
function selectType(val) {
    ['interactive','video','pdf','text'].forEach(t => {
        document.getElementById('tc-'+t).classList.remove('selected');
    });
    document.getElementById('tc-'+val).classList.add('selected');
    document.querySelector('input[value="'+val+'"]').checked = true;
    var isText = val === 'text';
    document.getElementById('sec-file').style.display = isText ? 'none' : '';
    document.getElementById('sec-text').style.display = isText ? '' : 'none';
    document.getElementById('sec-desc').style.display = isText ? 'none' : '';
    var hints = {
        interactive: {icon:'🎮', text:'Upload .html interactive lesson file', accept:'.html,.htm'},
        video:       {icon:'🎬', text:'Upload MP4 video file (progress bar shows upload status)', accept:'.mp4,.webm,.ogg'},
        pdf:         {icon:'📄', text:'Upload PDF document', accept:'.pdf'}
    };
    if (hints[val]) {
        document.getElementById('upload-icon').textContent = hints[val].icon;
        document.getElementById('upload-hint').textContent = hints[val].text;
        document.getElementById('lesson_file').accept = hints[val].accept;
    }
}

// Auto-fill title from filename
document.getElementById('lesson_file').addEventListener('change', function() {
    var file = this.files[0];
    if (!file) return;
    var titleField = document.getElementById('lessonTitle');
    if (!titleField.value) {
        var name = file.name.replace(/\.[^.]+$/,'');          // remove extension
        name = name.replace(/^lesson[-_]?\d+[-_]?/i,'');      // remove "lesson-04-"
        name = name.replace(/[-_]/g,' ');                      // dashes to spaces
        name = name.replace(/\b\w/g, l => l.toUpperCase());   // title case
        titleField.value = name.trim();
    }
});

// Video upload progress
document.getElementById('lessonForm').addEventListener('submit', function(e) {
    var typeVal = document.querySelector('input[name="lesson_type"]:checked').value;
    if (typeVal !== 'video') return; // only show progress for video
    e.preventDefault();
    var form = this;
    var formData = new FormData(form);
    var xhr = new XMLHttpRequest();
    document.getElementById('progressWrap').style.display = 'block';
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').textContent = 'Uploading...';
    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            var pct = Math.round((e.loaded / e.total) * 100);
            document.getElementById('progressFill').style.width = pct + '%';
            document.getElementById('progressLabel').textContent = 'Uploading... ' + pct + '%' + (pct === 100 ? ' — Processing...' : '');
        }
    });
    xhr.addEventListener('load', function() {
        document.getElementById('progressLabel').textContent = '✅ Upload complete!';
        window.location.href = '?p=content&saved=1';
    });
    xhr.addEventListener('error', function() {
        document.getElementById('progressLabel').textContent = '❌ Upload failed. Try again.';
        document.getElementById('submitBtn').disabled = false;
        document.getElementById('submitBtn').textContent = '💾 Save Lesson';
    });
    xhr.open('POST', '?p=content');
    xhr.send(formData);
});
</script>

<?php
    elseif ($action === 'edit_module'):
        $modId = intval($_GET['mod']??0);
        $m = db()->querySingle("SELECT * FROM modules WHERE id=$modId", true);
        if ($m):
?>
<div class="dp-card">
    <h2 class="section-title">✏️ Edit Module: <?= e($m['title']) ?></h2>
    <form method="POST">
        <input type="hidden" name="edit_module" value="1">
        <input type="hidden" name="module_id" value="<?= $modId ?>">
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Icon</label>
                <input type="text" name="icon" value="<?= e($m['icon']) ?>" style="width:70px;text-align:center;font-size:1.3rem;padding:10px;">
            </div>
            <div style="flex:2;min-width:180px;">
                <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Title</label>
                <input type="text" name="title" value="<?= e($m['title']) ?>" required>
            </div>
            <div style="flex:3;min-width:180px;">
                <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Description</label>
                <input type="text" name="description" value="<?= e($m['description']??'') ?>">
            </div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer;font-weight:600;">
            <input type="checkbox" name="is_active" <?= $m['is_active']?'checked':'' ?>> Active (visible to students)
        </label>
        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">💾 Save Changes</button>
            <a href="?p=content" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>
<?php endif;
    else: // Module list
        // Show save success toast from redirect
        if (isset($_GET['saved'])) echo showMsg('✅ Lesson uploaded successfully!');
?>
<!-- Create module -->
<div class="dp-card">
    <h2 class="section-title">➕ Create New Module</h2>
    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="add_module" value="1">
        <div>
            <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Icon</label>
            <input type="text" name="icon" value="📚" style="width:64px;text-align:center;font-size:1.3rem;padding:10px;">
        </div>
        <div style="flex:2;min-width:180px;">
            <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Module Title *</label>
            <input type="text" name="title" required placeholder="e.g. Mental Health & Drugs">
        </div>
        <div style="flex:3;min-width:180px;">
            <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Description</label>
            <input type="text" name="description" placeholder="Brief description">
        </div>
        <button type="submit" class="btn btn-primary">📚 Create Module</button>
    </form>
</div>

<!-- Bulk lesson import -->
<div class="dp-card" style="border-left:4px solid var(--accent);">
    <h2 class="section-title">📥 Bulk Import Lessons (CSV / Excel)</h2>
    <p class="text-small text-muted" style="margin-bottom:12px;">
        Format: <code style="background:var(--light);padding:2px 8px;border-radius:4px;">Title, Type (interactive/video/pdf/text), Description</code><br>
        Export your Excel sheet as CSV and paste or upload below. Files must still be uploaded individually.
    </p>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="bulk_upload" value="1">
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;align-items:flex-end;">
            <div style="flex:1;min-width:200px;">
                <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Module</label>
                <select name="bulk_module_id" style="padding:10px 14px;">
                    <option value="">Select module...</option>
                    <?php $bm = db()->query("SELECT id,icon,title FROM modules WHERE is_active=1 ORDER BY sort_order");
                    while ($r = $bm->fetchArray(SQLITE3_ASSOC)) echo "<option value='{$r['id']}'>{$r['icon']} ".e($r['title'])."</option>"; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Upload CSV/Excel</label>
                <input type="file" name="bulk_file" accept=".csv,.txt,.xls,.xlsx" style="width:auto;">
            </div>
        </div>
        <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Or Paste CSV</label>
        <textarea name="bulk_text" rows="5" style="font-family:monospace;font-size:.82rem;" placeholder="Effects of Smoking, interactive, Lesson on smoking dangers
Cannabis Effects, interactive, Lesson on cannabis/bhang
Cocaine and Heroin, interactive, Hard drugs awareness
Stress and Mental Health, video, Mental health video"></textarea>
        <button type="submit" class="btn btn-accent" style="margin-top:10px;">📥 Import Lessons</button>
    </form>
</div>

<!-- Module list with lessons -->
<?php
    $allMods = db()->query("SELECT * FROM modules ORDER BY sort_order");
    while ($m = $allMods->fetchArray(SQLITE3_ASSOC)):
        $lc = db()->querySingle("SELECT COUNT(*) FROM lessons WHERE module_id={$m['id']} AND is_active=1");
        $lessons = db()->query("SELECT * FROM lessons WHERE module_id={$m['id']} AND is_active=1 ORDER BY sort_order,id");
?>
<div class="dp-card" style="<?= !$m['is_active']?'opacity:.55;':'' ?>border-left:4px solid <?= $m['is_active']?'var(--primary)':'#e5e7eb' ?>;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:<?= $lc?'14px':'0' ?>;">
        <div>
            <span style="font-size:1.3rem;"><?= $m['icon'] ?></span>
            <strong style="font-size:1.05rem;margin-left:8px;"><?= e($m['title']) ?></strong>
            <span class="chip" style="margin-left:8px;"><?= $lc ?> lesson<?= $lc!=1?'s':'' ?></span>
            <?php if(!$m['is_active']): ?><span class="badge badge-red" style="margin-left:6px;">Inactive</span><?php endif; ?>
            <?php if($m['description']): ?><span class="text-small text-muted" style="margin-left:8px;"><?= e($m['description']) ?></span><?php endif; ?>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <a href="?p=content&action=add_lesson&mod=<?= $m['id'] ?>" class="btn btn-primary btn-sm">+ Add Lesson</a>
            <a href="?p=content&action=edit_module&mod=<?= $m['id'] ?>" class="btn btn-secondary btn-sm">✏️ Edit</a>
        </div>
    </div>

    <?php if ($lc > 0):
        $typeIcons  = ['interactive'=>'🎮','video'=>'🎬','pdf'=>'📄','text'=>'📝'];
        $typeColors = ['interactive'=>'badge-purple','video'=>'badge-amber','pdf'=>'badge-blue','text'=>'badge-green'];
    ?>
    <div style="border-top:1px solid var(--border);padding-top:10px;">
    <?php while ($l = $lessons->fetchArray(SQLITE3_ASSOC)):
        $ti = $typeIcons[$l['lesson_type']]??'📝';
        $tc = $typeColors[$l['lesson_type']]??'badge-green';
    ?>
    <div class="dp-log-item" style="flex-wrap:wrap;gap:6px;">
        <div>
            <span><?= $ti ?> <strong><?= e($l['title']) ?></strong></span>
            <span class="badge <?= $tc ?>" style="margin-left:6px;"><?= ucfirst($l['lesson_type']) ?></span>
            <?php if($l['file_name']): ?>
                <span class="text-xs text-muted" style="margin-left:6px;">📁 <?= e($l['file_name']) ?> (<?= $l['file_size_kb'] ?>KB)</span>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:6px;">
            <a href="/arise/?p=lesson&slug=<?= e($l['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm">👁 View</a>
            <a href="?p=content&del_lesson=<?= $l['id'] ?>" class="btn btn-sm" style="background:var(--danger-light);color:#991b1b;" onclick="return confirm('Remove this lesson?')">🗑</a>
        </div>
    </div>
    <?php endwhile; ?>
    </div>
    <?php endif; ?>
</div>
<?php endwhile; ?>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════
// STUDENTS
// ═══════════════════════════════════════════════════════
elseif ($page === 'schools'):
    // Schools & Classes management — inline
    $smsg = '';

    // Create school
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_school'])) {
        $sname = trim($_POST['school_name']??'');
        $county = trim($_POST['county']??'');
        if ($sname) {
            try {
                $stmt = db()->prepare('INSERT INTO schools (name,county) VALUES (:n,:c)');
                $stmt->bindValue(':n',$sname); $stmt->bindValue(':c',$county);
                $stmt->execute();
                $smsg = "✅ School '".e($sname)."' added!";
            } catch(Exception $e) { $smsg = '❌ School already exists.'; }
        }
    }

    // Create class
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_class'])) {
        $cname = trim($_POST['class_name']??'');
        $sid = intval($_POST['school_id']??0);
        $level = trim($_POST['level']??'');
        if ($cname && $sid) {
            $stmt = db()->prepare('INSERT INTO classes (school_id,name,level) VALUES (:s,:n,:l)');
            $stmt->bindValue(':s',$sid); $stmt->bindValue(':n',$cname); $stmt->bindValue(':l',$level);
            $stmt->execute();
            $smsg = "✅ Class '".e($cname)."' added!";
        }
    }

    // Delete school
    if (isset($_GET['del_school'])) {
        db()->exec("UPDATE schools SET is_active=0 WHERE id=".intval($_GET['del_school']));
        $smsg = '✅ School removed.';
    }

    // Delete class
    if (isset($_GET['del_class'])) {
        db()->exec("UPDATE classes SET is_active=0 WHERE id=".intval($_GET['del_class']));
        $smsg = '✅ Class removed.';
    }

    echo '<h1 class="page-title">🏫 Projects & Clusters</h1>';
    echo '<p class="text-muted" style="margin-top:-16px;margin-bottom:20px;">Create projects and clusters so learners can self-enroll by selecting from a dropdown — no typing needed.</p>';
    echo showMsg($smsg);

    // Add project form
    echo '<div class="dp-card"><h2 class="section-title">➕ Add Project</h2>
    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="add_school" value="1">
    <div style="flex:2;min-width:200px;">
        <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Project Name *</label>
        <input type="text" name="school_name" required placeholder="e.g. Nairobi Youth Health Project">
    </div>
    <div style="flex:1;min-width:140px;">
        <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">County / Region</label>
        <input type="text" name="county" placeholder="e.g. Nairobi">
    </div>
    <button type="submit" class="btn btn-primary">🏫 Add Project</button>
    </form></div>';

    // List schools with their classes
    $allSchools = db()->query("SELECT * FROM schools WHERE is_active=1 ORDER BY name");
    while ($school = $allSchools->fetchArray(SQLITE3_ASSOC)):
        $sc = db()->querySingle("SELECT COUNT(*) FROM classes WHERE school_id={$school['id']} AND is_active=1");
        $stc = db()->querySingle("SELECT COUNT(*) FROM students WHERE school_name='".SQLite3::escapeString($school['name'])."' AND is_active=1");
        echo '<div class="dp-card" style="border-left:4px solid var(--pri);">';
        echo "<div style='display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:".($sc?'14px':'0')."'>";
        echo "<div><span style='font-size:1.2rem;margin-right:8px;'>🏫</span><strong style='font-size:1.05rem;'>".e($school['name'])."</strong>";
        if ($school['county']) echo " <span class='text-small text-muted'>— ".e($school['county'])."</span>";
        echo " <span class='chip' style='margin-left:8px;'>$sc cluster".($sc!=1?'s':'')."</span>";
        echo " <span class='chip' style='margin-left:4px;'>$stc learner".($stc!=1?'s':'')."</span></div>";
        echo "<div style='display:flex;gap:6px;'>";
        echo "<a href='?p=schools&del_school={$school['id']}' class='btn btn-sm' style='background:#fee2e2;color:#991b1b;' onclick='return confirm(\"Remove project?\")'>Remove</a>";
        echo "</div></div>";

        // Existing clusters
        $classes = db()->query("SELECT * FROM classes WHERE school_id={$school['id']} AND is_active=1 ORDER BY name");
        $hasClasses = false;
        $classHtml = '<div style="border-top:1px solid var(--border);padding-top:12px;display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">';
        while ($cl = $classes->fetchArray(SQLITE3_ASSOC)) {
            $hasClasses = true;
            $classHtml .= "<span style='display:inline-flex;align-items:center;gap:6px;background:var(--pri-light,#ede9fe);border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-size:.85rem;font-weight:600;'>".e($cl['name']);
            $classHtml .= " <a href='?p=schools&del_class={$cl['id']}' style='color:#991b1b;font-size:.8rem;' onclick='return confirm(\"Remove cluster?\")'>x</a>";
        }
        $classHtml .= '</div>';
        if ($hasClasses) echo $classHtml;

        // Add cluster form
        echo "<form method='POST' style='display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;'>
        <input type='hidden' name='add_class' value='1'>
        <input type='hidden' name='school_id' value='{$school['id']}'>
        <div style='flex:2;min-width:150px;'>
            <label style='display:block;font-size:.72rem;font-weight:700;color:#6b7280;margin-bottom:4px;text-transform:uppercase;'>Cluster Name *</label>
            <input type='text' name='class_name' required placeholder='e.g. Group A, Cohort 2025, Stream Blue'>
        </div>
        <div style='flex:1;min-width:120px;'>
            <label style='display:block;font-size:.72rem;font-weight:700;color:#6b7280;margin-bottom:4px;text-transform:uppercase;'>Level</label>
            <input type='text' name='level' placeholder='e.g. Senior'>
        </div>
        <button type='submit' class='btn btn-secondary btn-sm'>+ Add Cluster</button>
        </form>";
        echo '</div>';
    endwhile;

elseif ($page === 'students'):
    // ── Reset PIN handler (added here because admin_students.php is not writable by deploy user) ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'reset_pin'
        && hasPermission('students_manage'))
    {
        $pinStudentId = intval($_POST['student_id'] ?? 0);
        if ($pinStudentId > 0) {
            $plainPin  = str_pad((string)rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            $hashedPin = password_hash($plainPin, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE students SET password_hash=:h WHERE id=:id');
            $stmt->bindValue(':h', $hashedPin);
            $stmt->bindValue(':id', $pinStudentId);
            $stmt->execute();
            $pinName = db()->querySingle("SELECT full_name FROM students WHERE id=$pinStudentId");
            ariseAuditLog('reset_student_pin', 'student', $pinStudentId,
                'Reset PIN for learner: ' . ($pinName ?? "ID $pinStudentId"));
            $_SESSION['_arise_pin_flash'] = [
                'name' => $pinName ?? "ID $pinStudentId",
                'pin'  => $plainPin,
            ];
        }
        header('Location: ?p=students');
        exit;
    }

    // ── Capture include output so we can inject the Reset PIN button and flash message ──
    ob_start();
    include __DIR__.'/pages/admin_students.php';
    $studentsHtml = ob_get_clean();

    // Inject PIN flash message after the first <h1 …> tag
    if (!empty($_SESSION['_arise_pin_flash'])) {
        $pf   = $_SESSION['_arise_pin_flash'];
        unset($_SESSION['_arise_pin_flash']);
        $pName  = htmlspecialchars($pf['name'], ENT_QUOTES);
        $pPin   = htmlspecialchars($pf['pin'],  ENT_QUOTES);
        $flash  = '<div class="alert alert-success" style="margin-bottom:14px;padding:12px 16px;'
                . 'border-radius:6px;border-left:4px solid #16a34a;background:#f0fdf4;color:#166534;">'
                . '🔐 PIN reset for <strong>' . $pName . '</strong>. '
                . 'New PIN: <strong style="font-size:1.1em;letter-spacing:2px;">' . $pPin . '</strong>'
                . ' &mdash; share this with the learner, then ask them to change it.'
                . '</div>';
        // Insert after the opening <h1 …> tag
        $studentsHtml = preg_replace('/(<h1[^>]*>.*?<\/h1>)/s', '$1' . $flash, $studentsHtml, 1);
    }

    // Inject "Reset PIN" button into every action cell that already has a Reset Pass button
    $studentsHtml = preg_replace_callback(
        '/(<form[^>]+method=["\']POST["\'][^>]*>.*?name=["\']action["\'][^>]*value=["\']reset_password["\'].*?<input[^>]+name=["\']student_id["\'][^>]*value=["\'](\d+)["\'].*?<\/form>)/s',
        function ($m) {
            $sid = intval($m[2]);
            $resetPinForm = '<form method="POST" style="display:inline;margin-left:4px;">'
                . '<input type="hidden" name="action" value="reset_pin">'
                . '<input type="hidden" name="student_id" value="' . $sid . '">'
                . '<button type="submit" class="btn btn-secondary"'
                . ' style="padding:3px 8px;font-size:0.75rem;background:#7c3aed;border-color:#7c3aed;color:#fff;"'
                . ' onclick="return confirm(\'Generate a new 4-digit PIN for this learner?\')">'
                . '🔐 Reset PIN</button></form>';
            return $m[1] . $resetPinForm;
        },
        $studentsHtml
    );

    echo $studentsHtml;

elseif ($page === 'forum_mod'):
    // Forum moderation
    echo '<h1 class="page-title">💬 Forum Moderation</h1>';
    $action = $_GET['action'] ?? '';
    if ($action === 'hide' && isset($_GET['id'])) {
        db()->exec("UPDATE forum_posts SET is_hidden=1 WHERE id=".intval($_GET['id']));
        echo '<div class="alert alert-success">✅ Post hidden.</div>';
    }
    if ($action === 'pin' && isset($_GET['id'])) {
        $cur = db()->querySingle("SELECT is_pinned FROM forum_posts WHERE id=".intval($_GET['id']));
        db()->exec("UPDATE forum_posts SET is_pinned=".($cur?0:1)." WHERE id=".intval($_GET['id']));
        echo '<div class="alert alert-success">✅ Post '.($cur?'un':'').'pinned.</div>';
    }
    $posts = db()->query("SELECT fp.*,m.title AS mod_title FROM forum_posts fp LEFT JOIN modules m ON fp.module_id=m.id WHERE fp.parent_id IS NULL ORDER BY fp.created_at DESC LIMIT 50");
    echo '<div class="dp-card"><table class="arise-table"><thead><tr><th>Post</th><th>By</th><th>Module</th><th>Replies</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
    while ($p = $posts->fetchArray(SQLITE3_ASSOC)) {
        $rc = db()->querySingle("SELECT COUNT(*) FROM forum_posts WHERE parent_id={$p['id']}");
        $title = $p['title'] ? e($p['title']) : e(substr($p['body'],0,50)).'...';
        $pinned = $p['is_pinned'] ? '📌 ' : '';
        $hidden = $p['is_hidden'] ? '<span class="badge badge-red">Hidden</span>' : '';
        echo "<tr><td><strong>$pinned$title</strong> $hidden</td><td>".e($p['student_name'])."</td><td>".e($p['mod_title']??'General')."</td><td>$rc</td><td style='font-size:.8rem'>".date('M j',strtotime($p['created_at']))."</td>";
        echo "<td style='white-space:nowrap;'><a href='?p=forum_mod&action=pin&id={$p['id']}' class='btn btn-sm btn-secondary'>".($p['is_pinned']?'📌 Unpin':'📌 Pin')."</a> ";
        if (!$p['is_hidden']) echo "<a href='?p=forum_mod&action=hide&id={$p['id']}' class='btn btn-sm' style='background:#fee2e2;color:#991b1b;' onclick='return confirm(\"Hide?\")'>Hide</a>";
        echo "</td></tr>";
    }
    echo '</tbody></table></div>';

elseif ($page === 'questions'):
    include __DIR__.'/pages/admin_questions.php';

elseif ($page === 'forum'):
    // Forum moderation — answer/delete forum posts
    $_GET['action'] = 'forum_mod';
    include __DIR__.'/pages/admin_questions.php';

elseif ($page === 'certificates'):
    include __DIR__.'/pages/admin_certificates.php';

elseif ($page === 'users'):
    include __DIR__.'/pages/admin_users.php';

elseif ($page === 'setup'):
    include __DIR__.'/pages/admin_setup.php';

elseif ($page === 'backup'):
    include __DIR__.'/pages/admin_backup.php';

elseif ($page === 'analytics'):
    include __DIR__.'/pages/admin_analytics.php';

elseif ($page === 'quiz'):
    include __DIR__.'/pages/admin_quiz.php';

elseif ($page === 'admin_question_difficulty'):
    include __DIR__.'/pages/admin_question_difficulty.php';

elseif ($page === 'teacher_content_publish'):
    include __DIR__.'/pages/teacher_content_publish.php';

elseif ($page === 'challenges'):
    include __DIR__.'/pages/admin_challenges.php';

elseif ($page === 'audit'):
    include __DIR__.'/pages/admin_audit.php';

elseif ($page === 'bulk_upload'):
    include __DIR__.'/pages/admin_bulk_upload.php';

elseif ($page === 'clusters'):
    include __DIR__.'/pages/admin_clusters.php';

elseif ($page === 'reports'):
    include __DIR__.'/pages/admin_reports.php';

elseif ($page === 'poll_results'):
    include __DIR__.'/pages/admin_poll_results.php';

elseif ($page === 'recycle'):
    include __DIR__.'/pages/admin_recycle.php';

elseif ($page === 'facilitator'):
    include __DIR__.'/pages/admin_facilitator.php';

elseif ($page === 'datapost'):
    include __DIR__.'/pages/datapost.php';

elseif ($page === 'facilitator_report'):
    // ── Session Summary / Printable Report ───────────────────────────────────
    $sessionId = intval($_GET['session_id'] ?? 0);
    if (!$sessionId) {
        echo '<div class="alert alert-danger">&#10060; No session ID provided.</div>';
    } else {
        $session = db()->querySingle("SELECT * FROM facilitator_sessions WHERE id=$sessionId", true);
        if (!$session) {
            echo '<div class="alert alert-danger">&#10060; Session not found.</div>';
        } else {
            $startedAt = $session['started_at'] ?? null;
            $endedAt   = $session['ended_at']   ?? null;

            // Duration
            $durationMins = 0;
            if ($startedAt && $endedAt) {
                $durationMins = max(0, (int) round((strtotime($endedAt) - strtotime($startedAt)) / 60));
            }
            $durationStr = $durationMins >= 60
                ? floor($durationMins / 60).'h '.($durationMins % 60).'m'
                : $durationMins.' min';

            // Active learners in session window
            $activeLearnersFromLessons = [];
            $activeLearnersFromQuizzes = [];

            if ($startedAt && $endedAt) {
                $safeStart = SQLite3::escapeString($startedAt);
                $safeEnd   = SQLite3::escapeString($endedAt);
                $lpr = db()->query(
                    "SELECT DISTINCT student_id FROM lesson_progress
                     WHERE completed=1
                       AND completed_at >= '$safeStart'
                       AND completed_at <= '$safeEnd'
                       AND student_id IS NOT NULL AND student_id > 0"
                );
                while ($r = $lpr->fetchArray(SQLITE3_ASSOC)) $activeLearnersFromLessons[] = $r['student_id'];

                $qar = db()->query(
                    "SELECT DISTINCT student_id FROM quiz_attempts
                     WHERE completed_at >= '$safeStart'
                       AND completed_at <= '$safeEnd'
                       AND student_id IS NOT NULL AND student_id > 0"
                );
                while ($r = $qar->fetchArray(SQLITE3_ASSOC)) $activeLearnersFromQuizzes[] = $r['student_id'];
            } else {
                $safeSchool  = SQLite3::escapeString($session['school_name']  ?? '');
                $safeCluster = SQLite3::escapeString($session['cluster_name'] ?? '');
                $lpr = db()->query(
                    "SELECT DISTINCT id FROM students
                     WHERE school_name='$safeSchool' AND class_name='$safeCluster' AND is_active=1"
                );
                while ($r = $lpr->fetchArray(SQLITE3_ASSOC)) $activeLearnersFromLessons[] = $r['id'];
            }

            $activeLearnerIds = array_values(array_unique(
                array_merge($activeLearnersFromLessons, $activeLearnersFromQuizzes)
            ));
            $learnerCount   = count($activeLearnerIds);
            $completedCount = count(array_unique($activeLearnersFromLessons));
            $completionRate = $learnerCount > 0 ? round($completedCount * 100 / $learnerCount) : 0;

            // Avg quiz score
            $avgScore = 0;
            if ($startedAt && $endedAt) {
                $safeStart = SQLite3::escapeString($startedAt);
                $safeEnd   = SQLite3::escapeString($endedAt);
                $avgScore  = floatval(db()->querySingle(
                    "SELECT ROUND(AVG(percentage),1) FROM quiz_attempts
                     WHERE completed_at >= '$safeStart' AND completed_at <= '$safeEnd'"
                ) ?? 0);
            }

            // Knowledge gain (pre/post)
            $avgPre = $avgPost = $knowledgeGain = 0;
            if ($startedAt && $endedAt) {
                $safeStart = SQLite3::escapeString($startedAt);
                $safeEnd   = SQLite3::escapeString($endedAt);
                $avgPre  = floatval(db()->querySingle(
                    "SELECT ROUND(AVG(percentage),1) FROM pretest_attempts
                     WHERE test_type='pre' AND taken_at >= '$safeStart' AND taken_at <= '$safeEnd'"
                ) ?? 0);
                $avgPost = floatval(db()->querySingle(
                    "SELECT ROUND(AVG(percentage),1) FROM pretest_attempts
                     WHERE test_type='post' AND taken_at >= '$safeStart' AND taken_at <= '$safeEnd'"
                ) ?? 0);
                $knowledgeGain = round($avgPost - $avgPre, 1);
            }

            $sessionDate = $startedAt ? date('l, F j, Y', strtotime($startedAt)) : '—';
            $startTime   = $startedAt ? date('g:i A', strtotime($startedAt)) : '—';
            $endTime     = $endedAt   ? date('g:i A', strtotime($endedAt))   : '—';
?>
<style>
.fac-report { max-width:800px;margin:0 auto; }
.fac-report-header { background:linear-gradient(135deg,#052e16,#0a5e2a);color:#fff;border-radius:14px;padding:28px 32px 24px;margin-bottom:20px; }
.fac-report-header .logo-row { display:flex;align-items:center;gap:10px;margin-bottom:16px; }
.fac-report-header .logo-icon { width:40px;height:40px;background:linear-gradient(135deg,#0ea271,#f59e0b);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.3rem; }
.fac-report-header .logo-text { font-size:1.2rem;font-weight:800; }
.fac-report-header .logo-sub  { font-size:.75rem;color:rgba(255,255,255,.5); }
.fac-session-code { display:inline-block;background:rgba(255,255,255,.12);color:#6ee7b7;padding:4px 14px;border-radius:8px;font-size:1.1rem;font-weight:800;letter-spacing:3px;margin-top:10px;border:1px solid rgba(110,231,183,.25); }
.fac-meta-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:20px; }
.fac-meta-item { background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px; }
.fac-meta-label { font-size:.68rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px; }
.fac-meta-value { font-size:.9rem;font-weight:700;color:#111; }
.fac-stats-row { display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:20px; }
.fac-stat-card { border-radius:12px;padding:18px 14px;text-align:center;border:1px solid #e5e7eb; }
.fac-stat-val { font-size:1.8rem;font-weight:900;line-height:1;margin-bottom:5px; }
.fac-stat-lbl { font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#6b7280; }
.fac-section-title { font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#374151;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid #e5e7eb; }
.fac-gain-track { background:#e5e7eb;border-radius:50px;height:10px;overflow:hidden;margin:6px 0; }
.fac-gain-fill  { height:100%;border-radius:50px; }
.fac-actions { display:flex;justify-content:flex-end;gap:10px;margin-bottom:16px; }
.fac-print-btn { background:#0ea271;color:#fff;border:none;border-radius:10px;padding:9px 20px;font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit; }
.fac-back-btn  { display:inline-block;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:10px;padding:9px 16px;font-size:.88rem;font-weight:600;text-decoration:none;font-family:inherit; }
@media print {
    .fac-actions,.sidebar,.topbar { display:none !important; }
    .fac-report-header,.fac-stat-card,.fac-meta-item { -webkit-print-color-adjust:exact;print-color-adjust:exact; }
}
</style>

<div class="fac-report">

  <div class="fac-actions">
    <a href="?p=facilitator" class="fac-back-btn">&#8592; Back to Sessions</a>
    <button class="fac-print-btn" onclick="window.print()">&#128438; Print Report</button>
  </div>

  <!-- Header -->
  <div class="fac-report-header">
    <div class="logo-row">
      <div class="logo-icon">&#127775;</div>
      <div>
        <div class="logo-text">ARISE</div>
        <div class="logo-sub">Session Summary Report</div>
      </div>
    </div>
    <div style="font-size:1.3rem;font-weight:800;margin-bottom:4px;">Facilitator Session Report</div>
    <div style="font-size:.85rem;color:rgba(255,255,255,.6);">
      <?= e($session['school_name'] ?? '—') ?> &mdash; <?= e($session['cluster_name'] ?? '—') ?>
    </div>
    <div class="fac-session-code"><?= e($session['session_code']) ?></div>
  </div>

  <!-- Session details -->
  <div class="dp-card" style="margin-bottom:16px;">
    <div class="fac-section-title">&#128203; Session Details</div>
    <div class="fac-meta-grid">
      <div class="fac-meta-item">
        <div class="fac-meta-label">Project</div>
        <div class="fac-meta-value"><?= e($session['school_name'] ?? '—') ?></div>
      </div>
      <div class="fac-meta-item">
        <div class="fac-meta-label">Cluster</div>
        <div class="fac-meta-value"><?= e($session['cluster_name'] ?? '—') ?></div>
      </div>
      <div class="fac-meta-item">
        <div class="fac-meta-label">Session Code</div>
        <div class="fac-meta-value" style="letter-spacing:2px;color:#0ea271;"><?= e($session['session_code']) ?></div>
      </div>
      <div class="fac-meta-item">
        <div class="fac-meta-label">Date</div>
        <div class="fac-meta-value"><?= $sessionDate ?></div>
      </div>
      <div class="fac-meta-item">
        <div class="fac-meta-label">Start Time</div>
        <div class="fac-meta-value"><?= $startTime ?></div>
      </div>
      <div class="fac-meta-item">
        <div class="fac-meta-label">End Time</div>
        <div class="fac-meta-value"><?= $endTime ?></div>
      </div>
      <div class="fac-meta-item">
        <div class="fac-meta-label">Duration</div>
        <div class="fac-meta-value"><?= $durationStr ?></div>
      </div>
    </div>
  </div>

  <!-- Key stats -->
  <div class="dp-card" style="margin-bottom:16px;">
    <div class="fac-section-title">&#128202; Key Statistics</div>
    <div class="fac-stats-row">
      <div class="fac-stat-card" style="background:#f0fdf4;border-color:#bbf7d0;">
        <div class="fac-stat-val" style="color:#065f46;"><?= $learnerCount ?></div>
        <div class="fac-stat-lbl">Active Learners</div>
      </div>
      <div class="fac-stat-card" style="background:#eff6ff;border-color:#bfdbfe;">
        <div class="fac-stat-val" style="color:#1d4ed8;"><?= $completionRate ?>%</div>
        <div class="fac-stat-lbl">Completion Rate</div>
      </div>
      <div class="fac-stat-card" style="background:#fef9c3;border-color:#fde68a;">
        <div class="fac-stat-val" style="color:#92400e;"><?= $avgScore > 0 ? $avgScore.'%' : '—' ?></div>
        <div class="fac-stat-lbl">Avg Quiz Score</div>
      </div>
      <div class="fac-stat-card" style="background:<?= $knowledgeGain >= 0 ? '#f0fdf4' : '#fef2f2' ?>;border-color:<?= $knowledgeGain >= 0 ? '#bbf7d0' : '#fecaca' ?>;">
        <div class="fac-stat-val" style="color:<?= $knowledgeGain >= 0 ? '#065f46' : '#991b1b' ?>;">
          <?= ($knowledgeGain > 0 ? '+' : '').$knowledgeGain ?>%
        </div>
        <div class="fac-stat-lbl">Knowledge Gain</div>
      </div>
    </div>
  </div>

  <!-- Pre/post assessment -->
  <div class="dp-card" style="margin-bottom:16px;">
    <div class="fac-section-title">&#128200; Pre vs. Post Assessment</div>
    <?php if ($avgPre > 0 || $avgPost > 0): ?>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:12px;">
      <div style="flex:1;min-width:160px;">
        <div style="font-size:.72rem;color:#6b7280;font-weight:600;margin-bottom:3px;">Pre-test avg: <strong><?= $avgPre ?>%</strong></div>
        <div class="fac-gain-track">
          <div class="fac-gain-fill" style="width:<?= min(100,$avgPre) ?>%;background:linear-gradient(90deg,#6b7280,#9ca3af);"></div>
        </div>
      </div>
      <div style="flex:1;min-width:160px;">
        <div style="font-size:.72rem;color:#6b7280;font-weight:600;margin-bottom:3px;">Post-test avg: <strong><?= $avgPost ?>%</strong></div>
        <div class="fac-gain-track">
          <div class="fac-gain-fill" style="width:<?= min(100,$avgPost) ?>%;background:linear-gradient(90deg,#0ea271,#f59e0b);"></div>
        </div>
      </div>
    </div>
    <div style="font-size:.83rem;color:#374151;">
      Knowledge gain: <strong style="color:<?= $knowledgeGain >= 0 ? '#065f46' : '#991b1b' ?>;"><?= ($knowledgeGain > 0 ? '+' : '').$knowledgeGain ?>%</strong>
      &mdash;
      <?php
        if ($knowledgeGain > 10)     echo 'Strong improvement during this session.';
        elseif ($knowledgeGain > 0)  echo 'Moderate improvement during this session.';
        elseif ($knowledgeGain == 0) echo 'No change detected between pre- and post-test.';
        else                         echo 'Post-test scores were lower than pre-test scores.';
      ?>
    </div>
    <?php else: ?>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;font-size:.83rem;color:#92400e;">
      &#9888; No pre/post assessment data recorded during this session window.
    </div>
    <?php endif; ?>
  </div>

  <!-- Notes for printing -->
  <div class="dp-card" style="margin-bottom:16px;">
    <div class="fac-section-title">Facilitator Notes</div>
    <div style="border:2px dashed #e5e7eb;border-radius:8px;padding:16px;min-height:80px;font-size:.85rem;color:#d1d5db;">
      (Write notes here after printing)
    </div>
  </div>

  <div style="text-align:center;font-size:.75rem;color:#9ca3af;padding:8px 0 20px;">
    ARISE Platform &mdash; Session Report &mdash; Generated <?= date('M j, Y \a\t g:i A') ?>
  </div>

</div><!-- /fac-report -->
<?php
        } // end session found
    } // end sessionId check

else:
    header('Location: ?p=dashboard'); exit;
endif;
?>

</div><!-- /page-content -->
</div><!-- /main-wrap -->
<script src="/arise/js/qr_helper.js"></script>
</body>
</html>
