<?php
/**
 * ARISE DataPost — Sync / Email / GitHub
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
] as $sql) { try { db()->exec($sql); } catch(Exception $e) {} }

try { db()->exec("CREATE TABLE IF NOT EXISTS datapost_sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sync_timestamp TEXT,
    data_snapshot TEXT,
    posted_at TEXT
)"); } catch(Exception $e) {}

// ── Ensure one config row always exists ──────────────────────────────────────
$cfg = db()->querySingle("SELECT * FROM datapost_config LIMIT 1", true);
if (!$cfg) {
    db()->exec("INSERT OR IGNORE INTO datapost_config (school_id, school_name)
                VALUES ('arise-default','ARISE Platform')");
    $cfg = db()->querySingle("SELECT * FROM datapost_config LIMIT 1", true);
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Send email via SMTP using curl (Gmail / any SMTP).
 * Returns ['ok'=>bool, 'error'=>string]
 */
function smtp_send(string $to, string $subject, string $body, array $smtp): array {
    $host = $smtp['host'] ?? 'smtp.gmail.com';
    $port = (int)($smtp['port'] ?? 587);
    $user = $smtp['user'] ?? '';
    $pass = $smtp['pass'] ?? '';
    $from = $smtp['from'] ?: $user;

    // Build RFC-2822 message file
    $crlf = "\r\n";
    $date = date('r');
    $msgFile = tempnam(sys_get_temp_dir(), 'arise_mail_');
    file_put_contents($msgFile,
        "Date: $date{$crlf}" .
        "To: $to{$crlf}" .
        "From: ARISE DataPost <$from>{$crlf}" .
        "Subject: $subject{$crlf}" .
        "MIME-Version: 1.0{$crlf}" .
        "Content-Type: text/plain; charset=UTF-8{$crlf}" .
        "{$crlf}" .
        $body
    );

    $url = ($port === 465) ? "smtps://{$host}:{$port}" : "smtp://{$host}:{$port}";

    $cmd = sprintf(
        'curl -s --url %s --ssl-reqd --mail-from %s --mail-rcpt %s --upload-file %s --user %s 2>&1',
        escapeshellarg($url),
        escapeshellarg($from),
        escapeshellarg($to),
        escapeshellarg($msgFile),
        escapeshellarg("$user:$pass")
    );

    $out = []; $code = 0;
    exec($cmd, $out, $code);
    @unlink($msgFile);

    if ($code === 0) return ['ok' => true, 'error' => ''];
    return ['ok' => false, 'error' => implode(' | ', $out)];
}

