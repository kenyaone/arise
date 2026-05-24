<?php
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
echo "<pre>\n=== LIVE DB SUMMARY ===\n";
echo "Modules: ".$db->querySingle("SELECT COUNT(*) FROM modules WHERE is_active=1")."\n";
echo "Lessons active: ".$db->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1")."\n";
echo "Quiz questions: ".$db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE is_published=1")."\n";
echo "Clusters: ".$db->querySingle("SELECT COUNT(*) FROM clusters")."\n";
echo "Schools active: ".$db->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1")."\n";

echo "\n=== VIDEO FILE CHECK ===\n";
$r = $db->query("SELECT l.module_id, l.slug, l.file_path FROM lessons l WHERE l.lesson_type='video' AND l.is_active=1 ORDER BY l.module_id");
while($row=$r->fetchArray(SQLITE3_ASSOC)) {
    $fp = $row['file_path'];
    $full = '/home/cpmsfdav/public_html/data/uploads/'.$fp;
    $exists = file_exists($full) ? '✓' : '✗ MISSING';
    echo "mod".$row['module_id'].' '.$row['slug'].' → '.$fp.' '.$exists."\n";
}

echo "\n=== INTERACTIVE FILE CHECK ===\n";
$r = $db->query("SELECT l.module_id, l.slug, l.file_path FROM lessons l WHERE l.lesson_type='interactive' AND l.is_active=1 ORDER BY l.module_id, l.id");
$missing = 0;
while($row=$r->fetchArray(SQLITE3_ASSOC)) {
    $fp = $row['file_path'];
    $full = '/home/cpmsfdav/public_html/data/uploads/'.$fp;
    if (!file_exists($full)) {
        echo "✗ MISSING: mod".$row['module_id'].' '.$row['slug'].' → '.$fp."\n";
        $missing++;
    }
}
echo ($missing === 0 ? "All interactive files present ✓\n" : "$missing files missing\n");

$db->close();
echo "</pre>";
unlink(__FILE__);
