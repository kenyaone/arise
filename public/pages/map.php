<?php
if (!function_exists('db')) {
    require_once __DIR__ . '/../../includes/config.php';
}

// ── Session ───────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Logout ────────────────────────────────────────────────────────────────
if (isset($_GET['cluster_logout'])) {
    unset($_SESSION['arise_cluster_id'], $_SESSION['arise_cluster_name'],
          $_SESSION['arise_school_id'],  $_SESSION['arise_school_name']);
    header('Location: /arise/?p=map');
    exit;
}

// ── Login (cluster or project-level) ─────────────────────────────────────
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cluster_login'])) {
    $cname = trim($_POST['cluster_name'] ?? '');
    $cpw   = $_POST['cluster_password'] ?? '';
    if ($cname && $cpw) {
        $pwHash = hash('sha256', $cpw);
        // Check cluster first
        $cStmt = db()->prepare("SELECT id, name, password_hash FROM clusters WHERE LOWER(name)=LOWER(:n) LIMIT 1");
        $cStmt->bindValue(':n', $cname);
        $crow = $cStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($crow && hash_equals($crow['password_hash'], $pwHash)) {
            $_SESSION['arise_cluster_id']   = $crow['id'];
            $_SESSION['arise_cluster_name'] = $crow['name'];
            unset($_SESSION['arise_school_id'], $_SESSION['arise_school_name']);
            header('Location: /arise/?p=map'); exit;
        }
        // Check school/project password
        $sStmt = db()->prepare("SELECT id, name FROM schools WHERE LOWER(name)=LOWER(:n) AND password_hash=:h AND is_active=1 LIMIT 1");
        $sStmt->bindValue(':n', $cname); $sStmt->bindValue(':h', $pwHash);
        $srow = $sStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($srow) {
            $_SESSION['arise_school_id']   = $srow['id'];
            $_SESSION['arise_school_name'] = $srow['name'];
            unset($_SESSION['arise_cluster_id'], $_SESSION['arise_cluster_name']);
            header('Location: /arise/?p=map'); exit;
        }
        $loginError = 'Incorrect project name or password.';
    } else {
        $loginError = 'Please enter both project name and password.';
    }
}

// ── Determine access level ────────────────────────────────────────────────
$isAdmin    = !empty($_SESSION['arise_admin_id']);
$clusterId  = $_SESSION['arise_cluster_id']  ?? null;
$clusterName= $_SESSION['arise_cluster_name']?? null;
$schoolId   = $_SESSION['arise_school_id']   ?? null;
$schoolName = $_SESSION['arise_school_name'] ?? null;

