<?php
$db = new SQLite3('/home/cpmsfdav/public_html/data/arise.db');
$up = '/home/cpmsfdav/public_html/data/uploads/';
$ok = '✓'; $no = '✗';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<style>
body{font-family:monospace;font-size:13px;padding:20px;background:#f8f9fa;}
h2{color:#1a1a2e;border-bottom:2px solid #7c3aed;padding-bottom:4px;}
table{border-collapse:collapse;width:100%;margin-bottom:24px;}
th{background:#7c3aed;color:white;padding:6px 10px;text-align:left;}
td{padding:5px 10px;border-bottom:1px solid #e5e7eb;}
tr:hover td{background:#f3f4f6;}
.ok{color:#16a34a;font-weight:700;}
.no{color:#dc2626;font-weight:700;}
.na{color:#9ca3af;}
</style></head><body>\n";

// ── 1. MODULES + LESSON COUNTS ────────────────────────────────────────────
echo "<h2>1. Modules & Lesson Counts</h2>";
echo "<table><tr><th>ID</th><th>Module</th><th>Interactive</th><th>Video</th><th>Refusal</th><th>Quiz Qs</th></tr>\n";
$mods = $db->query("SELECT id, title FROM modules WHERE is_active=1 ORDER BY id");
while ($m = $mods->fetchArray(SQLITE3_ASSOC)) {
    $mid = $m['id'];
    $inter  = $db->querySingle("SELECT COUNT(*) FROM lessons WHERE module_id=$mid AND lesson_type='interactive' AND slug NOT LIKE 'refusal%' AND is_active=1");
    $vid    = $db->querySingle("SELECT COUNT(*) FROM lessons WHERE module_id=$mid AND lesson_type='video' AND is_active=1");
    $ref    = $db->querySingle("SELECT COUNT(*) FROM lessons WHERE module_id=$mid AND slug LIKE 'refusal%' AND is_active=1");
    $quizq  = $db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE module_id=$mid AND is_published=1");
    echo "<tr><td>$mid</td><td>{$m['title']}</td><td>$inter</td><td>$vid</td><td>$ref</td><td>$quizq</td></tr>\n";
}
echo "</table>\n";

// ── 2. INTERACTIVE FILES ─────────────────────────────────────────────────
echo "<h2>2. Interactive Lessons — File Check</h2>";
echo "<table><tr><th>Mod</th><th>Slug</th><th>File</th><th>Status</th></tr>\n";
$r = $db->query("SELECT l.module_id, l.slug, l.file_path, m.title FROM lessons l JOIN modules m ON m.id=l.module_id WHERE l.lesson_type='interactive' AND l.is_active=1 ORDER BY l.module_id, l.id");
$missingI = 0;
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $exists = file_exists($up.$row['file_path']);
    if (!$exists) $missingI++;
    $st = $exists ? "<span class='ok'>$ok FILE OK</span>" : "<span class='no'>$no MISSING</span>";
    echo "<tr><td>{$row['module_id']}</td><td>{$row['slug']}</td><td>{$row['file_path']}</td><td>$st</td></tr>\n";
}
echo "</table>\n";

// ── 3. VIDEO FILES ───────────────────────────────────────────────────────
echo "<h2>3. Video Lessons — File Check</h2>";
echo "<table><tr><th>Mod</th><th>Slug</th><th>File</th><th>Status</th></tr>\n";
$r = $db->query("SELECT l.module_id, l.slug, l.file_path, m.title FROM lessons l JOIN modules m ON m.id=l.module_id WHERE l.lesson_type='video' AND l.is_active=1 ORDER BY l.module_id");
$missingV = 0;
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $exists = file_exists($up.$row['file_path']);
    if (!$exists) $missingV++;
    $st = $exists ? "<span class='ok'>$ok FILE OK</span>" : "<span class='no'>$no MISSING</span>";
    echo "<tr><td>{$row['module_id']}</td><td>{$row['slug']}</td><td>{$row['file_path']}</td><td>$st</td></tr>\n";
}
echo "</table>\n";

// ── 4. REGISTRATION (clusters + schools) ─────────────────────────────────
echo "<h2>4. Registration Data</h2>";
echo "<table><tr><th>Cluster</th><th>Schools</th></tr>\n";
$r = $db->query("SELECT c.name, COUNT(s.id) as cnt FROM clusters c LEFT JOIN schools s ON s.cluster_id=c.id AND s.is_active=1 GROUP BY c.id ORDER BY c.name");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
    $st = $row['cnt'] > 0 ? "<span class='ok'>{$row['cnt']} projects</span>" : "<span class='no'>0 projects</span>";
    echo "<tr><td>{$row['name']}</td><td>$st</td></tr>\n";
}
$totalClusters = $db->querySingle("SELECT COUNT(*) FROM clusters");
$totalSchools  = $db->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1");
echo "<tr><td><strong>TOTAL</strong></td><td><strong>$totalClusters clusters, $totalSchools projects</strong></td></tr>\n";
echo "</table>\n";

// ── 5. COMMUNITY PANEL (injected into lessons) ───────────────────────────
echo "<h2>5. Community Panel Injection</h2>";
$testSlug = 'cannabis-bhang';
$ch = curl_init("https://ariseci.org/arise/?p=lesson&slug=$testSlug");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_TIMEOUT=>10]);
$body = curl_exec($ch); curl_close($ch);
echo "<table><tr><th>Check</th><th>Status</th></tr>\n";
$checks = [
    'arise-community div present'     => strpos($body,'arise-community') !== false,
    'fetch override (save_quiz_score)' => strpos($body,'save_quiz_score') !== false,
    'setTimeout trigger'              => strpos($body,'setTimeout') !== false,
    'Discuss this Module link'        => strpos($body,'Discuss this Module') !== false,
    'Ask Anonymously link'            => strpos($body,'Ask Anonymously') !== false,
    'Star rating form'                => strpos($body,'star-row') !== false,
    'ARISE_LESSON_ID set'             => strpos($body,'ARISE_LESSON_ID=') !== false,
    'ARISE_VIDEO_URL set'             => strpos($body,'ARISE_VIDEO_URL=') !== false,
];
foreach ($checks as $label => $pass) {
    $st = $pass ? "<span class='ok'>$ok YES</span>" : "<span class='no'>$no NOT FOUND</span>";
    echo "<tr><td>$label</td><td>$st</td></tr>\n";
}
echo "</table>\n";

// ── 6. REFUSAL SKILLS — no video ─────────────────────────────────────────
echo "<h2>6. Refusal Skills — Video Suppressed</h2>";
$refSlug = 'refusal-skills-drugs';
$ch = curl_init("https://ariseci.org/arise/?p=lesson&slug=$refSlug");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_TIMEOUT=>10]);
$refBody = curl_exec($ch); curl_close($ch);
preg_match("/ARISE_VIDEO_URL='([^']*)'/", $refBody, $m2);
$videoUrl = $m2[1] ?? 'NOT FOUND';
echo "<table><tr><th>Check</th><th>Status</th></tr>\n";
$noVideo = ($videoUrl === '');
echo "<tr><td>ARISE_VIDEO_URL empty for refusal lesson</td><td>".($noVideo ? "<span class='ok'>$ok EMPTY (correct)</span>" : "<span class='no'>$no '$videoUrl'</span>")."</td></tr>\n";
echo "</table>\n";

// ── 7. LIVE REGISTRATION PAGE ────────────────────────────────────────────
echo "<h2>7. Registration Page</h2>";
$ch = curl_init("https://ariseci.org/arise/?p=register");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_TIMEOUT=>10]);
$regBody = curl_exec($ch); curl_close($ch);
echo "<table><tr><th>Check</th><th>Status</th></tr>\n";
$regChecks = [
    'Cluster dropdown present'  => strpos($regBody,'clusterSelect') !== false,
    'Project dropdown present'  => strpos($regBody,'schoolSelect') !== false,
    'Password field present'    => strpos($regBody,'name="password"') !== false,
    'No "Project Name" fallback'=> strpos($regBody,'Project Name') === false,
    'loadProjects() JS present' => strpos($regBody,'loadProjects') !== false,
    'schoolsByCluster data set' => strpos($regBody,'schoolsByCluster') !== false,
];
foreach ($regChecks as $label => $pass) {
    $st = $pass ? "<span class='ok'>$ok YES</span>" : "<span class='no'>$no FAIL</span>";
    echo "<tr><td>$label</td><td>$st</td></tr>\n";
}
echo "</table>\n";

