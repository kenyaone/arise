<?php
session_start();

// ─── CONFIG ──────────────────────────────────────────────────────────────────
define('SUPER_PASSWORD', 'arise2026');   // change via admin
// ─────────────────────────────────────────────────────────────────────────────

$dbPath = __DIR__ . '/data/arise.db';
if (!file_exists($dbPath)) {
    $m = glob('/home/*/public_html/data/arise.db');
    if (!empty($m)) $dbPath = $m[0];
}
if (!file_exists($dbPath)) {
    $m = glob('/home/*/public_html/arise/data/arise.db');
    if (!empty($m)) $dbPath = $m[0];
}

$db = null;
if (file_exists($dbPath)) {
    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
        $db->busyTimeout(5000);
        // ensure clusters table exists
        $db->exec("CREATE TABLE IF NOT EXISTS clusters (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        // ensure schools has cluster_id
        try { $db->exec("ALTER TABLE schools ADD COLUMN cluster_id INTEGER REFERENCES clusters(id)"); }
        catch (Exception $e) {}
    } catch (Exception $e) { $db = null; }
}

// ─── AUTH ─────────────────────────────────────────────────────────────────────
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$loginError = '';
if (isset($_POST['password']) && !isset($_SESSION['arise_role'])) {
    $pw = trim($_POST['password']);
    if ($pw === SUPER_PASSWORD) {
        $_SESSION['arise_role']         = 'super';
        $_SESSION['arise_cluster_id']   = null;
        $_SESSION['arise_cluster_name'] = 'All Clusters';
    } elseif ($db) {
        $h   = hash('sha256', $pw);
        $row = $db->querySingle("SELECT id, name FROM clusters WHERE password_hash = '$h'", true);
        if ($row) {
            $_SESSION['arise_role']         = 'manager';
            $_SESSION['arise_cluster_id']   = (int)$row['id'];
            $_SESSION['arise_cluster_name'] = $row['name'];
            unset($_SESSION['arise_school_id'], $_SESSION['arise_school_name']);
        } else {
            // Check project/school passwords
            $srow = $db->querySingle("SELECT id, name FROM schools WHERE password_hash = '$h' AND is_active=1", true);
            if ($srow) {
                $_SESSION['arise_role']        = 'school';
                $_SESSION['arise_school_id']   = (int)$srow['id'];
                $_SESSION['arise_school_name'] = $srow['name'];
                unset($_SESSION['arise_cluster_id'], $_SESSION['arise_cluster_name']);
            } else {
                $loginError = 'Incorrect password. Please try again.';
            }
        }
    } else {
        $loginError = 'Database not available.';
    }
}

