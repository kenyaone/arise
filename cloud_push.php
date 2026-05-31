<?php
/**
 * ARISE Cloud Push — syncs local admin-managed tables to ariseci.org
 * Run via cron: * * * * * php /home/arise/cloud_push.php
 * Each machine is identified by its MAC-derived device_id so multiple
 * cloned machines can sync without overwriting each other's students.
 */

define('LOCAL_DB',   '/var/www/arise/data/arise.db');
define('MYSQL_URL',  'https://ariseci.org/arise_cron_receiver.php');
define('SYNC_SECRET','arise_sync_k3nya_2026');
define('LOG_FILE',   '/home/arise/cloud_sync.log');
define('DEVICE_ID_FILE', '/etc/arise_device_id');

function log_msg(string $msg): void {
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

// ── Resolve device_id ─────────────────────────────────────────────────────────
// Cached in /etc/arise_device_id so it survives network changes.
function get_device_id(): string {
    if (file_exists(DEVICE_ID_FILE)) {
        $id = trim(file_get_contents(DEVICE_ID_FILE));
        if ($id !== '') return $id;
    }
    // Derive from primary ethernet MAC (most stable interface)
    $mac = '';
    $out = shell_exec("ip -o link show 2>/dev/null");
    foreach (explode("\n", $out ?? '') as $line) {
        if (strpos($line, 'lo:') !== false) continue;
        if (preg_match('/link\/ether\s+([0-9a-f:]{17})/i', $line, $m)) {
            $mac = strtoupper(str_replace(':', '', $m[1]));
            break;
        }
    }
    if ($mac === '') $mac = strtoupper(md5(gethostname()));
    $id = 'ARISE-' . $mac;
    @file_put_contents(DEVICE_ID_FILE, $id);
    return $id;
}

$deviceId = get_device_id();

if (!file_exists(LOCAL_DB)) {
    log_msg("ERROR: local DB not found at " . LOCAL_DB);
    exit(1);
}

// Copy to /tmp so arise user can open it (SQLite needs WAL write access in DB dir)
$tmpDb = '/tmp/arise_push_' . getmypid() . '.db';
copy(LOCAL_DB, $tmpDb);
$db = new SQLite3($tmpDb, SQLITE3_OPEN_READONLY);
$db->busyTimeout(3000);

// ── Clusters — master machine only ───────────────────────────────────────────
// Only the machine that was freshly installed (not cloned) syncs clusters.
// first-boot-fix.sh removes /etc/arise_cluster_master on every clone so clones
// never re-add clusters that the master has deleted.
$isMaster = file_exists('/etc/arise_cluster_master');
$clusters = [];
if ($isMaster) {
    $r = $db->query("SELECT id, name, password_hash, lat, lng FROM clusters ORDER BY id");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $clusters[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'hash' => $row['password_hash'], 'lat' => $row['lat'], 'lng' => $row['lng']];
    }
}

// Build a local-cluster-id → name map so every school can carry its cluster
// label denormalized. Works regardless of master/clone role.
$clusterNameById = [];
$r = $db->query("SELECT id, name FROM clusters");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $clusterNameById[(int)$row['id']] = $row['name'];
}

