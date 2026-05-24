<?php
$dbPath = __DIR__ . '/data/arise.db';
if (!file_exists($dbPath)) { $m = glob('/home/*/public_html/data/arise.db'); if (!empty($m)) $dbPath = $m[0]; }
$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
echo "<pre>";

// Students school names
echo "-- Students school_name values:\n";
$r = $db->query("SELECT DISTINCT school_name, COUNT(*) as cnt FROM students WHERE deleted_at IS NULL GROUP BY school_name");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) echo "  [{$row['school_name']}] = {$row['cnt']}\n";

// Certificate holders
echo "\n-- Certificates (student -> school):\n";
$r = $db->query("SELECT c.id, s.school_name FROM certificates c JOIN students s ON s.id=c.student_id LIMIT 10");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) echo "  cert#{$row['id']} -> [{$row['school_name']}]\n";

// device_sync_stats school_names
echo "\n-- device_sync_stats school_names (distinct):\n";
$r = $db->query("SELECT DISTINCT school_name FROM device_sync_stats ORDER BY school_name LIMIT 20");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) echo "  [{$row['school_name']}]\n";

echo "</pre>";
@unlink(__FILE__);
