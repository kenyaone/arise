<?php
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
echo "<pre>\n=== INTERACTIVE LESSONS ===\n";
$r = $db->query("SELECT id, module_id, slug, file_path FROM lessons WHERE lesson_type='interactive' AND is_active=1 ORDER BY module_id, id");
while($row=$r->fetchArray(SQLITE3_ASSOC)) {
    $fp = $row['file_path'];
    $fullPath = '/home/cpmsfdav/public_html/data/uploads/'.$fp;
    $exists = file_exists($fullPath) ? '✓' : '✗ MISSING';
    echo $row['id'].'|'.$row['module_id'].'|'.$row['slug'].'|'.$fp.' '.$exists."\n";
}
echo "\n=== VIDEO LESSONS ===\n";
$r = $db->query("SELECT id, module_id, slug, file_path FROM lessons WHERE lesson_type='video' AND is_active=1 ORDER BY module_id, id");
while($row=$r->fetchArray(SQLITE3_ASSOC)) {
    $fp = $row['file_path'];
    $fullPath = '/home/cpmsfdav/public_html/data/uploads/'.$fp;
    $exists = file_exists($fullPath) ? '✓' : '✗ MISSING';
    echo $row['id'].'|'.$row['module_id'].'|'.$row['slug'].'|'.$fp.' '.$exists."\n";
}
$db->close();
echo "</pre>";
unlink(__FILE__);
