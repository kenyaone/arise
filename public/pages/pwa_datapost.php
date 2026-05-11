<?php
/**
 * DataPost Sync — Unified Dashboard
 * Syncs local data, sends reports, manages configuration
 * Offline-first PWA + full data management
 */
if (!function_exists('db')) {
    require_once __DIR__ . '/../../includes/config.php';
}

// ── Schema migrations (safe, idempotent) ─────────────────────────────────────
foreach ([
    "ALTER TABLE datapost_config ADD COLUMN email_endpoint TEXT DEFAULT ''",
    "ALTER TABLE datapost_config ADD COLUMN webhook_url TEXT DEFAULT ''",
    "ALTER TABLE datapost_config ADD COLUMN smtp_host TEXT DEFAULT 'smtp.gmail.com'",
    "ALTER TABLE datapost_config ADD COLUMN smtp_port INTEGER DEFAULT 587",
    "ALTER TABLE datapost_config ADD COLUMN smtp_user TEXT DEFAULT ''",
    "ALTER TABLE datapost_config ADD COLUMN smtp_pass TEXT DEFAULT ''",
    "ALTER TABLE datapost_config ADD COLUMN smtp_from TEXT DEFAULT ''",
    "ALTER TABLE datapost_config ADD COLUMN cloud_sync_url TEXT DEFAULT 'https://ariseci.org/arise-sync.php'",
    "ALTER TABLE datapost_config ADD COLUMN cloud_last_synced_at TEXT DEFAULT NULL",
    "ALTER TABLE datapost_config ADD COLUMN cloud_last_sync_count INTEGER DEFAULT 0",
] as $sql) { try { db()->exec($sql); } catch(Exception $e) {} }

