<?php
/**
 * ARISE Donor / M&E Report — Professional Impact Dashboard
 * Aggregated metrics by school, module performance, knowledge gain
 */
if (!function_exists('db')) {
    require_once __DIR__ . '/../../includes/config.php';
}

$reportGenerated = date('Y-m-d H:i:s');
$reportDate = date('F d, Y');

// KPI queries
$totalLearners = (int)db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1 AND deleted_at IS NULL");
$distinctModules = (int)db()->querySingle("SELECT COUNT(DISTINCT module_id) FROM quiz_attempts");
$totalCerts = (int)db()->querySingle("SELECT COUNT(*) FROM certificates");
$avgQuizScore = round((float)db()->querySingle("SELECT AVG(percentage) FROM quiz_attempts") ?? 0, 1);

$earliestLearner = db()->querySingle("SELECT registered_at FROM students WHERE is_active=1 ORDER BY registered_at ASC LIMIT 1");
$periodStart = $earliestLearner ? date('F d, Y', strtotime($earliestLearner)) : 'N/A';

// Schools
$schoolData = [];
$result = db()->query("SELECT s.school_name, COUNT(DISTINCT s.id) as learners, COUNT(DISTINCT CASE WHEN qa.id IS NOT NULL THEN s.id END) as quiz_takers, ROUND(AVG(CASE WHEN qa.id IS NOT NULL THEN qa.percentage ELSE NULL END), 1) as avg_score, COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN s.id END) as certified, ROUND(100.0 * COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN s.id END) / COUNT(DISTINCT s.id), 1) as cert_rate FROM students s INNER JOIN schools sc ON sc.name = s.school_name AND sc.is_active=1 LEFT JOIN quiz_attempts qa ON s.id = qa.student_id LEFT JOIN certificates c ON s.id = c.student_id WHERE s.is_active=1 AND s.deleted_at IS NULL GROUP BY s.school_name ORDER BY learners DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $schoolData[] = $row; }

// Modules
$moduleData = [];
$result = db()->query("SELECT m.title, COUNT(qa.id) as attempts, ROUND(AVG(qa.percentage), 1) as avg_score, ROUND(100.0 * SUM(CASE WHEN qa.percentage >= 60 THEN 1 ELSE 0 END) / COUNT(qa.id), 1) as pass_rate FROM modules m LEFT JOIN quiz_attempts qa ON m.id = qa.module_id WHERE m.is_active=1 GROUP BY m.id ORDER BY attempts DESC LIMIT 15");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $moduleData[] = $row; }

// Knowledge gain
$knowledgeGain = [];
$result = db()->query("SELECT m.title, ROUND(AVG(CASE WHEN pa.test_type='pre' THEN pa.percentage ELSE NULL END), 1) as avg_pre, ROUND(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage ELSE NULL END), 1) as avg_post, ROUND(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage ELSE NULL END) - AVG(CASE WHEN pa.test_type='pre' THEN pa.percentage ELSE NULL END), 1) as gain FROM modules m LEFT JOIN pretest_attempts pa ON m.id = pa.module_id WHERE m.is_active=1 GROUP BY m.id HAVING COUNT(CASE WHEN pa.test_type='pre' THEN 1 END) > 0 AND COUNT(CASE WHEN pa.test_type='post' THEN 1 END) > 0");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $knowledgeGain[] = $row; }

// Funnel
$totalStudents = (int)db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1 AND deleted_at IS NULL");
$tookQuiz = (int)db()->querySingle("SELECT COUNT(DISTINCT student_id) FROM quiz_attempts");
$earnedCerts = (int)db()->querySingle("SELECT COUNT(DISTINCT student_id) FROM certificates");

