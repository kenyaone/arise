<?php
/**
 * ARISE Donor / M&E Report — Professional Impact Dashboard
 * Aggregated metrics by school, module performance, knowledge gain
 */
if (!function_exists('db')) {
    require_once __DIR__ . '/../../includes/config.php';
}

// Report generation metadata
$reportGenerated = date('Y-m-d H:i:s');
$reportDate = date('F d, Y');

// KPI queries
$totalLearners = (int)db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1 AND deleted_at IS NULL");
$distinctModules = (int)db()->querySingle("SELECT COUNT(DISTINCT module_id) FROM quiz_attempts");
$totalCerts = (int)db()->querySingle("SELECT COUNT(*) FROM certificates");
$avgQuizScore = round((float)db()->querySingle("SELECT AVG(score_percent) FROM quiz_attempts") ?? 0, 1);

// Report period
$earliestLearner = db()->querySingle("SELECT registered_at FROM students WHERE is_active=1 ORDER BY registered_at ASC LIMIT 1");
$periodStart = $earliestLearner ? date('F d, Y', strtotime($earliestLearner)) : 'N/A';

// Schools with detailed breakdown
$schoolData = db()->query(
    "SELECT
        s.school_name,
        COUNT(DISTINCT s.id) as learners,
        COUNT(DISTINCT CASE WHEN qa.id IS NOT NULL THEN s.id END) as quiz_takers,
        ROUND(AVG(CASE WHEN qa.id IS NOT NULL THEN qa.score_percent ELSE NULL END), 1) as avg_score,
        COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN s.id END) as certified,
        ROUND(100.0 * COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN s.id END) / COUNT(DISTINCT s.id), 1) as cert_rate
    FROM students s
    LEFT JOIN quiz_attempts qa ON s.id = qa.student_id
    LEFT JOIN certificates c ON s.id = c.student_id
    WHERE s.is_active=1 AND s.deleted_at IS NULL
    GROUP BY s.school_name
    ORDER BY learners DESC"
)->fetchAll(SQLITE3_ASSOC);

// Module performance
$moduleData = db()->query(
    "SELECT
        m.title,
        COUNT(qa.id) as attempts,
        ROUND(AVG(qa.score_percent), 1) as avg_score,
        ROUND(100.0 * SUM(CASE WHEN qa.score_percent >= 60 THEN 1 ELSE 0 END) / COUNT(qa.id), 1) as pass_rate
    FROM modules m
    LEFT JOIN quiz_attempts qa ON m.id = qa.module_id
    WHERE m.is_active=1
    GROUP BY m.id
    ORDER BY attempts DESC
    LIMIT 15"
)->fetchAll(SQLITE3_ASSOC);

// Knowledge gain (pre/post test comparison)
$knowledgeGain = db()->query(
    "SELECT
        m.title,
        ROUND(AVG(CASE WHEN pa.test_type='pre' THEN pa.percentage ELSE NULL END), 1) as avg_pre,
        ROUND(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage ELSE NULL END), 1) as avg_post,
        ROUND(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage ELSE NULL END) -
              AVG(CASE WHEN pa.test_type='pre' THEN pa.percentage ELSE NULL END), 1) as gain
    FROM modules m
    LEFT JOIN pretest_attempts pa ON m.id = pa.module_id
    WHERE m.is_active=1
    GROUP BY m.id
    HAVING COUNT(CASE WHEN pa.test_type='pre' THEN 1 END) > 0
    AND COUNT(CASE WHEN pa.test_type='post' THEN 1 END) > 0"
)->fetchAll(SQLITE3_ASSOC);

// Completion funnel
$totalStudents = (int)db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1 AND deleted_at IS NULL");
$tookQuiz = (int)db()->querySingle("SELECT COUNT(DISTINCT student_id) FROM quiz_attempts");
$earnedCerts = (int)db()->querySingle("SELECT COUNT(DISTINCT student_id) FROM certificates");

// Daily activity (last 30 days)
$dailyActivity = db()->query(
    "SELECT
        date_of_day as date_label,
        total_sessions,
        total_quiz_attempts
    FROM daily_stats
    WHERE date_of_day >= datetime('now', '-30 days')
    ORDER BY date_of_day DESC"
)->fetchAll(SQLITE3_ASSOC);

