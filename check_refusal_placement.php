<?php
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
echo "<pre>\n=== LESSON ORDER PER MODULE (as students see them) ===\n\n";
$mods = $db->query("SELECT id, title FROM modules WHERE is_active=1 ORDER BY id");
while ($m = $mods->fetchArray(SQLITE3_ASSOC)) {
    $mid = $m['id'];
    $r = $db->query("SELECT slug, lesson_type, sort_order, title FROM lessons WHERE module_id=$mid AND is_active=1 ORDER BY sort_order, id");
    $rows = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    if (!$rows) continue;
    $hasRefusal = array_filter($rows, fn($x) => strpos($x['slug'],'refusal')!==false);
    $marker = $hasRefusal ? '✓' : '✗ NO REFUSAL';
    echo "Module {$mid}: {$m['title']} $marker\n";
    foreach ($rows as $pos => $row) {
        $isRef = strpos($row['slug'],'refusal')!==false;
        $flag = $isRef ? ' ◄ REFUSAL SKILLS' : '';
        printf("  %d. [sort=%d] %-12s %s%s\n", $pos+1, $row['sort_order'], $row['lesson_type'], $row['slug'], $flag);
    }
    echo "\n";
}
$db->close();
echo "</pre>";
unlink(__FILE__);
