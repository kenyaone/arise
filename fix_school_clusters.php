<?php
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
$db->exec('PRAGMA journal_mode=WAL;');
$sql = file_get_contents(__DIR__.'/fix_school_clusters.sql');
$db->exec($sql);
$linked = $db->querySingle("SELECT COUNT(*) FROM schools WHERE cluster_id IS NOT NULL AND cluster_id > 0 AND is_active=1");
$total  = $db->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1");
echo "<pre>Schools with cluster_id: $linked / $total\n";
$r = $db->query("SELECT c.name, COUNT(s.id) as cnt FROM clusters c LEFT JOIN schools s ON s.cluster_id=c.id AND s.is_active=1 GROUP BY c.id ORDER BY c.name");
while($row=$r->fetchArray(SQLITE3_NUM)) echo "  {$row[0]}: {$row[1]} projects\n";
$db->close();
echo "DONE ✓\n</pre>";
unlink(__DIR__.'/fix_school_clusters.php');
unlink(__DIR__.'/fix_school_clusters.sql');