try { db()->exec("CREATE TABLE IF NOT EXISTS datapost_sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sync_timestamp TEXT,
    data_snapshot TEXT,
    posted_at TEXT
)"); } catch(Exception $e) {}

// ── Ensure config row exists ──────────────────────────────────────────────────
$cfg = db()->querySingle("SELECT * FROM datapost_config LIMIT 1", true);
if (!$cfg) {
    db()->exec("INSERT OR IGNORE INTO datapost_config (school_id, school_name)
                VALUES ('arise-default','ARISE Platform')");
    $cfg = db()->querySingle("SELECT * FROM datapost_config LIMIT 1", true);
}

// ── Helper: SMTP sender ───────────────────────────────────────────────────────
function smtp_send(string $to, string $subject, string $body, array $smtp): array {
    $host = $smtp['host'] ?? 'smtp.gmail.com';
    $port = (int)($smtp['port'] ?? 587);
    $user = $smtp['user'] ?? '';
    $pass = $smtp['pass'] ?? '';
    $from = $smtp['from'] ?: $user;
    $crlf = "\r\n";
    $date = date('r');
    $msgFile = tempnam(sys_get_temp_dir(), 'arise_mail_');
    file_put_contents($msgFile,
        "Date: $date{$crlf}To: $to{$crlf}From: ARISE <$from>{$crlf}Subject: $subject{$crlf}MIME-Version: 1.0{$crlf}Content-Type: text/plain; charset=UTF-8{$crlf}{$crlf}" . $body
    );
    $url = ($port === 465) ? "smtps://{$host}:{$port}" : "smtp://{$host}:{$port}";
    $cmd = sprintf('curl -s --url %s --ssl-reqd --mail-from %s --mail-rcpt %s --upload-file %s --user %s 2>&1',
        escapeshellarg($url), escapeshellarg($from), escapeshellarg($to), escapeshellarg($msgFile), escapeshellarg("$user:$pass"));
    $out = []; $code = 0; exec($cmd, $out, $code); @unlink($msgFile);
    return $code === 0 ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => implode(' | ', $out)];
}

function snap(): array {
    return [
        'learners'       => (int)db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1 AND deleted_at IS NULL"),
        'modules'        => (int)db()->querySingle("SELECT COUNT(*) FROM modules WHERE is_active=1"),
        'lessons'        => (int)db()->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1"),
        'quizzes'        => (int)db()->querySingle("SELECT COUNT(*) FROM quiz_attempts"),
        'pretests'       => (int)db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='pre'"),
        'posttests'      => (int)db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='post'"),
        'certs'          => (int)db()->querySingle("SELECT COUNT(*) FROM certificates"),
        'forum'          => (int)db()->querySingle("SELECT COUNT(*) FROM forum_posts WHERE is_hidden=0"),
        'questions'      => (int)db()->querySingle("SELECT COUNT(*) FROM anonymous_questions"),
        'poll_responses' => (int)db()->querySingle("SELECT COUNT(*) FROM module_feedback"),
    ];
}

function pollSummary(): array {
    $res = db()->query("
        SELECT m.id, m.slug, m.title,
            COUNT(mf.id) AS responses,
            ROUND(AVG(mf.rating),2) AS avg_rating,
            SUM(mf.would_recommend) AS recommends
        FROM modules m
        LEFT JOIN module_feedback mf ON mf.module_id = m.id
        WHERE m.is_active=1
        GROUP BY m.id
        HAVING responses > 0
        ORDER BY m.id
    ");
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    return $rows;
}

// County-level fallback coordinates (centroid or main town)
const COUNTY_COORDS = [
    'Nairobi'              => [-1.2864, 36.8172],
    'Kiambu'               => [-1.0536, 36.6710],
    'Uasin Gishu'          => [ 0.5204, 35.2698],
    'UASIN GISHU'          => [ 0.5204, 35.2698],
    'Nandi'                => [ 0.2028, 35.1045],
    'Kisumu'               => [-0.0917, 34.7680],
    'Siaya'                => [ 0.0625, 34.2422],
    'Siaya-Kisumu'         => [-0.0500, 34.5000],
    'Kakamega'             => [ 0.2827, 34.7519],
    'Vihiga'               => [ 0.0076, 34.7234],
    'Vihiga-Nandi-Kisumu'  => [ 0.1000, 34.8500],
    'Busia'                => [ 0.4610, 34.1110],
    'Homa Bay'             => [-0.5180, 34.4570],
    'Migori'               => [-1.0634, 34.4731],
    'Narok'                => [-1.0834, 35.8730],
    'Migori-Narok'         => [-1.0634, 34.4731],
    'Narok-Kajiado'        => [-1.0834, 35.8730],
    'Kajiado'              => [-1.8520, 36.7760],
    'Machakos'             => [-1.5177, 37.2634],
    'Kitui'                => [-1.3671, 38.0106],
    'Kitui-Machakos'       => [-1.3671, 38.0106],
    'Makueni'              => [-1.8018, 37.6209],
    'Meru'                 => [ 0.0476, 37.6493],
    'Embu'                 => [-0.5330, 37.4580],
    'Tharaka Nithi'        => [-0.2960, 37.9570],
    'Tharaka Nithi-Embu'   => [-0.5330, 37.4580],
    'Nakuru'               => [-0.3031, 36.0800],
    'Laikipia'             => [ 0.3600, 36.7810],
    'Nyeri'                => [-0.4167, 36.9481],
    'Murang\'a'            => [-0.7833, 37.0370],
    'Kirinyaga'            => [-0.6580, 37.3310],
    'Nyandarua'            => [-0.1110, 36.3610],
    'Kericho'              => [-0.3690, 35.2840],
    'Bomet'                => [-0.7820, 35.3420],
    'Kisii'                => [-0.6773, 34.7796],
    'Nyamira'              => [-0.5670, 34.9370],
    'Trans Nzoia'          => [ 1.0570, 35.0000],
    'Elgeyo Marakwet'      => [ 0.7980, 35.5060],
    'Baringo'              => [ 0.4640, 35.7510],
    'West Pokot'           => [ 1.6230, 35.0940],
    'Turkana'              => [ 3.1190, 35.5960],
    'Samburu'              => [ 1.2160, 36.6950],
    'Isiolo'               => [ 0.3540, 37.5820],
    'Marsabit'             => [ 2.3350, 37.9890],
    'Garissa'              => [-0.4530, 39.6460],
    'Wajir'                => [ 1.7500, 40.0590],
    'Mandera'              => [ 3.9370, 41.8550],
    'Tana River'           => [-1.4890, 40.2650],
    'Lamu'                 => [-2.2720, 40.9020],
    'Kilifi'               => [-3.6300, 39.8500],
    'Kwale'                => [-4.1750, 39.4520],
    'Mombasa'              => [-4.0435, 39.6682],
    'Taita Taveta'         => [-3.3160, 38.4800],
    'Yala'                 => [ 0.1028, 34.3437],
];

function buildSchoolRows(): array {
    // ALL active schools regardless of learner count
    $result = db()->query("
        SELECT sc.name AS school_name, sc.county, sc.lat, sc.lng,
               c.name AS cluster_name
        FROM schools sc
        LEFT JOIN clusters c ON c.id = sc.cluster_id
        WHERE sc.is_active = 1
        ORDER BY sc.name
    ");
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sne = SQLite3::escapeString($row['school_name']);
        $learners = (int)db()->querySingle("SELECT COUNT(*) FROM students WHERE school_name='$sne' AND is_active=1 AND deleted_at IS NULL");

        // Apply county fallback if no explicit coordinates
        $lat = $row['lat'] !== null && $row['lat'] != 0 ? (float)$row['lat'] : null;
        $lng = $row['lng'] !== null && $row['lng'] != 0 ? (float)$row['lng'] : null;
        if (($lat === null || $lng === null) && !empty($row['county'])) {
            $fallback = COUNTY_COORDS[$row['county']] ?? null;
            if ($fallback) { $lat = $fallback[0]; $lng = $fallback[1]; }
        }

        $rows[] = [
            'school_name'    => $row['school_name'],
            'county'         => $row['county'] ?? '',
            'cluster'        => $row['cluster_name'] ?? '',
            'lat'            => $lat,
            'lng'            => $lng,
            'has_learners'   => $learners > 0,
            'learner_count'  => $learners,
            'quiz_count'     => (int)db()->querySingle("SELECT COUNT(qa.id) FROM quiz_attempts qa JOIN students s ON s.id=qa.student_id WHERE s.school_name='$sne'"),
            'pretest_count'  => (int)db()->querySingle("SELECT COUNT(*) FROM pretest_attempts pa JOIN students s ON s.id=pa.student_id WHERE s.school_name='$sne' AND pa.test_type='pre'"),
            'posttest_count' => (int)db()->querySingle("SELECT COUNT(*) FROM pretest_attempts pa JOIN students s ON s.id=pa.student_id WHERE s.school_name='$sne' AND pa.test_type='post'"),
        ];
    }
    return $rows;
}

// ── Handle AJAX requests ──────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'sync') {
    header('Content-Type: application/json');
    $ts = date('Y-m-d H:i:s');
    $data = snap();
    db()->exec("INSERT INTO datapost_sync_log (sync_timestamp, data_snapshot) VALUES ('" . SQLite3::escapeString($ts) . "','" . SQLite3::escapeString(json_encode($data)) . "')");
    echo json_encode(['status'=>'synced','timestamp'=>$ts,'summary'=>$data]);
    exit;
}

if ($action === 'config_email') {
    header('Content-Type: application/json');
    $email     = trim($_POST['email']     ?? '');
    $smtpHost  = trim($_POST['smtp_host'] ?? 'smtp.gmail.com');
    $smtpPort  = (int)($_POST['smtp_port'] ?? 587);
    $smtpUser  = trim($_POST['smtp_user'] ?? '');
    $smtpPass  = trim($_POST['smtp_pass'] ?? '');
    $smtpFrom  = trim($_POST['smtp_from'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status'=>'error','message'=>'Invalid recipient email']);
        exit;
    }
    if ($smtpUser && !filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status'=>'error','message'=>'Invalid SMTP username (should be email)']);
        exit;
    }

    $id = (int)$cfg['id'];
    $stmt = db()->prepare("UPDATE datapost_config SET email_endpoint=:e, smtp_host=:h, smtp_port=:p, smtp_user=:u, smtp_pass=:pw, smtp_from=:f WHERE id=:id");
    $stmt->bindValue(':e',  $email);
    $stmt->bindValue(':h',  $smtpHost);
    $stmt->bindValue(':p',  $smtpPort);
    $stmt->bindValue(':u',  $smtpUser);
    $stmt->bindValue(':pw', $smtpPass);
    $stmt->bindValue(':f',  $smtpFrom ?: $smtpUser);
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    echo json_encode(['status'=>'success','message'=>'Settings saved','email'=>$email]);
    exit;
}

if ($action === 'post') {
    header('Content-Type: application/json');
    $emailTo  = trim($cfg['email_endpoint'] ?? '');
    $smtpUser = trim($cfg['smtp_user'] ?? '');
    $smtpPass = trim($cfg['smtp_pass'] ?? '');

    if (!$emailTo) { echo json_encode(['status'=>'error','message'=>'No recipient email — configure settings first']); exit; }
    if (!$smtpUser || !$smtpPass) { echo json_encode(['status'=>'error','message'=>'SMTP not configured']); exit; }

    $last = db()->querySingle("SELECT * FROM datapost_sync_log ORDER BY id DESC LIMIT 1", true);
    if (!$last) { echo json_encode(['status'=>'error','message'=>'Nothing synced yet']); exit; }

    $data    = json_decode($last['data_snapshot'], true);
    $subject = "ARISE — Performance Report — " . date('d/m/Y');
    $result = smtp_send($emailTo, $subject, json_encode($data, JSON_PRETTY_PRINT), [
        'host' => $cfg['smtp_host'] ?? 'smtp.gmail.com',
        'port' => (int)($cfg['smtp_port'] ?? 587),
        'user' => $smtpUser,
        'pass' => $smtpPass,
        'from' => $cfg['smtp_from'] ?? $smtpUser,
    ]);

    if (!$result['ok']) {
        echo json_encode(['status'=>'error','message'=>'Failed: ' . $result['error']]);
        exit;
    }

    db()->exec("UPDATE datapost_sync_log SET posted_at='" . date('Y-m-d H:i:s') . "' WHERE id=" . (int)$last['id']);
    echo json_encode(['status'=>'success','message'=>"Report sent to $emailTo"]);
    exit;
}

if ($action === 'test_email') {
    header('Content-Type: application/json');
    $emailTo  = trim($cfg['email_endpoint'] ?? '');
    $smtpUser = trim($cfg['smtp_user'] ?? '');
    $smtpPass = trim($cfg['smtp_pass'] ?? '');

    if (!$emailTo || !$smtpUser || !$smtpPass) {
        echo json_encode(['status'=>'error','message'=>'Complete settings first']);
        exit;
    }

    $result = smtp_send($emailTo, 'ARISE DataPost — Test', 'Test email from ARISE DataPost', [
        'host' => $cfg['smtp_host'] ?? 'smtp.gmail.com',
        'port' => (int)($cfg['smtp_port'] ?? 587),
        'user' => $smtpUser,
        'pass' => $smtpPass,
        'from' => $cfg['smtp_from'] ?? $smtpUser,
    ]);

    echo json_encode($result['ok'] ? ['status'=>'success','message'=>'Test email sent'] : ['status'=>'error','message'=>'Failed: ' . $result['error']]);
    exit;
}

if ($action === 'cloud_sync') {
    header('Content-Type: application/json');
    $schoolRows = buildSchoolRows();
    if (empty($schoolRows)) { echo json_encode(['status'=>'error','message'=>'No active schools found']); exit; }
    $payload = json_encode(['api_key'=>'ARISE_CLOUD_SYNC_2026_KEY','device_id'=>$cfg['school_id']??'arise-unknown','synced_at'=>date('Y-m-d H:i:s'),'schools'=>$schoolRows,'poll_summary'=>pollSummary()]);
    $syncUrl  = $cfg['cloud_sync_url'] ?? 'https://ariseci.org/arise-sync.php';

    $ch = curl_init($syncUrl);
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>20]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response) { echo json_encode(['status'=>'error','message'=>'Could not reach ariseci.org']); exit; }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) { echo json_encode(['status'=>'error','message'=>"Unexpected response (HTTP $httpCode)"]); exit; }
    if ($httpCode < 300 && ($decoded['status']??'') === 'ok') {
        $count = $decoded['upserted'] ?? count($schoolRows);
        $ts = date('Y-m-d H:i:s');
        db()->exec("UPDATE datapost_config SET cloud_last_synced_at='".SQLite3::escapeString($ts)."',cloud_last_sync_count=$count WHERE id=".(int)$cfg['id']);
        echo json_encode(['status'=>'success','message'=>"Synced $count school(s)",'timestamp'=>$ts]);
    } else {
        echo json_encode(['status'=>'error','message'=>'Cloud sync failed']);
    }
    exit;
}

