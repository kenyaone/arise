<?php
$target = '/home/cpmsfdav/public_html/data/arise.db';
$source = '/home/cpmsfdav/public_html/arise/data/arise.db';
echo "<pre>\n";

$db = new SQLite3($target);
echo "=== TARGET DB ===\n";
echo "Lessons active: ".$db->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1")."\n";
echo "Quiz questions: ".$db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE is_published=1")."\n";

// Check lesson schema
$r = $db->query("PRAGMA table_info(lessons)");
echo "\nLessons columns: ";
$cols = [];
while($row=$r->fetchArray(SQLITE3_ASSOC)) $cols[] = $row['name'];
echo implode(', ', $cols)."\n";

// Check quiz_questions schema
$r = $db->query("PRAGMA table_info(quiz_questions)");
echo "Quiz_questions columns: ";
$cols = [];
while($row=$r->fetchArray(SQLITE3_ASSOC)) $cols[] = $row['name'];
echo implode(', ', $cols)."\n";

// Check clusters schema
try {
    $r = $db->query("PRAGMA table_info(clusters)");
    echo "Clusters columns: ";
    $cols = [];
    while($row=$r->fetchArray(SQLITE3_ASSOC)) $cols[] = $row['name'];
    echo implode(', ', $cols)."\n";
} catch(Exception $e) { echo "clusters table error: ".$e->getMessage()."\n"; }

// Check what lessons exist in target
$r = $db->query("SELECT id, slug FROM lessons ORDER BY id");
echo "\nTarget lessons:\n";
while($row=$r->fetchArray(SQLITE3_NUM)) echo $row[0]."|".$row[1]."\n";

$db->close();

// Now attach source and find missing lessons
$db = new SQLite3($target);
$db->exec("ATTACH DATABASE '$source' AS src");
echo "\nMissing lessons (in src not in target):\n";
$r = $db->query("SELECT id, slug FROM src.lessons WHERE is_active=1 AND id NOT IN (SELECT id FROM lessons)");
while($row=$r->fetchArray(SQLITE3_NUM)) echo $row[0]."|".$row[1]."\n";

echo "\nClusters in source: ".$db->querySingle("SELECT COUNT(*) FROM src.clusters")."\n";
$db->exec("DETACH DATABASE src");
$db->close();
echo "</pre>";
unlink(__FILE__);