function snap(): array {
    // Platform totals
    $summary = [
        'timestamp'  => date('Y-m-d H:i:s'),
        'learners'   => (int)db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1 AND deleted_at IS NULL"),
        'modules'    => (int)db()->querySingle("SELECT COUNT(*) FROM modules WHERE is_active=1"),
        'lessons'    => (int)db()->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1"),
        'quizzes'    => (int)db()->querySingle("SELECT COUNT(*) FROM quiz_attempts"),
        'pretests'   => (int)db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='pre'"),
        'posttests'  => (int)db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='post'"),
        'certs'      => (int)db()->querySingle("SELECT COUNT(*) FROM certificates"),
        'forum'      => (int)db()->querySingle("SELECT COUNT(*) FROM forum_posts WHERE is_hidden=0"),
        'questions'  => (int)db()->querySingle("SELECT COUNT(*) FROM anonymous_questions"),
        'avg_quiz_score' => round((float)db()->querySingle("SELECT AVG(score_percent) FROM quiz_attempts") ?? 0, 1),
    ];

    // School-level breakdown
    $schools = db()->query(
        "SELECT
            s.school_name as school,
            COUNT(DISTINCT s.id) as learners,
            COUNT(DISTINCT CASE WHEN qa.id IS NOT NULL THEN s.id END) as quiz_takers,
            ROUND(AVG(CASE WHEN qa.id IS NOT NULL THEN qa.score_percent ELSE NULL END), 1) as avg_score,
            COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN s.id END) as certified,
            ROUND(100.0 * COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN s.id END) / COUNT(DISTINCT s.id), 1) as cert_rate
        FROM students s
        LEFT JOIN quiz_attempts qa ON s.id = qa.student_id
        LEFT JOIN certificates c ON s.id = c.student_id
        WHERE s.is_active=1 AND s.deleted_at IS NULL
        GROUP BY s.school_name
        ORDER BY learners DESC"
    )->fetchAll(SQLITE3_ASSOC);

    $summary['schools'] = [];
    foreach ($schools as $row) {
        $summary['schools'][] = [
            'name' => $row['school'],
            'learners' => (int)$row['learners'],
            'quiz_takers' => (int)$row['quiz_takers'],
            'avg_score' => (float)$row['avg_score'],
            'certified' => (int)$row['certified'],
            'cert_rate' => (float)$row['cert_rate'],
        ];
    }

    // Top modules by engagement
    $modules = db()->query(
        "SELECT
            m.title as module,
            COUNT(qa.id) as attempts,
            ROUND(AVG(qa.score_percent), 1) as avg_score,
            ROUND(100.0 * SUM(CASE WHEN qa.score_percent >= 60 THEN 1 ELSE 0 END) / COUNT(qa.id), 1) as pass_rate
        FROM modules m
        LEFT JOIN quiz_attempts qa ON m.id = qa.module_id
        WHERE m.is_active=1
        GROUP BY m.id
        ORDER BY attempts DESC
        LIMIT 10"
    )->fetchAll(SQLITE3_ASSOC);

    $summary['top_modules'] = [];
    foreach ($modules as $row) {
        if ((int)$row['attempts'] > 0) {
            $summary['top_modules'][] = [
                'name' => $row['module'],
                'attempts' => (int)$row['attempts'],
                'avg_score' => (float)$row['avg_score'],
                'pass_rate' => (float)$row['pass_rate'],
            ];
        }
    }

    // Knowledge gain (if pre/post data exists)
    $gains = db()->query(
        "SELECT
            m.title as module,
            ROUND(AVG(CASE WHEN pa.test_type='pre' THEN pa.percentage ELSE NULL END), 1) as avg_pre,
            ROUND(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage ELSE NULL END), 1) as avg_post,
            ROUND(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage ELSE NULL END) -
                  AVG(CASE WHEN pa.test_type='pre' THEN pa.percentage ELSE NULL END), 1) as gain
        FROM modules m
        LEFT JOIN pretest_attempts pa ON m.id = pa.module_id
        WHERE m.is_active=1
        GROUP BY m.id
        HAVING COUNT(CASE WHEN pa.test_type='pre' THEN 1 END) > 0
        AND COUNT(CASE WHEN pa.test_type='post' THEN 1 END) > 0"
    )->fetchAll(SQLITE3_ASSOC);

    $summary['knowledge_gain'] = [];
    foreach ($gains as $row) {
        $summary['knowledge_gain'][] = [
            'module' => $row['module'],
            'pre_avg' => (float)$row['avg_pre'],
            'post_avg' => (float)$row['avg_post'],
            'gain' => (float)$row['gain'],
        ];
    }

    return $summary;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX / JSON ENDPOINTS
// ─────────────────────────────────────────────────────────────────────────────

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
        echo json_encode(['status'=>'error','message'=>'Invalid SMTP username (should be your Gmail address)']);
        exit;
    }

    $id = (int)$cfg['id'];
    $stmt = db()->prepare(
        "UPDATE datapost_config SET
            email_endpoint=:e, smtp_host=:h, smtp_port=:p,
            smtp_user=:u, smtp_pass=:pw, smtp_from=:f
         WHERE id=:id"
    );
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

