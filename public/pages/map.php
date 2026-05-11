<?php
if (!function_exists('db')) {
    require_once __DIR__ . '/../../includes/config.php';
}

// ── Session ───────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Cluster logout ────────────────────────────────────────────────────────
if (isset($_GET['cluster_logout'])) {
    unset($_SESSION['arise_cluster_id'], $_SESSION['arise_cluster_name']);
    header('Location: /arise/?p=map');
    exit;
}

// ── Cluster login ─────────────────────────────────────────────────────────
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cluster_login'])) {
    $cname = trim($_POST['cluster_name'] ?? '');
    $cpw   = $_POST['cluster_password'] ?? '';
    if ($cname && $cpw) {
        $cStmt = db()->prepare("SELECT id, name, password_hash FROM clusters WHERE LOWER(name)=LOWER(:n) LIMIT 1");
        $cStmt->bindValue(':n', $cname);
        $row = $cStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row && hash_equals($row['password_hash'], hash('sha256', $cpw))) {
            $_SESSION['arise_cluster_id']   = $row['id'];
            $_SESSION['arise_cluster_name'] = $row['name'];
            header('Location: /arise/?p=map');
            exit;
        }
        $loginError = 'Incorrect cluster name or password.';
    } else {
        $loginError = 'Please enter both cluster name and password.';
    }
}

// ── Determine access level ────────────────────────────────────────────────
$isAdmin   = !empty($_SESSION['arise_admin_id']);
$clusterId = $_SESSION['arise_cluster_id'] ?? null;
$clusterName = $_SESSION['arise_cluster_name'] ?? null;