// Show login if not authenticated
if (!isset($_SESSION['arise_role'])) {
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ARISE — Login</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;background:linear-gradient(135deg,#0D5E3D 0%,#1E8055 50%,#FF9700 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;}
.card{background:white;border-radius:16px;padding:40px 36px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.logo{text-align:center;margin-bottom:28px;}
.logo h1{font-size:2rem;color:#0a5e2a;font-weight:900;letter-spacing:2px;}
.logo p{color:#666;font-size:.9rem;margin-top:4px;}
.badge{display:inline-block;background:#0a5e2a;color:white;padding:3px 12px;border-radius:20px;font-size:.75rem;font-weight:700;margin-top:6px;}
label{display:block;font-weight:600;color:#333;margin-bottom:6px;font-size:.9rem;}
input[type=password]{width:100%;padding:12px 16px;border:2px solid #e5e7eb;border-radius:8px;font-size:1rem;outline:none;transition:.2s;}
input[type=password]:focus{border-color:#0a5e2a;}
.btn{width:100%;padding:13px;background:#0a5e2a;color:white;border:none;border-radius:8px;font-size:1rem;font-weight:700;cursor:pointer;margin-top:16px;transition:.2s;}
.btn:hover{background:#0d7a38;}
.error{background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:6px;font-size:.88rem;margin-bottom:16px;}
.hint{text-align:center;color:#999;font-size:.8rem;margin-top:18px;}
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <h1>ARISE</h1>
        <p>Projects Performance Map</p>
        <span class="badge">RESTRICTED ACCESS</span>
    </div>
    <?php if ($loginError): ?>
    <div class="error">⚠ <?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="POST">
        <label for="pw">Access Password</label>
        <input type="password" id="pw" name="password" placeholder="Enter your password" autofocus required>
        <button type="submit" class="btn">Access Dashboard →</button>
    </form>
    <p class="hint">Project managers — use your project password<br>Cluster managers — use your cluster password<br>Super admins — use the super password</p>
</div>
</body>
</html><?php
    exit;
}

// ─── AUTHENTICATED: LOAD DATA ─────────────────────────────────────────────────
$role        = $_SESSION['arise_role'];
$clusterId   = $_SESSION['arise_cluster_id']  ?? null;
$clusterName = $_SESSION['arise_cluster_name']?? '';
$schoolId    = $_SESSION['arise_school_id']   ?? null;
$schoolName  = $_SESSION['arise_school_name'] ?? '';

$dbError        = null;
$projects       = [];    // all synced projects (has_learners flag)
$projectsNoSync = [];    // schools not yet in device_sync_stats
$clusters       = [];    // all clusters (super only)
$pollData       = [];    // module feedback summary
$total_projects = 0;
$total_learners = 0;
$total_certs    = 0;
$global_avg_score = 0;
$projectsJson   = '[]';
$noSyncJson     = '[]';
$lastSyncTime   = null;
$hasSyncedData  = false;

$countyCoords = [
    'Nairobi'             => ['-1.2864', '36.8172'],
    'Kiambu'              => ['-1.0536', '36.6710'],
    'Uasin Gishu'         => ['0.5204',  '35.2698'],
    'UASIN GISHU'         => ['0.5204',  '35.2698'],
    'Nandi'               => ['0.2028',  '35.1045'],
    'Kisumu'              => ['-0.0917', '34.7680'],
    'Siaya'               => ['0.0625',  '34.2422'],
    'Siaya-Kisumu'        => ['-0.0500', '34.5000'],
    'Kakamega'            => ['0.2827',  '34.7519'],
    'Vihiga'              => ['0.0076',  '34.7234'],
    'Vihiga-Nandi-Kisumu' => ['0.1000',  '34.8500'],
    'Busia'               => ['0.4610',  '34.1110'],
    'Homa Bay'            => ['-0.5180', '34.4570'],
    'Migori'              => ['-1.0634', '34.4731'],
    'Narok'               => ['-1.0834', '35.8730'],
    'Migori-Narok'        => ['-1.0634', '34.4731'],
    'Narok-Kajiado'       => ['-1.0834', '35.8730'],
    'Kajiado'             => ['-1.8520', '36.7760'],
    'Machakos'            => ['-1.5177', '37.2634'],
    'Kitui'               => ['-1.3671', '38.0106'],
    'Kitui-Machakos'      => ['-1.3671', '38.0106'],
    'Makueni'             => ['-1.8018', '37.6209'],
    'Meru'                => ['0.0476',  '37.6493'],
    'Embu'                => ['-0.5330', '37.4580'],
    'Tharaka Nithi'       => ['-0.2960', '37.9570'],
    'Tharaka Nithi-Embu'  => ['-0.5330', '37.4580'],
    'Nakuru'              => ['-0.3031', '36.0800'],
    'Laikipia'            => ['0.3600',  '36.7810'],
    'Nyeri'               => ['-0.4167', '36.9481'],
    'Murang\'a'           => ['-0.7833', '37.0370'],
    'Kirinyaga'           => ['-0.6580', '37.3310'],
    'Nyandarua'           => ['-0.1110', '36.3610'],
    'Kericho'             => ['-0.3690', '35.2840'],
    'Bomet'               => ['-0.7820', '35.3420'],
    'Kisii'               => ['-0.6773', '34.7796'],
    'Nyamira'             => ['-0.5670', '34.9370'],
    'Trans Nzoia'         => ['1.0570',  '35.0000'],
    'Elgeyo Marakwet'     => ['0.7980',  '35.5060'],
    'Baringo'             => ['0.4640',  '35.7510'],
    'West Pokot'          => ['1.6230',  '35.0940'],
    'Turkana'             => ['3.1190',  '35.5960'],
    'Samburu'             => ['1.2160',  '36.6950'],
    'Isiolo'              => ['0.3540',  '37.5820'],
    'Mombasa'             => ['-4.0435', '39.6682'],
    'Kilifi'              => ['-3.6300', '39.8500'],
    'Kwale'               => ['-4.1750', '39.4520'],
    'Taita Taveta'        => ['-3.3160', '38.4800'],
    'Yala'                => ['0.1028',  '34.3437'],
];

if (!$db) {
    $dbError = "Database not found.";
} else {
    try {
        // Apply county coords
        foreach ($countyCoords as $county => $coords) {
            $db->exec("UPDATE schools SET lat={$coords[0]},lng={$coords[1]} WHERE county='$county' AND (lat IS NULL OR lat=0) AND is_active=1");
            $db->exec("UPDATE device_sync_stats SET lat={$coords[0]},lng={$coords[1]} WHERE county='$county' AND (lat IS NULL OR lat=0)");
        }

        // Filter clause — super=all, school=one project, manager=one cluster
        if ($role === 'super') {
            $clusterJoin  = '';
            $clusterWhere = '';
            $clusterWhere2= '';
        } elseif ($role === 'school' && $schoolId) {
            $clusterJoin  = "LEFT JOIN schools _s ON _s.name = d.school_name";
            $clusterWhere = "AND _s.id = " . intval($schoolId);
            $clusterWhere2= "AND sc.id = " . intval($schoolId);
        } else {
            $clusterJoin  = "LEFT JOIN schools _s ON _s.name = d.school_name";
            $clusterWhere = "AND _s.cluster_id = " . intval($clusterId);
            $clusterWhere2= "AND sc.cluster_id = " . intval($clusterId);
        }
        // $clusterWhere2 already set above

        // Load all clusters for super view
        if ($role === 'super') {
            $r = $db->query("SELECT id, name FROM clusters ORDER BY name");
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) $clusters[] = $row;
        }

        $hasSyncedData = (int)$db->querySingle("SELECT COUNT(*) FROM device_sync_stats") > 0;

        if ($hasSyncedData) {
            $sql = "
                SELECT d.school_name AS name,
                       COALESCE(s.county,'') AS county,
                       s.cluster_id,
                       COALESCE(cl.name,'') AS cluster_name,
                       d.lat, d.lng,
                       COALESCE(NULLIF(SUM(d.learner_count),0), COUNT(DISTINCT st.id)) AS learners,
                       COALESCE(NULLIF(SUM(d.quiz_count),0), COUNT(DISTINCT qa.id)) AS quiz_attempts,
                       CASE WHEN SUM(d.quiz_count)>0 AND SUM(d.avg_score*d.quiz_count)>0
                            THEN ROUND(SUM(d.avg_score*d.quiz_count)/SUM(d.quiz_count),1)
                            WHEN COUNT(CASE WHEN qa.percentage>0 THEN 1 END)>0
                            THEN ROUND(AVG(CASE WHEN qa.percentage>0 THEN CAST(qa.percentage AS REAL) END),1)
                            ELSE 0 END AS avg_score,
                       COALESCE(NULLIF(SUM(d.cert_count),0), COUNT(DISTINCT cert.id)) AS certs,
                       CASE WHEN COALESCE(NULLIF(SUM(d.learner_count),0), COUNT(DISTINCT st.id))>0
                            THEN ROUND(100.0*COALESCE(NULLIF(SUM(d.cert_count),0), COUNT(DISTINCT cert.id))
                                       /COALESCE(NULLIF(SUM(d.learner_count),0), COUNT(DISTINCT st.id)),1)
                            ELSE 0 END AS cert_rate,
                       ROUND(AVG(CASE WHEN d.avg_pre_score IS NOT NULL THEN d.avg_pre_score END),1) AS avg_pre,
                       ROUND(AVG(CASE WHEN d.avg_post_score IS NOT NULL THEN d.avg_post_score END),1) AS avg_post,
                       ROUND(AVG(CASE WHEN d.knowledge_gain IS NOT NULL THEN d.knowledge_gain END),1) AS knowledge_gain,
                       SUM(COALESCE(d.behavior_surveys,0)) AS behavior_surveys,
                       ROUND(AVG(CASE WHEN d.behavior_surveys>0 THEN d.pct_changed END),1) AS pct_changed,
                       ROUND(AVG(CASE WHEN d.behavior_surveys>0 THEN d.pct_shared END),1) AS pct_shared,
                       ROUND(AVG(CASE WHEN d.behavior_surveys>0 THEN d.pct_confident END),1) AS pct_confident,
                       SUM(COALESCE(d.lesson_completions,0)) AS lesson_completions,
                       SUM(COALESCE(d.active_last_30_days,0)) AS active_last_30_days,
                       MAX(d.last_synced_at) AS last_synced_at,
                       CASE WHEN COALESCE(NULLIF(SUM(d.learner_count),0), COUNT(DISTINCT st.id))>0 THEN 1 ELSE 0 END AS has_learners
                FROM device_sync_stats d
                INNER JOIN schools s      ON s.name = d.school_name AND s.is_active=1
                LEFT JOIN clusters cl     ON cl.id  = s.cluster_id
                LEFT JOIN students st     ON st.school_name = s.name AND st.deleted_at IS NULL
                LEFT JOIN certificates cert ON cert.student_id = st.id
                LEFT JOIN quiz_attempts qa  ON qa.student_id  = st.id
                " . ($role === 'manager' ? "WHERE s.cluster_id = $clusterId" : "") . "
                GROUP BY d.school_name
                ORDER BY COALESCE(NULLIF(SUM(d.learner_count),0), COUNT(DISTINCT st.id)) DESC";
            $result = $db->query($sql);
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) $projects[] = $row;

            $syncedNames  = array_map(fn($s) => $s['name'], $projects);
            $placeholders = implode(',', array_fill(0, max(count($syncedNames),1), '?'));
            $stmtNS = $db->prepare("SELECT sc.id, sc.name, sc.county, sc.lat, sc.lng, sc.cluster_id,
                                           COALESCE(cl.name,'') AS cluster_name
                                    FROM schools sc
                                    LEFT JOIN clusters cl ON cl.id = sc.cluster_id
                                    WHERE sc.is_active=1
                                    " . (!empty($syncedNames) ? "AND sc.name NOT IN ($placeholders)" : "") . "
                                    " . ($role === 'manager' ? "AND sc.cluster_id = $clusterId" : "") . "
                                    ORDER BY sc.name");
            foreach ($syncedNames as $i => $n) $stmtNS->bindValue($i+1, $n, SQLITE3_TEXT);
            $rNS = $stmtNS->execute();
            while ($row = $rNS->fetchArray(SQLITE3_ASSOC)) $projectsNoSync[] = $row;

        } else {
            try {
                foreach (["ALTER TABLE schools ADD COLUMN lat REAL DEFAULT NULL","ALTER TABLE schools ADD COLUMN lng REAL DEFAULT NULL"] as $s) { try{@$db->exec($s);}catch(Exception $e){} }
                foreach ($countyCoords as $county => $coords) $db->exec("UPDATE schools SET lat={$coords[0]},lng={$coords[1]} WHERE county='$county' AND (lat IS NULL OR lat=0) AND is_active=1");

                $clWhere = $role === 'manager' ? "AND sc.cluster_id = $clusterId" : "";
                $stmt = $db->prepare("SELECT sc.id, sc.name, sc.county, sc.lat, sc.lng,
                                             sc.cluster_id, COALESCE(cl.name,'') AS cluster_name,
                                             COUNT(DISTINCT s.id) AS learners,
                                             COUNT(DISTINCT c.id) AS certs,
                                             ROUND(AVG(CAST(qa.percentage AS REAL)),1) AS avg_score,
                                             COUNT(DISTINCT qa.id) AS quiz_attempts
                                      FROM schools sc
                                      LEFT JOIN clusters cl ON cl.id = sc.cluster_id
                                      LEFT JOIN students s  ON s.school_name = sc.name AND s.deleted_at IS NULL
                                      LEFT JOIN certificates c ON c.student_id = s.id
                                      LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
                                      WHERE sc.is_active=1 $clWhere
                                      GROUP BY sc.id ORDER BY learners DESC");
                $r = $stmt->execute();
                while ($row = $r->fetchArray(SQLITE3_ASSOC)) $projects[] = $row;
            } catch (\Throwable $e) { $dbError = "Unable to load project data: " . $e->getMessage(); }
        }

        $total_projects   = count($projects) + count($projectsNoSync);
        $total_learners   = array_sum(array_column($projects, 'learners'));
        $total_certs      = array_sum(array_column($projects, 'certs'));
        $total_attempts   = array_sum(array_column($projects, 'quiz_attempts'));
        if ($total_attempts > 0) {
            $global_avg_score = round(array_sum(array_map(fn($s) => ($s['avg_score'] ?? 0) * ($s['quiz_attempts'] ?? 0), $projects)) / $total_attempts, 1);
        }
        // Supplement totals from actual local tables (covers schools not in device_sync_stats)
        try {
            $direct_certs = (int)$db->querySingle("SELECT COUNT(DISTINCT c.id) FROM certificates c JOIN students s ON s.id=c.student_id WHERE s.deleted_at IS NULL");
            if ($direct_certs > $total_certs) $total_certs = $direct_certs;
            $direct_learners = (int)$db->querySingle("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL");
            if ($direct_learners > $total_learners) $total_learners = $direct_learners;
            if ($global_avg_score == 0) {
                $direct_avg = (float)$db->querySingle("SELECT ROUND(AVG(CAST(percentage AS REAL)),1) FROM quiz_attempts WHERE percentage > 0");
                if ($direct_avg > 0) $global_avg_score = $direct_avg;
            }
        } catch (\Throwable $e) {}
        if ($hasSyncedData) $lastSyncTime = $db->querySingle("SELECT MAX(last_synced_at) FROM device_sync_stats");

        // Load poll summary
        try {
            $pr = $db->query("SELECT * FROM module_feedback_sync ORDER BY module_id");
            if ($pr) while ($row = $pr->fetchArray(SQLITE3_ASSOC)) $pollData[] = $row;
        } catch (\Throwable $e) {}

        $projectsJson = json_encode($projects);
        $noSyncJson   = json_encode($projectsNoSync);

    } catch (\Throwable $e) { $dbError = "Database error: " . $e->getMessage(); }
}

// Rank synced projects (only those with learners get a real score)
$ranked = [];
$totalKnowledgeGain = 0; $kgCount = 0;
$totalBehavior = 0; $totalChanged = 0;
foreach ($projects as $s) {
    $learners = (int)($s['learners'] ?? 0);
    if ($learners > 0) {
        $quizAvg  = (float)($s['avg_score'] ?? 0);
        $certRate = (float)($s['cert_rate'] ?? 0);
        $kg       = $s['knowledge_gain'] !== null ? (float)$s['knowledge_gain'] : null;
        $s['score']    = ($quizAvg * 0.5) + ($certRate * 0.3) + (($kg ?? 0) * 0.2);
        $s['certRate'] = $certRate;
        if ($kg !== null) { $totalKnowledgeGain += $kg; $kgCount++; }
        $bs = (int)($s['behavior_surveys'] ?? 0);
        if ($bs > 0) { $totalBehavior += $bs; $totalChanged += round($bs * (float)($s['pct_changed'] ?? 0) / 100); }
    } else {
        $s['score']    = -1;
        $s['certRate'] = 0;
    }
    $ranked[] = $s;
}
usort($ranked, fn($a,$b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
$global_kg       = $kgCount > 0 ? round($totalKnowledgeGain / $kgCount, 1) : null;
$global_pct_chg  = $totalBehavior > 0 ? round(100 * $totalChanged / $totalBehavior, 1) : null;

// Group by cluster (super only)
$byCluster = [];
foreach ($ranked as $p) {
    $cn = $p['cluster_name'] ?: 'Unassigned';
    $byCluster[$cn][] = $p;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ARISE Projects Map</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;background:linear-gradient(135deg,#0D5E3D 0%,#1E8055 35%,#FF9700 100%);background-attachment:fixed;min-height:100vh;color:#1a1a1a;}
.container{max-width:1200px;margin:0 auto;padding:20px;}
.header{background:rgba(255,255,255,.97);border-radius:12px;padding:22px 25px;margin-bottom:20px;box-shadow:0 4px 12px rgba(0,0,0,.1);}
.header-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;}
.header-top h1{font-size:1.8rem;color:#0a5e2a;display:flex;align-items:center;gap:10px;}
.header-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.role-badge{background:#0a5e2a;color:white;padding:5px 14px;border-radius:20px;font-size:.8rem;font-weight:700;}
.role-badge.super{background:#7c3aed;}
.print-btn{background:#0ea271;color:white;border:none;padding:10px 20px;border-radius:8px;font-weight:700;cursor:pointer;}
.logout-btn{background:transparent;color:#dc2626;border:2px solid #dc2626;padding:9px 18px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.85rem;}
.logout-btn:hover{background:#dc2626;color:white;}
.alert{background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:12px;border-radius:8px;margin-bottom:16px;}
.alert.error{background:#f8d7da;border-color:#f5c6cb;color:#721c24;}
.stats-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;}
.stat-item{background:linear-gradient(135deg,#0a5e2a,#0ea271);color:white;padding:15px;border-radius:8px;text-align:center;}
.stat-item .number{font-size:1.7rem;font-weight:900;}
.stat-item .label{font-size:.85rem;opacity:.9;margin-top:4px;}
.map-section{background:white;border-radius:12px;overflow:hidden;margin-bottom:20px;box-shadow:0 4px 12px rgba(0,0,0,.1);}
#map{height:500px;width:100%;}
.cluster-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;}
.cluster-tab{background:rgba(255,255,255,.85);border:none;padding:9px 18px;border-radius:20px;font-weight:600;cursor:pointer;font-size:.88rem;transition:.15s;}
.cluster-tab:hover,.cluster-tab.active{background:#0a5e2a;color:white;}
.cluster-section{margin-bottom:30px;}
.cluster-heading{color:white;font-size:1.1rem;font-weight:800;margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.cluster-heading .tag{background:rgba(255,255,255,.2);padding:2px 10px;border-radius:12px;font-size:.8rem;}
.projects-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:18px;}
.project-card{background:white;border-radius:12px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.1);border-left:4px solid #0ea271;transition:.2s;}
.project-card.no-learners{border-left-color:#f59e0b;opacity:.85;}
.project-card.no-device{border-left-color:#cbd5e1;opacity:.75;}
.project-card:hover{transform:translateY(-3px);box-shadow:0 4px 16px rgba(0,0,0,.15);}
.badge-no-learners{display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #f59e0b;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:700;margin-bottom:8px;}
.badge-no-device{display:inline-block;background:#f3f4f6;color:#6b7280;border:1px solid #d1d5db;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:700;margin-bottom:8px;}
.poll-section{background:rgba(255,255,255,.97);border-radius:12px;padding:22px 25px;margin-top:20px;box-shadow:0 4px 12px rgba(0,0,0,.1);}
.poll-section h2{color:#0a5e2a;margin-bottom:16px;font-size:1.1rem;}
.poll-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;}
.poll-card{background:#f0fdf4;border-radius:8px;padding:14px;border-left:4px solid #16a34a;}
.poll-card h4{color:#0a5e2a;font-size:.9rem;margin-bottom:8px;}
.stars{color:#f59e0b;font-size:1rem;letter-spacing:1px;}
.project-card h3{color:#0a5e2a;font-size:1rem;margin-bottom:6px;}
.project-card .location{color:#0ea271;font-weight:600;margin-bottom:10px;font-size:.88rem;}
.metric{display:flex;justify-content:space-between;padding:5px 0;border-top:1px solid #f0f0f0;}
.metric-label{color:#666;font-size:.85rem;}
.metric-value{font-weight:700;color:#0a5e2a;font-size:.85rem;}
.rank-badge{position:absolute;top:10px;right:10px;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1rem;color:white;}
.map-legend{background:white;padding:14px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);font-size:.88rem;margin-bottom:16px;display:flex;gap:20px;flex-wrap:wrap;}
.legend-item{display:flex;align-items:center;gap:8px;}
.legend-dot{width:14px;height:14px;border-radius:50%;flex-shrink:0;}
.footer{background:rgba(255,255,255,.95);border-radius:12px;padding:18px;text-align:center;color:#666;margin-top:20px;}
.no-data{background:white;border-radius:12px;padding:40px;text-align:center;margin-top:20px;}
@media(max-width:768px){.header-top h1{font-size:1.4rem;}#map{height:320px;}.projects-grid{grid-template-columns:1fr;}}
@media print{body{background:white;}.print-btn,.logout-btn{display:none;}.cluster-tabs{display:none;}}
</style>
</head>
<body>
<div class="container">

    <div class="header">
        <div class="header-top">
            <h1>🗺 ARISE Projects Map</h1>
            <div class="header-actions">
                <?php if ($role === 'super'): ?>
                <span class="role-badge super">⭐ Super Admin</span>
                <?php else: ?>
                <span class="role-badge">📁 <?= htmlspecialchars($clusterName) ?></span>
                <?php endif; ?>
                <button class="print-btn" onclick="exportExcel()" style="background:#1d6f42;">📊 Export Excel</button>
                <button class="print-btn" onclick="window.print()">🖨 Print / PDF</button>
                <form method="POST" style="display:inline;">
                    <button type="submit" name="logout" class="logout-btn">Logout</button>
                </form>
            </div>
        </div>

        <?php if ($dbError): ?>
        <div class="alert error"><?= htmlspecialchars($dbError) ?></div>
        <?php endif; ?>

        <div class="stats-bar">
            <div class="stat-item">
                <div class="number"><?= $total_projects ?: '—' ?></div>
                <div class="label">Active Projects</div>
            </div>
            <div class="stat-item">
                <div class="number"><?= $total_learners ? number_format($total_learners) : '—' ?></div>
                <div class="label">Total Learners</div>
            </div>
            <div class="stat-item">
                <div class="number"><?= $total_certs ? number_format($total_certs) : '—' ?></div>
                <div class="label">Certificates Earned</div>
            </div>
            <div class="stat-item">
                <div class="number"><?= $global_avg_score ?: '—' ?>%</div>
                <div class="label">Avg Quiz Score</div>
            </div>
            <div class="stat-item" style="background:linear-gradient(135deg,#0369a1,#0ea5e9);">
                <div class="number"><?= $global_kg !== null ? '+'.number_format($global_kg,1).'%' : '—' ?></div>
                <div class="label">Avg Knowledge Gain</div>
            </div>
            <div class="stat-item" style="background:linear-gradient(135deg,#7c3aed,#a855f7);">
                <div class="number"><?= $global_pct_chg !== null ? number_format($global_pct_chg,1).'%' : '—' ?></div>
                <div class="label">Reported Behavior Change</div>
            </div>
        </div>
    </div>

    <div class="map-section"><div id="map"></div></div>

    <div class="map-legend">
        <div class="legend-item">
            <div class="legend-dot" style="background:#dc2626;"></div>
            <span>Active — has learners</span>
        </div>
        <div class="legend-item">
            <div class="legend-dot" style="background:#f59e0b;"></div>
            <span>Synced — no learners yet</span>
        </div>
        <div class="legend-item">
            <div class="legend-dot" style="background:#cbd5e1;"></div>
            <span>No device yet</span>
        </div>
    </div>

    <?php if ($role === 'super' && count($byCluster) > 1): ?>
    <div class="cluster-tabs" id="clusterTabs">
        <button class="cluster-tab active" onclick="filterCluster('all', this)">All Clusters</button>
        <?php foreach ($byCluster as $cn => $ps): ?>
        <button class="cluster-tab" onclick="filterCluster('<?= htmlspecialchars($cn, ENT_QUOTES) ?>', this)">
            <?= htmlspecialchars($cn) ?> <small>(<?= count($ps) ?>)</small>
        </button>
        <?php endforeach; ?>
        <?php if (!empty($projectsNoSync)): ?>
        <button class="cluster-tab" onclick="filterCluster('__nodevice', this)">
            No Device <small>(<?= count($projectsNoSync) ?>)</small>
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (count($ranked) > 0): ?>

    <?php if ($role === 'super'): ?>
    <!-- Super: grouped by cluster -->
    <?php foreach ($byCluster as $cn => $ps): ?>
    <div class="cluster-section" data-cluster="<?= htmlspecialchars($cn) ?>">
        <div class="cluster-heading">
            📁 <?= htmlspecialchars($cn) ?>
            <span class="tag"><?= count($ps) ?> project<?= count($ps) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="projects-grid">
        <?php
        $rankCounter = 0;
        foreach ($ps as $idx => $s):
            $hasLearners = !empty($s['has_learners']) && (int)$s['has_learners'] > 0;
            $cardClass   = $hasLearners ? '' : 'no-learners';
            if ($hasLearners) $rankCounter++;
            $rank = $hasLearners ? $rankCounter : 0;
            $bg   = match(true) { $rank==1 => '#f59e0b', $rank==2 => '#9ca3af', $rank==3 => '#d97706', default => '#9ca3af' };
        ?>
        <div class="project-card <?= $cardClass ?>" style="position:relative;">
            <?php if ($hasLearners): ?>
            <div class="rank-badge" style="background:<?= $bg ?>;"><?= $rank ?></div>
            <?php else: ?>
            <span class="badge-no-learners">⏳ No learners yet</span>
            <?php endif; ?>
            <h3><?= htmlspecialchars($s['name'] ?? 'Unknown') ?></h3>
            <p class="location">📌 <?= htmlspecialchars($s['county'] ?? 'Unknown') ?></p>
            <?php if ($hasLearners): ?>
            <div style="background:#f0fdf4;padding:7px;border-radius:6px;margin-bottom:8px;text-align:center;">
                <strong style="color:#15803d;font-size:.9rem;">Score: <?= number_format($s['score']??0,1) ?>/100</strong>
            </div>
            <div class="metric"><span class="metric-label">👩‍🎓 Learners</span><span class="metric-value"><?= (int)($s['learners']??0) ?></span></div>
            <div class="metric"><span class="metric-label">📝 Quiz Avg</span><span class="metric-value"><?= ($s['avg_score']??0)>0 ? $s['avg_score'].'%' : 'N/A' ?></span></div>
            <div class="metric"><span class="metric-label">🎓 Certificates</span><span class="metric-value"><?= (int)($s['certs']??0) ?></span></div>
            <div class="metric"><span class="metric-label">📊 Cert Rate</span><span class="metric-value"><?= ($s['certRate']??0)>0 ? number_format($s['certRate'],1).'%' : '0%' ?></span></div>
            <div class="metric"><span class="metric-label">📋 Pre-Test Avg</span><span class="metric-value" style="color:#64748b;"><?= isset($s['avg_pre']) && $s['avg_pre'] !== null ? number_format((float)$s['avg_pre'],1).'%' : '—' ?></span></div>
            <div class="metric"><span class="metric-label">📋 Post-Test Avg</span><span class="metric-value" style="color:#0369a1;"><?= isset($s['avg_post']) && $s['avg_post'] !== null ? number_format((float)$s['avg_post'],1).'%' : '—' ?></span></div>
            <div class="metric"><span class="metric-label">📈 Knowledge Gain</span><span class="metric-value" style="color:#0369a1;"><?= $s['knowledge_gain'] !== null ? '+'.number_format((float)$s['knowledge_gain'],1).'%' : '—' ?></span></div>
            <?php $bs = (int)($s['behavior_surveys']??0); ?>
            <div style="background:#faf5ff;border-radius:6px;padding:7px 8px;margin-top:6px;">
                <div style="font-size:.72rem;font-weight:700;color:#6d28d9;margin-bottom:4px;">🧠 Behavioral Survey<?= $bs > 0 ? ' ('.$bs.' responses)' : ' — awaiting responses' ?></div>
                <div class="metric" style="border:none;padding:2px 0;"><span class="metric-label">🔄 Changed Behavior</span><span class="metric-value" style="color:#7c3aed;"><?= $bs > 0 ? number_format((float)($s['pct_changed']??0),1).'%' : '—' ?></span></div>
                <div class="metric" style="border:none;padding:2px 0;"><span class="metric-label">🤝 Shared Knowledge</span><span class="metric-value" style="color:#0ea271;"><?= $bs > 0 ? number_format((float)($s['pct_shared']??0),1).'%' : '—' ?></span></div>
                <div class="metric" style="border:none;padding:2px 0;"><span class="metric-label">💪 Feel Confident</span><span class="metric-value" style="color:#d97706;"><?= $bs > 0 ? number_format((float)($s['pct_confident']??0),1).'%' : '—' ?></span></div>
            </div>
            <?php else: ?>
            <p style="color:#92400e;font-size:.83rem;margin-top:6px;">Device is registered — awaiting first learner registration.</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($projectsNoSync)): ?>
    <div class="cluster-section" data-cluster="__nodevice">
        <div class="cluster-heading">⚫ Projects Without Devices <span class="tag"><?= count($projectsNoSync) ?></span></div>
        <div class="projects-grid">
        <?php foreach ($projectsNoSync as $s): ?>
        <div class="project-card no-device" style="position:relative;">
            <span class="badge-no-device">No device yet</span>
            <h3><?= htmlspecialchars($s['name'] ?? 'Unknown') ?></h3>
            <p class="location">📌 <?= htmlspecialchars($s['county'] ?? 'Unknown') ?><?= $s['cluster_name'] ? ' — '.htmlspecialchars($s['cluster_name']) : '' ?></p>
            <p style="color:#6b7280;font-size:.83rem;margin-top:6px;">No ARISE device connected yet.</p>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- Manager: flat list for their cluster -->
    <div style="margin-bottom:12px;">
        <h2 style="color:white;font-size:1.1rem;">📊 <?= htmlspecialchars($clusterName) ?> — Project Rankings</h2>
    </div>
    <div class="projects-grid">
    <?php
    $rankCounter = 0;
    foreach ($ranked as $idx => $s):
        $hasLearners = !empty($s['has_learners']) && (int)$s['has_learners'] > 0;
        if ($hasLearners) $rankCounter++;
        $rank = $hasLearners ? $rankCounter : 0;
        $bg   = match(true) { $rank==1 => '#f59e0b', $rank==2 => '#9ca3af', $rank==3 => '#d97706', default => '#9ca3af' };
    ?>
    <div class="project-card <?= $hasLearners ? '' : 'no-learners' ?>" style="position:relative;">
        <?php if ($hasLearners): ?>
        <div class="rank-badge" style="background:<?= $bg ?>;"><?= $rank ?></div>
        <?php else: ?>
        <span class="badge-no-learners">⏳ No learners yet</span>
        <?php endif; ?>
        <h3><?= htmlspecialchars($s['name'] ?? 'Unknown') ?></h3>
        <p class="location">📌 <?= htmlspecialchars($s['county'] ?? 'Unknown') ?></p>
        <?php if ($hasLearners): ?>
        <div style="background:#f0fdf4;padding:7px;border-radius:6px;margin-bottom:8px;text-align:center;">
            <strong style="color:#15803d;font-size:.9rem;">Score: <?= number_format($s['score']??0,1) ?>/100</strong>
        </div>
        <div class="metric"><span class="metric-label">👩‍🎓 Learners</span><span class="metric-value"><?= (int)($s['learners']??0) ?></span></div>
        <div class="metric"><span class="metric-label">📝 Quiz Avg</span><span class="metric-value"><?= ($s['avg_score']??0)>0 ? $s['avg_score'].'%' : 'N/A' ?></span></div>
        <div class="metric"><span class="metric-label">🎓 Certificates</span><span class="metric-value"><?= (int)($s['certs']??0) ?></span></div>
        <div class="metric"><span class="metric-label">📊 Cert Rate</span><span class="metric-value"><?= ($s['certRate']??0)>0 ? number_format($s['certRate'],1).'%' : '0%' ?></span></div>
        <div class="metric"><span class="metric-label">📋 Pre-Test Avg</span><span class="metric-value" style="color:#64748b;"><?= isset($s['avg_pre']) && $s['avg_pre'] !== null ? number_format((float)$s['avg_pre'],1).'%' : '—' ?></span></div>
        <div class="metric"><span class="metric-label">📋 Post-Test Avg</span><span class="metric-value" style="color:#0369a1;"><?= isset($s['avg_post']) && $s['avg_post'] !== null ? number_format((float)$s['avg_post'],1).'%' : '—' ?></span></div>
        <div class="metric"><span class="metric-label">📈 Knowledge Gain</span><span class="metric-value" style="color:#0369a1;"><?= $s['knowledge_gain'] !== null ? '+'.number_format((float)$s['knowledge_gain'],1).'%' : '—' ?></span></div>
        <?php $bs = (int)($s['behavior_surveys']??0); ?>
        <div style="background:#faf5ff;border-radius:6px;padding:7px 8px;margin-top:6px;">
            <div style="font-size:.72rem;font-weight:700;color:#6d28d9;margin-bottom:4px;">🧠 Behavioral Survey<?= $bs > 0 ? ' ('.$bs.' responses)' : ' — awaiting responses' ?></div>
            <div class="metric" style="border:none;padding:2px 0;"><span class="metric-label">🔄 Changed Behavior</span><span class="metric-value" style="color:#7c3aed;"><?= $bs > 0 ? number_format((float)($s['pct_changed']??0),1).'%' : '—' ?></span></div>
            <div class="metric" style="border:none;padding:2px 0;"><span class="metric-label">🤝 Shared Knowledge</span><span class="metric-value" style="color:#0ea271;"><?= $bs > 0 ? number_format((float)($s['pct_shared']??0),1).'%' : '—' ?></span></div>
            <div class="metric" style="border:none;padding:2px 0;"><span class="metric-label">💪 Feel Confident</span><span class="metric-value" style="color:#d97706;"><?= $bs > 0 ? number_format((float)($s['pct_confident']??0),1).'%' : '—' ?></span></div>
        </div>
        <?php else: ?>
        <p style="color:#92400e;font-size:.83rem;margin-top:6px;">Device registered — awaiting first learner.</p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php if (!empty($projectsNoSync)): ?>
    <div style="margin-top:24px;margin-bottom:12px;">
        <h2 style="color:white;font-size:1rem;">⚫ Projects Without Devices</h2>
    </div>
    <div class="projects-grid">
    <?php foreach ($projectsNoSync as $s): ?>
    <div class="project-card no-device">
        <span class="badge-no-device">No device yet</span>
        <h3><?= htmlspecialchars($s['name'] ?? 'Unknown') ?></h3>
        <p class="location">📌 <?= htmlspecialchars($s['county'] ?? 'Unknown') ?></p>
        <p style="color:#6b7280;font-size:.83rem;margin-top:6px;">No ARISE device connected yet.</p>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php else: ?>
    <div class="no-data">
        <p>📍 No project data yet</p>
        <small>Projects will appear once data is synced to this server.</small>
    </div>
    <?php endif; ?>

    <?php if (!empty($pollData)): ?>
    <div class="poll-section">
        <h2>📊 Learner Feedback — Module Ratings</h2>
        <div class="poll-grid">
        <?php foreach ($pollData as $pm):
            $avg   = round((float)$pm['avg_rating'], 1);
            $stars = str_repeat('★', (int)round($avg)) . str_repeat('☆', 5 - (int)round($avg));
            $resp  = (int)$pm['total_responses'];
            $rec   = $resp > 0 ? round(100 * (int)$pm['recommends'] / $resp) : 0;
        ?>
        <div class="poll-card">
            <h4><?= htmlspecialchars($pm['module_title']) ?></h4>
            <div class="stars"><?= $stars ?></div>
            <p style="font-size:.85rem;color:#15803d;font-weight:700;"><?= $avg ?>/5 avg rating</p>
            <p style="font-size:.78rem;color:#555;margin-top:4px;"><?= $resp ?> response<?= $resp!==1?'s':'' ?> &bull; <?= $rec ?>% recommend</p>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="footer">
        <p><strong>ARISE Platform</strong> — Adolescent Reproductive Health Information Support &amp; Empowerment</p>
        <?php if ($lastSyncTime): ?>
        <p style="font-size:.85rem;color:#999;margin-top:6px;">Last synced: <?= htmlspecialchars($lastSyncTime) ?></p>
        <?php endif; ?>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
const redPin    = L.icon({iconUrl:'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 48"><path fill="%23dc2626" d="M16 0C9.4 0 4 5.4 4 12c0 8 12 36 12 36s12-28 12-36c0-6.6-5.4-12-12-12z"/><circle cx="16" cy="12" r="4" fill="white"/></svg>',iconSize:[32,48],iconAnchor:[16,48],popupAnchor:[0,-48]});
const orangePin = L.icon({iconUrl:'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 48"><path fill="%23f59e0b" d="M16 0C9.4 0 4 5.4 4 12c0 8 12 36 12 36s12-28 12-36c0-6.6-5.4-12-12-12z"/><circle cx="16" cy="12" r="4" fill="white"/></svg>',iconSize:[32,48],iconAnchor:[16,48],popupAnchor:[0,-48]});
const grayPin   = L.icon({iconUrl:'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 48"><path fill="%23cbd5e1" d="M16 0C9.4 0 4 5.4 4 12c0 8 12 36 12 36s12-28 12-36c0-6.6-5.4-12-12-12z"/><circle cx="16" cy="12" r="4" fill="%23999"/></svg>',iconSize:[32,48],iconAnchor:[16,48],popupAnchor:[0,-48]});

const map = L.map('map').setView([-0.023559, 37.906193], 6);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(map);

<?= "const projectsData = $projectsJson;\n" ?>
<?= "const noSyncData   = $noSyncJson;\n" ?>

// Spread overlapping pins using golden-angle spiral so each project is visible
const coordBuckets = {};
function jitter(lat, lng) {
    const key = parseFloat(lat).toFixed(2) + ',' + parseFloat(lng).toFixed(2);
    const n = coordBuckets[key] = (coordBuckets[key] || 0);
    coordBuckets[key]++;
    if (n === 0) return [parseFloat(lat), parseFloat(lng)];
    const angle = n * 2.399963; // golden angle radians
    const r = 0.009 * Math.ceil(n / 3 + 1);
    return [parseFloat(lat) + r * Math.cos(angle), parseFloat(lng) + r * Math.sin(angle)];
}

projectsData.forEach(p => {
    if (!p.lat || !p.lng) return;
    // Only place a pin when the project has learners
    if (p.has_learners != 1) return;
    const [jLat, jLng] = jitter(p.lat, p.lng);
    const certRate = p.cert_rate > 0 ? p.cert_rate + '%' : (p.learners > 0 && p.certs > 0 ? Math.round(p.certs/p.learners*100)+'%' : '0%');
    const kg       = p.knowledge_gain != null ? `+${p.knowledge_gain}%` : '—';
    const pre      = p.avg_pre  != null ? `${p.avg_pre}%`  : '—';
    const post     = p.avg_post != null ? `${p.avg_post}%` : '—';
    const bs       = parseInt(p.behavior_surveys) || 0;
    const bLabel   = bs > 0 ? `🧠 Behavioral Survey (${bs} responses)` : '🧠 Behavioral Survey — awaiting responses';
    const bchg     = bs > 0 ? `${p.pct_changed}%`   : '—';
    const shared   = bs > 0 ? `${p.pct_shared}%`    : '—';
    const conf     = bs > 0 ? `${p.pct_confident}%` : '—';
    L.marker([jLat, jLng], {icon: redPin}).bindPopup(`
        <div style="width:230px;font-size:.85rem;">
        <h4 style="color:#dc2626;margin-bottom:4px;">🔴 ${p.name||p.school_name}</h4>
        <p style="margin-bottom:6px;color:#555;font-size:.8rem;">📌 ${p.county||'Unknown'}${p.cluster_name ? ' — '+p.cluster_name : ''}</p>
        <hr style="margin:5px 0;border:none;border-top:1px solid #e5e7eb;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:3px 8px;font-size:.82rem;">
          <span style="color:#555">👩‍🎓 Learners</span><strong>${p.learners||0}</strong>
          <span style="color:#555">📝 Quiz Avg</span><strong>${p.avg_score>0?p.avg_score+'%':'N/A'}</strong>
          <span style="color:#555">🎓 Certs</span><strong>${p.certs||0} (${certRate})</strong>
          <span style="color:#555">📋 Pre-Test</span><strong style="color:#64748b">${pre}</strong>
          <span style="color:#555">📋 Post-Test</span><strong style="color:#0369a1">${post}</strong>
          <span style="color:#555">📈 Knowledge Gain</span><strong style="color:#0369a1">${kg}</strong>
        </div>
        <div style="background:#faf5ff;border-radius:5px;padding:5px 7px;margin-top:6px;font-size:.8rem;">
          <div style="font-weight:700;color:#6d28d9;margin-bottom:3px;">${bLabel}</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:2px 8px;">
            <span style="color:#555">🔄 Changed</span><strong style="color:#7c3aed">${bchg}</strong>
            <span style="color:#555">🤝 Shared</span><strong style="color:#0ea271">${shared}</strong>
            <span style="color:#555">💪 Confident</span><strong style="color:#d97706">${conf}</strong>
          </div>
        </div>
        <p style="margin-top:6px;padding:4px 7px;background:#dcfce7;border-radius:4px;color:#166534;font-size:.78rem;">✓ Device syncing</p>
        </div>`).addTo(map);
});

// ── Excel export ──────────────────────────────────────────────────────────
function exportExcel() {
    const wb   = XLSX.utils.book_new();
    const date = new Date().toLocaleDateString('en-KE',{day:'2-digit',month:'short',year:'numeric'}).replace(/ /g,'-');

    // Sheet 1: Projects with data
    const h1 = ['Project','County','Cluster','Learners','Certificates','Cert Rate (%)','Quiz Avg (%)',
                 'Pre-Test Avg (%)','Post-Test Avg (%)','Knowledge Gain (%)','Behavior Surveys',
                 'Behavior Change (%)','Knowledge Shared (%)','Feel Confident (%)','Lesson Completions',
                 'Active Last 30d','Last Synced'];
    const r1 = projectsData.map(p => [
        p.name||'', p.county||'', p.cluster_name||'',
        p.learners||0, p.certs||0,
        p.cert_rate > 0 ? parseFloat(p.cert_rate) : '',
        p.avg_score > 0 ? parseFloat(p.avg_score) : '',
        p.avg_pre   != null ? parseFloat(p.avg_pre)   : '',
        p.avg_post  != null ? parseFloat(p.avg_post)  : '',
        p.knowledge_gain != null ? parseFloat(p.knowledge_gain) : '',
        p.behavior_surveys||0,
        p.pct_changed  > 0 ? parseFloat(p.pct_changed)  : '',
        p.pct_shared   > 0 ? parseFloat(p.pct_shared)   : '',
        p.pct_confident> 0 ? parseFloat(p.pct_confident): '',
        p.lesson_completions||0,
        p.active_last_30_days||0,
        p.last_synced_at||''
    ]);
    const ws1 = XLSX.utils.aoa_to_sheet([h1, ...r1]);
    ws1['!cols'] = [22,14,18,10,13,13,12,15,15,16,16,18,17,16,16,14,18].map(w=>({wch:w}));
    XLSX.utils.book_append_sheet(wb, ws1, 'Projects');

    // Sheet 2: Projects without devices (if any)
    if (noSyncData.length > 0) {
        const h2 = ['Project','County','Cluster','Note'];
        const r2 = noSyncData.map(p => [p.name||'', p.county||'', p.cluster_name||'', 'No device connected yet']);
        const ws2 = XLSX.utils.aoa_to_sheet([h2, ...r2]);
        ws2['!cols'] = [22,14,18,25].map(w=>({wch:w}));
        XLSX.utils.book_append_sheet(wb, ws2, 'No Device Yet');
    }

    XLSX.writeFile(wb, `ARISE-Projects-${date}.xlsx`);
}

// Cluster tab filter
function filterCluster(name, btn) {
    document.querySelectorAll('.cluster-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.cluster-section').forEach(sec => {
        sec.style.display = (name === 'all' || sec.dataset.cluster === name) ? '' : 'none';
    });
}
</script>
</body>
</html>
