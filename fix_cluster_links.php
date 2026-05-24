<?php
$target = '/home/cpmsfdav/public_html/data/arise.db';
$source = '/home/cpmsfdav/public_html/arise/data/arise.db';
$db = new SQLite3($target);
$db->exec('PRAGMA journal_mode=WAL;');
echo "<pre>\n";

// Check current state
$linked = $db->querySingle("SELECT COUNT(*) FROM schools WHERE cluster_id IS NOT NULL AND cluster_id > 0 AND is_active=1");
$total  = $db->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1");
echo "Schools with cluster_id: $linked / $total\n";

// Attach source and update cluster_id for all schools
$db->exec("ATTACH DATABASE '$source' AS src");
$db->exec("UPDATE schools SET cluster_id = (SELECT cluster_id FROM src.schools WHERE src.schools.id = schools.id) WHERE id IN (SELECT id FROM src.schools)");
$db->exec("DETACH DATABASE src");

$linked2 = $db->querySingle("SELECT COUNT(*) FROM schools WHERE cluster_id IS NOT NULL AND cluster_id > 0 AND is_active=1");
echo "After fix — Schools with cluster_id: $linked2 / $total\n\n";

// Show per-cluster count
$r = $db->query("SELECT c.name, COUNT(s.id) as cnt FROM clusters c LEFT JOIN schools s ON s.cluster_id=c.id AND s.is_active=1 GROUP BY c.id ORDER BY c.name");
echo "Cluster breakdown:\n";
while($row=$r->fetchArray(SQLITE3_NUM)) echo "  {$row[0]}: {$row[1]} projects\n";

$db->close();
echo "\nDONE ✓\n</pre>";
unlink(__FILE__);
