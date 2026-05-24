<?php
// Quick DB diagnostic — self-deletes after running
$dbPath = __DIR__ . '/data/arise.db';
if (!file_exists($dbPath)) {
    $m = glob('/home/*/public_html/data/arise.db');
    if (!empty($m)) $dbPath = $m[0];
}
if (!file_exists($dbPath)) {
    $m = glob('/home/*/public_html/arise/data/arise.db');
    if (!empty($m)) $dbPath = $m[0];
}
echo "<pre>DB: $dbPath\n";
$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);

// quiz_attempts columns
echo "\n-- quiz_attempts columns:\n";
$r = $db->query("PRAGMA table_info(quiz_attempts)");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) echo "  {$row['name']} ({$row['type']})\n";

// sample quiz_attempts rows
echo "\n-- quiz_attempts sample (5 rows):\n";
$r = $db->query("SELECT * FROM quiz_attempts LIMIT 5");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) { echo "  "; print_r($row); }

// certificates count
echo "\n-- certificates count: ";
echo $db->querySingle("SELECT COUNT(*) FROM certificates") . "\n";

// students count
echo "-- students count: ";
echo $db->querySingle("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL") . "\n";

// device_sync_stats columns
echo "\n-- device_sync_stats columns:\n";
$r = $db->query("PRAGMA table_info(device_sync_stats)");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) echo "  {$row['name']} ({$row['type']})\n";

// device_sync_stats sample
echo "\n-- device_sync_stats sample (3 rows, key fields):\n";
$r = $db->query("SELECT school_name, learner_count, cert_count, quiz_count, avg_score, knowledge_gain, behavior_surveys FROM device_sync_stats LIMIT 3");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) { echo "  "; print_r($row); }

echo "</pre>";
// Self-delete
@unlink(__FILE__);
