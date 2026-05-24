<?php
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
echo "<pre>\n";
echo "PRAGMA table_info(schools):\n";
$r = $db->query("PRAGMA table_info(schools)");
while($row=$r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";
echo "\nSample schools (id, name, cluster_id):\n";
$r = $db->query("SELECT id,name,cluster_id FROM schools ORDER BY id LIMIT 20");
while($row=$r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";
$db->close();
echo "</pre>";
unlink(__FILE__);