if ($action === 'cloud_sync_prepare') {
    header('Content-Type: application/json');
    $schoolRows = buildSchoolRows();
    echo json_encode(['status'=>'ok','schools'=>$schoolRows,'poll_summary'=>pollSummary(),'generated_at'=>date('Y-m-d H:i:s')]);
    exit;
}

// HTML Response ───────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
$lastSync = db()->querySingle("SELECT sync_timestamp, data_snapshot FROM datapost_sync_log ORDER BY id DESC LIMIT 1", true);
$syncData = $lastSync ? json_decode($lastSync['data_snapshot'], true) : null;
$emailCfg = $cfg['email_endpoint'] ?? '';
$smtpOk   = !empty($cfg['smtp_user']) && !empty($cfg['smtp_pass']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0ea271">
    <meta name="description" content="ARISE DataPost — Sync data, send reports, manage schools">
    <title>ARISE DataPost</title>
    <link rel="manifest" href="/arise/pwa_manifest.json">
    <link rel="icon" type="image/png" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 180 180'><rect fill='%230ea271' width='180' height='180'/><text x='50%' y='50%' font-size='90' fill='white' text-anchor='middle' dominant-baseline='middle' font-weight='bold'>D</text></svg>">
    <link rel="apple-touch-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 180 180'><rect fill='%230ea271' width='180' height='180'/><text x='50%' y='50%' font-size='90' fill='white' text-anchor='middle' dominant-baseline='middle' font-weight='bold'>D</text></svg>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #111;
        }
        .header {
            background: linear-gradient(135deg, #0ea271, #059669);
            color: white;
            padding: 24px 16px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
        }
        .header h1 { font-size: 1.6rem; margin-bottom: 4px; font-weight: 700; }
        .header .subtext { font-size: .9rem; opacity: .9; margin-bottom: 12px; }
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: .8rem;
            font-weight: 600;
        }
        .status-online { background: #10b981; color: white; }
        .status-offline { background: #ef4444; color: white; }

        .container { max-width: 900px; margin: 0 auto; padding: 16px; }

        .tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 20px;
            overflow-x: auto;
        }
        .tab-btn {
            padding: 12px 16px;
            background: none;
            border: none;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            font-size: .95rem;
            white-space: nowrap;
            border-bottom: 3px solid transparent;
            transition: .2s;
        }
        .tab-btn.active {
            color: #0ea271;
            border-bottom-color: #0ea271;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
        }

        .card h3 { font-size: 1.1rem; margin-bottom: 16px; color: #111; font-weight: 600; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat {
            background: #f9fafb;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #f3f4f6;
        }
        .stat-value { font-size: 1.8rem; font-weight: 700; color: #0ea271; }
        .stat-label { font-size: .85rem; color: #6b7280; margin-top: 4px; }

        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .stat-row:last-child { border-bottom: none; }
        .stat-label { font-size: .9rem; color: #6b7280; }
        .stat-value { font-weight: 700; color: #111; font-size: 1.1rem; }

        .button-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .btn {
            flex: 1;
            min-width: 120px;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: .9rem;
            cursor: pointer;
            transition: .2s;
            font-family: inherit;
        }
        .btn-primary {
            background: #0ea271;
            color: white;
        }
        .btn-primary:active { opacity: .9; transform: scale(.98); }
        .btn-primary:disabled { opacity: .5; cursor: not-allowed; }

        .btn-secondary {
            background: #e5e7eb;
            color: #111;
        }
        .btn-secondary:active { opacity: .9; }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        select,
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: .9rem;
            font-family: inherit;
            margin-bottom: 12px;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #0ea271;
            box-shadow: 0 0 0 3px rgba(14, 162, 113, .1);
        }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #111; font-size: .9rem; }

        .info-box {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
            font-size: .85rem;
            color: #166534;
            line-height: 1.5;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 12px;
            font-size: .9rem;
            color: #991b1b;
            margin-bottom: 12px;
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 12px;
            font-size: .9rem;
            color: #166534;
            margin-bottom: 12px;
        }

        .sync-log {
            background: #f9fafb;
            border-radius: 8px;
            padding: 12px;
            font-size: .8rem;
            font-family: monospace;
            color: #6b7280;
            max-height: 200px;
            overflow-y: auto;
            margin-top: 8px;
            border: 1px solid #f3f4f6;
        }

        .sync-log .success { color: #10b981; }
        .sync-log .error { color: #ef4444; }
        .sync-log .info { color: #3b82f6; }

        .loading { animation: spin 1s linear infinite; display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .divider {
            height: 1px;
            background: #e5e7eb;
            margin: 16px 0;
        }

        @media (max-width: 600px) {
            .container { padding: 12px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .button-group { flex-direction: column; }
            .btn { min-width: auto; }
        }
    </style>
    <script>
        window.ARISE_SCHOOL_ID = '<?= addslashes($cfg['school_id'] ?? '') ?>';
        window.ARISE_SCHOOL_NAME = '<?= addslashes($cfg['school_name'] ?? '') ?>';
    </script>
    <script src="/arise/js/arise-sync.js" defer></script>
</head>
<body>
    <!-- PWA INSTALL BANNER -->
    <div id="installBanner" style="display:none;position:fixed;top:0;left:0;right:0;background:#10b981;color:white;padding:12px;z-index:9999;flex-direction:column;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,.2);">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div><strong>📱 Install DataPost App?</strong> Quick access from home screen</div>
            <button class="btn btn-primary" onclick="installPWA()" style="margin:0;padding:8px 16px;font-size:.85rem;width:auto;flex:none;">Install</button>
            <button onclick="document.getElementById('installBanner').style.display='none'" style="background:none;border:none;color:white;cursor:pointer;font-size:1.2rem;padding:0;width:auto;flex:none;">✕</button>
        </div>
        <div id="installResult"></div>
    </div>

    <div class="header">
        <h1>📊 ARISE DataPost</h1>
        <div class="subtext">Sync data, send reports, manage schools</div>
        <div class="status-badge" id="statusBadge">🟢 Online</div>
    </div>

    <div class="container">
        <!-- TAB NAVIGATION -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('overview')">📊 Overview</button>
            <button class="tab-btn" onclick="switchTab('settings')">⚙️ Settings</button>
            <button class="tab-btn" onclick="switchTab('sync')">🔄 Sync</button>
        </div>

        <!-- TAB 1: OVERVIEW -->
        <div id="tab-overview" class="tab-content active">
            <div class="card">
                <h3>📋 Data Summary</h3>
                <div class="stats-grid">
                    <div class="stat">
                        <div class="stat-value" id="val-learners">0</div>
                        <div class="stat-label">Learners</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="val-quizzes">0</div>
                        <div class="stat-label">Quizzes</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="val-pretests">0</div>
                        <div class="stat-label">Pre-Tests</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="val-posttests">0</div>
                        <div class="stat-label">Post-Tests</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="val-certs">0</div>
                        <div class="stat-label">Certificates</div>
                    </div>
                    <div class="stat">
                        <div class="stat-value" id="val-modules">0</div>
                        <div class="stat-label">Modules</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3>📡 Network Status</h3>
                <div class="stat-row">
                    <span class="stat-label">Connection</span>
                    <span class="stat-value" id="connectionStatus">🔴 Offline</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Last Sync</span>
                    <span class="stat-value" id="lastSyncTime"><?= $lastSync ? $lastSync['sync_timestamp'] : 'Never' ?></span>
                </div>
                <div class="button-group">
                    <button class="btn btn-primary" onclick="doSync()">🔄 Sync Now</button>
                </div>
            </div>

            <div class="card" id="queueStatusCard" style="display:none;">
                <h3>📤 Cloud Sync Queue</h3>
                <div class="stat-row">
                    <span class="stat-label">Pending</span>
                    <span class="stat-value" id="queuePending">0</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Failed</span>
                    <span class="stat-value" id="queueFailed">0</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Last Cloud Sent</span>
                    <span class="stat-value" id="queueLastSent">-</span>
                </div>
                <div class="button-group">
                    <button class="btn btn-primary" onclick="processQueueNow()" id="sendNowBtn">📤 Send Now</button>
                    <button class="btn btn-secondary" onclick="retryFailedNow()" id="retryBtn" style="display:none;">🔁 Retry Failed</button>
                </div>
            </div>

            <div class="info-box">
                💡 <strong>Tip:</strong> Click "Sync Now" to fetch latest data from ARISE server and upload to cloud.
            </div>
        </div>

        <!-- TAB 2: SETTINGS -->
        <div id="tab-settings" class="tab-content">
            <div class="card">
                <h3>📧 Email Settings</h3>
                <div class="form-group">
                    <label>Recipient Email</label>
                    <input type="email" id="emailEndpoint" placeholder="example@gmail.com" value="<?= e($emailCfg) ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Host</label>
                    <input type="text" id="smtpHost" placeholder="smtp.gmail.com" value="<?= e($cfg['smtp_host'] ?? 'smtp.gmail.com') ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Port</label>
                    <input type="text" id="smtpPort" placeholder="587" value="<?= e($cfg['smtp_port'] ?? 587) ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Username (Email)</label>
                    <input type="email" id="smtpUser" placeholder="your@gmail.com" value="<?= e($cfg['smtp_user'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>SMTP Password / App Password</label>
                    <input type="password" id="smtpPass" placeholder="App password (not regular password)" value="<?= e($cfg['smtp_pass'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>From Address (optional)</label>
                    <input type="email" id="smtpFrom" placeholder="no-reply@arise.org" value="<?= e($cfg['smtp_from'] ?? '') ?>">
                </div>
                <div class="button-group">
                    <button class="btn btn-primary" onclick="saveEmailSettings()">💾 Save Settings</button>
                    <button class="btn btn-secondary" onclick="testEmail()">✉️ Test Email</button>
                </div>
                <div id="emailResult"></div>
            </div>

            <div class="card">
                <h3>☁️ Cloud Sync</h3>
                <div class="stat-row">
                    <span class="stat-label">Cloud URL</span>
                    <span class="stat-value"><?= e($cfg['cloud_sync_url'] ?? 'https://ariseci.org') ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Last Cloud Sync</span>
                    <span class="stat-value"><?= e($cfg['cloud_last_synced_at'] ?? 'Never') ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Schools Synced</span>
                    <span class="stat-value"><?= $cfg['cloud_last_sync_count'] ?? 0 ?></span>
                </div>
                <div class="button-group">
                    <button class="btn btn-primary" onclick="cloudSync()">☁️ Sync to Cloud</button>
                </div>
                <div id="cloudResult"></div>
            </div>

            <div class="card">
                <h3>⏰ Auto-Sync Schedule</h3>
                <p style="font-size:.9rem; color:#6b7280; margin-bottom:12px;">Automatically sync to cloud every 2 weeks when phone has internet.</p>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;">
                        <input type="checkbox" id="autoSyncEnabled" style="width:18px;height:18px;cursor:pointer;">
                        <span>Enable Auto-Sync (every 14 days)</span>
                    </label>
                </div>

                <div class="stat-row">
                    <span class="stat-label">Last Auto-Sync</span>
                    <span class="stat-value" id="lastAutoSync">Never</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Next Auto-Sync</span>
                    <span class="stat-value" id="nextAutoSync">-</span>
                </div>

                <div class="button-group" style="margin-top:16px;">
                    <button class="btn btn-primary" onclick="saveAutoSyncSettings()">💾 Save Auto-Sync</button>
                </div>
                <div id="autoSyncResult"></div>
            </div>

            <div class="info-box">
                💡 <strong>How it works:</strong> When enabled, your phone will automatically upload data to cloud every 14 days (whenever it has internet). Results logged below.
            </div>
        </div>

        <!-- TAB 3: SYNC -->
        <div id="tab-sync" class="tab-content">
            <div class="card">
                <h3>📋 Sync Actions</h3>
                <div class="info-box">
                    💡 <strong>Workflow:</strong> (1) Click "Sync Local" to update data snapshot, (2) Click "Send Report" to email it, (3) Click "Sync to Cloud" to upload school stats.
                </div>
                <div class="divider"></div>
                <h4 style="margin: 16px 0 12px; font-size: .95rem; color: #111;">Step 1: Update Local Data</h4>
                <button class="btn btn-primary" onclick="doSync()">📥 Sync Local Data</button>
                <div id="syncResult" style="margin-top: 8px;"></div>

                <h4 style="margin: 16px 0 12px; font-size: .95rem; color: #111;">Step 2: Send Report</h4>
                <?php if ($emailCfg && $smtpOk): ?>
                    <button class="btn btn-primary" onclick="sendReport()">📧 Send Report to Email</button>
                <?php else: ?>
                    <button class="btn btn-primary" disabled>📧 Send Report (Configure Email First)</button>
                <?php endif; ?>
                <div id="reportResult" style="margin-top: 8px;"></div>

                <h4 style="margin: 16px 0 12px; font-size: .95rem; color: #111;">Step 3: Cloud Sync</h4>
                <button class="btn btn-primary" onclick="cloudSync()">☁️ Sync to Cloud (ariseci.org)</button>
                <div id="cloudResult2" style="margin-top: 8px;"></div>

                <div class="divider"></div>
                <h4 style="margin: 16px 0 12px; font-size: .95rem; color: #111;">Sync History</h4>
                <div class="sync-log" id="syncLog">
                    <div class="info">Ready to sync...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            // Hide all content
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

            // Show selected
            document.getElementById('tab-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // Update connection status
        function updateConnectionStatus() {
            const online = navigator.onLine;
            const connEl = document.getElementById('connectionStatus');
            const badgeEl = document.getElementById('statusBadge');

            if (online) {
                connEl.textContent = '🟢 Online';
                badgeEl.textContent = '🟢 Online';
                badgeEl.className = 'status-badge status-online';
            } else {
                connEl.textContent = '🔴 Offline';
                badgeEl.textContent = '🔴 Offline';
                badgeEl.className = 'status-badge status-offline';
            }
        }

        // Show message in result div
        function showResult(elemId, msg, isError = false) {
            const el = document.getElementById(elemId);
            el.className = isError ? 'alert-error' : 'alert-success';
            el.innerHTML = msg;
            setTimeout(() => el.innerHTML = '', 4000);
        }

        // Sync local data
        async function doSync() {
            const btn = event?.target || document.querySelector('button[onclick="doSync()"]');
            if (!btn) return;

            btn.disabled = true;
            btn.textContent = '⏳ Syncing...';

            try {
                const res = await fetch('/arise/?p=datapost&action=sync', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await res.text();
                    console.error('Expected JSON but got:', text.substring(0, 100));
                    showResult('syncResult', `❌ Server error: received ${contentType}`, true);
                    log('❌ Invalid response type', 'error');
                    btn.disabled = false;
                    btn.textContent = '📥 Sync Local Data';
                    return;
                }

                const data = await res.json();

                if (data.status === 'synced') {
                    updateStats(data.summary);
                    showResult('syncResult', `✅ Synced at ${data.timestamp}`, false);
                    log('✅ Local data synced', 'success');

                    // Queue for cloud sync via IndexedDB
                    try {
                        const schoolRes = await fetch('/arise/?p=datapost&action=cloud_sync_prepare', { method: 'POST' });
                        const schoolData = await schoolRes.json();
                        if (schoolData.schools) {
                            if (window.AriseSyncManager && window.AriseSyncManager.queueAndSend) {
                                await window.AriseSyncManager.queueAndSend(data.summary, schoolData.schools);
                                log('📱 Data queued for cloud sync', 'info');
                                if (typeof refreshQueueUI === 'function') refreshQueueUI();
                            }
                        }
                    } catch (qe) {
                        console.warn('Could not queue for cloud:', qe);
                    }
                } else {
                    showResult('syncResult', '❌ Sync failed', true);
                    log('❌ Sync failed', 'error');
                }
            } catch (e) {
                console.error('Sync error:', e);
                showResult('syncResult', `❌ Error: ${e.message}`, true);
                log(`❌ ${e.message}`, 'error');
            }

            btn.disabled = false;
            btn.textContent = '📥 Sync Local Data';
        }

        // Update stats display
        function updateStats(data) {
            document.getElementById('val-learners').textContent = data.learners || 0;
            document.getElementById('val-quizzes').textContent = data.quizzes || 0;
            document.getElementById('val-pretests').textContent = data.pretests || 0;
            document.getElementById('val-posttests').textContent = data.posttests || 0;
            document.getElementById('val-certs').textContent = data.certs || 0;
            document.getElementById('val-modules').textContent = data.modules || 0;
        }

        // Save email settings
        async function saveEmailSettings() {
            const btn = event.target;
            btn.disabled = true;
            btn.textContent = '💾 Saving...';

            const formData = new FormData();
            formData.append('action', 'config_email');
            formData.append('email', document.getElementById('emailEndpoint').value);
            formData.append('smtp_host', document.getElementById('smtpHost').value);
            formData.append('smtp_port', document.getElementById('smtpPort').value);
            formData.append('smtp_user', document.getElementById('smtpUser').value);
            formData.append('smtp_pass', document.getElementById('smtpPass').value);
            formData.append('smtp_from', document.getElementById('smtpFrom').value);

            try {
                const res = await fetch('/arise/?p=datapost', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.status === 'success') {
                    showResult('emailResult', '✅ Settings saved!', false);
                    log('✅ Email settings saved', 'success');
                } else {
                    showResult('emailResult', `❌ ${data.message}`, true);
                    log('❌ Settings save failed', 'error');
                }
            } catch (e) {
                showResult('emailResult', `❌ ${e.message}`, true);
                log(`❌ ${e.message}`, 'error');
            }

            btn.disabled = false;
            btn.textContent = '💾 Save Settings';
        }

        // Test email
        async function testEmail() {
            const btn = event.target;
            btn.disabled = true;
            btn.textContent = '⏳ Sending...';

            try {
                const res = await fetch('/arise/?p=datapost&action=test_email', { method: 'POST' });
                const data = await res.json();

                if (data.status === 'success') {
                    showResult('emailResult', '✅ ' + data.message, false);
                    log('✅ Test email sent', 'success');
                } else {
                    showResult('emailResult', `❌ ${data.message}`, true);
                    log('❌ Test email failed', 'error');
                }
            } catch (e) {
                showResult('emailResult', `❌ ${e.message}`, true);
                log(`❌ ${e.message}`, 'error');
            }

            btn.disabled = false;
            btn.textContent = '✉️ Test Email';
        }

        // Send report
        async function sendReport() {
            const btn = event?.target || document.querySelector('button:contains("Send Report")');
            if (!btn) return;

            btn.disabled = true;
            btn.textContent = '⏳ Sending...';

            try {
                const res = await fetch('/arise/?p=datapost&action=post', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await res.text();
                    console.error('Expected JSON but got:', text.substring(0, 100));
                    showResult('reportResult', `❌ Server error: received ${contentType}`, true);
                    btn.disabled = false;
                    btn.textContent = '📧 Send Report to Email';
                    return;
                }

                const data = await res.json();

                if (data.status === 'success') {
                    showResult('reportResult', '✅ ' + data.message, false);
                    log('✅ Report sent', 'success');
                } else {
                    showResult('reportResult', `❌ ${data.message}`, true);
                    log('❌ Send report failed', 'error');
                }
            } catch (e) {
                console.error('Report error:', e);
                showResult('reportResult', `❌ ${e.message}`, true);
                log(`❌ ${e.message}`, 'error');
            }

            btn.disabled = false;
            btn.textContent = '📧 Send Report to Email';
        }

        // Cloud sync
        async function cloudSync() {
            const btn = event?.target || document.querySelector('button:contains("Sync to Cloud")');
            if (!btn) return;

            btn.disabled = true;
            btn.textContent = '⏳ Syncing...';

            try {
                // Try to reach local server with short timeout
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 3000);

                let res;
                try {
                    res = await fetch('/arise/?p=datapost&action=cloud_sync', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        signal: controller.signal
                    });
                    clearTimeout(timeoutId);
                } catch (fetchErr) {
                    clearTimeout(timeoutId);

                    // Server unreachable - use cached data for cloud sync
                    if (!navigator.onLine) {
                        log('📡 Offline - using cached data', 'info');
                        showResult('cloudResult', 'ℹ️ Offline - cached data queued for sync when online', false);
                        const el2 = document.getElementById('cloudResult2');
                        if (el2) showResult('cloudResult2', 'ℹ️ Offline - cached data queued', false);
                        btn.disabled = false;
                        btn.textContent = '☁️ Sync to Cloud';
                        return;
                    }

                    // On mobile network - local server unreachable
                    log('⚠️ ARISE server unreachable - ensure you synced data while on LAN first', 'error');
                    showResult('cloudResult', '⚠️ ARISE server unavailable. Make sure you ran "Sync Local Data" while on LAN.', true);
                    const el2 = document.getElementById('cloudResult2');
                    if (el2) showResult('cloudResult2', '⚠️ ARISE server unavailable', true);
                    btn.disabled = false;
                    btn.textContent = '☁️ Sync to Cloud';
                    return;
                }

                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await res.text();
                    console.error('Expected JSON but got:', text.substring(0, 100));
                    const resultId = document.getElementById('cloudResult') ? 'cloudResult' : 'cloudResult2';
                    showResult(resultId, `❌ Server error: received ${contentType}`, true);
                    btn.disabled = false;
                    btn.textContent = '☁️ Sync to Cloud';
                    return;
                }

                const data = await res.json();

                if (data.status === 'success') {
                    showResult('cloudResult', '✅ ' + data.message, false);
                    const el2 = document.getElementById('cloudResult2');
                    if (el2) showResult('cloudResult2', '✅ ' + data.message, false);
                    log('✅ Cloud sync success', 'success');
                } else {
                    showResult('cloudResult', `❌ ${data.message}`, true);
                    const el2 = document.getElementById('cloudResult2');
                    if (el2) showResult('cloudResult2', `❌ ${data.message}`, true);
                    log('❌ Cloud sync failed', 'error');
                }
            } catch (e) {
                console.error('Cloud sync error:', e);
                showResult('cloudResult', `❌ ${e.message}`, true);
                const el2 = document.getElementById('cloudResult2');
                if (el2) showResult('cloudResult2', `❌ ${e.message}`, true);
                log(`❌ ${e.message}`, 'error');
            }

            btn.disabled = false;
            btn.textContent = '☁️ Sync to Cloud';
        }

        // Logging
        function log(msg, type = 'info') {
            const logEl = document.getElementById('syncLog');
            if (!logEl) return;

            const logEntry = document.createElement('div');
            logEntry.className = type;
            logEntry.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
            logEl.insertBefore(logEntry, logEl.firstChild);

            if (logEl.children.length > 20) {
                logEl.removeChild(logEl.lastChild);
            }
        }

        // Queue Status UI
        async function refreshQueueUI() {
            if (!window.AriseSyncManager) return;

            try {
                const stats = await window.AriseSyncManager.getQueueStats();
                const card = document.getElementById('queueStatusCard');
                const pending = stats.pending || 0;
                const failed = stats.failed || 0;

                document.getElementById('queuePending').textContent = pending;
                document.getElementById('queueFailed').textContent = failed;

                if (stats.lastSent) {
                    const lastDate = new Date(stats.lastSent);
                    document.getElementById('queueLastSent').textContent = lastDate.toLocaleString();
                } else {
                    document.getElementById('queueLastSent').textContent = 'Never';
                }

                // Show card if there are pending or failed syncs
                if (pending > 0 || failed > 0) {
                    card.style.display = 'block';
                    document.getElementById('sendNowBtn').style.display = pending > 0 ? 'block' : 'none';
                    document.getElementById('retryBtn').style.display = failed > 0 ? 'block' : 'none';
                } else {
                    card.style.display = 'none';
                }
            } catch (e) {
                console.warn('Error refreshing queue UI:', e);
            }
        }

        async function processQueueNow() {
            if (!window.AriseSyncManager) return;

            const btn = document.getElementById('sendNowBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Sending...';

            try {
                await window.AriseSyncManager.processQueue();
                log('📤 Queue processed', 'success');
                refreshQueueUI();
            } catch (e) {
                log('❌ Queue process error: ' + e.message, 'error');
            }

            btn.disabled = false;
            btn.textContent = '📤 Send Now';
        }

        async function retryFailedNow() {
            if (!window.AriseSyncManager) return;

            const btn = document.getElementById('retryBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Retrying...';

            try {
                await window.AriseSyncManager.retryFailed();
                log('🔁 Failed syncs retried', 'success');
                refreshQueueUI();
            } catch (e) {
                log('❌ Retry error: ' + e.message, 'error');
            }

            btn.disabled = false;
            btn.textContent = '🔁 Retry Failed';
        }

        // Auto-Sync Management
        function loadAutoSyncSettings() {
            const enabled = localStorage.getItem('autoSyncEnabled') === 'true';
            const lastSync = localStorage.getItem('lastAutoSync');
            document.getElementById('autoSyncEnabled').checked = enabled;
            if (lastSync) {
                document.getElementById('lastAutoSync').textContent = new Date(lastSync).toLocaleDateString();
            }
            updateNextSyncDate();
        }

        function updateNextSyncDate() {
            const lastSync = localStorage.getItem('lastAutoSync');
            const enabled = document.getElementById('autoSyncEnabled').checked;
            const el = document.getElementById('nextAutoSync');

            if (!enabled) {
                el.textContent = 'Disabled';
                return;
            }

            if (!lastSync) {
                el.textContent = 'Next time online';
                return;
            }

            const last = new Date(lastSync);
            const next = new Date(last.getTime() + 14 * 24 * 60 * 60 * 1000);
            const daysLeft = Math.max(0, Math.ceil((next - new Date()) / (1000 * 60 * 60 * 24)));

            if (daysLeft === 0) {
                el.textContent = 'Due now';
            } else if (daysLeft === 1) {
                el.textContent = 'Tomorrow';
            } else {
                el.textContent = 'In ' + daysLeft + ' days';
            }
        }

        async function saveAutoSyncSettings() {
            const btn = event.target;
            const enabled = document.getElementById('autoSyncEnabled').checked;

            localStorage.setItem('autoSyncEnabled', enabled);
            showResult('autoSyncResult', enabled ? '✅ Auto-sync enabled (every 14 days)' : '✅ Auto-sync disabled', false);
            updateNextSyncDate();
            log(enabled ? '✅ Auto-sync enabled' : '✅ Auto-sync disabled', 'success');

            // If enabled and it's time, do sync now
            if (enabled && shouldAutoSync()) {
                setTimeout(() => {
                    log('⏰ Auto-sync time reached - syncing now...', 'info');
                    cloudSync();
                }, 1000);
            }
        }

        function shouldAutoSync() {
            const enabled = localStorage.getItem('autoSyncEnabled') === 'true';
            if (!enabled) return false;

            const lastSync = localStorage.getItem('lastAutoSync');
            if (!lastSync) return true; // First time

            const last = new Date(lastSync);
            const daysPassed = (new Date() - last) / (1000 * 60 * 60 * 24);
            return daysPassed >= 14;
        }

        function recordAutoSync() {
            const now = new Date().toISOString();
            localStorage.setItem('lastAutoSync', now);
            updateNextSyncDate();
        }

        // Enhance cloudSync to record auto-sync if enabled
        const originalCloudSync = cloudSync;
        async function cloudSyncWithAutoRecord() {
            const wasAutoSyncTime = shouldAutoSync();
            await originalCloudSync();
            if (wasAutoSyncTime && localStorage.getItem('autoSyncEnabled') === 'true') {
                recordAutoSync();
            }
        }

        // PWA Install Prompt Handler
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            const banner = document.getElementById('installBanner');
            if (banner) {
                banner.style.display = 'flex';
            }
            log('💡 App ready to install', 'info');
        });

        async function installPWA() {
            if (!deferredPrompt) {
                log('❌ Install not available on this device', 'error');
                return;
            }
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                log('✅ App installed! Check your home screen', 'success');
                document.getElementById('installBanner').style.display = 'none';
            } else {
                log('⚠️ Install cancelled', 'info');
            }
            deferredPrompt = null;
        }

        window.addEventListener('appinstalled', () => {
            log('✅ DataPost successfully installed to home screen', 'success');
        });

        // Initialize
        window.addEventListener('online', updateConnectionStatus);
        window.addEventListener('offline', updateConnectionStatus);

        document.addEventListener('DOMContentLoaded', function() {
            updateConnectionStatus();
            loadAutoSyncSettings();
            log('✅ DataPost ready', 'success');

            // Wire up queue UI refresh and set up AriseSyncManager
            if (window.AriseSyncManager) {
                window.AriseSyncManager.refreshQueueUI = refreshQueueUI;
                refreshQueueUI();
            }

            // Auto-sync on page load if conditions met
            if (shouldAutoSync() && navigator.onLine && localStorage.getItem('autoSyncEnabled') === 'true') {
                log('⏰ Auto-sync triggered', 'info');
                setTimeout(() => cloudSyncWithAutoRecord(), 2000);
            }

            // Register service worker
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('/arise/sw.js').catch(() => {});
            }
        });
    </script>
</body>
</html>
