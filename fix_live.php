<?php
// Fix 1: Create uploads symlink so /arise/uploads/ → /arise/data/uploads/
$target = '/home/cpmsfdav/public_html/arise/data/uploads';
$link   = '/home/cpmsfdav/public_html/arise/uploads';
if (is_link($link)) {
    echo "uploads symlink: already exists ✓\n";
} elseif (file_exists($link)) {
    echo "uploads: ERROR — real directory exists at $link, cannot symlink\n";
} else {
    echo "uploads symlink: " . (symlink($target, $link) ? "created ✓" : "FAILED ✗") . "\n";
}

// Fix 2: Add GBV video lesson for module 4 (only if video file exists)
$db = new SQLite3('/home/cpmsfdav/public_html/arise/data/arise.db');
$gbvVideo = $db->querySingle("SELECT id FROM lessons WHERE module_id=4 AND lesson_type='video' AND is_active=1");
if ($gbvVideo) {
    echo "GBV video lesson: already exists (id=$gbvVideo) ✓\n";
} else {
    // Check if video file exists
    $videoPath = '/home/cpmsfdav/public_html/arise/data/uploads/videos/arise_gbv.mp4';
    if (file_exists($videoPath)) {
        $db->exec("INSERT INTO lessons (module_id, title, slug, lesson_type, file_path, sort_order, is_active, is_published) VALUES (4,'Gender-Based Violence','gbv-video','video','videos/arise_gbv.mp4',10,1,1)");
        echo "GBV video lesson: inserted ✓\n";
    } else {
        echo "GBV video lesson: no video file found — skipped\n";
    }
}

// Verify refusal skills all present on remote
$refusalCount = $db->querySingle("SELECT COUNT(*) FROM lessons WHERE slug LIKE 'refusal-%'");
echo "Refusal skills lessons on remote: $refusalCount\n";

// Show all lessons
$r = $db->query("SELECT l.module_id, m.title, l.slug FROM lessons l JOIN modules m ON l.module_id=m.id WHERE l.slug LIKE 'refusal%' ORDER BY l.module_id");
while ($row = $r->fetchArray(SQLITE3_NUM)) {
    echo "  module {$row[0]} ({$row[1]}): {$row[2]}\n";
}

$db->close();
unlink(__FILE__);