// Forum and engagement
$forumPosts = (int)db()->querySingle("SELECT COUNT(*) FROM forum_posts WHERE is_hidden=0");
$anonQuestions = (int)db()->querySingle("SELECT COUNT(*) FROM anonymous_questions");
$activeSchools = (int)db()->querySingle("SELECT COUNT(DISTINCT school_name) FROM students WHERE is_active=1 AND deleted_at IS NULL");

// Helper functions
function scoreColor($score) {
    if ($score >= 70) return '#0ea271';
    if ($score >= 50) return '#f59e0b';
    return '#ef4444';
}

function gainBadgeColor($gain) {
    if ($gain > 10) return ['bg'=>'#dcfce7', 'fg'=>'#065f46', 'icon'=>'📈'];
    if ($gain >= 0) return ['bg'=>'#fef3c7', 'fg'=>'#92400e', 'icon'=>'→'];
    return ['bg'=>'#fee2e2', 'fg'=>'#991b1b', 'icon'=>'📉'];
}
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
.cover{background:linear-gradient(135deg,#052e16 0%,#0a5e2a 100%);color:#fff;padding:60px 40px;text-align:center;position:relative}
.cover h1{font-size:2.5rem;font-weight:900;margin-bottom:12px;letter-spacing:-1px}
.cover .subtitle{font-size:1.2rem;color:rgba(255,255,255,.85);margin-bottom:30px}
.cover .period{font-size:.9rem;color:rgba(255,255,255,.65)}
.no-print{position:fixed;top:20px;right:20px;background:#0ea271;color:#fff;border:none;padding:12px 24px;border-radius:8px;font-weight:700;cursor:pointer;z-index:1000;font-size:.9rem}
.no-print:hover{background:#059669}
.section{padding:40px;border-bottom:1px solid #e5e7eb}
.section:last-child{border-bottom:none}
.section-title{font-size:1.4rem;font-weight:800;color:#052e16;border-left:4px solid #0ea271;padding-left:16px;margin-bottom:24px}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:30px}
@media(max-width:1024px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.kpi-grid{grid-template-columns:1fr}}
.kpi-tile{background:linear-gradient(135deg,#f0fdf4,#dbeafe);border-left:5px solid #0ea271;padding:24px;border-radius:8px;text-align:center}
.kpi-val{font-size:2.2rem;font-weight:900;color:#0ea271;margin-bottom:6px}
.kpi-lbl{font-size:.85rem;color:#6b7280;text-transform:uppercase;font-weight:700;letter-spacing:.5px}
.chart-container{margin:30px 0;overflow-x:auto}
.bar-item{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #e5e7eb}
.bar-item:last-child{border-bottom:none}
.bar-label{width:150px;font-weight:600;color:#374151;font-size:.9rem;white-space:nowrap}
.bar-wrap{flex:1;background:#e5e7eb;border-radius:6px;height:28px;display:flex;align-items:center;position:relative;overflow:hidden}
.bar-fill{height:100%;display:flex;align-items:center;justify-content:flex-end;padding-right:8px;color:#fff;font-weight:700;font-size:.8rem;border-radius:6px}
.bar-val{margin-left:12px;width:50px;text-align:right;font-weight:700;color:#1f2937;font-size:.9rem}
table{width:100%;border-collapse:collapse;margin:20px 0}
th{background:#052e16;color:#fff;padding:12px;text-align:left;font-size:.8rem;text-transform:uppercase;font-weight:700}
td{padding:12px;border-bottom:1px solid #e5e7eb;font-size:.9rem}
tr:nth-child(even) td{background:#f9fafb}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700}
.badge-green{background:#dcfce7;color:#065f46}
.badge-amber{background:#fef3c7;color:#92400e}
.badge-red{background:#fee2e2;color:#991b1b}
.funnel-step{display:flex;align-items:center;gap:12px;padding:16px;background:#f9fafb;border-radius:8px;margin-bottom:12px}
.funnel-number{font-size:1.4rem;font-weight:900;color:#0ea271;min-width:60px}
.funnel-bar{flex:1;background:#e5e7eb;height:24px;border-radius:6px;position:relative;overflow:hidden}
.funnel-fill{height:100%;display:flex;align-items:center;justify-content:flex-end;padding-right:8px;color:#fff;font-weight:700;font-size:.8rem}
.funnel-label{width:100px;font-weight:600;color:#374151}
.funnel-pct{width:60px;text-align:right;font-weight:700;color:#1f2937}
.stat-box{background:#f0fdf4;border:2px solid #0ea271;border-radius:8px;padding:20px;text-align:center;margin:12px}
.stat-num{font-size:1.8rem;font-weight:900;color:#0ea271}
.stat-lbl{font-size:.85rem;color:#6b7280;text-transform:uppercase;margin-top:6px}
.footer{background:#052e16;color:#fff;padding:24px;text-align:center;font-size:.8rem;border-radius:0 0 12px 12px}
.footer p{color:rgba(255,255,255,.7);margin:4px 0}
@media print{
  .no-print{display:none}
  body{padding:0;background:#fff}
  .container{box-shadow:none;border-radius:0}
  .section{page-break-inside:avoid}
  .cover{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  @page{margin:15mm 20mm}
}
</style>
</head>
<body>

<button class="no-print" onclick="window.print()">🖨️ Print / Save as PDF</button>

<div class="container">

<!-- Cover -->
<div class="cover">
  <h1>ARISE Platform</h1>
  <div class="subtitle">Impact & Data Report</div>
  <div class="period">
    <?= $reportDate ?> &nbsp;·&nbsp;
    Reporting Period: <?= $periodStart ?> — <?= date('F d, Y') ?>
  </div>
</div>

<!-- Executive Summary -->
<div class="section">
  <div class="section-title">Executive Summary</div>
  <div class="kpi-grid">
    <div class="kpi-tile">
      <div class="kpi-val"><?= $totalLearners ?></div>
      <div class="kpi-lbl">Total Learners</div>
    </div>
    <div class="kpi-tile">
      <div class="kpi-val"><?= $distinctModules ?></div>
      <div class="kpi-lbl">Modules Covered</div>
    </div>
    <div class="kpi-tile">
      <div class="kpi-val"><?= $totalCerts ?></div>
      <div class="kpi-lbl">Certificates Earned</div>
    </div>
    <div class="kpi-tile">
      <div class="kpi-val"><?= $avgQuizScore ?>%</div>
      <div class="kpi-lbl">Average Quiz Score</div>
    </div>
  </div>
</div>

<!-- School Participation -->
<div class="section">
  <div class="section-title">Participation by School</div>
  <div class="chart-container">
    <?php
    $maxLearners = max(array_column($schoolData, 'learners')) ?: 1;
    foreach ($schoolData as $row):
      $height = max(3, round($row['learners'] / $maxLearners * 150));
    ?>
    <div class="bar-item">
      <div class="bar-label"><?= esc($row['school_name']) ?></div>
      <div class="bar-wrap">
        <div class="bar-fill" style="width:<?= round($row['learners']/$maxLearners*100) ?>%; background:#0ea271;">
          <?= $row['learners'] ?>
        </div>
      </div>
      <div class="bar-val"><?= $row['learners'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <table>
    <thead>
      <tr>
        <th>School</th>
        <th>Learners</th>
        <th>Quiz Takers</th>
        <th>Avg Score</th>
        <th>Certified</th>
        <th>Completion %</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($schoolData as $row): ?>
      <tr>
        <td><strong><?= esc($row['school_name']) ?></strong></td>
        <td><?= $row['learners'] ?></td>
        <td><?= $row['quiz_takers'] ?></td>
        <td>
          <?php $sc = scoreColor($row['avg_score'] ?? 0); ?>
          <span style="color:<?= $sc ?>;font-weight:700"><?= $row['avg_score'] ? $row['avg_score'].'%' : '—' ?></span>
        </td>
        <td><?= $row['certified'] ?></td>
        <td><?= $row['cert_rate'] ?>%</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Module Performance -->
<div class="section">
  <div class="section-title">Module Performance</div>
  <table>
    <thead>
      <tr>
        <th>Module</th>
        <th>Quiz Attempts</th>
        <th>Avg Score</th>
        <th>Pass Rate</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($moduleData as $row): ?>
      <tr>
        <td><strong><?= esc($row['title']) ?></strong></td>
        <td><?= $row['attempts'] ?? 0 ?></td>
        <td>
          <?php $sc = scoreColor($row['avg_score'] ?? 0); ?>
          <span style="color:<?= $sc ?>;font-weight:700"><?= $row['avg_score'] ? $row['avg_score'].'%' : '—' ?></span>
        </td>
        <td><span class="badge badge-green"><?= $row['pass_rate'] ?? 0 ?>%</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Knowledge Gain -->
<?php if (count($knowledgeGain) > 0): ?>
<div class="section">
  <div class="section-title">Knowledge Gain (Pre/Post Test Analysis)</div>
  <table>
    <thead>
      <tr>
        <th>Module</th>
        <th>Pre-Test Avg</th>
        <th>Post-Test Avg</th>
        <th>Knowledge Gain</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($knowledgeGain as $row):
      $colors = gainBadgeColor($row['gain'] ?? 0);
    ?>
      <tr>
        <td><strong><?= esc($row['title']) ?></strong></td>
        <td><span style="color:#374151;font-weight:700"><?= $row['avg_pre'] ?>%</span></td>
        <td><span style="color:#374151;font-weight:700"><?= $row['avg_post'] ?>%</span></td>
        <td>
          <span class="badge" style="background:<?= $colors['bg'] ?>;color:<?= $colors['fg'] ?>">
            <?= $colors['icon'] ?> <?= $row['gain'] > 0 ? '+' : '' ?><?= $row['gain'] ?>%
          </span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Completion Funnel -->
<div class="section">
  <div class="section-title">Completion Funnel</div>

  <div class="funnel-step">
    <div class="funnel-number"><?= $totalStudents ?></div>
    <div class="funnel-label">Registered</div>
    <div class="funnel-bar"><div class="funnel-fill" style="width:100%;background:#052e16">100%</div></div>
    <div class="funnel-pct">100%</div>
  </div>

  <div class="funnel-step">
    <div class="funnel-number"><?= $tookQuiz ?></div>
    <div class="funnel-label">Took Quiz</div>
    <div class="funnel-bar"><div class="funnel-fill" style="width:<?= $totalStudents > 0 ? round($tookQuiz/$totalStudents*100) : 0 ?>%;background:#0ea271"><?= $totalStudents > 0 ? round($tookQuiz/$totalStudents*100) : 0 ?>%</div></div>
    <div class="funnel-pct"><?= $totalStudents > 0 ? round($tookQuiz/$totalStudents*100) : 0 ?>%</div>
  </div>

  <div class="funnel-step">
    <div class="funnel-number"><?= $earnedCerts ?></div>
    <div class="funnel-label">Certified</div>
    <div class="funnel-bar"><div class="funnel-fill" style="width:<?= $totalStudents > 0 ? round($earnedCerts/$totalStudents*100) : 0 ?>%;background:#f59e0b"><?= $totalStudents > 0 ? round($earnedCerts/$totalStudents*100) : 0 ?>%</div></div>
    <div class="funnel-pct"><?= $totalStudents > 0 ? round($earnedCerts/$totalStudents*100) : 0 ?>%</div>
  </div>
</div>

<!-- Engagement -->
<div class="section">
  <div class="section-title">Engagement & Participation</div>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px">
    <div class="stat-box">
      <div class="stat-num"><?= $forumPosts ?></div>
      <div class="stat-lbl">Forum Posts</div>
    </div>
    <div class="stat-box">
      <div class="stat-num"><?= $anonQuestions ?></div>
      <div class="stat-lbl">Questions Asked</div>
    </div>
    <div class="stat-box">
      <div class="stat-num"><?= $activeSchools ?></div>
      <div class="stat-lbl">Active Schools</div>
    </div>
  </div>
</div>

<!-- Daily Activity -->
<?php if (count($dailyActivity) > 0): ?>
<div class="section">
  <div class="section-title">Daily Activity (Last 30 Days)</div>
  <div class="chart-container">
    <?php
    $maxSessions = max(array_column($dailyActivity, 'total_sessions')) ?: 1;
    foreach ($dailyActivity as $row):
      $height = max(3, round($row['total_sessions'] / $maxSessions * 150));
    ?>
    <div class="bar-item">
      <div class="bar-label"><?= date('M d', strtotime($row['date_label'])) ?></div>
      <div class="bar-wrap">
        <div class="bar-fill" style="width:<?= round($row['total_sessions']/$maxSessions*100) ?>%; background:linear-gradient(90deg,#0ea271,#065f46);">
          <?= $row['total_sessions'] ?>
        </div>
      </div>
      <div class="bar-val"><?= $row['total_sessions'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Footer -->
<div class="footer">
  <p><strong>ARISE Platform — Impact & Data Report</strong></p>
  <p>Generated on <?= $reportGenerated ?> via DataPost</p>
  <p style="margin-top:12px;font-size:.7rem">Adolescent Reproductive Health Information Support & Empowerment</p>
</div>

</div>

</body>
</html>
<?php
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
