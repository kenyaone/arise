<?php
/**
 * Cloud seed 2026-08-02: place the 8 Meru-Isiolo Compassion CDCs onto
 * ariseci.org/locations.php (public MySQL map) so the projects are
 * visible to visitors and to Compassion staff before their field boxes
 * have started syncing.
 *
 * Each row is created with:
 *   - cluster_name = 'MERU_ISIOLO' (drives grouping on the map)
 *   - learner_count = 1 (single placeholder learner, no quizzes)
 *   - all other counters = 0
 *   - a synthetic device_id keyed to the Compassion code so a later real
 *     sync from that CDC will UPSERT into the same row (the cron_receiver
 *     does an UPDATE on device_id).
 *
 * Idempotent: re-running does not create duplicates because the schools
 * table has UNIQUE (device_id) and we UPSERT.
 *
 * Run once on the ariseci.org box:
 *     php ~/public_html/seed_meru_isiolo.php
 * (After the cpanel deploy hook has copied this file to public_html.)
 */

const CONFIG_PATH = '/home/cpmsfdav/cloud_db_config.php';

if (!is_file(CONFIG_PATH)) {
    fwrite(STDERR, "config missing: " . CONFIG_PATH . " — run this on the ariseci.org server\n");
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

// [code, name, county]
$sites = [
    ['KE 0785', 'MCK Ntumburi CDC',    'Meru'],
    ['KE 0704', 'MCK Tuiuia CDC',      'Meru'],
    ['KE 0792', 'MCK Nguushishi CDC',  'Meru'],
    ['KE 0786', 'MCK St. Mathews CDC', 'Meru'],
    ['KE 0314', 'EAPC Archers CDC',    'Isiolo'],
    ['KE 0713', 'MCK Kisima CDC',      'Isiolo'],
    ['KE 0251', 'FGCK Sere-Olipi CDC', 'Samburu'],
    ['KE 0830', 'MCK Ngarendare CDC',  'Laikipia'],
];

$sql = "INSERT INTO schools
    (device_id, name, county, is_active, cluster_name,
     learner_count, quiz_count, pretest_count, posttest_count,
     avg_score, cert_count, cert_rate, quiz_pass_count, quiz_pass_rate,
     lesson_completions, active_last_30_days,
     first_registration, latest_activity, last_sync_at)
    VALUES (?, ?, ?, 1, ?,
            1, 0, 0, 0,
            0, 0, 0, 0, 0,
            0, 0,
            NOW(), NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        cluster_name = VALUES(cluster_name),
        learner_count = GREATEST(learner_count, VALUES(learner_count)),
        is_active     = 1,
        last_sync_at  = NOW()";
$stmt = $mysqli->prepare($sql);
if (!$stmt) { fwrite(STDERR, "prepare: " . $mysqli->error . "\n"); exit(1); }

$inserted = 0; $updated = 0;
foreach ($sites as [$code, $name, $county]) {
    $deviceId = 'seed-' . strtolower(str_replace([' ', '.'], ['', ''], $code)) . '-meru-isiolo';
    $cluster  = 'MERU_ISIOLO';
    $stmt->bind_param('ssss', $deviceId, $name, $county, $cluster);
    $stmt->execute();
    if ($mysqli->affected_rows === 1) $inserted++;
    elseif ($mysqli->affected_rows === 2) $updated++;
    echo "  $code  $name  ($county)  device_id=$deviceId\n";
}
$stmt->close();
$mysqli->close();

echo "done. inserted=$inserted  updated=$updated\n";
echo "visit https://ariseci.org/locations.php to see the MERU_ISIOLO cluster.\n";