// Must be admin, cluster session, or school session to see the map
if (!$isAdmin && !$clusterId && !$schoolId) {
    // Show login form
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARISE Projects Map — Login</title>
    <link rel="stylesheet" href="/arise/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #0D5E3D 0%, #1E8055 35%, #FF9700 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        .login-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }
        .login-card h1 {
            color: #0a5e2a;
            font-size: 1.6rem;
            margin-bottom: 6px;
            text-align: center;
        }
        .login-card p.sub {
            color: #666;
            text-align: center;
            font-size: 0.9rem;
            margin-bottom: 28px;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group input:focus { border-color: #0ea271; }
        .btn-login {
            width: 100%;
            background: #0ea271;
            color: white;
            border: none;
            padding: 13px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
        }
        .btn-login:hover { background: #059669; }
        .error-box {
            background: #fee2e2;
            color: #b91c1c;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.9rem;
            margin-bottom: 18px;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #0ea271;
            text-decoration: none;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="login-card">
    <h1>🗺 ARISE Projects Map</h1>
    <p class="sub">Sign in with your project or cluster name and password</p>
    <?php if ($loginError): ?>
    <div class="error-box">⚠️ <?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="POST" action="/arise/?p=map">
        <input type="hidden" name="cluster_login" value="1">
        <div class="form-group">
            <label for="cluster_name">Project or Cluster Name</label>
            <input type="text" id="cluster_name" name="cluster_name" placeholder="Enter your project or cluster name" required autocomplete="username">
        </div>
        <div class="form-group">
            <label for="cluster_password">Password</label>
            <input type="password" id="cluster_password" name="cluster_password" placeholder="Enter cluster password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn-login">Sign In →</button>
    </form>
    <a href="/arise/" class="back-link">← Back to ARISE</a>
</div>
</body>
</html>
    <?php
    exit;
}

// ── DB migrations (safe, idempotent) ─────────────────────────────────────
foreach ([
    "ALTER TABLE schools ADD COLUMN lat REAL DEFAULT NULL",
    "ALTER TABLE schools ADD COLUMN lng REAL DEFAULT NULL",
] as $sql) { try { db()->exec($sql); } catch(Exception $e) {} }

// Ensure device_sync_stats exists (may be empty on local devices — that's fine)
try {
    db()->exec("CREATE TABLE IF NOT EXISTS device_sync_stats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id TEXT NOT NULL, school_name TEXT NOT NULL,
        county TEXT, lat REAL, lng REAL,
        learner_count INTEGER DEFAULT 0, quiz_count INTEGER DEFAULT 0,
        avg_score REAL DEFAULT 0, cert_count INTEGER DEFAULT 0,
        pretest_count INTEGER DEFAULT 0, posttest_count INTEGER DEFAULT 0,
        cert_rate REAL DEFAULT 0, quiz_pass_count INTEGER DEFAULT 0,
        quiz_pass_rate REAL DEFAULT 0,
        avg_pre_score REAL DEFAULT NULL, avg_post_score REAL DEFAULT NULL,
        knowledge_gain REAL DEFAULT NULL,
        behavior_surveys INTEGER DEFAULT 0,
        pct_changed REAL DEFAULT 0, pct_shared REAL DEFAULT 0, pct_confident REAL DEFAULT 0,
        retention_count INTEGER DEFAULT 0, avg_retention_score REAL DEFAULT 0,
        lesson_completions INTEGER DEFAULT 0, active_last_30_days INTEGER DEFAULT 0,
        first_registration TEXT DEFAULT NULL, latest_activity TEXT DEFAULT NULL,
        facilitator_sessions INTEGER DEFAULT 0, last_synced_at DATETIME,
        UNIQUE(device_id, school_name)
    )");
    foreach ([
        "ALTER TABLE device_sync_stats ADD COLUMN cert_rate REAL DEFAULT 0",
        "ALTER TABLE device_sync_stats ADD COLUMN avg_pre_score REAL DEFAULT NULL",
        "ALTER TABLE device_sync_stats ADD COLUMN avg_post_score REAL DEFAULT NULL",
        "ALTER TABLE device_sync_stats ADD COLUMN knowledge_gain REAL DEFAULT NULL",
        "ALTER TABLE device_sync_stats ADD COLUMN behavior_surveys INTEGER DEFAULT 0",
        "ALTER TABLE device_sync_stats ADD COLUMN pct_changed REAL DEFAULT 0",
        "ALTER TABLE device_sync_stats ADD COLUMN pct_shared REAL DEFAULT 0",
        "ALTER TABLE device_sync_stats ADD COLUMN pct_confident REAL DEFAULT 0",
        "ALTER TABLE device_sync_stats ADD COLUMN latest_activity TEXT DEFAULT NULL",
    ] as $s) { try { db()->exec($s); } catch(Exception $e) {} }
} catch(Exception $e) {}

// ── Seed coordinates for Kenyan schools (run once per school) ──────────────
$coords = [
    'Arise School' => [0.5204, 35.2698],
    'Moi Girls High School Eldoret' => [0.5174, 35.2740],
    'Kapsabet Boys High School' => [0.2028, 35.1045],
    'Nairobi School' => [-1.2980, 36.8240],
    'Alliance Girls High School' => [-1.1713, 36.8351],
    'St. Mary\'s School Nairobi' => [-1.2860, 36.8200],
    'Matioli' => [0.1028, 34.3437],
];

foreach ($coords as $name => $coord) {
    $checkStmt = db()->prepare("SELECT lat FROM schools WHERE name=:n AND lat IS NULL LIMIT 1");
    $checkStmt->bindValue(':n', $name);
    $check = $checkStmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($check !== false) {
        $stmt = db()->prepare("UPDATE schools SET lat=?, lng=? WHERE name=?");
        $stmt->bindValue(1, $coord[0]);
        $stmt->bindValue(2, $coord[1]);
        $stmt->bindValue(3, $name);
        try { $stmt->execute(); } catch(Exception $e) {}
    }
}

// ── Cluster analytics (all clusters or just the logged-in one) ───────────
$clusterWhere = "";
if (!$isAdmin && $clusterId) $clusterWhere = "AND c.id = " . intval($clusterId);
if ($schoolId)               $clusterWhere = "AND sc.id = " . intval($schoolId); // school login: only that school's cluster

$clusterRes = db()->query("
    SELECT
        c.id, c.name, c.lat, c.lng,
        COUNT(DISTINCT sc.id) AS projects,
        COALESCE(NULLIF(COUNT(DISTINCT s.id),0), COALESCE(SUM(d.learner_count),0)) AS learners,
        COALESCE(NULLIF(COUNT(DISTINCT cert.id),0), COALESCE(SUM(d.cert_count),0)) AS certs,
        COALESCE(NULLIF(COUNT(DISTINCT qa.id),0), COALESCE(SUM(d.quiz_count),0)) AS quiz_attempts,
        CASE WHEN COUNT(DISTINCT qa.id)>0
             THEN ROUND(AVG(CAST(qa.percentage AS REAL)),1)
             WHEN SUM(d.quiz_count)>0
             THEN ROUND(SUM(d.avg_score*d.quiz_count)/NULLIF(SUM(d.quiz_count),0),1)
             ELSE 0 END AS avg_score,
        CASE WHEN COALESCE(SUM(d.learner_count),0)>0
             THEN ROUND(100.0*COALESCE(SUM(d.cert_count),0)/SUM(d.learner_count),1)
             ELSE ROUND(100.0*COUNT(DISTINCT cert.id)/NULLIF(COUNT(DISTINCT s.id),0),1) END AS cert_rate,
        ROUND(AVG(CASE WHEN d.knowledge_gain IS NOT NULL THEN d.knowledge_gain END),1) AS knowledge_gain,
        MAX(COALESCE(s.last_seen, d.latest_activity)) AS last_activity
    FROM clusters c
    LEFT JOIN schools sc ON sc.cluster_id = c.id AND sc.is_active = 1
    LEFT JOIN students s ON s.school_name = sc.name AND s.deleted_at IS NULL
    LEFT JOIN certificates cert ON cert.student_id = s.id
    LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
    LEFT JOIN device_sync_stats d ON d.school_name = sc.name AND d.device_id='arise-default'
    WHERE 1=1 $clusterWhere
    GROUP BY c.id
    ORDER BY learners DESC, c.name ASC
");
$clusters_data = [];
while ($row = $clusterRes->fetchArray(SQLITE3_ASSOC)) { $clusters_data[] = $row; }

// ── Per-school analytics for the card grid ───────────────────────────────
$schoolFilter = "";
if (!$isAdmin && $clusterId) $schoolFilter = "AND sc.cluster_id = " . intval($clusterId);
if ($schoolId)               $schoolFilter = "AND sc.id = " . intval($schoolId);

$schoolRes = db()->query("
    SELECT sc.id, sc.name, sc.county, sc.lat, sc.lng, sc.cluster_id,
        cl.name AS cluster_name,
        COALESCE(NULLIF(COUNT(DISTINCT s.id),0), COALESCE(d.learner_count,0)) AS learners,
        COALESCE(NULLIF(COUNT(DISTINCT c.id),0), COALESCE(d.cert_count,0)) AS certs,
        CASE WHEN COUNT(DISTINCT qa.id)>0 THEN ROUND(AVG(CAST(qa.percentage AS REAL)),1)
             ELSE COALESCE(d.avg_score,0) END AS avg_score,
        COALESCE(NULLIF(COUNT(DISTINCT qa.id),0), COALESCE(d.quiz_count,0)) AS quiz_attempts,
        COALESCE(d.cert_rate, 0) AS cert_rate,
        d.knowledge_gain,
        d.avg_pre_score AS avg_pre,
        d.avg_post_score AS avg_post,
        d.pct_changed,
        d.behavior_surveys
    FROM schools sc
    LEFT JOIN clusters cl ON cl.id = sc.cluster_id
    LEFT JOIN students s  ON s.school_name = sc.name AND s.deleted_at IS NULL
    LEFT JOIN certificates c ON c.student_id = s.id
    LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
    LEFT JOIN device_sync_stats d ON d.school_name = sc.name AND d.device_id='arise-default'
    WHERE sc.is_active = 1 $schoolFilter
    GROUP BY sc.id
    ORDER BY learners DESC, sc.name ASC
");
$schools = [];
while ($row = $schoolRes->fetchArray(SQLITE3_ASSOC)) { $schools[] = $row; }

// ── Totals ────────────────────────────────────────────────────────────────
$total_projects = count($schools);
$total_learners = 0; $total_certs = 0; $total_attempts = 0;
foreach ($clusters_data as $cl) {
    $total_learners += (int)$cl['learners'];
    $total_certs    += (int)$cl['certs'];
    $total_attempts += (int)$cl['quiz_attempts'];
}
$clusters_synced = count(array_filter($clusters_data, fn($c) => $c['learners'] > 0));
$clusters_unsynced = count($clusters_data) - $clusters_synced;

if ($total_attempts > 0) {
    $global_avg_score = round(db()->querySingle("SELECT AVG(CAST(percentage AS REAL)) FROM quiz_attempts"), 1);
} elseif (db()->querySingle("SELECT COUNT(*) FROM device_sync_stats WHERE device_id='arise-default'") > 0) {
    $global_avg_score = round(db()->querySingle("SELECT SUM(avg_score*quiz_count)/NULLIF(SUM(quiz_count),0) FROM device_sync_stats WHERE device_id='arise-default'") ?: 0, 1);
    $total_learners   = (int)db()->querySingle("SELECT SUM(learner_count) FROM device_sync_stats WHERE device_id='arise-default'");
    $total_certs      = (int)db()->querySingle("SELECT SUM(cert_count) FROM device_sync_stats WHERE device_id='arise-default'");
} else {
    $global_avg_score = 0;
}

$clustersJson = json_encode($clusters_data);
$schoolsJson  = json_encode($schools);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARISE Projects Map<?= $clusterName ? ' — ' . htmlspecialchars($clusterName) : '' ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="/arise/css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #0D5E3D 0%, #1E8055 35%, #FF9700 100%);
            background-attachment: fixed;
            min-height: 100vh;
            color: #1a1a1a;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-top h1 {
            font-size: 2rem;
            color: #0a5e2a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

        .print-btn {
            background: #0ea271;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.95rem;
            text-decoration: none;
        }

        .print-btn:hover { background: #059669; }

        .logout-btn {
            background: rgba(100,100,100,0.12);
            color: #555;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
        }
        .logout-btn:hover { background: rgba(100,100,100,0.2); }

        .cluster-badge {
            background: #fef3c7;
            color: #92400e;
            border: 1.5px solid #fcd34d;
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 0.85rem;
            font-weight: 700;
        }

        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .stat-item {
            background: linear-gradient(135deg, #0a5e2a, #0ea271);
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-item .number {
            font-size: 1.8rem;
            font-weight: 900;
        }

        .stat-item .label {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 5px;
        }

        .map-section {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #map {
            height: 500px;
            width: 100%;
        }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .project-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0ea271;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .project-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .project-card h3 {
            color: #0a5e2a;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }

        .project-card p { color: #666; font-size: 0.9rem; margin: 8px 0; }

        .project-card .county {
            color: #0ea271;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .metric {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-top: 1px solid #f0f0f0;
        }

        .metric:first-of-type { border-top: none; }
        .metric-label { color: #666; }
        .metric-value { font-weight: 700; color: #0a5e2a; }

        .footer {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            color: #666;
            margin-top: 20px;
        }

        .footer p { margin: 8px 0; }
        .footer a { color: #0ea271; text-decoration: none; font-weight: 600; }
        .footer a:hover { text-decoration: underline; }

        @media print {
            body { background: white; }
            .print-btn, .logout-btn { display: none; }
            .container { max-width: 100%; }
        }

        @media(max-width: 768px) {
            .header-top { flex-direction: column; align-items: flex-start; }
            .header-top h1 { font-size: 1.5rem; }
            .stats-bar { grid-template-columns: repeat(2, 1fr); }
            #map { height: 350px; }
            .projects-grid { grid-template-columns: 1fr; }
        }

        .leaflet-popup-content { font-family: 'Segoe UI', Arial, sans-serif; }
        .leaflet-popup-content h4 { color: #0a5e2a; margin-bottom: 8px; font-size: 1rem; }
        .leaflet-popup-content p { margin: 4px 0; color: #666; font-size: 0.9rem; }
    </style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="header-top">
            <h1>🗺 ARISE Projects Map<?= $clusterName ? '<span style="font-size:1.1rem;color:#666;margin-left:10px;font-weight:400">— ' . htmlspecialchars($clusterName) . '</span>' : '' ?></h1>
            <div class="header-actions">
                <?php if ($schoolName): ?>
                <span class="cluster-badge">🏫 <?= htmlspecialchars($schoolName) ?></span>
                <a href="/arise/?p=map&cluster_logout=1" class="logout-btn">Sign Out</a>
                <?php elseif ($clusterName): ?>
                <span class="cluster-badge">📍 <?= htmlspecialchars($clusterName) ?></span>
                <a href="/arise/?p=map&cluster_logout=1" class="logout-btn">Sign Out</a>
                <?php elseif ($isAdmin): ?>
                <span class="cluster-badge" style="background:#e0f2fe;color:#075985;border-color:#7dd3fc;">👑 Admin View</span>
                <?php endif; ?>
                <button class="print-btn" onclick="exportExcel()" style="background:#1d6f42;">📊 Export Excel</button>
                <button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
            </div>
        </div>

        <div class="stats-bar">
            <div class="stat-item">
                <div class="number"><?= $total_projects ?></div>
                <div class="label">Active Projects</div>
            </div>
            <div class="stat-item">
                <div class="number"><?= number_format($total_learners) ?></div>
                <div class="label">Total Learners</div>
            </div>
            <div class="stat-item" style="background:linear-gradient(135deg,#166534,#22c55e)">
                <div class="number"><?= $clusters_synced ?></div>
                <div class="label">Clusters Synced &#128994;</div>
            </div>
            <div class="stat-item" style="background:linear-gradient(135deg,#7f1d1d,#ef4444)">
                <div class="number"><?= $clusters_unsynced ?></div>
                <div class="label">Need Sync &#128308;</div>
            </div>
        </div>
    </div>

    <!-- Map legend -->
    <div style="background:rgba(255,255,255,0.92);border-radius:10px;padding:10px 18px;margin-bottom:12px;display:flex;gap:20px;align-items:center;flex-wrap:wrap;font-size:.85rem;font-weight:600;">
        <span>&#128205; Clusters:</span>
        <span style="color:#166534">&#11044; Active &mdash; learners this month</span>
        <span style="color:#d97706">&#11044; Stale &mdash; 30+ days quiet</span>
        <span style="color:#b91c1c">&#11044; No data yet</span>
        <span style="color:#4338ca;margin-left:8px;">&#11044; Projects (approx. if no pin set)</span>
    </div>

    <!-- Map -->
    <div class="map-section">
        <div id="map"></div>
    </div>

    <!-- Cluster summary cards -->
    <?php if ($isAdmin): ?>
    <div>
        <h2 style="color:white;margin-bottom:15px;">&#128209; Cluster Sync Status <span style="font-size:.85rem;font-weight:400;opacity:.8">— click a card to locate on map</span></h2>
        <div class="projects-grid">
            <?php foreach ($clusters_data as $cl):
                $hasData = $cl['learners'] > 0;
                $lastAct = $cl['last_activity'] ?? null;
                $daysSince = $lastAct ? (time() - strtotime($lastAct)) / 86400 : 999;
                $isStale = $hasData && $daysSince > 30;
                if (!$hasData)      { $borderColor = '#ef4444'; $statusIcon = '&#128308;'; $statusText = 'No data'; $badgeBg = '#fee2e2'; $badgeFg = '#991b1b'; }
                elseif ($isStale)   { $borderColor = '#f59e0b'; $statusIcon = '&#127992;'; $statusText = 'Stale';   $badgeBg = '#fef3c7'; $badgeFg = '#92400e'; }
                else                { $borderColor = '#22c55e'; $statusIcon = '&#128994;'; $statusText = 'Active';  $badgeBg = '#dcfce7'; $badgeFg = '#166534'; }
                $certRate = ($cl['cert_rate'] ?? 0) ?: 0;
            ?>
            <div class="project-card" style="border-left-color:<?= $borderColor ?>;cursor:pointer;transition:transform .15s,box-shadow .15s" onclick="flyToCluster(<?= $cl['id'] ?>)">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
                    <h3 style="margin:0"><?= htmlspecialchars($cl['name']) ?></h3>
                    <span style="font-size:.75rem;font-weight:700;padding:3px 10px;border-radius:20px;background:<?= $badgeBg ?>;color:<?= $badgeFg ?>"><?= $statusIcon ?> <?= $statusText ?></span>
                </div>
                <?php if ($lastAct && $hasData): ?>
                <div style="font-size:.75rem;color:#6b7280;margin-bottom:8px;">Last activity: <?= date('M j, Y', strtotime($lastAct)) ?></div>
                <?php endif; ?>
                <div class="metric">
                    <span class="metric-label">&#127982; Projects</span>
                    <span class="metric-value"><?= $cl['projects'] ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">&#128105;&#8205;&#127979; Learners</span>
                    <span class="metric-value"><?= $cl['learners'] ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">&#127891; Cert Rate</span>
                    <span class="metric-value" style="color:<?= $certRate >= 50 ? '#0ea271' : ($certRate > 0 ? '#f59e0b' : '#9ca3af') ?>"><?= $certRate > 0 ? $certRate . '%' : '—' ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">&#128202; Quiz Avg</span>
                    <span class="metric-value"><?= ($cl['avg_score'] ?? 0) > 0 ? $cl['avg_score'] . '%' : 'N/A' ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <!-- Cluster manager: show individual project cards -->
    <div>
        <h2 style="color:white;margin-bottom:15px;">&#128205; Projects in <?= htmlspecialchars($clusterName ?? 'Cluster') ?></h2>
        <div class="projects-grid">
            <?php foreach ($schools as $school):
                $noLearners = (int)($school['learners'] ?? 0) === 0;
            ?>
            <div class="project-card" style="<?= $noLearners ? 'opacity:.75;border-top:3px solid #9ca3af;' : '' ?>">
                <?php if ($noLearners): ?>
                    <div style="display:inline-block;background:#f3f4f6;color:#6b7280;font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:10px;margin-bottom:6px;letter-spacing:.03em;">⏳ No learners yet</div>
                <?php endif; ?>
                <h3><?= htmlspecialchars($school['name']) ?></h3>
                <p class="county">&#128205; <?= htmlspecialchars($school['county'] ?? 'Unknown') ?></p>
                <div class="metric">
                    <span class="metric-label">&#128105;&#8205;&#127979; Learners</span>
                    <span class="metric-value" style="<?= $noLearners ? 'color:#9ca3af;' : '' ?>"><?= $school['learners'] ?? 0 ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">&#128202; Quiz Avg</span>
                    <span class="metric-value"><?= ($school['avg_score'] ?? 0) > 0 ? $school['avg_score'] . '%' : 'N/A' ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">&#127891; Certificates</span>
                    <span class="metric-value"><?= $school['certs'] ?? 0 ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="footer">
        <p><strong>ARISE Platform</strong> — Adolescent Reproductive Health Information Support & Empowerment</p>
        <p>Real-time map of ARISE project deployments across Kenya</p>
        <p style="margin-top: 15px;"><a href="/arise/">← Back to ARISE Home</a></p>
    </div>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<!-- SheetJS for Excel export -->
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
    const clustersData = <?= $clustersJson ?>;
    const schoolsData  = <?= $schoolsJson ?>;

    const map = L.map('map').setView([-0.5, 37.0], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // ── Cluster marker registry (for fly-to) ─────────────────────────────
    const clusterMarkers = {};
    const clusterCoords  = {};

    // ── Cluster markers ───────────────────────────────────────────────────
    clustersData.forEach(cl => {
        if (!cl.lat || !cl.lng) return;
        clusterCoords[cl.id] = [parseFloat(cl.lat), parseFloat(cl.lng)];

        const hasData = cl.learners > 0;
        const lastAct = cl.last_activity ? new Date(cl.last_activity.replace(' ','T')) : null;
        const daysSince = lastAct ? (Date.now() - lastAct.getTime()) / 86400000 : Infinity;
        const isStale = hasData && daysSince > 30;

        const fillColor = !hasData ? '#dc2626' : (isStale ? '#d97706' : '#16a34a');
        const ringColor = !hasData ? '#b91c1c' : (isStale ? '#b45309' : '#15803d');
        const statusLabel = !hasData ? '🔴 No data yet' : (isStale ? `🟡 Stale — ${Math.round(daysSince)}d ago` : '🟢 Active');
        const radius = Math.max(14, Math.min(28, 10 + (cl.projects || 0)));
        const certRate = cl.learners > 0 ? Math.round((cl.certs / cl.learners) * 100) : 0;
        const lastActStr = lastAct ? lastAct.toLocaleDateString('en-KE', {day:'numeric',month:'short',year:'numeric'}) : '—';

        const kgStr = cl.knowledge_gain != null ? `<span style="color:#555">Knowledge gain</span><strong style="color:#0369a1">+${cl.knowledge_gain}%</strong>` : '';
        const popup = `
            <div style="font-size:.88rem;width:230px">
                <div style="font-weight:800;font-size:1rem;color:#111;margin-bottom:4px">${cl.name}</div>
                <div style="margin-bottom:8px;font-size:.8rem;color:#555">${statusLabel} &nbsp;·&nbsp; Last: ${lastActStr}</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px 10px;font-size:.82rem">
                    <span style="color:#555">Projects</span><strong>${cl.projects || 0}</strong>
                    <span style="color:#555">Learners</span><strong>${cl.learners || 0}</strong>
                    <span style="color:#555">Cert rate</span><strong style="color:${certRate>=50?'#0ea271':certRate>0?'#d97706':'#9ca3af'}">${certRate > 0 ? certRate + '%' : '—'}</strong>
                    <span style="color:#555">Quiz avg</span><strong>${cl.avg_score > 0 ? cl.avg_score + '%' : 'N/A'}</strong>
                    ${kgStr}
                </div>
                ${!hasData ? '<div style="margin-top:8px;padding:5px 8px;background:#fee2e2;border-radius:6px;font-size:.77rem;color:#991b1b;font-weight:600">⚠️ Needs device deployment or data sync</div>' : ''}
            </div>`;

        const m = L.circleMarker([cl.lat, cl.lng], {
            radius, fillColor, color: ringColor, weight: 3, opacity: 0.9, fillOpacity: 0.8
        }).bindPopup(popup).addTo(map);
        clusterMarkers[cl.id] = m;

        // Cluster name label
        L.marker([cl.lat, cl.lng], {
            icon: L.divIcon({
                className: '',
                html: `<div style="font-size:.65rem;font-weight:700;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.7);text-align:center;pointer-events:none;margin-top:-8px;white-space:nowrap">${cl.name.split('-')[0]}</div>`,
                iconAnchor: [0, 0]
            }),
            interactive: false, zIndexOffset: 1000
        }).addTo(map);
    });

    // ── Individual project markers ─────────────────────────────────────────
    // Deterministic jitter so schools without exact coords stay in place on reload
    function jitter(id, range) {
        const h = (id * 2654435761) >>> 0;
        return ((h % 1000) / 1000 - 0.5) * range;
    }

    schoolsData.forEach(sc => {
        // Only show projects that have learners
        if ((sc.learners || 0) < 1) return;

        let lat = parseFloat(sc.lat) || 0;
        let lng = parseFloat(sc.lng) || 0;
        let isApprox = false;

        if (!lat || !lng) {
            const base = sc.cluster_id ? clusterCoords[sc.cluster_id] : null;
            if (!base) return;
            lat = base[0] + jitter(sc.id, 0.18);
            lng = base[1] + jitter(sc.id + 1000, 0.22);
            isApprox = true;
        }

        const hasLearners = true; // guaranteed by filter above
        const fillColor  = hasLearners ? '#6366f1' : '#9ca3af';
        const strokeColor = hasLearners ? '#4338ca' : '#6b7280';
        const approxNote  = isApprox ? '<div style="margin-top:5px;font-size:.72rem;color:#d97706;font-style:italic">📍 Approx. location — set exact pin in admin</div>' : '';
        const certRate = sc.cert_rate > 0 ? sc.cert_rate + '%' : (sc.learners > 0 && sc.certs > 0 ? Math.round(sc.certs/sc.learners*100)+'%' : '—');
        const kgLine = sc.knowledge_gain != null ? `<span style="color:#555">Knowledge gain</span><strong style="color:#0369a1">+${sc.knowledge_gain}%</strong>` : '';
        const bchgLine = sc.pct_changed > 0 ? `<span style="color:#555">Behavior change</span><strong style="color:#7c3aed">${sc.pct_changed}%</strong>` : '';
        const noDataNote  = hasLearners ? '' : '<div style="margin-top:5px;padding:3px 7px;background:#f3f4f6;border-radius:5px;font-size:.75rem;color:#6b7280;font-weight:600">⏳ No learners yet</div>';
        const popup = `<div style="font-size:.85rem;width:210px">
            <strong style="color:#0a5e2a">${sc.name}</strong><br>
            <span style="color:#666;font-size:.8rem">📌 ${sc.county || ''}${sc.cluster_name ? ' · ' + sc.cluster_name : ''}</span><br>
            <div style="margin-top:6px;display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:.82rem">
                <span style="color:#555">Learners</span><strong>${sc.learners || 0}</strong>
                <span style="color:#555">Cert rate</span><strong>${certRate}</strong>
                <span style="color:#555">Quiz avg</span><strong>${sc.avg_score > 0 ? sc.avg_score + '%' : '—'}</strong>
                <span style="color:#555">Certs</span><strong>${sc.certs || 0}</strong>
                ${kgLine}${bchgLine}
            </div>
            ${noDataNote}${approxNote}
        </div>`;

        L.circleMarker([lat, lng], {
            radius: hasLearners ? 7 : 5,
            fillColor, color: strokeColor,
            weight: 1.5, opacity: 1,
            fillOpacity: isApprox ? 0.35 : (hasLearners ? 0.85 : 0.55),
            dashArray: isApprox ? '3 4' : null
        }).bindPopup(popup).addTo(map);
    });

    // ── Fly to cluster on card click ──────────────────────────────────────
    function flyToCluster(id) {
        const m = clusterMarkers[id];
        if (!m) return;
        document.getElementById('map').scrollIntoView({behavior: 'smooth', block: 'center'});
        setTimeout(() => {
            map.flyTo(m.getLatLng(), 9, {duration: 1.0});
            m.openPopup();
        }, 350);
    }

    // ── Excel export ──────────────────────────────────────────────────────
    function exportExcel() {
        const wb = XLSX.utils.book_new();
        const date = new Date().toLocaleDateString('en-KE', {day:'2-digit',month:'short',year:'numeric'}).replace(/ /g,'-');

        // ── Sheet 1: Projects ─────────────────────────────────────────────
        const projHeaders = [
            'Project Name','County','Cluster','Learners','Certificates',
            'Cert Rate (%)','Quiz Avg (%)','Knowledge Gain (%)','Behavior Change (%)',
            'Behavior Surveys','Pre-Test Avg (%)','Post-Test Avg (%)','Quiz Attempts'
        ];
        const projRows = schoolsData.map(s => [
            s.name        || '',
            s.county      || '',
            s.cluster_name|| '',
            s.learners    || 0,
            s.certs       || 0,
            s.cert_rate   > 0 ? parseFloat(s.cert_rate)   : '',
            s.avg_score   > 0 ? parseFloat(s.avg_score)   : '',
            s.knowledge_gain != null ? parseFloat(s.knowledge_gain) : '',
            s.pct_changed > 0 ? parseFloat(s.pct_changed) : '',
            s.behavior_surveys || 0,
            s.avg_pre  != null ? parseFloat(s.avg_pre)  : '',
            s.avg_post != null ? parseFloat(s.avg_post) : '',
            s.quiz_attempts || 0
        ]);
        const projSheet = XLSX.utils.aoa_to_sheet([projHeaders, ...projRows]);
        // Column widths
        projSheet['!cols'] = [22,14,18,10,12,13,12,16,18,16,15,16,13].map(w => ({wch: w}));
        XLSX.utils.book_append_sheet(wb, projSheet, 'Projects');

        // ── Sheet 2: Clusters ─────────────────────────────────────────────
        const clHeaders = [
            'Cluster Name','Projects','Learners','Certificates',
            'Cert Rate (%)','Quiz Avg (%)','Knowledge Gain (%)','Last Activity'
        ];
        const clRows = clustersData.map(c => [
            c.name           || '',
            c.projects       || 0,
            c.learners       || 0,
            c.certs          || 0,
            c.cert_rate > 0  ? parseFloat(c.cert_rate)      : '',
            c.avg_score > 0  ? parseFloat(c.avg_score)      : '',
            c.knowledge_gain != null ? parseFloat(c.knowledge_gain) : '',
            c.last_activity  || ''
        ]);
        const clSheet = XLSX.utils.aoa_to_sheet([clHeaders, ...clRows]);
        clSheet['!cols'] = [22,10,10,14,13,12,16,20].map(w => ({wch: w}));
        XLSX.utils.book_append_sheet(wb, clSheet, 'Clusters');

        XLSX.writeFile(wb, `ARISE-Projects-Map-${date}.xlsx`);
    }
</script>

</body>
</html>
