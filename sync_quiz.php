<?php
set_time_limit(120);
$target = '/home/cpmsfdav/public_html/data/arise.db';
$source = '/home/cpmsfdav/public_html/arise/data/arise.db';

$db = new SQLite3($target);
$db->exec('PRAGMA journal_mode=WAL;');
$db->exec('PRAGMA foreign_keys=OFF;');
echo "<pre>\n";

$db->exec("ATTACH DATABASE '$source' AS src");

$before = $db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE is_published=1");
echo "Before: $before questions\n";

$db->exec("INSERT OR IGNORE INTO quiz_questions
    (id,module_id,question_type,question,option_a,option_b,option_c,option_d,option_e,correct_option,explanation,sort_order,is_published,section,competency,difficulty)
    SELECT id,module_id,question_type,question,option_a,option_b,option_c,option_d,COALESCE(option_e,''),correct_option,explanation,sort_order,is_published,
           COALESCE(section,'lesson'),COALESCE(competency,''),COALESCE(difficulty,'MEDIUM')
    FROM src.quiz_questions WHERE is_published=1");

$after = $db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE is_published=1");
echo "After: $after questions\n";
echo "Added: ".($after-$before)." new questions\n";

// Show breakdown by module
$r = $db->query("SELECT module_id, COUNT(*) as cnt FROM quiz_questions WHERE is_published=1 GROUP BY module_id ORDER BY module_id");
echo "\nPer module:\n";
while($row=$r->fetchArray(SQLITE3_NUM)) echo "  module ".$row[0].": ".$row[1]." questions\n";

$db->exec("DETACH DATABASE src");
$db->close();
echo "\nDONE ✓\n</pre>";
unlink(__FILE__);
