<?php
$db = new SQLite3('/home/cpmsfdav/public_html/arise/data/arise.db');
echo "<pre>\n";
echo "Clusters: ".$db->querySingle("SELECT COUNT(*) FROM clusters")."\n";
echo "Active schools: ".$db->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1")."\n";
// Check if cluster_id column exists
$r = $db->query("PRAGMA table_info(schools)");
$cols = [];
while($row=$r->fetchArray(SQLITE3_ASSOC)) $cols[]=$row['name'];
echo "Schools columns: ".implode(', ',$cols)."\n";
// Try the exact query register.php uses
try {
    $r2 = $db->query("SELECT s.id, s.name, s.cluster_id, COALESCE(c.name,'') AS cluster_name FROM schools s LEFT JOIN clusters c ON c.id=s.cluster_id WHERE s.is_active=1 ORDER BY s.name");
    $n=0; while($r2->fetchArray(SQLITE3_ASSOC)) $n++;
    echo "Register query returns: $n rows\n";
} catch(Exception $e) { echo "Query error: ".$e->getMessage()."\n"; }
$db->close();
echo "</pre>";
unlink(__FILE__);