if ($action === 'sync') {
    header('Content-Type: application/json');
    $ts   = date('Y-m-d H:i:s');
    $data = snap();
    db()->exec("INSERT INTO datapost_sync_log (sync_timestamp, data_snapshot)
                VALUES ('" . SQLite3::escapeString($ts) . "','" . SQLite3::escapeString(json_encode($data)) . "')");
    echo json_encode(['status'=>'synced','timestamp'=>$ts,'summary'=>$data]);
    exit;
}

if ($action === 'post') {
    header('Content-Type: application/json');
    $emailTo  = trim($cfg['email_endpoint'] ?? '');
    $smtpUser = trim($cfg['smtp_user'] ?? '');
    $smtpPass = trim($cfg['smtp_pass'] ?? '');

    if (!$emailTo) {
        echo json_encode(['status'=>'error','message'=>'No recipient email — click ⚙ Settings first']);
        exit;
    }
    if (!$smtpUser || !$smtpPass) {
        echo json_encode(['status'=>'error','message'=>'SMTP not configured — click ⚙ Settings and enter your Gmail + App Password']);
        exit;
    }

    $last = db()->querySingle("SELECT * FROM datapost_sync_log ORDER BY id DESC LIMIT 1", true);
    if (!$last) {
        echo json_encode(['status'=>'error','message'=>'Nothing synced yet — click SYNC first']);
        exit;
    }

    $data    = json_decode($last['data_snapshot'], true);
    $school  = $cfg['school_name'] ?? 'ARISE';
    $subject = "ARISE Data Report — {$school} — " . date('Y-m-d');

    $lines = [
        "ARISE PLATFORM — DATA REPORT",
        str_repeat("=", 60),
        "Synced  : " . ($data['timestamp'] ?? $last['sync_timestamp']),
        "Sent    : " . date('Y-m-d H:i:s'),
        "",
        "PLATFORM SUMMARY",
        str_repeat("-", 60),
    ];

    // Add top-level metrics
    $topKeys = ['learners', 'modules', 'lessons', 'quizzes', 'certs', 'avg_quiz_score', 'forum', 'questions'];
    foreach ($topKeys as $k) {
        if (isset($data[$k]) && !is_array($data[$k])) {
            $lines[] = sprintf("%-20s: %s", ucfirst(str_replace('_', ' ', $k)), $data[$k]);
        }
    }

    // School breakdown
    if (!empty($data['schools'])) {
        $lines[] = "";
        $lines[] = "SCHOOL BREAKDOWN";
        $lines[] = str_repeat("-", 60);
        $lines[] = sprintf("%-30s | %8s | %8s | %10s | %5s", "School", "Learners", "Quizzed", "Avg Score", "Cert %");
        $lines[] = str_repeat("-", 60);
        foreach ($data['schools'] as $s) {
            $lines[] = sprintf("%-30s | %8d | %8d | %9.1f%% | %5.1f%%",
                substr($s['name'], 0, 29),
                $s['learners'],
                $s['quiz_takers'],
                $s['avg_score'],
                $s['cert_rate']
            );
        }
    }

    // Top modules
    if (!empty($data['top_modules'])) {
        $lines[] = "";
        $lines[] = "TOP MODULES BY ENGAGEMENT";
        $lines[] = str_repeat("-", 60);
        $lines[] = sprintf("%-30s | %8s | %10s | %8s", "Module", "Attempts", "Avg Score", "Pass %");
        $lines[] = str_repeat("-", 60);
        foreach (array_slice($data['top_modules'], 0, 5) as $m) {
            $lines[] = sprintf("%-30s | %8d | %9.1f%% | %7.1f%%",
                substr($m['name'], 0, 29),
                $m['attempts'],
                $m['avg_score'],
                $m['pass_rate']
            );
        }
    }

    // Knowledge gain
    if (!empty($data['knowledge_gain'])) {
        $lines[] = "";
        $lines[] = "KNOWLEDGE GAIN ANALYSIS";
        $lines[] = str_repeat("-", 60);
        $lines[] = sprintf("%-30s | %8s | %8s | %8s", "Module", "Pre Avg", "Post Avg", "Gain");
        $lines[] = str_repeat("-", 60);
        foreach ($data['knowledge_gain'] as $g) {
            $lines[] = sprintf("%-30s | %7.1f%% | %7.1f%% | %7.1f%%",
                substr($g['module'], 0, 29),
                $g['pre_avg'],
                $g['post_avg'],
                $g['gain']
            );
        }
    }

    $lines[] = "";
    $lines[] = "VIEW FULL REPORT";
    $lines[] = "Visual reports: http://192.168.0.10/arise/?p=donor_report";
    $lines[] = "DataPost: http://192.168.0.10/data";
    $lines[] = "Platform: http://192.168.0.10/arise/";

    $result = smtp_send($emailTo, $subject, implode("\n", $lines), [
        'host' => $cfg['smtp_host'] ?? 'smtp.gmail.com',
        'port' => (int)($cfg['smtp_port'] ?? 587),
        'user' => $smtpUser,
        'pass' => $smtpPass,
        'from' => $cfg['smtp_from'] ?? $smtpUser,
    ]);

    if ($result['ok']) {
        db()->exec("UPDATE datapost_sync_log SET posted_at='" . date('Y-m-d H:i:s') . "' WHERE id=" . (int)$last['id']);
        echo json_encode(['status'=>'success','message'=>"Report sent to $emailTo"]);
    } else {
        echo json_encode(['status'=>'error','message'=>'SMTP failed: ' . $result['error']]);
    }
    exit;
}

if ($action === 'test_email') {
    header('Content-Type: application/json');
    $emailTo  = trim($cfg['email_endpoint'] ?? '');
    $smtpUser = trim($cfg['smtp_user'] ?? '');
    $smtpPass = trim($cfg['smtp_pass'] ?? '');

    if (!$emailTo || !$smtpUser || !$smtpPass) {
        echo json_encode(['status'=>'error','message'=>'Complete all settings first']);
        exit;
    }

    $result = smtp_send($emailTo, 'ARISE DataPost — Test Email', "This is a test from ARISE DataPost.\n\nIf you received this, email is working!\n\nPlatform: http://192.168.0.10/arise/", [
        'host' => $cfg['smtp_host'] ?? 'smtp.gmail.com',
        'port' => (int)($cfg['smtp_port'] ?? 587),
        'user' => $smtpUser,
        'pass' => $smtpPass,
        'from' => $cfg['smtp_from'] ?? $smtpUser,
    ]);

    echo json_encode($result['ok']
        ? ['status'=>'success','message'=>"Test email sent to $emailTo — check your inbox"]
        : ['status'=>'error','message'=>'Failed: ' . $result['error']]
    );
    exit;
}

if ($action === 'update') {
    header('Content-Type: application/json');
    $dir = '/var/www/arise';
    if (!is_dir("$dir/.git")) {
        echo json_encode(['status'=>'error','message'=>'Not connected to GitHub. Use Connect GitHub below.']);
        exit;
    }
    exec("git config --global --add safe.directory $dir 2>&1");
    exec("cd $dir && git pull 2>&1", $out, $code);
    echo json_encode([
        'status'  => $code === 0 ? 'success' : 'error',
        'message' => $code === 0 ? 'System updated' : 'Pull failed',
        'log'     => implode("\n", $out),
    ]);
    exit;
}

if ($action === 'git_connect') {
    header('Content-Type: application/json');
    $token = trim($_POST['token'] ?? '');
    $repo  = trim($_POST['repo']  ?? 'kenyaone/arise');
    if (!$token) { echo json_encode(['status'=>'error','message'=>'Token required']); exit; }

    $dir    = '/var/www/arise';
    $remote = "https://{$token}@github.com/{$repo}.git";
    exec("git config --global --add safe.directory $dir 2>&1");

    if (!is_dir("$dir/.git")) exec("git -C $dir init 2>&1");

    exec("git -C $dir remote get-url origin 2>&1", $x, $cx);
    if ($cx === 0) exec("git -C $dir remote set-url origin " . escapeshellarg($remote) . " 2>&1");
    else           exec("git -C $dir remote add origin "     . escapeshellarg($remote) . " 2>&1");

    exec("git -C $dir config user.email 'arise@local' 2>&1");
    exec("git -C $dir config user.name 'ARISE Server' 2>&1");

    exec("git -C $dir ls-remote origin 2>&1", $o, $c);
    if ($c === 0) {
        echo json_encode(['status'=>'success','message'=>"Connected to github.com/$repo"]);
    } else {
        echo json_encode(['status'=>'error','message'=>'Could not reach repo: ' . implode(' ', $o)]);
    }
    exit;
}

if ($action === 'git_push') {
    header('Content-Type: application/json');
    $dir = '/var/www/arise';
    if (!is_dir("$dir/.git")) { echo json_encode(['status'=>'error','message'=>'Not connected to GitHub']); exit; }
    exec("git config --global --add safe.directory $dir 2>&1");
    exec("git -C $dir add -A 2>&1", $o1);
    exec("git -C $dir diff --cached --quiet 2>&1", $o2, $dirty);
    if ($dirty === 0) {
        echo json_encode(['status'=>'ok','message'=>'Nothing new to push — already up to date']);
        exit;
    }
    exec("git -C $dir commit -m 'ARISE update " . date('Y-m-d H:i') . "' 2>&1", $o3, $c3);
    exec("git -C $dir push -u origin HEAD 2>&1", $o4, $c4);
    $log = implode("\n", array_merge($o3, $o4));
    echo json_encode([
        'status'  => $c4 === 0 ? 'success' : 'error',
        'message' => $c4 === 0 ? 'Pushed to GitHub successfully' : 'Push failed',
        'log'     => $log,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// HTML DASHBOARD
// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');

$lastSync = db()->querySingle("SELECT sync_timestamp, data_snapshot FROM datapost_sync_log ORDER BY id DESC LIMIT 1", true);
$syncData = $lastSync ? json_decode($lastSync['data_snapshot'], true) : null;
$emailCfg = $cfg['email_endpoint'] ?? '';
$smtpOk   = !empty($cfg['smtp_user']) && !empty($cfg['smtp_pass']);
$isGit    = is_dir('/var/www/arise/.git');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ARISE DataPost</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0a0f1e;color:#e2e8f0;min-height:100vh;padding:24px}
.wrap{max-width:860px;margin:0 auto}
.hdr{background:linear-gradient(135deg,#052e16,#0a5e2a);padding:28px 32px;border-radius:12px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.hdr h1{font-size:1.7rem;font-weight:900;color:#6ee7b7}
.hdr p{color:rgba(255,255,255,.55);font-size:.82rem;margin-top:3px}
.hl{display:flex;gap:8px;flex-wrap:wrap}
.hl a,.hl button{color:#6ee7b7;font-size:.82rem;text-decoration:none;padding:7px 13px;border:1px solid rgba(110,231,183,.3);border-radius:7px;background:none;cursor:pointer}
.hl a:hover,.hl button:hover{background:rgba(110,231,183,.1)}
.card{background:#111827;border:1px solid #1f2937;border-radius:10px;padding:20px;margin-bottom:18px}
.card h2{font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px}
.srow{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid #1f2937}
.srow:last-child{border:none}
.sl{color:#9ca3af;font-size:.87rem}
.sv{font-weight:700;font-size:.9rem;color:#6ee7b7}
.sv.warn{color:#fbbf24}.sv.bad{color:#f87171}
.arow{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}
@media(max-width:580px){.arow{grid-template-columns:1fr}}
.ab{background:#111827;border:2px solid #1f2937;border-radius:10px;padding:20px 14px;text-align:center;cursor:pointer;transition:.15s;border-left:4px solid #1f2937}
.ab:hover{border-color:#0ea271;background:rgba(14,162,113,.07)}
.ab.sync{border-left-color:#0ea271}.ab.post{border-left-color:#3b82f6}.ab.upd{border-left-color:#f59e0b}
.ab .ai{font-size:2rem;margin-bottom:7px}.ab .at{font-weight:800;font-size:.92rem;margin-bottom:3px}.ab .ad{font-size:.75rem;color:#6b7280}
.kgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}
@media(max-width:480px){.kgrid{grid-template-columns:repeat(2,1fr)}}
.kc{background:#0d1117;border-radius:8px;padding:12px;text-align:center}
.kv{font-size:1.5rem;font-weight:900;color:#6ee7b7}.kl{font-size:.7rem;color:#6b7280;text-transform:uppercase;margin-top:2px}
.msg{padding:13px 17px;border-radius:8px;margin-bottom:14px;font-weight:600;font-size:.88rem;display:none}
.msg.ok{background:#064e3b;border:1px solid #065f46;color:#6ee7b7}
.msg.err{background:#450a0a;border:1px solid #7f1d1d;color:#fca5a5}
.msg.warn{background:#451a03;border:1px solid #78350f;color:#fcd34d}
#logBox{background:#0d1117;border:1px solid #1f2937;border-radius:8px;padding:12px;font-family:monospace;font-size:.76rem;white-space:pre-wrap;max-height:180px;overflow-y:auto;color:#93c5fd;display:none;margin-top:10px}
.gbtns{display:flex;gap:10px;flex-wrap:wrap}
.gbtn{padding:9px 18px;border-radius:8px;font-weight:700;font-size:.84rem;cursor:pointer;border:none}
.gbtn.connect{background:#f59e0b;color:#0d1117}.gbtn.pull{background:#3b82f6;color:#fff}
.gbtn.push{background:#8b5cf6;color:#fff}.gbtn:hover{opacity:.82}
/* Modals */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:500;align-items:flex-start;justify-content:center;padding:40px 20px;overflow-y:auto}
.modal.show{display:flex}
.mbox{background:#111827;border:1px solid #374151;border-radius:12px;padding:28px;width:100%;max-width:480px;margin:auto}
.mbox h3{color:#6ee7b7;margin-bottom:6px;font-size:1.05rem;font-weight:800}
.mbox .sub{color:#6b7280;font-size:.82rem;margin-bottom:18px}
.frow{margin-bottom:14px}
.frow label{display:block;font-size:.75rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px}
.frow input{width:100%;padding:10px 13px;background:#0d1117;border:1px solid #374151;color:#e2e8f0;border-radius:8px;font-family:inherit;font-size:.9rem}
.frow input:focus{outline:none;border-color:#0ea271}
.frow .hint{font-size:.74rem;color:#4b5563;margin-top:4px}
.btn{width:100%;padding:11px;background:#0ea271;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:.9rem;cursor:pointer;margin-bottom:8px}
.btn:hover{background:#059669}.btn.sec{background:#1f2937;color:#9ca3af}.btn.sec:hover{background:#374151;color:#fff}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.72rem;font-weight:700}
.badge.yes{background:#064e3b;color:#6ee7b7}.badge.no{background:#450a0a;color:#fca5a5}
.tab{background:#0d1117;border:1px solid #374151;color:#9ca3af;padding:7px 13px;border-radius:7px;font-size:.8rem;font-weight:600;cursor:pointer}
.tab:hover{border-color:#0ea271;color:#6ee7b7}
.active-tab{background:rgba(14,162,113,.15);border-color:#0ea271;color:#6ee7b7}
</style>
</head>
<body>
<div class="wrap">

<div class="hdr">
  <div>
    <h1>📡 ARISE DataPost</h1>
    <p>Offline Sync &nbsp;·&nbsp; Email Reports &nbsp;·&nbsp; GitHub Updates &nbsp;·&nbsp; <strong>192.168.0.10/data</strong></p>
  </div>
  <div class="hl">
    <button onclick="openSettings()">⚙️ Settings</button>
    <a href="/arise/admin/" target="_blank">🔐 Admin</a>
    <a href="/arise/">🏠 Platform</a>
  </div>
</div>

<div id="msg" class="msg"></div>
<div id="logBox"></div>

<!-- Status -->
<div class="card">
  <h2>Status</h2>
  <div class="srow"><span class="sl">Last Synced</span><span class="sv" id="lastSyncVal"><?= $lastSync ? esc($lastSync['sync_timestamp']) : 'Never' ?></span></div>
  <div class="srow"><span class="sl">Recipient Email</span><span class="sv <?= $emailCfg ? '' : 'warn' ?>" id="emailVal"><?= $emailCfg ? esc($emailCfg) : '⚠ Not set — click Settings' ?></span></div>
  <div class="srow"><span class="sl">SMTP (Gmail)</span><span class="sv <?= $smtpOk ? '' : 'warn' ?>"><?= $smtpOk ? '✓ Configured' : '⚠ Not configured' ?></span></div>
  <div class="srow"><span class="sl">GitHub</span><span class="sv"><span class="badge <?= $isGit ? 'yes' : 'no' ?>"><?= $isGit ? '✓ Connected' : '✗ Not connected' ?></span></span></div>
</div>

<!-- 3 main actions -->
<div class="arow">
  <div class="ab sync" onclick="doSync()">
    <div class="ai">💾</div>
    <div class="at">SYNC</div>
    <div class="ad">Collect data now<br>(offline)</div>
  </div>
  <div class="ab post" onclick="doPost()">
    <div class="ai">📤</div>
    <div class="at">POST</div>
    <div class="ad">Email the report<br>(needs internet)</div>
  </div>
  <div class="ab upd" onclick="doPull()">
    <div class="ai">⬆️</div>
    <div class="at">UPDATE</div>
    <div class="ad">Pull from GitHub<br>(needs internet)</div>
  </div>
</div>

<!-- Latest snapshot -->
<?php if ($syncData): ?>
<div class="card">
  <h2>Latest Snapshot — <?= esc($lastSync['sync_timestamp']) ?></h2>
  <div class="kgrid">
    <?php foreach ($syncData as $k=>$v): ?>
    <div class="kc"><div class="kv"><?= $v ?></div><div class="kl"><?= ucfirst($k) ?></div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Reports -->
<div class="card">
  <h2>📊 Donor & Impact Reports</h2>
  <div class="gbtns">
    <a href="/arise/?p=donor_report" target="_blank" style="background:#d97706;color:#fff;text-decoration:none;padding:9px 18px;border-radius:8px;font-weight:700;font-size:.84rem;cursor:pointer;border:none;display:inline-block">📈 View Full Impact Report</a>
    <button class="gbtn pull" style="background:#10b981" onclick="openDonorGuide()">📋 Reporting Guide</button>
  </div>
  <p style="font-size:.8rem;color:#6b7280;margin-top:12px">✓ Professional, printable reports aggregated by school and module<br>✓ Knowledge gain analysis, completion funnels, and engagement metrics</p>
</div>

<!-- GitHub -->
<div class="card">
  <h2>GitHub — kenyaone/arise</h2>
  <div class="gbtns">
    <button class="gbtn connect" onclick="openGitHub()"><?= $isGit ? '🔑 Reconnect' : '🔗 Connect GitHub' ?></button>
    <?php if ($isGit): ?>
    <button class="gbtn pull" onclick="doPull()">⬇️ Pull Updates</button>
    <button class="gbtn push" onclick="doPush()">⬆️ Push Changes</button>
    <?php endif; ?>
  </div>
</div>

</div><!-- wrap -->

<!-- ── Settings Modal ─────────────────────────────────────────────────── -->
<div class="modal" id="settingsModal">
  <div class="mbox">
    <h3>⚙️ Email Settings</h3>

    <!-- Provider tabs -->
    <div style="display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap">
      <button onclick="setProvider('brevo')"   id="tab-brevo"   class="tab active-tab">Brevo (Recommended)</button>
      <button onclick="setProvider('gmail')"   id="tab-gmail"   class="tab">Gmail</button>
      <button onclick="setProvider('custom')"  id="tab-custom"  class="tab">Custom SMTP</button>
    </div>

    <!-- Brevo instructions -->
    <div id="provider-brevo">
      <div style="background:#0d1117;border:1px solid #1f2937;border-radius:8px;padding:12px;margin-bottom:14px;font-size:.8rem;color:#9ca3af;line-height:1.7">
        <strong style="color:#6ee7b7">Free — 300 emails/day, no credit card:</strong><br>
        1. Go to <strong>brevo.com</strong> → Sign up free<br>
        2. Settings → SMTP &amp; API → SMTP tab<br>
        3. Copy your <strong>Login</strong> and <strong>Master password</strong> below<br>
        SMTP Host: <strong>smtp-relay.brevo.com</strong>, Port: <strong>587</strong>
      </div>
    </div>

    <!-- Gmail instructions -->
    <div id="provider-gmail" style="display:none">
      <div style="background:#0d1117;border:1px solid #1f2937;border-radius:8px;padding:12px;margin-bottom:14px;font-size:.8rem;color:#9ca3af;line-height:1.7">
        <strong style="color:#fbbf24">Requires 2-Step Verification to be ON:</strong><br>
        1. myaccount.google.com → Security → 2-Step Verification (turn on)<br>
        2. Then: Security → <strong>App passwords</strong> → create "ARISE"<br>
        3. Use the 16-char code as your password below<br>
        SMTP Host: <strong>smtp.gmail.com</strong>, Port: <strong>587</strong>
      </div>
    </div>

    <!-- Custom SMTP instructions -->
    <div id="provider-custom" style="display:none">
      <div style="background:#0d1117;border:1px solid #1f2937;border-radius:8px;padding:12px;margin-bottom:14px;font-size:.8rem;color:#9ca3af;line-height:1.7">
        Enter the SMTP details from any email provider (Mailgun, SMTP2GO, Zoho, Office 365, etc.)
      </div>
    </div>

    <div class="frow">
      <label>Recipient Email (where reports go)</label>
      <input type="email" id="sEmail" placeholder="musilwabonface@gmail.com" value="<?= esc($emailCfg) ?>">
    </div>
    <div class="frow">
      <label>SMTP Host</label>
      <input type="text" id="sSmtpHost" value="<?= esc($cfg['smtp_host']??'smtp-relay.brevo.com') ?>" placeholder="smtp-relay.brevo.com">
    </div>
    <div class="frow">
      <label>SMTP Port</label>
      <input type="number" id="sSmtpPort" value="<?= esc((string)($cfg['smtp_port']??'587')) ?>" placeholder="587">
    </div>
    <div class="frow">
      <label>SMTP Username / Login</label>
      <input type="text" id="sSmtpUser" value="<?= esc($cfg['smtp_user']??'') ?>" placeholder="your-login@email.com">
    </div>
    <div class="frow">
      <label>SMTP Password / Key</label>
      <input type="password" id="sSmtpPass" value="<?= esc($cfg['smtp_pass']??'') ?>" placeholder="SMTP password or API key">
    </div>
    <div class="frow">
      <label>From Email (optional — defaults to SMTP user)</label>
      <input type="text" id="sSmtpFrom" value="<?= esc($cfg['smtp_from']??'') ?>" placeholder="arise@yourdomain.com">
    </div>

    <button class="btn" onclick="saveSettings()">💾 Save Settings</button>
    <button class="btn" style="background:#0d9488" onclick="testEmail()">📧 Send Test Email Now</button>
    <button class="btn sec" onclick="closeModal('settingsModal')">Cancel</button>
  </div>
</div>

<!-- ── GitHub Modal ───────────────────────────────────────────────────── -->
<div class="modal" id="githubModal">
  <div class="mbox">
    <h3>🔗 Connect GitHub</h3>
    <p class="sub">Enter your GitHub Personal Access Token (needs <strong>repo</strong> scope). It will be stored only in the remote URL.</p>
    <div class="frow">
      <label>Personal Access Token</label>
      <input type="password" id="ghToken" placeholder="ghp_xxxxxxxxxxxxxxxxxxxx" autocomplete="off">
      <div class="hint">github.com → Settings → Developer settings → Personal access tokens → Generate new token → tick <em>repo</em></div>
    </div>
    <div class="frow">
      <label>Repository</label>
      <input type="text" id="ghRepo" value="kenyaone/arise">
    </div>
    <button class="btn" onclick="connectGitHub()">Connect &amp; Test</button>
    <button class="btn sec" onclick="closeModal('githubModal')">Cancel</button>
  </div>
</div>

<script>
function esc(s){ const d=document.createElement('div');d.textContent=s;return d.innerHTML; }
function show(msg,type='ok'){
  const el=document.getElementById('msg');
  el.textContent=msg; el.className='msg '+type; el.style.display='block';
  if(type==='ok') setTimeout(()=>el.style.display='none',7000);
}
function showLog(t){const lb=document.getElementById('logBox');lb.textContent=t;lb.style.display=t?'block':'none';}

function api(action,extra={}){
  const f=new FormData();
  f.append('action',action);
  Object.entries(extra).forEach(([k,v])=>f.append(k,v));
  return fetch(location.pathname+location.search,{method:'POST',body:f})
    .then(r=>{
      const ct=r.headers.get('content-type')||'';
      if(!ct.includes('json')) return r.text().then(t=>{throw new Error('Bad response: '+t.slice(0,200));});
      return r.json();
    });
}

function doSync(){
  show('⏳ Syncing...');
  api('sync').then(d=>{
    if(d.status==='synced'){
      show('✅ Synced — '+d.summary.learners+' learners, '+d.summary.quizzes+' quizzes, '+d.summary.certs+' certs');
      document.getElementById('lastSyncVal').textContent=d.timestamp;
      setTimeout(()=>location.reload(),1800);
    } else show('❌ '+(d.message||'Sync failed'),'err');
  }).catch(e=>show('❌ '+e.message,'err'));
}

function doPost(){
  show('⏳ Sending report via email...');
  api('post').then(d=>{
    if(d.status==='success') show('✅ '+d.message);
    else show('❌ '+d.message,'err');
  }).catch(e=>show('❌ '+e.message,'err'));
}

function doPull(){
  show('⏳ Pulling from GitHub...');
  api('update').then(d=>{
    show(d.status==='success'?'✅ '+d.message:'❌ '+d.message, d.status==='success'?'ok':'err');
    showLog(d.log||'');
    if(d.status==='success') setTimeout(()=>location.reload(),3000);
  }).catch(e=>show('❌ '+e.message,'err'));
}

function doPush(){
  if(!confirm('Push all current files to GitHub kenyaone/arise?')) return;
  show('⏳ Pushing to GitHub...');
  api('git_push').then(d=>{
    show(d.status==='success'||d.status==='ok'?'✅ '+d.message:'❌ '+d.message,
         d.status==='error'?'err':'ok');
    showLog(d.log||'');
  }).catch(e=>show('❌ '+e.message,'err'));
}

function connectGitHub(){
  const token=document.getElementById('ghToken').value.trim();
  const repo=document.getElementById('ghRepo').value.trim();
  if(!token){show('❌ Enter your GitHub token','err');return;}
  show('⏳ Connecting...');
  api('git_connect',{token,repo}).then(d=>{
    closeModal('githubModal');
    show(d.status==='success'?'✅ '+d.message:'❌ '+d.message,d.status==='success'?'ok':'err');
    if(d.status==='success') setTimeout(()=>location.reload(),1500);
  }).catch(e=>show('❌ '+e.message,'err'));
}

function getSmtpFields(){
  return {
    email:     document.getElementById('sEmail').value.trim(),
    smtp_host: document.getElementById('sSmtpHost').value.trim(),
    smtp_port: document.getElementById('sSmtpPort').value.trim(),
    smtp_user: document.getElementById('sSmtpUser').value.trim(),
    smtp_pass: document.getElementById('sSmtpPass').value.trim(),
    smtp_from: document.getElementById('sSmtpFrom').value.trim(),
  };
}

function saveSettings(){
  const f=getSmtpFields();
  if(!f.email){show('❌ Enter recipient email','err');return;}
  if(!f.smtp_user||!f.smtp_pass){show('❌ Enter SMTP username and password','err');return;}
  api('config_email',f).then(d=>{
    if(d.status==='success'){
      show('✅ Settings saved');
      document.getElementById('emailVal').textContent=f.email;
      document.getElementById('emailVal').className='sv';
      closeModal('settingsModal');
    } else show('❌ '+d.message,'err');
  }).catch(e=>show('❌ '+e.message,'err'));
}

function testEmail(){
  const f=getSmtpFields();
  if(!f.email||!f.smtp_user||!f.smtp_pass){show('❌ Fill all required fields','err');return;}
  show('⏳ Saving settings and sending test email...');
  api('config_email',f)
    .then(()=>api('test_email'))
    .then(d=>{
      show(d.status==='success'?'✅ '+d.message:'❌ '+d.message, d.status==='success'?'ok':'err');
    }).catch(e=>show('❌ '+e.message,'err'));
}

// Provider tab switcher
const providerDefaults = {
  brevo:  {host:'smtp-relay.brevo.com', port:'587'},
  gmail:  {host:'smtp.gmail.com',       port:'587'},
  custom: {host:'',                     port:'587'},
};
function setProvider(p){
  ['brevo','gmail','custom'].forEach(t=>{
    document.getElementById('provider-'+t).style.display = t===p ? '' : 'none';
    document.getElementById('tab-'+t).className = 'tab' + (t===p?' active-tab':'');
  });
  if(providerDefaults[p]){
    document.getElementById('sSmtpHost').value = providerDefaults[p].host;
    document.getElementById('sSmtpPort').value = providerDefaults[p].port;
  }
}

function openSettings(){document.getElementById('settingsModal').classList.add('show');}
function openGitHub(){document.getElementById('githubModal').classList.add('show');}
function openDonorGuide(){alert('📊 Donor Report Features:\n\n✓ Full impact metrics aggregated by school\n✓ Module performance with quiz scores\n✓ Knowledge gain (pre/post test analysis)\n✓ Completion funnel visualization\n✓ Daily activity tracking\n✓ Professional PDF export\n\nClick "View Full Impact Report" to access the complete report.');}
function closeModal(id){document.getElementById(id).classList.remove('show');}

document.addEventListener('click',e=>{
  ['settingsModal','githubModal'].forEach(id=>{if(e.target===document.getElementById(id))closeModal(id);});
});
document.addEventListener('keydown',e=>{if(e.key==='Escape')['settingsModal','githubModal'].forEach(closeModal);});
</script>
</body>
</html>
<?php
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
