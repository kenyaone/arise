<?php
/**
 * ARISE Cluster Orphan Repair — one-off cloud maintenance script.
 *
 * Fixes the state left behind by the old arise_cluster_receiver.php bug where
 * clone syncs wiped the clusters table but left schools.cluster_id pointing at
 * now-missing cluster rows. Inserts a placeholder cluster row for each orphan
 * id, using the county of the schools attached to it as the cluster name (or
 * "Cluster <id>" if no county is available).
 *
 * USAGE (browser):
 *   https://ariseci.org/arise/arise_cluster_repair.php?secret=<SECRET>&dry=1
 *   https://ariseci.org/arise/arise_cluster_repair.php?secret=<SECRET>&apply=1
 *
 * A master push (pushClusterDefinitions) run after this will overwrite these
 * stubs with real cluster names — the stubs only exist to unblock the register
 * form in the meantime.
 */
define('SYNC_SECRET', 'arise_sync_k3nya_2026');

header('Content-Type: application/json');

if (($_GET['secret'] ?? '') !== SYNC_SECRET) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit;
}

$dry   = isset($_GET['dry'])   && $_GET['dry']   !== '0';
$apply = isset($_GET['apply']) && $_GET['apply'] !== '0';
if (!$dry && !$apply) {
    echo json_encode(['ok' => false, 'error' => 'Pass ?dry=1 to preview or ?apply=1 to write.']); exit;
}

// Locate DB — same probe order as arise_cluster_receiver.php.
$dbPath = dirname(__DIR__) . '/data/arise.db';
if (!file_exists($dbPath)) $dbPath = __DIR__ . '/data/arise.db';
if (!file_exists($dbPath)) {
    foreach (['/home/*/public_html/data/arise.db', '/home/*/public_html/arise/data/arise.db'] as $g) {
        $m = glob($g); if (!empty($m)) { $dbPath = $m[0]; break; }
    }
}
if (!file_exists($dbPath)) {
    echo json_encode(['ok' => false, 'error' => 'DB not found']); exit;
}

$db = new SQLite3($dbPath, $apply ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
$db->busyTimeout(5000);

// Find orphan cluster_ids on schools — cluster_id set but no matching row in clusters.
$orphans = [];
$r = $db->query("
    SELECT s.cluster_id AS cid,
           COUNT(*)     AS school_count,
           GROUP_CONCAT(DISTINCT COALESCE(NULLIF(s.county,''), '')) AS counties,
           GROUP_CONCAT(s.name, ' | ') AS sample_names
    FROM schools s
    LEFT JOIN clusters c ON c.id = s.cluster_id
    WHERE s.cluster_id IS NOT NULL
      AND s.is_active = 1
      AND c.id IS NULL
    GROUP BY s.cluster_id
    ORDER BY s.cluster_id
");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    // Pick a name: first non-empty county, else fallback.
    $counties = array_filter(array_map('trim', explode(',', $row['counties'] ?? '')));
    $name = $counties ? reset($counties) : ('Cluster ' . (int)$row['cid']);

    // Guard against name collision with an existing cluster row.
    $existing = (int)$db->querySingle(
        "SELECT COUNT(*) FROM clusters WHERE name='" . SQLite3::escapeString($name) . "'"
    );
    if ($existing > 0) $name .= ' #' . (int)$row['cid'];

    $orphans[] = [
        'cluster_id'   => (int)$row['cid'],
        'school_count' => (int)$row['school_count'],
        'proposed_name'=> $name,
        'sample'       => substr((string)$row['sample_names'], 0, 200),
    ];
}

$result = ['ok' => true, 'db' => $dbPath, 'mode' => $apply ? 'apply' : 'dry', 'orphans' => $orphans];

if ($apply && $orphans) {
    $db->exec('BEGIN');
    $inserted = 0;
    $stmt = $db->prepare(
        'INSERT INTO clusters (id, name, password_hash) VALUES (:id, :name, :hash)'
    );
    foreach ($orphans as $o) {
        // Empty hash — real cluster password is unknown here; master push will overwrite.
        $stmt->bindValue(':id',   $o['cluster_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':name', $o['proposed_name'], SQLITE3_TEXT);
        $stmt->bindValue(':hash', '', SQLITE3_TEXT);
        try { $stmt->execute(); $inserted++; }
        catch (Exception $e) { $result['errors'][] = ['cid' => $o['cluster_id'], 'msg' => $e->getMessage()]; }
    }
    $db->exec('COMMIT');
    $result['inserted'] = $inserted;
}

echo json_encode($result, JSON_PRETTY_PRINT);
