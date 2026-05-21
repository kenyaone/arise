<?php
/**
 * ARISE Cluster Sync Receiver — permanent endpoint on cloud server
 * POST: secret + payload (JSON)
 * Replaces all clusters and updates school assignments from local admin.
 */
define('SYNC_SECRET', 'arise_sync_k3nya_2026');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']); exit;
}
if (($_POST['secret'] ?? '') !== SYNC_SECRET) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit;
}

$payload = json_decode($_POST['payload'] ?? '{}', true);
if (!$payload) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']); exit;
}

// Locate DB — prefer parent-level data/arise.db (used by root locations.php)
$dbPath = dirname(__DIR__) . '/data/arise.db';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/data/arise.db';
if (!file_exists($dbPath)) {
    foreach (['/home/*/public_html/data/arise.db', '/home/*/public_html/arise/data/arise.db'] as $g) {
        $m = glob($g); if (!empty($m)) { $dbPath = $m[0]; break; }
    }
}
if (!file_exists($dbPath)) {
    echo json_encode(['ok' => false, 'error' => 'DB not found at ' . $dbPath]); exit;
}

$db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
$db->busyTimeout(5000);

// Ensure schema
$db->exec("CREATE TABLE IF NOT EXISTS clusters (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
)");
foreach (['cluster_id INTEGER', 'county TEXT', 'password_hash TEXT', 'lat REAL', 'lng REAL'] as $col) {
    try { $db->exec("ALTER TABLE schools ADD COLUMN $col"); } catch(Exception $e){}
}
foreach (['lat REAL', 'lng REAL'] as $col) {
    try { $db->exec("ALTER TABLE clusters ADD COLUMN $col"); } catch(Exception $e){}
}

$clusters = $payload['clusters'] ?? [];
$schools  = $payload['schools']  ?? [];

// Helper: extract a numeric lat or null from a payload value.
$num = function($v) {
    if ($v === null || $v === '' || $v === 0 || $v === '0') return null;
    $f = (float)$v;
    return ($f === 0.0) ? null : $f;
};

// Replace clusters atomically
$db->exec('BEGIN');
$db->exec('UPDATE schools SET cluster_id=NULL');
$db->exec('DELETE FROM clusters');
foreach ($clusters as $c) {
    $lat = $num($c['lat'] ?? null);
    $lng = $num($c['lng'] ?? null);
    $stmt = $db->prepare('INSERT INTO clusters (id, name, password_hash, lat, lng) VALUES (:id, :name, :hash, :lat, :lng)');
    $stmt->bindValue(':id',   (int)$c['id'],   SQLITE3_INTEGER);
    $stmt->bindValue(':name', $c['name'],      SQLITE3_TEXT);
    $stmt->bindValue(':hash', $c['hash'],      SQLITE3_TEXT);
    $stmt->bindValue(':lat',  $lat, $lat === null ? SQLITE3_NULL : SQLITE3_FLOAT);
    $stmt->bindValue(':lng',  $lng, $lng === null ? SQLITE3_NULL : SQLITE3_FLOAT);
    $stmt->execute();
}

// Upsert schools (update if exists by name, insert if new). lat/lng only
// overwritten when the payload actually provides a non-zero value, so manually
// placed pins on the cloud aren't wiped by a sync that has no coords.
foreach ($schools as $s) {
    $cid = $s['cluster_id'] ? (int)$s['cluster_id'] : null;
    $lat = $num($s['lat'] ?? null);
    $lng = $num($s['lng'] ?? null);

    $sets = ['county=:county', 'cluster_id=:cid', 'password_hash=:hash', 'is_active=:active'];
    if ($lat !== null) $sets[] = 'lat=:lat';
    if ($lng !== null) $sets[] = 'lng=:lng';
    $stmt = $db->prepare('UPDATE schools SET ' . implode(',', $sets) . ' WHERE name=:name');
    $stmt->bindValue(':county', $s['county'] ?? '',       SQLITE3_TEXT);
    $stmt->bindValue(':cid',    $cid, $cid ? SQLITE3_INTEGER : SQLITE3_NULL);
    $stmt->bindValue(':hash',   $s['hash'] ?? '',         SQLITE3_TEXT);
    $stmt->bindValue(':active', (int)($s['active'] ?? 1), SQLITE3_INTEGER);
    $stmt->bindValue(':name',   $s['name'],               SQLITE3_TEXT);
    if ($lat !== null) $stmt->bindValue(':lat', $lat, SQLITE3_FLOAT);
    if ($lng !== null) $stmt->bindValue(':lng', $lng, SQLITE3_FLOAT);
    $stmt->execute();

    if ($db->changes() === 0) {
        $stmt2 = $db->prepare('INSERT OR IGNORE INTO schools (name, county, cluster_id, password_hash, is_active, lat, lng) VALUES (:name, :county, :cid, :hash, :active, :lat, :lng)');
        $stmt2->bindValue(':name',   $s['name'],               SQLITE3_TEXT);
        $stmt2->bindValue(':county', $s['county'] ?? '',       SQLITE3_TEXT);
        $stmt2->bindValue(':cid',    $cid, $cid ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt2->bindValue(':hash',   $s['hash'] ?? '',         SQLITE3_TEXT);
        $stmt2->bindValue(':active', (int)($s['active'] ?? 1), SQLITE3_INTEGER);
        $stmt2->bindValue(':lat',    $lat, $lat === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt2->bindValue(':lng',    $lng, $lng === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt2->execute();
    }
}
$db->exec('COMMIT');

echo json_encode(['ok' => true, 'clusters' => count($clusters), 'schools' => count($schools)]);