// Daily activity
$dailyActivity = [];
$result = db()->query("SELECT date_of_day as date_label, total_sessions, total_quiz_attempts FROM daily_stats WHERE date_of_day >= datetime('now', '-30 days') ORDER BY date_of_day DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $dailyActivity[] = $row; }

// Engagement
$forumPosts = (int)db()->querySingle("SELECT COUNT(*) FROM forum_posts WHERE is_hidden=0");
$anonQuestions = (int)db()->querySingle("SELECT COUNT(*) FROM anonymous_questions");
$activeSchools = (int)db()->querySingle("SELECT COUNT(DISTINCT school_name) FROM students WHERE is_active=1 AND deleted_at IS NULL");

function scoreColor($score) { return ($score >= 70) ? '#0ea271' : (($score >= 50) ? '#f59e0b' : '#ef4444'); }
function gainBadgeColor($gain) { return ($gain > 10) ? ['bg'=>'#dcfce7', 'fg'=>'#065f46', 'icon'=>'📈'] : (($gain >= 0) ? ['bg'=>'#fef3c7', 'fg'=>'#92400e', 'icon'=>'→'] : ['bg'=>'#fee2e2', 'fg'=>'#991b1b', 'icon'=>'📉']); }
function esc($s) { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ARISE — Impact & Data Report</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f9fafb;color:#1f2937;line-height:1.6;padding:20px}
.container{max-width:1000px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08)}
.cover{background:linear-gradient(135deg,#052e16 0%,#0a5e2a 100%);color:#fff;padding:60px 40px;text-align:center}
.cover h1{font-size:2.5rem;font-weight:900;margin-bottom:12px}
.cover .subtitle{font-size:1.2rem;color:rgba(255,255,255,.85);margin-bottom:30px}
.cover .period{font-size:.9rem;color:rgba(255,255,255,.65)}
.no-print{position:fixed;top:20px;right:20px;background:#0ea271;color:#fff;border:none;padding:12px 24px;border-radius:8px;font-weight:700;cursor:pointer;z-index:1000;font-size:.9rem}
.section{padding:40px;border-bottom:1px solid #e5e7eb}
.section:last-child{border-bottom:none}
.section-title{font-size:1.4rem;font-weight:800;color:#052e16;border-left:4px solid #0ea271;padding-left:16px;margin-bottom:24px}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
@media(max-width:768px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
.kpi-tile{background:linear-gradient(135deg,#f0fdf4,#dbeafe);border-left:5px solid #0ea271;padding:24px;border-radius:8px;text-align:center}
.kpi-val{font-size:2.2rem;font-weight:900;color:#0ea271;margin-bottom:6px}
.kpi-lbl{font-size:.85rem;color:#6b7280;text-transform:uppercase;font-weight:700}
.bar-item{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #e5e7eb}
.bar-label{width:150px;font-weight:600;color:#374151;font-size:.9rem}
.bar-wrap{flex:1;background:#e5e7eb;border-radius:6px;height:28px;display:flex;overflow:hidden}
.bar-fill{height:100%;display:flex;align-items:center;justify-content:flex-end;padding-right:8px;color:#fff;font-weight:700;font-size:.8rem;border-radius:6px}
.bar-val{width:50px;text-align:right;font-weight:700;color:#1f2937}
table{width:100%;border-collapse:collapse;margin:20px 0}
th{background:#052e16;color:#fff;padding:12px;text-align:left;font-size:.8rem;text-transform:uppercase;font-weight:700}
td{padding:12px;border-bottom:1px solid #e5e7eb;font-size:.9rem}
tr:nth-child(even) td{background:#f9fafb}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700}
.badge-green{background:#dcfce7;color:#065f46}
.stat-box{background:#f0fdf4;border:2px solid #0ea271;border-radius:8px;padding:20px;text-align:center;margin:12px}
.stat-num{font-size:1.8rem;font-weight:900;color:#0ea271}
.stat-lbl{font-size:.85rem;color:#6b7280;text-transform:uppercase;margin-top:6px}
.footer{background:#052e16;color:#fff;padding:24px;text-align:center;font-size:.8rem;border-radius:0 0 12px 12px}
@media print{.no-print{display:none} body{padding:0;background:#fff} .container{box-shadow:none;border-radius:0}}
</style>
</head>
<body>

<button class="no-print" onclick="window.print()">🖨️ Print / Save as PDF</button>

<div class="container">

<div class="cover">
  <h1>ARISE Platform</h1>
  <div class="subtitle">Impact & Data Report</div>
  <div class="period"><?= $reportDate ?> · <?= $periodStart ?> — <?= date('F d, Y') ?></div>
</div>

<div class="section">
  <div class="section-title">Executive Summary</div>
  <div class="kpi-grid">
    <div class="kpi-tile"><div class="kpi-val"><?= $totalLearners ?></div><div class="kpi-lbl">Total Learners</div></div>
    <div class="kpi-tile"><div class="kpi-val"><?= $distinctModules ?></div><div class="kpi-lbl">Modules Covered</div></div>
    <div class="kpi-tile"><div class="kpi-val"><?= $totalCerts ?></div><div class="kpi-lbl">Certificates Earned</div></div>
    <div class="kpi-tile"><div class="kpi-val"><?= $avgQuizScore ?>%</div><div class="kpi-lbl">Average Quiz Score</div></div>
  </div>
</div>

<div class="section">
  <div class="section-title">Participation by Project</div>
  <div style="margin:30px 0">
    <?php $maxL = max(array_column($schoolData, 'learners')) ?: 1;
    foreach ($schoolData as $row): $h = round($row['learners']/$maxL*100); ?>
    <div class="bar-item">
      <div class="bar-label"><?= esc($row['school_name']) ?></div>
      <div class="bar-wrap"><div class="bar-fill" style="width:<?= $h ?>%;background:#0ea271"><?= $row['learners'] ?></div></div>
      <div class="bar-val"><?= $row['learners'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <table>
    <thead><tr><th>Project</th><th>Learners</th><th>Quiz Takers</th><th>Avg Score</th><th>Certified</th><th>Completion %</th></tr></thead>
    <tbody>
    <?php foreach ($schoolData as $row): $sc = scoreColor($row['avg_score'] ?? 0); ?>
      <tr><td><strong><?= esc($row['school_name']) ?></strong></td><td><?= $row['learners'] ?></td><td><?= $row['quiz_takers'] ?></td><td><span style="color:<?= $sc ?>;font-weight:700"><?= $row['avg_score'] ? $row['avg_score'].'%' : '—' ?></span></td><td><?= $row['certified'] ?></td><td><?= $row['cert_rate'] ?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="section">
  <div class="section-title">Module Performance</div>
  <table>
    <thead><tr><th>Module</th><th>Quiz Attempts</th><th>Avg Score</th><th>Pass Rate</th></tr></thead>
    <tbody>
    <?php foreach ($moduleData as $row): $sc = scoreColor($row['avg_score'] ?? 0); ?>
      <tr><td><strong><?= esc($row['title']) ?></strong></td><td><?= $row['attempts'] ?? 0 ?></td><td><span style="color:<?= $sc ?>;font-weight:700"><?= $row['avg_score'] ? $row['avg_score'].'%' : '—' ?></span></td><td><span class="badge badge-green"><?= $row['pass_rate'] ?? 0 ?>%</span></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (count($knowledgeGain) > 0): ?>
<div class="section">
  <div class="section-title">Knowledge Gain (Pre/Post Test Analysis)</div>
  <table>
    <thead><tr><th>Module</th><th>Pre-Test Avg</th><th>Post-Test Avg</th><th>Knowledge Gain</th></tr></thead>
    <tbody>
    <?php foreach ($knowledgeGain as $row): $colors = gainBadgeColor($row['gain'] ?? 0); ?>
      <tr><td><strong><?= esc($row['title']) ?></strong></td><td><span style="color:#374151;font-weight:700"><?= $row['avg_pre'] ?>%</span></td><td><span style="color:#374151;font-weight:700"><?= $row['avg_post'] ?>%</span></td><td><span class="badge" style="background:<?= $colors['bg'] ?>;color:<?= $colors['fg'] ?>"><?= $colors['icon'] ?> <?= $row['gain'] > 0 ? '+' : '' ?><?= $row['gain'] ?>%</span></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="section">
  <div class="section-title">Completion Funnel</div>
  <div style="padding:20px 0">
    <div class="bar-item"><div style="font-weight:900;color:#0ea271;min-width:60px"><?= $totalStudents ?></div><div style="flex:1">Registered</div><div style="width:100px;text-align:right;font-weight:700">100%</div></div>
    <div class="bar-item"><div style="font-weight:900;color:#0ea271;min-width:60px"><?= $tookQuiz ?></div><div style="flex:1">Took Quiz</div><div style="width:100px;text-align:right;font-weight:700"><?= $totalStudents > 0 ? round($tookQuiz/$totalStudents*100) : 0 ?>%</div></div>
    <div class="bar-item"><div style="font-weight:900;color:#0ea271;min-width:60px"><?= $earnedCerts ?></div><div style="flex:1">Certified</div><div style="width:100px;text-align:right;font-weight:700"><?= $totalStudents > 0 ? round($earnedCerts/$totalStudents*100) : 0 ?>%</div></div>
  </div>
</div>

<div class="section">
  <div class="section-title">Engagement & Participation</div>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px">
    <div class="stat-box"><div class="stat-num"><?= $forumPosts ?></div><div class="stat-lbl">Forum Posts</div></div>
    <div class="stat-box"><div class="stat-num"><?= $anonQuestions ?></div><div class="stat-lbl">Questions Asked</div></div>
    <div class="stat-box"><div class="stat-num"><?= $activeSchools ?></div><div class="stat-lbl">Active Projects</div></div>
  </div>
</div>

<div class="footer">
  <p><strong>ARISE Platform — Impact & Data Report</strong></p>
  <p>Generated on <?= $reportGenerated ?> via DataPost</p>
  <p style="margin-top:12px;font-size:.7rem">Adolescent Reproductive Health Information Support & Empowerment</p>
</div>

</div>
</body>
</html>