// ── 8. SUMMARY ───────────────────────────────────────────────────────────
echo "<h2>8. Summary</h2>";
$totalLessons = $db->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1");
$totalModules = $db->querySingle("SELECT COUNT(*) FROM modules WHERE is_active=1");
$totalQuiz    = $db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE is_published=1");
$totalRefusal = $db->querySingle("SELECT COUNT(*) FROM lessons WHERE slug LIKE 'refusal%' AND is_active=1");
echo "<table><tr><th>Metric</th><th>Count</th><th>Status</th></tr>\n";
echo "<tr><td>Modules</td><td>$totalModules</td><td><span class='ok'>$ok</span></td></tr>\n";
echo "<tr><td>Total Lessons</td><td>$totalLessons</td><td><span class='ok'>$ok</span></td></tr>\n";
echo "<tr><td>Refusal Skills Lessons</td><td>$totalRefusal</td><td><span class='ok'>$ok</span></td></tr>\n";
echo "<tr><td>Quiz Questions</td><td>$totalQuiz</td><td><span class='ok'>$ok</span></td></tr>\n";
echo "<tr><td>Clusters</td><td>$totalClusters</td><td><span class='ok'>$ok</span></td></tr>\n";
echo "<tr><td>Active Schools/Projects</td><td>$totalSchools</td><td><span class='ok'>$ok</span></td></tr>\n";
echo "<tr><td>Interactive Files Missing</td><td>$missingI</td><td>".($missingI===0?"<span class='ok'>$ok ALL PRESENT</span>":"<span class='no'>$no $missingI MISSING</span>")."</td></tr>\n";
echo "<tr><td>Video Files Missing</td><td>$missingV</td><td>".($missingV===0?"<span class='ok'>$ok ALL PRESENT</span>":"<span class='no'>$no $missingV MISSING</span>")."</td></tr>\n";
echo "</table>\n";

$db->close();
echo "</body></html>";
unlink(__FILE__);
