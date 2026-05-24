<?php
// Audit the REAL live database
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
echo "<pre>\n";

echo "=== MODULES ===\n";
$r = $db->query("SELECT id, title FROM modules ORDER BY id");
while($row=$r->fetchArray(SQLITE3_NUM)) echo $row[0]."|".$row[1]."\n";

echo "\n=== LESSONS per module ===\n";
$r = $db->query("SELECT m.id, m.title, COUNT(l.id) as cnt FROM modules m LEFT JOIN lessons l ON l.module_id=m.id AND l.is_active=1 GROUP BY m.id ORDER BY m.id");
while($row=$r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";

echo "\n=== QUIZ QUESTIONS ===\n";
$total = $db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE is_published=1");
echo "Total: $total\n";

echo "\n=== CLUSTERS ===\n";
try { $c=$db->querySingle("SELECT COUNT(*) FROM clusters"); echo "Count: $c\n"; } catch(Exception $e){ echo "Table missing\n"; }

echo "\n=== SCHOOLS ===\n";
try { $s=$db->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1"); echo "Active: $s\n"; } catch(Exception $e){ echo "Table missing\n"; }

echo "\n=== REFUSAL SKILLS LESSONS ===\n";
try {
    $r=$db->query("SELECT module_id,slug FROM lessons WHERE slug LIKE 'refusal%' AND is_active=1");
    while($row=$r->fetchArray(SQLITE3_NUM)) echo implode('|',$row)."\n";
} catch(Exception $e){ echo "Error: ".$e->getMessage()."\n"; }

$db->close();
echo "</pre>";
unlink(__FILE__);