// Must be admin or have a cluster session to see the map
if (!$isAdmin && !$clusterId) {
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
    <p class="sub">Sign in with your cluster credentials to view your project locations</p>
    <?php if ($loginError): ?>
    <div class="error-box">⚠️ <?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="POST" action="/arise/?p=map">
        <input type="hidden" name="cluster_login" value="1">
        <div class="form-group">
            <label for="cluster_name">Cluster Name</label>
            <input type="text" id="cluster_name" name="cluster_name" placeholder="e.g. Kisumu North" required autocomplete="username">
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
$clusterWhere = (!$isAdmin && $clusterId) ? "AND c.id = " . intval($clusterId) : "";

$clusterRes = db()->query("
    SELECT
        c.id, c.name, c.lat, c.lng,
        COUNT(DISTINCT sc.id) AS projects,
        COUNT(DISTINCT s.id)  AS learners,
        COUNT(DISTINCT cert.id) AS certs,
        COUNT(DISTINCT qa.id) AS quiz_attempts,
        ROUND(AVG(CAST(qa.percentage AS REAL)), 1) AS avg_score
    FROM clusters c
    LEFT JOIN schools sc ON sc.cluster_id = c.id AND sc.is_active = 1
    LEFT JOIN students s ON s.school_name = sc.name AND s.deleted_at IS NULL
    LEFT JOIN certificates cert ON cert.student_id = s.id
    LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
    WHERE 1=1 $clusterWhere
    GROUP BY c.id
    ORDER BY learners DESC, c.name ASC
");
$clusters_data = [];
while ($row = $clusterRes->fetchArray(SQLITE3_ASSOC)) { $clusters_data[] = $row; }

// ── Per-school analytics for the card grid ───────────────────────────────
$schoolFilter = (!$isAdmin && $clusterId) ? "AND sc.cluster_id = " . intval($clusterId) : "";

$schoolRes = db()->query("
    SELECT sc.id, sc.name, sc.county, sc.lat, sc.lng, sc.cluster_id,
        cl.name AS cluster_name,
        COUNT(DISTINCT s.id)    AS learners,
        COUNT(DISTINCT c.id)    AS certs,
        ROUND(AVG(CAST(qa.percentage AS REAL)), 1) AS avg_score,
        COUNT(DISTINCT qa.id)   AS quiz_attempts
    FROM schools sc
    LEFT JOIN clusters cl ON cl.id = sc.cluster_id
    LEFT JOIN students s  ON s.school_name = sc.name AND s.deleted_at IS NULL
    LEFT JOIN certificates c ON c.student_id = s.id
    LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
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

$global_avg_score = $total_attempts > 0 ? round(db()->querySingle("SELECT AVG(CAST(percentage AS REAL)) FROM quiz_attempts"), 1) : 0;

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
                <?php if ($clusterName): ?>
                <span class="cluster-badge">📍 <?= htmlspecialchars($clusterName) ?></span>
                <a href="/arise/?p=map&cluster_logout=1" class="logout-btn">Sign Out</a>
                <?php elseif ($isAdmin): ?>
                <span class="cluster-badge" style="background:#e0f2fe;color:#075985;border-color:#7dd3fc;">👑 Admin View</span>
                <?php endif; ?>
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
        <span>&#128205; Map Legend:</span>
        <span style="color:#166534">&#11044; Synced &mdash; has learner data</span>
        <span style="color:#b91c1c">&#11044; No data &mdash; needs device/sync</span>
        <span style="color:#d97706">&#11044; Partial &mdash; has projects, few learners</span>
    </div>

    <!-- Map -->
    <div class="map-section">
        <div id="map"></div>
    </div>

    <!-- Cluster summary cards -->
    <?php if ($isAdmin): ?>
    <div>
        <h2 style="color:white;margin-bottom:15px;">&#128209; Cluster Sync Status</h2>
        <div class="projects-grid">
            <?php foreach ($clusters_data as $cl):
                $hasData = $cl['learners'] > 0;
                $partial = !$hasData && $cl['projects'] > 0;
                $borderColor = $hasData ? '#22c55e' : '#ef4444';
                $statusIcon = $hasData ? '&#128994;' : '&#128308;';
                $statusText = $hasData ? 'Synced' : 'No data';
            ?>
            <div class="project-card" style="border-left-color:<?= $borderColor ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
                    <h3 style="margin:0"><?= htmlspecialchars($cl['name']) ?></h3>
                    <span style="font-size:.75rem;font-weight:700;padding:3px 10px;border-radius:20px;background:<?= $hasData ? '#dcfce7' : '#fee2e2' ?>;color:<?= $hasData ? '#166534' : '#991b1b' ?>"><?= $statusIcon ?> <?= $statusText ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">&#127982; Projects</span>
                    <span class="metric-value"><?= $cl['projects'] ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">&#128105;&#8205;&#127979; Learners</span>
                    <span class="metric-value"><?= $cl['learners'] ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">&#127891; Certificates</span>
                    <span class="metric-value"><?= $cl['certs'] ?></span>
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
<script>
    const clustersData = <?= $clustersJson ?>;
    const schoolsData  = <?= $schoolsJson ?>;

    const map = L.map('map').setView([-0.5, 37.0], 6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // ── Cluster markers (colored by sync status) ─────────────────────────
    clustersData.forEach(cl => {
        if (!cl.lat || !cl.lng) return;

        const hasData  = cl.learners > 0;
        const partial  = !hasData && cl.projects > 3;  // many projects but zero learners
        const fillColor = hasData ? '#16a34a' : (partial ? '#d97706' : '#dc2626');
        const ringColor = hasData ? '#15803d' : (partial ? '#b45309' : '#b91c1c');
        const statusLabel = hasData ? '🟢 Synced' : '🔴 No data yet';
        const radius = Math.max(14, Math.min(28, 10 + cl.projects));

        const popup = `
            <div style="font-size:.88rem;width:220px">
                <div style="font-weight:800;font-size:1rem;color:#111;margin-bottom:6px">${cl.name}</div>
                <div style="margin-bottom:8px;font-size:.8rem">${statusLabel}</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 8px;font-size:.82rem">
                    <span style="color:#555">Projects</span><strong>${cl.projects}</strong>
                    <span style="color:#555">Learners</span><strong>${cl.learners}</strong>
                    <span style="color:#555">Certs</span><strong>${cl.certs}</strong>
                    <span style="color:#555">Quiz Avg</span><strong>${cl.avg_score > 0 ? cl.avg_score + '%' : 'N/A'}</strong>
                </div>
                ${!hasData ? '<div style="margin-top:8px;padding:5px 8px;background:#fee2e2;border-radius:6px;font-size:.77rem;color:#991b1b;font-weight:600">⚠️ Needs device deployment or data sync</div>' : ''}
            </div>`;

        L.circleMarker([cl.lat, cl.lng], {
            radius: radius,
            fillColor: fillColor,
            color: ringColor,
            weight: 3,
            opacity: 0.9,
            fillOpacity: 0.8
        }).bindPopup(popup).addTo(map);

        // Cluster name label
        L.marker([cl.lat, cl.lng], {
            icon: L.divIcon({
                className: '',
                html: `<div style="font-size:.65rem;font-weight:700;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.7);text-align:center;pointer-events:none;margin-top:-8px;white-space:nowrap">${cl.name.split('-')[0]}</div>`,
                iconAnchor: [0, 0]
            }),
            interactive: false,
            zIndexOffset: 1000
        }).addTo(map);
    });

    // ── Individual school markers (colour: blue=has learners, grey=no learners) ──
    schoolsData.forEach(sc => {
        if (!sc.lat || !sc.lng) return;
        const hasLearners = (sc.learners || 0) > 0;
        const fillColor = hasLearners ? '#6366f1' : '#9ca3af';
        const strokeColor = hasLearners ? '#4338ca' : '#6b7280';
        const noLearnerNote = hasLearners ? '' : '<div style="margin-top:5px;padding:4px 7px;background:#f3f4f6;border-radius:5px;font-size:.75rem;color:#6b7280;font-weight:600">⏳ No learners yet</div>';
        const popup = `<div style="font-size:.85rem;width:190px">
            <strong>${sc.name}</strong><br>
            📌 ${sc.county || ''}${sc.cluster_name ? ' · ' + sc.cluster_name : ''}<br>
            👩‍🎓 ${sc.learners || 0} learners · 🎓 ${sc.certs || 0} certs
            ${noLearnerNote}
        </div>`;
        L.circleMarker([sc.lat, sc.lng], {
            radius: hasLearners ? 6 : 5,
            fillColor: fillColor,
            color: strokeColor,
            weight: 1.5,
            opacity: 1,
            fillOpacity: hasLearners ? 0.85 : 0.55
        }).bindPopup(popup).addTo(map);
    });
</script>

</body>
</html>