// ── Schools with aggregated per-project learning metrics ─────────────────────
// Defaults — every active school gets a row, even with zero learners.
$schools = [];
$schoolsByName = [];
$r = $db->query("SELECT name, county, is_active, password_hash, lat, lng, cluster_id FROM schools WHERE is_active=1");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $name = $row['name'];
    $cid  = $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null;
    $schools[$name] = [
        'name'                  => $name,
        'county'                => $row['county'] ?? '',
        'active'                => (int)$row['is_active'],
        'hash'                  => $row['password_hash'] ?? '',
        'lat'                   => $row['lat'],
        'lng'                   => $row['lng'],
        'device_id'             => $deviceId,
        'cluster_local_id'      => $cid,
        'cluster_name'          => $cid !== null ? ($clusterNameById[$cid] ?? '') : '',
        // metric defaults
        'learner_count'         => 0,
        'quiz_count'            => 0,
        'pretest_count'         => 0,
        'posttest_count'        => 0,
        'avg_score'             => 0.0,
        'cert_count'            => 0,
        'cert_rate'             => 0.0,
        'quiz_pass_count'       => 0,
        'quiz_pass_rate'        => 0.0,
        'avg_pre_score'         => null,
        'avg_post_score'        => null,
        'knowledge_gain'        => null,
        'behavior_surveys'      => 0,
        'pct_changed'           => 0.0,
        'pct_shared'            => 0.0,
        'pct_confident'         => 0.0,
        'retention_count'       => 0,
        'avg_retention_score'   => 0.0,
        'lesson_completions'    => 0,
        'active_last_30_days'   => 0,
        'first_registration'    => null,
        'latest_activity'       => null,
        'facilitator_sessions'  => 0,
    ];
}

// Helper — run an aggregate query and fold results back into $schools by name.
// Silently skip if a referenced table is missing on this box.
$apply = function (string $sql, callable $cb) use ($db, &$schools): void {
    try {
        $r = @$db->query($sql);
        if (!$r) return;
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $name = $row['school_name'] ?? '';
            if ($name !== '' && isset($schools[$name])) $cb($schools[$name], $row);
        }
    } catch (Throwable $e) { /* table missing — skip */ }
};

$apply(
    "SELECT school_name, COUNT(*) v FROM students
       WHERE is_active=1 AND deleted_at IS NULL GROUP BY school_name",
    function (array &$o, array $r) { $o['learner_count'] = (int)$r['v']; }
);

$apply(
    "SELECT s.school_name,
        COUNT(qa.id) c,
        ROUND(AVG(qa.percentage),1) avg,
        SUM(CASE WHEN qa.percentage>=60 THEN 1 ELSE 0 END) pass
     FROM quiz_attempts qa
     JOIN students s ON s.id = qa.student_id
     GROUP BY s.school_name",
    function (array &$o, array $r) {
        $o['quiz_count']       = (int)$r['c'];
        $o['avg_score']        = (float)($r['avg'] ?? 0);
        $o['quiz_pass_count']  = (int)$r['pass'];
        $o['quiz_pass_rate']   = (int)$r['c'] > 0 ? round((int)$r['pass'] / (int)$r['c'] * 100, 1) : 0.0;
    }
);

$apply(
    "SELECT s.school_name, COUNT(*) v
     FROM certificates c JOIN students s ON s.id = c.student_id
     GROUP BY s.school_name",
    function (array &$o, array $r) {
        $o['cert_count'] = (int)$r['v'];
        $o['cert_rate']  = $o['learner_count'] > 0 ? round($o['cert_count'] / $o['learner_count'] * 100, 1) : 0.0;
    }
);

$apply(
    "SELECT s.school_name,
        ROUND(AVG(CASE WHEN pa.test_type='pre'  THEN pa.percentage END),1) avg_pre,
        ROUND(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage END),1) avg_post,
        COUNT(CASE WHEN pa.test_type='pre'  THEN 1 END) pre_n,
        COUNT(CASE WHEN pa.test_type='post' THEN 1 END) post_n
     FROM pretest_attempts pa JOIN students s ON s.id = pa.student_id
     GROUP BY s.school_name",
    function (array &$o, array $r) {
        $pre  = $r['avg_pre']  !== null ? (float)$r['avg_pre']  : null;
        $post = $r['avg_post'] !== null ? (float)$r['avg_post'] : null;
        $o['avg_pre_score']  = $pre;
        $o['avg_post_score'] = $post;
        $o['knowledge_gain'] = ($pre !== null && $post !== null) ? round($post - $pre, 1) : null;
        $o['pretest_count']  = (int)$r['pre_n'];
        $o['posttest_count'] = (int)$r['post_n'];
    }
);

