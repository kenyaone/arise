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

require_once __DIR__ . '/includes/courier_bundle.php';

function log_msg(string $msg): void {
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

if (!file_exists(LOCAL_DB)) {
    log_msg("ERROR: local DB not found at " . LOCAL_DB);
    exit(1);
}

$deviceId = courier_device_id();

// Copy to /tmp so arise user can open it (SQLite needs WAL write access in DB dir)
$tmpDb = '/tmp/arise_push_' . getmypid() . '.db';
copy(LOCAL_DB, $tmpDb);
$db = new SQLite3($tmpDb, SQLITE3_OPEN_READONLY);
$db->busyTimeout(3000);

$data = build_courier_bundle($db);
$isMaster = isset($data['clusters']);

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
