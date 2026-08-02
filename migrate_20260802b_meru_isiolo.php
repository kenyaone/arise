<?php
/**
 * Migration 2026-08-02b: seed MERU_ISIOLO cluster + 8 CDCs into the ARISE
 * SQLite DB so the cluster manager can log in at /arise/?p=map with the
 * cluster password and see his 8 projects.
 *
 * Cluster: MERU_ISIOLO   password: 12345678  (sha256 hashed)
 * Sites (Compassion FCP codes from Meru cluster site visit 29 July 2026):
 *   KE 0785 MCK Ntumburi CDC       KE 0704 MCK Tuiuia CDC
 *   KE 0792 MCK Nguushishi CDC     KE 0786 MCK St. Mathews CDC
 *   KE 0314 EAPC Archers CDC       KE 0713 MCK Kisima CDC
 *   KE 0251 FGCK Sere-Olipi CDC    KE 0830 MCK Ngarendare CDC
 *
 * Each site is seeded with ONE placeholder learner and NO quiz attempts.
 * Coordinates are left NULL — will be filled in either by real device
 * syncs from the sites or by an admin edit later.
 *
 * Idempotent: uses INSERT OR IGNORE keyed on unique names.
 */

require_once __DIR__ . '/includes/config.php';
$db = db();

// ── 1. Cluster ──────────────────────────────────────────────────────────────
$clusterName = 'MERU_ISIOLO';
$clusterPw   = '12345678';
$hash        = hash('sha256', $clusterPw);

$stmt = $db->prepare("INSERT OR IGNORE INTO clusters (name, password_hash) VALUES (:n, :h)");
$stmt->bindValue(':n', $clusterName, SQLITE3_TEXT);
$stmt->bindValue(':h', $hash,        SQLITE3_TEXT);
$stmt->execute();

// If the cluster already existed, update its password to the requested one.
$upd = $db->prepare("UPDATE clusters SET password_hash=:h WHERE name=:n");
$upd->bindValue(':h', $hash,        SQLITE3_TEXT);
$upd->bindValue(':n', $clusterName, SQLITE3_TEXT);
$upd->execute();

$clusterId = (int) $db->querySingle("SELECT id FROM clusters WHERE name='$clusterName'");
if ($clusterId <= 0) { die("failed to create/find cluster\n"); }
echo "cluster $clusterName id=$clusterId, password set\n";

// ── 2. Schools ──────────────────────────────────────────────────────────────
// [Compassion code, school name, county]  county is best-effort; Compassion
// FCP branding groups them under MERU_ISIOLO regardless of civil county.
$sites = [
    ['KE 0785', 'MCK Ntumburi CDC',     'Meru'],
    ['KE 0704', 'MCK Tuiuia CDC',       'Meru'],
    ['KE 0792', 'MCK Nguushishi CDC',   'Meru'],
    ['KE 0786', 'MCK St. Mathews CDC',  'Meru'],
    ['KE 0314', 'EAPC Archers CDC',     'Isiolo'],
    ['KE 0713', 'MCK Kisima CDC',       'Isiolo'],
    ['KE 0251', 'FGCK Sere-Olipi CDC',  'Samburu'],
    ['KE 0830', 'MCK Ngarendare CDC',   'Laikipia'],
];

$sInsert = $db->prepare(
    "INSERT OR IGNORE INTO schools (name, county, is_active, cluster_id)
     VALUES (:name, :county, 1, :cid)");
$sBind   = $db->prepare(
    "UPDATE schools SET cluster_id=:cid, county=:county, is_active=1
     WHERE name=:name AND (cluster_id IS NULL OR cluster_id=:cid)");

foreach ($sites as [$code, $name, $county]) {
    $sInsert->bindValue(':name',   $name,      SQLITE3_TEXT);
    $sInsert->bindValue(':county', $county,    SQLITE3_TEXT);
    $sInsert->bindValue(':cid',    $clusterId, SQLITE3_INTEGER);
    $sInsert->execute(); $sInsert->reset();

    $sBind->bindValue(':cid',    $clusterId, SQLITE3_INTEGER);
    $sBind->bindValue(':county', $county,    SQLITE3_TEXT);
    $sBind->bindValue(':name',   $name,      SQLITE3_TEXT);
    $sBind->execute(); $sBind->reset();

    echo "  school $code $name ($county)\n";
}

// ── 3. One placeholder learner per school (no quizzes) ──────────────────────
$stInsert = $db->prepare(
    "INSERT OR IGNORE INTO students
     (full_name, school_name, class_name, session_hash, is_active,
      language_pref, text_size, notifications)
     VALUES (:full, :sch, :cls, :hash, 1, 'en', 'md', '[]')");

foreach ($sites as [$code, $name, $county]) {
    $full = 'Placeholder Learner';
    $cls  = 'CDC Group';
    $sess = 'seed-' . strtolower(str_replace([' ', '.'], ['-', ''], $name));
    $stInsert->bindValue(':full', $full, SQLITE3_TEXT);
    $stInsert->bindValue(':sch',  $name, SQLITE3_TEXT);
    $stInsert->bindValue(':cls',  $cls,  SQLITE3_TEXT);
    $stInsert->bindValue(':hash', $sess, SQLITE3_TEXT);
    $stInsert->execute(); $stInsert->reset();
    echo "  learner seeded for $name\n";
}

echo "done. cluster manager can now log in at /arise/?p=map with:\n";
echo "  cluster name: $clusterName\n";
echo "  password:     $clusterPw\n";
