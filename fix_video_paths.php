<?php
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
$db->exec('PRAGMA journal_mode=WAL;');
echo "<pre>\n";

$updates = [
    [24, 'videos/arise_drug_abuse.mp4'],
    [27, 'videos/arise_abstinence.mp4'],
    [28, 'videos/arise_adolescent_growth.mp4'],
    [36, 'videos/arise_healthy_relationships.mp4'],
    [37, 'videos/arise_mental_health.mp4'],
    [38, 'videos/arise_stds.mp4'],
    [39, 'videos/arise_social_media_srh.mp4'],
    [40, 'videos/arise_uti.mp4'],
    [41, 'videos/arise_gambling.mp4'],
    [43, 'videos/arise_career_decisions.mp4'],
    [44, 'videos/arise_cyber_crimes.mp4'],
];

foreach ($updates as [$id, $fp]) {
    $db->exec("UPDATE lessons SET file_path='$fp' WHERE id=$id");
    $full = '/home/cpmsfdav/public_html/data/uploads/'.$fp;
    $exists = file_exists($full) ? '✓' : '✗ FILE MISSING';
    echo "id=$id → $fp $exists\n";
}

$db->close();
echo "\nDONE ✓\n</pre>";
unlink(__FILE__);