$apply(
    "SELECT s.school_name,
        COUNT(bs.id) v,
        SUM(COALESCE(bs.q1_changed,0))   changed,
        SUM(COALESCE(bs.q2_shared,0))    shared,
        SUM(COALESCE(bs.q3_confident,0)) confident
     FROM behavioral_surveys bs JOIN students s ON s.id = bs.student_id
     GROUP BY s.school_name",
    function (array &$o, array $r) {
        $n = (int)$r['v'];
        $o['behavior_surveys'] = $n;
        $o['pct_changed']      = $n > 0 ? round((int)$r['changed']   / $n * 100, 1) : 0.0;
        $o['pct_shared']       = $n > 0 ? round((int)$r['shared']    / $n * 100, 1) : 0.0;
        $o['pct_confident']    = $n > 0 ? round((int)$r['confident'] / $n * 100, 1) : 0.0;
    }
);

$apply(
    "SELECT s.school_name,
        COUNT(rt.id) v,
        ROUND(AVG(rt.percentage),1) avg
     FROM retention_tests rt JOIN students s ON s.id = rt.student_id
     GROUP BY s.school_name",
    function (array &$o, array $r) {
        $o['retention_count']     = (int)$r['v'];
        $o['avg_retention_score'] = (float)($r['avg'] ?? 0);
    }
);

$apply(
    "SELECT s.school_name, COUNT(*) v
     FROM lesson_progress lp JOIN students s ON s.id = lp.student_id
     WHERE lp.completed = 1
     GROUP BY s.school_name",
    function (array &$o, array $r) { $o['lesson_completions'] = (int)$r['v']; }
);

$apply(
    "SELECT school_name, COUNT(*) v FROM students
     WHERE is_active=1 AND deleted_at IS NULL
       AND last_seen >= datetime('now','-30 days')
     GROUP BY school_name",
    function (array &$o, array $r) { $o['active_last_30_days'] = (int)$r['v']; }
);

$apply(
    "SELECT school_name,
        MIN(registered_at) first_reg,
        MAX(last_seen)     last_act
     FROM students WHERE is_active=1 AND deleted_at IS NULL
     GROUP BY school_name",
    function (array &$o, array $r) {
        $o['first_registration'] = $r['first_reg'] ?: null;
        $o['latest_activity']    = $r['last_act']  ?: null;
    }
);

$apply(
    "SELECT school_name, COUNT(*) v FROM facilitator_sessions GROUP BY school_name",
    function (array &$o, array $r) { $o['facilitator_sessions'] = (int)$r['v']; }
);

$schools = array_values($schools);

// ── Students — tagged with device_id, only this machine's data is replaced ────
$students = [];
$r = $db->query("SELECT id, full_name, school_name, class_name, session_hash, is_active, registered_at, deleted_at FROM students");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $row['device_id'] = $deviceId;
    $students[] = $row;
}

// Only include the clusters key when this is the master — the receiver skips
// cluster replacement entirely when the key is absent, so clones can never
// wipe or restore clusters that the master has changed.
$data = compact('schools', 'students', 'deviceId');
if ($isMaster) $data['clusters'] = $clusters;
$payload = json_encode($data);

$role = $isMaster ? 'master' : 'clone';

// ── Push to cloud MySQL receiver (drives locations.php) ─────────────────────
$ctxMy = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => 'Content-Type: application/x-www-form-urlencoded',
    'content'       => http_build_query(['secret' => SYNC_SECRET, 'payload' => $payload]),
    'timeout'       => 15,
    'ignore_errors' => true,
]]);
$respMy = @file_get_contents(MYSQL_URL, false, $ctxMy);
$resultMy = $respMy ? json_decode($respMy, true) : null;
if ($resultMy && ($resultMy['ok'] ?? false)) {
    log_msg("MySQL OK [{$role}] — device:{$deviceId} schools:{$resultMy['schools']} students:{$resultMy['students']}");
} else {
    log_msg("MySQL FAIL [{$role}] — device:{$deviceId} response: " . (substr($respMy ?? 'no response', 0, 300)));
}

@unlink($tmpDb);
