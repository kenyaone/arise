<?php
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
echo "<pre>\n=== COMMUNITY PANEL CHECK — ALL INTERACTIVE LESSONS ===\n\n";

$lessons = $db->query("
    SELECT l.slug, l.title, l.file_path, m.title AS mod_title
    FROM lessons l JOIN modules m ON m.id=l.module_id
    WHERE l.lesson_type='interactive' AND l.is_active=1
    AND l.slug NOT LIKE 'refusal%'
    ORDER BY m.id, l.sort_order
");

$pass = 0; $fail = 0; $issues = [];

while ($l = $lessons->fetchArray(SQLITE3_ASSOC)) {
    $url = 'https://ariseci.org/arise/?p=lesson&slug=' . urlencode($l['slug']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => 0,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $checks = [
        'panel_div'      => strpos($body, 'arise-community') !== false,
        'score_box'      => strpos($body, 'score-box') !== false,
        'mutation_obs'   => strpos($body, 'MutationObserver') !== false,
        'set_interval'   => strpos($body, 'setInterval') !== false,
        'discuss_link'   => strpos($body, 'Discuss this Module') !== false,
        'anon_link'      => strpos($body, 'Ask Anonymously') !== false,
        'star_rating'    => strpos($body, 'star-row') !== false,
        'lesson_id_set'  => strpos($body, 'ARISE_LESSON_ID=') !== false,
        'mod_title_ok'   => strpos($body, 'How was') !== false && strpos($body, 'drugs-substance-abuse') === false,
        'file_served'    => ($http === 200),
    ];

    $ok = !in_array(false, $checks);
    if ($ok) $pass++; else $fail++;

    $failed_keys = array_keys(array_filter($checks, fn($v) => !$v));
    $status = $ok ? '✓ ALL OK' : '✗ FAIL: '.implode(', ', $failed_keys);
    printf("%-45s %s\n", substr($l['mod_title'].' / '.$l['title'], 0, 45), $status);
    if (!$ok) $issues[] = ['lesson' => $l['slug'], 'fails' => $failed_keys];
}

echo "\n--- SUMMARY ---\n";
echo "PASSED: $pass  FAILED: $fail\n";
if ($issues) {
    echo "\nISSUES:\n";
    foreach ($issues as $i) echo "  ".$i['lesson'].": ".implode(', ',$i['fails'])."\n";
}
$db->close();
echo "</pre>";
unlink(__FILE__);
