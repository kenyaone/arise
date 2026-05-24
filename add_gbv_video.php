<?php
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
$db->exec('PRAGMA journal_mode=WAL;');
$db->exec("INSERT OR IGNORE INTO lessons (id, module_id, title, slug, lesson_type, file_path, sort_order, is_active)
    VALUES (63, 4, 'GBV Video', 'gbv-video', 'video', 'videos/arise_gbv.mp4', 3, 1)");
echo "<pre>Module 4 lessons:\n";
$r = $db->query("SELECT id, slug, lesson_type, file_path FROM lessons WHERE module_id=4");
while($row=$r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";
$fp = '/home/cpmsfdav/public_html/data/uploads/videos/arise_gbv.mp4';
echo "\nFile on server: ".(file_exists($fp)?'✓ present':'✗ not yet (upload in progress)')."\n";
$db->close();
echo "</pre>";
unlink(__FILE__);
