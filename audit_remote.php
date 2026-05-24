<?php
error_reporting(E_ALL); ini_set('display_errors', 1);
$db = new SQLite3('/home/cpmsfdav/public_html/arise/data/arise.db');

echo "<pre>\n";

echo "=== MODULES ===\n";
$r = $db->query("SELECT id, title FROM modules ORDER BY id");
while ($row = $r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";

echo "\n=== LESSONS PER MODULE ===\n";
$r = $db->query("SELECT m.id, m.title, COUNT(l.id) as lessons, SUM(l.is_published) as pub FROM modules m LEFT JOIN lessons l ON l.module_id=m.id AND l.is_active=1 GROUP BY m.id ORDER BY m.id");
while ($row = $r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";

echo "\n=== QUIZ QUESTIONS PER MODULE/SECTION ===\n";
$r = $db->query("SELECT module_id, section, COUNT(*) as cnt FROM quiz_questions WHERE is_published=1 GROUP BY module_id, section ORDER BY module_id, section");
while ($row = $r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";

echo "\n=== TOTALS ===\n";
echo "modules: ".$db->querySingle("SELECT COUNT(*) FROM modules")."\n";
echo "lessons: ".$db->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1")."\n";
echo "quiz_questions: ".$db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE is_published=1")."\n";

echo "\n=== VIDEO LESSONS ===\n";
$r = $db->query("SELECT l.id, l.module_id, l.title, l.file_path FROM lessons l WHERE l.lesson_type='video' AND l.is_active=1 ORDER BY l.module_id");
while ($row = $r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";

$db->close();
echo "</pre>\n";
unlink(__FILE__);
