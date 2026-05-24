<?php
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
$db->exec('PRAGMA journal_mode=WAL;');
$db->exec("INSERT OR IGNORE INTO lessons (id, module_id, title, slug, lesson_type, file_path, sort_order, is_active)
    VALUES (62, 8, 'Menstrual Health Video', 'menstrual-health-video', 'video', 'videos/arise_menstrual_health.mp4', 2, 1)");
$r = $db->query("SELECT id, slug, file_path FROM lessons WHERE module_id=8");
echo "<pre>\n";
while($row=$r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";
$db->close();
echo "</pre>";
unlink(__FILE__);
