<?php
/**
 * Cloud prune 2026-08-02: hide every school that is NOT in the MERU_ISIOLO
 * cluster from ariseci.org/locations.php, leaving only the 8 Meru cluster
 * CDCs visible on the public map.
 *
 * Safety:
 *   - Uses is_active=0 (soft delete) instead of DELETE FROM. Rows are
 *     preserved with all their historic learner_count / quiz data. Reverse
 *     with:  UPDATE schools SET is_active=1;
 *   - locations.php's WHERE clause is `is_active=1 AND last_sync_at IS NOT
 *     NULL`, so is_active=0 removes them from the map without touching sync
 *     records.
 *   - Prints a before/after snapshot so you can eyeball what changed.
 *
 * Run on the ariseci.org server:
 *     php ~/public_html/prune_to_meru_isiolo.php
 * (After the cpanel deploy hook has copied cloud/*.php to public_html.)
 */

const CONFIG_PATH = '/home/cpmsfdav/cloud_db_config.php';

if (!is_file(CONFIG_PATH)) {
    fwrite(STDERR, "config missing: " . CONFIG_PATH . " — run this on ariseci.org\n");
    exit(1);
}
$cfg = require CONFIG_PATH;

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = @new mysqli($cfg['host'] ?? 'localhost', $cfg['user'] ?? '', $cfg['pass'] ?? '', $cfg['db'] ?? '');
if ($mysqli->connect_errno) {
    fwrite(STDERR, "db connect: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

const KEEP_CLUSTER = 'MERU_ISIOLO';

// ── 1. Snapshot BEFORE ──────────────────────────────────────────────────────
echo "=== BEFORE ===\n";
$r = $mysqli->query("
    SELECT COALESCE(NULLIF(cluster_name,''),'(no cluster)') AS grp,
           COUNT(*) AS n_active
      FROM schools
     WHERE is_active=1 AND last_sync_at IS NOT NULL
     GROUP BY grp
     ORDER BY grp");
while ($row = $r->fetch_assoc()) {
    printf("  %-30s  %d visible on map\n", $row['grp'], $row['n_active']);
}
$r->free();

// ── 2. Soft-delete anything not in MERU_ISIOLO ─────────────────────────────
$stmt = $mysqli->prepare(
    "UPDATE schools
        SET is_active = 0
      WHERE (cluster_name IS NULL OR cluster_name <> ?)
        AND is_active = 1");
$stmt->bind_param('s', $keep);
$keep = KEEP_CLUSTER;
$stmt->execute();
$hidden = $stmt->affected_rows;
$stmt->close();

// Belt-and-braces: make sure the MERU_ISIOLO rows are active.
$stmt = $mysqli->prepare(
    "UPDATE schools
        SET is_active = 1
      WHERE cluster_name = ?
        AND is_active = 0");
$stmt->bind_param('s', $keep);
$stmt->execute();
$reactivated = $stmt->affected_rows;
$stmt->close();

echo "\n=== PRUNE ===\n";
echo "  hidden (is_active 1→0): $hidden\n";
echo "  re-shown (is_active 0→1 within MERU_ISIOLO): $reactivated\n";

// ── 3. Snapshot AFTER + row-level detail for MERU_ISIOLO ───────────────────
echo "\n=== AFTER ===\n";
$r = $mysqli->query("
    SELECT COALESCE(NULLIF(cluster_name,''),'(no cluster)') AS grp,
           COUNT(*) AS n_active
      FROM schools
     WHERE is_active=1 AND last_sync_at IS NOT NULL
     GROUP BY grp
     ORDER BY grp");
while ($row = $r->fetch_assoc()) {
    printf("  %-30s  %d visible on map\n", $row['grp'], $row['n_active']);
}
$r->free();

echo "\n=== MERU_ISIOLO projects now on the map ===\n";
$r = $mysqli->query("
    SELECT name, county, learner_count, quiz_count,
           IFNULL(DATE_FORMAT(last_sync_at,'%Y-%m-%d %H:%i'),'—') AS last_sync
      FROM schools
     WHERE is_active=1 AND cluster_name = '" . $mysqli->real_escape_string(KEEP_CLUSTER) . "'
     ORDER BY name");
$total = 0;
while ($row = $r->fetch_assoc()) {
    printf("  %-24s %-10s learners=%-4d quizzes=%-4d last_sync=%s\n",
        $row['name'], $row['county'], $row['learner_count'],
        $row['quiz_count'], $row['last_sync']);
    $total++;
}
$r->free();
echo "  ─────────\n  total: $total\n";

$mysqli->close();
echo "\nOpen https://ariseci.org/locations.php to eyeball the map.\n";
echo "To reverse: UPDATE schools SET is_active=1;\n";
