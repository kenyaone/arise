<?php
$db = new SQLite3('/home/cpmsfdav/public_html/arise/data/arise.db');
echo "<pre>\n=== REMOTE module 1 lessons ===\n";
$r = $db->query("SELECT id, title, slug, is_active, is_published FROM lessons WHERE module_id=1 ORDER BY sort_order, id");
while ($row = $r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";
$db->close();
echo "</pre>";
unlink(__FILE__);
