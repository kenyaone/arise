<?php
// Check what db() function uses
require_once dirname(__DIR__).'/includes/functions.php';
$db2 = db();
$c = $db2->querySingle("SELECT COUNT(*) FROM clusters");
$s = $db2->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1");
echo "<pre>DB path used by app functions\nClusters: $c\nActive schools: $s\n";
$r = $db2->query("SELECT id,name FROM clusters ORDER BY name");
while($row=$r->fetchArray(SQLITE3_NUM)) echo "  {$row[0]}: {$row[1]}\n";
echo "</pre>";
unlink(__FILE__);
