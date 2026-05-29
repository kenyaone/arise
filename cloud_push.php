<?php
/**
 * ARISE Cloud Push — syncs local admin-managed tables to ariseci.org
 * Run via cron: * * * * * php /home/arise/cloud_push.php
 * Each machine is identified by its MAC-derived device_id so multiple
 * cloned machines can sync without overwriting each other's students.
 */

define('LOCAL_DB',   '/var/www/arise/data/arise.db');
define('SYNC_URL',   'https://ariseci.org/arise/arise_cluster_receiver.php');
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

// ── Clusters & schools — admin data, full replace (master machine only) ───────
$clusters = [];
$r = $db->query("SELECT id, name, password_hash, lat, lng FROM clusters ORDER BY id");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $clusters[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'hash' => $row['password_hash'], 'lat' => $row['lat'], 'lng' => $row['lng']];
}

$schools = [];
$r = $db->query("SELECT name, county, cluster_id, is_active, password_hash, lat, lng FROM schools");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $schools[] = [
        'name'       => $row['name'],
        'county'     => $row['county'] ?? '',
        'cluster_id' => $row['cluster_id'] ? (int)$row['cluster_id'] : null,
        'active'     => (int)$row['is_active'],
        'hash'       => $row['password_hash'] ?? '',
        'lat'        => $row['lat'],
        'lng'        => $row['lng'],
    ];
}

// ── Students — tagged with device_id, only this machine's data is replaced ────
$students = [];
$r = $db->query("SELECT id, full_name, school_name, class_name, session_hash, is_active, registered_at, deleted_at FROM students");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $row['device_id'] = $deviceId;
    $students[] = $row;
}

$payload = json_encode(compact('clusters', 'schools', 'students', 'deviceId'));

$ctx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => 'Content-Type: application/x-www-form-urlencoded',
    'content'       => http_build_query(['secret' => SYNC_SECRET, 'payload' => $payload]),
    'timeout'       => 15,
    'ignore_errors' => true,
]]);

$resp = @file_get_contents(SYNC_URL, false, $ctx);
$result = $resp ? json_decode($resp, true) : null;

if ($result && ($result['ok'] ?? false)) {
    log_msg("Sync OK — device:{$deviceId} clusters:{$result['clusters']} schools:{$result['schools']} students:{$result['students']}");
} else {
    log_msg("Sync FAILED — device:{$deviceId} response: " . ($resp ?: 'no response'));
}

@unlink($tmpDb);
