<?php
$db = new SQLite3('/home/cpmsfdav/public_html/arise/data/arise.db');
echo "<pre>\n";
$r = $db->query("SELECT * FROM lessons WHERE id=42");
$row = $r->fetchArray(SQLITE3_ASSOC);
foreach ($row as $k=>$v) echo "$k: $v\n";
$db->close();
echo "</pre>";
unlink(__FILE__);
