<?php
/**
 * ARISE Admin Analytics — Impact Assessment Dashboard
 * Sections: KPIs · Funnel · Knowledge Gain by Module · Cohort Comparison ·
 *           Per-Module Funnels · Daily Activity Chart · Behavioral Survey · Difficult Questions
 */

$auth_ok = isset($_SESSION['arise_admin_id']);
if (!$auth_ok) { echo '<div class="alert alert-danger">Not logged in.</div>'; return; }

/* ══════════════════════════════════════════════════════════════
   SECTION 0 — Date range
══════════════════════════════════════════════════════════════ */
$rangeOptions = ['7'=>'Last 7 Days','30'=>'Last 30 Days','90'=>'Last 90 Days','all'=>'All Time'];
$range = isset($_GET['range']) && array_key_exists($_GET['range'], $rangeOptions) ? $_GET['range'] : '30';

if ($range === 'all') {
    $whereDate   = '1=1';
    $andDate     = '';
    $rangeLabel  = 'All Time';
} else {
    $days        = (int)$range;
    $cutoff      = date('Y-m-d', strtotime("-{$days} days"));
    $whereDate   = "DATE(viewed_at) >= '$cutoff'";
    $andDate     = "AND DATE(viewed_at) >= '$cutoff'";
    $rangeLabel  = $rangeOptions[$range];
}

/* ══════════════════════════════════════════════════════════════
   SECTION 1 — Impact KPIs
══════════════════════════════════════════════════════════════ */
$db = db();

// Pre/Post averages
$preAvg  = round((float)($db->querySingle("SELECT AVG(percentage) FROM pretest_attempts WHERE test_type='pre'")  ?? 0), 1);
$postAvg = round((float)($db->querySingle("SELECT AVG(percentage) FROM pretest_attempts WHERE test_type='post'") ?? 0), 1);
$gain    = round($postAvg - $preAvg, 1);

// Normalised Gain Index  = (post - pre) / (100 - pre) × 100
$normGain = ($preAvg < 100 && ($postAvg - $preAvg) != 0)
    ? round(($postAvg - $preAvg) / (100 - $preAvg) * 100, 1)
    : 0;

// Learners with both pre + post
$bothCount = (int)($db->querySingle(
    "SELECT COUNT(*) FROM (
        SELECT session_hash FROM pretest_attempts WHERE test_type='pre'
        INTERSECT
        SELECT session_hash FROM pretest_attempts WHERE test_type='post'
    )"
) ?? 0);

// Pass rate (quiz_attempts percentage >= 60)
$totalAttempts = (int)($db->querySingle("SELECT COUNT(*) FROM quiz_attempts") ?? 0);
$passCount     = (int)($db->querySingle("SELECT COUNT(*) FROM quiz_attempts WHERE percentage >= 60") ?? 0);
$passRate      = $totalAttempts > 0 ? round($passCount / $totalAttempts * 100, 1) : 0;

// Completion rate: learners who completed ≥1 lesson / total active learners
$totalActive     = (int)($db->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1 AND deleted_at IS NULL") ?? 0);
$completedSome   = (int)($db->querySingle(
    "SELECT COUNT(DISTINCT student_id) FROM lesson_progress WHERE completed=1"
) ?? 0);
$completionRate  = $totalActive > 0 ? round($completedSome / $totalActive * 100, 1) : 0;

// Total certificates
$totalCerts = (int)($db->querySingle("SELECT COUNT(*) FROM certificates") ?? 0);

/* ══════════════════════════════════════════════════════════════
   SECTION 2 — Completion Funnel (overall)
══════════════════════════════════════════════════════════════ */
$fRegistered    = $totalActive;
$fOpenedModule  = (int)($db->querySingle(
    "SELECT COUNT(DISTINCT session_hash) FROM page_views WHERE $whereDate"
) ?? 0);
$fPreTest       = (int)($db->querySingle(
    "SELECT COUNT(DISTINCT student_id) FROM pretest_attempts WHERE test_type='pre'"
) ?? 0);
$fLessons       = (int)($db->querySingle(
    "SELECT COUNT(DISTINCT student_id) FROM lesson_progress WHERE completed=1"
) ?? 0);
$fPostTest      = (int)($db->querySingle(
    "SELECT COUNT(DISTINCT student_id) FROM pretest_attempts WHERE test_type='post'"
) ?? 0);
$fCertified     = (int)($db->querySingle(
    "SELECT COUNT(DISTINCT student_id) FROM certificates"
) ?? 0);

$funnelSteps = [
    ['label' => 'Registered',          'count' => $fRegistered,   'color' => '#052e16'],
    ['label' => 'Opened a Module',     'count' => $fOpenedModule, 'color' => '#065f46'],
    ['label' => 'Pre-Test Done',       'count' => $fPreTest,      'color' => '#0ea271'],
    ['label' => 'Lessons Completed',   'count' => $fLessons,      'color' => '#10b981'],
    ['label' => 'Post-Test Done',      'count' => $fPostTest,     'color' => '#f59e0b'],
    ['label' => 'Certified',           'count' => $fCertified,    'color' => '#d97706'],
];
$funnelTop = max($fRegistered, 1);

/* ══════════════════════════════════════════════════════════════
   SECTION 3 — Knowledge Gain by Module
══════════════════════════════════════════════════════════════ */
$modGainList = [];
$mgr = $db->query("
    SELECT m.id, m.title, m.icon,
        ROUND(AVG(CASE WHEN pa.test_type='pre'  THEN pa.percentage END), 1) AS pre_avg,
        ROUND(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage END), 1) AS post_avg,
        COUNT(DISTINCT CASE WHEN pa.test_type='pre'  THEN pa.student_id END) AS pre_count,
        COUNT(DISTINCT CASE WHEN pa.test_type='post' THEN pa.student_id END) AS post_count,
        COUNT(DISTINCT c.id) AS certs
    FROM modules m
    LEFT JOIN pretest_attempts pa ON pa.module_id = m.id
    LEFT JOIN certificates c      ON c.module_id  = m.id
    WHERE m.is_active = 1
    GROUP BY m.id
    ORDER BY (COALESCE(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage END),0)
            - COALESCE(AVG(CASE WHEN pa.test_type='pre'  THEN pa.percentage END),0)) DESC
");
while ($r = $mgr->fetchArray(SQLITE3_ASSOC)) {
    $r['pre_avg']  = $r['pre_avg']  !== null ? (float)$r['pre_avg']  : null;
    $r['post_avg'] = $r['post_avg'] !== null ? (float)$r['post_avg'] : null;
    if ($r['pre_avg'] !== null && $r['post_avg'] !== null) {
        $r['gain']      = round($r['post_avg'] - $r['pre_avg'], 1);
        $denom = 100 - $r['pre_avg'];
        $r['norm_gain'] = ($denom > 0) ? round(($r['post_avg'] - $r['pre_avg']) / $denom * 100, 1) : 0;
    } else {
        $r['gain']      = null;
        $r['norm_gain'] = null;
    }
    $r['learners_tested'] = max((int)($r['pre_count'] ?? 0), (int)($r['post_count'] ?? 0));
    $modGainList[] = $r;
}

/* ══════════════════════════════════════════════════════════════
   SECTION 4 — Cohort Comparison
══════════════════════════════════════════════════════════════ */
$cohortList = [];
$cr = $db->query("
    SELECT
        s.school_name   AS project,
        s.class_name    AS cluster,
        COUNT(DISTINCT s.id)                                   AS learners,
        ROUND(AVG(qa.percentage), 1)                           AS avg_score,
        ROUND(SUM(CASE WHEN qa.percentage>=60 THEN 1.0 ELSE 0 END)
              / MAX(1.0, COUNT(qa.id)) * 100, 1)               AS pass_rate,
        COUNT(DISTINCT c.id)                                   AS certs,
        ROUND(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage END)
            - AVG(CASE WHEN pa.test_type='pre'  THEN pa.percentage END), 1) AS knowledge_gain
    FROM students s
    INNER JOIN schools sc ON sc.name = s.school_name AND sc.is_active=1
    LEFT JOIN quiz_attempts  qa ON qa.student_id = s.id
    LEFT JOIN certificates   c  ON c.student_id  = s.id
    LEFT JOIN pretest_attempts pa ON pa.student_id = s.id
    WHERE s.is_active=1 AND s.deleted_at IS NULL
    GROUP BY s.school_name, s.class_name
    ORDER BY learners DESC
");
while ($r = $cr->fetchArray(SQLITE3_ASSOC)) $cohortList[] = $r;

/* ══════════════════════════════════════════════════════════════
   SECTION 5 — Completion Funnel per Module (top 8 by views)
══════════════════════════════════════════════════════════════ */
$topModules = [];
$tmr = $db->query("
    SELECT m.id, m.title, m.icon,
        COUNT(DISTINCT pv.session_hash) AS views
    FROM modules m
    LEFT JOIN page_views pv ON pv.module_id = m.id
    WHERE m.is_active=1
    GROUP BY m.id
    ORDER BY views DESC
    LIMIT 8
");
while ($r = $tmr->fetchArray(SQLITE3_ASSOC)) {
    $mid = (int)$r['id'];
    $r['pre_done']    = (int)($db->querySingle("SELECT COUNT(DISTINCT student_id) FROM pretest_attempts WHERE module_id=$mid AND test_type='pre'")  ?? 0);
    $r['post_done']   = (int)($db->querySingle("SELECT COUNT(DISTINCT student_id) FROM pretest_attempts WHERE module_id=$mid AND test_type='post'") ?? 0);
    $r['lessons_done']= (int)($db->querySingle(
        "SELECT COUNT(DISTINCT lp.student_id) FROM lesson_progress lp
         JOIN lessons l ON l.id=lp.lesson_id
         WHERE l.module_id=$mid AND lp.completed=1"
    ) ?? 0);
    $r['cert_earned'] = (int)($db->querySingle("SELECT COUNT(DISTINCT student_id) FROM certificates WHERE module_id=$mid") ?? 0);
    $topModules[] = $r;
}

/* ══════════════════════════════════════════════════════════════
   SECTION 6 — Daily Activity Chart
══════════════════════════════════════════════════════════════ */
$chartDays  = ($range === 'all') ? 30 : (int)$range;
$dailyChart = [];
for ($i = $chartDays - 1; $i >= 0; $i--) {
    $d    = date('Y-m-d', strtotime("-{$i} days"));
    $sess = (int)($db->querySingle(
        "SELECT COUNT(DISTINCT session_hash) FROM page_views WHERE DATE(viewed_at)='$d'"
    ) ?? 0);
    $dailyChart[] = ['date' => $d, 'sessions' => $sess];
}
$chartMax = max(array_column($dailyChart, 'sessions') ?: [1]);

/* ══════════════════════════════════════════════════════════════
   SECTION 7 — Behavioral Survey
══════════════════════════════════════════════════════════════ */
$surveyTotal   = (int)($db->querySingle("SELECT COUNT(*) FROM behavioral_surveys") ?? 0);
$surveyChanged = 0; $surveyShared = 0; $surveyConfident = 0;
if ($surveyTotal > 0) {
    $surveyChanged   = round((int)($db->querySingle("SELECT COUNT(*) FROM behavioral_surveys WHERE q1_changed=1")   ?? 0) / $surveyTotal * 100, 1);
    $surveyShared    = round((int)($db->querySingle("SELECT COUNT(*) FROM behavioral_surveys WHERE q2_shared=1")    ?? 0) / $surveyTotal * 100, 1);
    $surveyConfident = round((int)($db->querySingle("SELECT COUNT(*) FROM behavioral_surveys WHERE q3_confident=1") ?? 0) / $surveyTotal * 100, 1);
}

/* ══════════════════════════════════════════════════════════════
   SECTION 8 — Most Difficult Questions
══════════════════════════════════════════════════════════════ */
$difficultQs = [];
$dqr = $db->query("
    SELECT
        m.title  AS module_title,
        qq.question,
        COUNT(qa.id)                                                 AS total_attempts,
        SUM(CASE WHEN qa.is_correct=0 THEN 1 ELSE 0 END)            AS wrong_count,
        ROUND(SUM(CASE WHEN qa.is_correct=0 THEN 1.0 ELSE 0 END)
              / MAX(1, COUNT(qa.id)) * 100, 1)                       AS wrong_pct
    FROM quiz_answers qa
    JOIN quiz_questions qq ON qq.id = qa.question_id
    JOIN modules m         ON m.id  = qq.module_id
    GROUP BY qa.question_id
    HAVING total_attempts >= 3
    ORDER BY wrong_pct DESC
    LIMIT 10
");
while ($r = $dqr->fetchArray(SQLITE3_ASSOC)) $difficultQs[] = $r;

// ─── Helper: gain colour ────────────────────────────────────────────
function gainColor(float|null $g): string {
    if ($g === null) return '#6b7280';
    if ($g > 10)  return '#065f46';
    if ($g >= 0)  return '#92400e';
    return '#991b1b';
}
function gainBg(float|null $g): string {
    if ($g === null) return '#f3f4f6';
    if ($g > 10)  return '#dcfce7';
    if ($g >= 0)  return '#fef3c7';
    return '#fee2e2';
}
?>

<?php /* ══════════════════════════════════════════════════════════════
   STYLES (scoped inline — no Bootstrap, no html/body wrapper)
══════════════════════════════════════════════════════════════ */ ?>
<style>
:root {
  --an-dark:   #052e16;
  --an-green:  #0ea271;
  --an-amber:  #f59e0b;
  --an-blue:   #3b82f6;
  --an-red:    #ef4444;
  --an-border: #e5e7eb;
  --an-muted:  #6b7280;
  --an-radius: 16px;
}
.an-page-header {
  display:flex; justify-content:space-between; align-items:center;
  flex-wrap:wrap; gap:12px; margin-bottom:28px;
}
.an-page-header h1 {
  font-size:1.4rem; font-weight:900; color:var(--an-dark); margin:0;
}
.an-range-form { display:flex; gap:6px; flex-wrap:wrap; }
.an-range-btn {
  padding:7px 16px; border-radius:8px; font-size:.8rem; font-weight:700;
  border:1.5px solid var(--an-border); background:#fff; cursor:pointer;
  color:#374151; text-decoration:none; transition:all .2s;
}
.an-range-btn:hover  { border-color:var(--an-green); color:var(--an-green); }
.an-range-btn.active { background:var(--an-green); color:#fff; border-color:var(--an-green); }

/* Section heading */
.an-section-head {
  border-left:4px solid var(--an-green); padding-left:12px;
  font-size:1rem; font-weight:800; color:var(--an-dark);
  margin:0 0 16px;
}

/* KPI grid */
.an-kpi-grid {
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(170px,1fr));
  gap:14px; margin-bottom:28px;
}
.an-kpi {
  border-radius:var(--an-radius); padding:20px 18px; background:#fff;
  box-shadow:0 2px 10px rgba(0,0,0,.07); border-top:5px solid transparent;
  transition:transform .2s;
}
.an-kpi:hover { transform:translateY(-3px); }
.an-kpi .val {
  font-size:2.1rem; font-weight:900; line-height:1; margin-bottom:5px;
}
.an-kpi .lbl {
  font-size:.71rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.4px; color:var(--an-muted);
}
.an-kpi .sub {
  font-size:.76rem; margin-top:5px; color:#374151;
}
.an-kpi.green  { border-top-color:var(--an-green); }
.an-kpi.blue   { border-top-color:var(--an-blue);  }
.an-kpi.amber  { border-top-color:var(--an-amber); }
.an-kpi.red    { border-top-color:var(--an-red);   }
.an-kpi.dark   { border-top-color:var(--an-dark);  }
.an-kpi.green .val { color:#065f46; }
.an-kpi.blue  .val { color:#1e40af; }
.an-kpi.amber .val { color:#92400e; }
.an-kpi.red   .val { color:#991b1b; }
.an-kpi.dark  .val { color:var(--an-dark); }

/* Funnel */
.an-funnel { margin-bottom:28px; }
.an-funnel-step {
  display:grid;
  grid-template-columns:180px 1fr 90px 80px;
  align-items:center; gap:12px;
  padding:8px 0; border-bottom:1px solid var(--an-border);
}
.an-funnel-step:last-child { border-bottom:none; }
.an-funnel-label  { font-size:.85rem; font-weight:700; color:#374151; }
.an-funnel-bar-bg { height:22px; background:#f3f4f6; border-radius:50px; overflow:hidden; }
.an-funnel-bar-fill { height:100%; border-radius:50px; transition:width .5s; }
.an-funnel-count  { font-size:.88rem; font-weight:800; text-align:right; }
.an-funnel-pct    { font-size:.78rem; color:var(--an-muted); text-align:right; }
.an-dropoff {
  font-size:.72rem; color:var(--an-red); font-weight:700;
  grid-column:1/-1; padding-left:186px; margin-top:-4px;
}

/* Mini-funnel card grid */
.an-mini-grid {
  display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
  gap:14px; margin-bottom:28px;
}
.an-mini-card {
  border-radius:12px; background:#fff; padding:16px;
  box-shadow:0 2px 10px rgba(0,0,0,.07); border:1px solid var(--an-border);
}
.an-mini-card h4 {
  font-size:.88rem; font-weight:800; color:var(--an-dark);
  margin:0 0 12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.an-mini-row {
  display:flex; justify-content:space-between; align-items:center;
  font-size:.78rem; margin-bottom:6px;
}
.an-mini-bar-bg { height:5px; background:#f3f4f6; border-radius:50px; overflow:hidden; margin-top:2px; }
.an-mini-bar-fill { height:100%; border-radius:50px; }

/* Daily chart */
.an-chart-wrap {
  display:flex; align-items:flex-end; gap:3px;
  height:130px; padding-bottom:24px; position:relative;
  overflow-x:auto; margin-bottom:28px;
}
.an-bar-col {
  display:flex; flex-direction:column; align-items:center;
  gap:2px; flex:1; min-width:12px; max-width:30px;
}
.an-bar-fill {
  width:100%; border-radius:4px 4px 0 0; min-height:3px;
  background:linear-gradient(180deg,#0ea271,#065f46);
  transition:height .4s;
}
.an-bar-lbl {
  font-size:.6rem; color:var(--an-muted); transform:rotate(-45deg);
  white-space:nowrap; margin-top:2px; position:absolute; bottom:0;
}

/* Behavioral survey */
.an-survey-grid {
  display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
  gap:14px; margin-bottom:28px;
}
.an-survey-tile {
  border-radius:12px; padding:22px 18px; text-align:center;
  background:#f0fdf4; border:1.5px solid #bbf7d0;
}
.an-survey-tile .sv-pct { font-size:2.5rem; font-weight:900; color:#065f46; }
.an-survey-tile .sv-lbl { font-size:.8rem; color:#374151; font-weight:600; margin-top:6px; }

/* Zebra + hover override for arise-table */
.an-table-wrap { overflow-x:auto; }
.an-table-wrap .arise-table tbody tr:nth-child(even) { background:#f9fafb; }
.an-table-wrap .arise-table tbody tr:hover { background:#ecfdf5 !important; }

/* Tooltip */
.an-tooltip {
  position:relative; display:inline-block; cursor:help;
  border-bottom:1px dashed var(--an-muted);
}
.an-tooltip:hover::after {
  content:attr(data-tip);
  position:absolute; bottom:130%; left:50%; transform:translateX(-50%);
  background:#1f2937; color:#fff; font-size:.72rem; font-weight:500;
  padding:5px 10px; border-radius:6px; white-space:nowrap; z-index:99;
  pointer-events:none;
}
</style>

<?php /* ══════════════════════════════════════════════════════════════
   SECTION 0 — Page header
══════════════════════════════════════════════════════════════ */ ?>
<div class="an-page-header">
  <h1>Impact Analytics</h1>
  <form method="get" class="an-range-form">
    <?php
      // Pass through current page param if present
      if (isset($_GET['p'])) {
          echo '<input type="hidden" name="p" value="' . e($_GET['p']) . '">';
      }
    ?>
    <?php foreach ($rangeOptions as $key => $label): ?>
      <a href="?<?= isset($_GET['p']) ? 'p=' . e($_GET['p']) . '&' : '' ?>range=<?= $key ?>"
         class="an-range-btn<?= $range === $key ? ' active' : '' ?>">
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </form>
</div>

<?php /* ══════════════════════════════════════════════════════════════
   SECTION 1 — Impact KPI tiles
══════════════════════════════════════════════════════════════ */ ?>
<div class="dp-card" style="margin-bottom:28px;">
  <h2 class="an-section-head">Impact Assessment KPIs</h2>
  <div class="an-kpi-grid">

    <!-- Pre-Test Avg -->
    <div class="an-kpi amber">
      <div class="val"><?= $preAvg ?>%</div>
      <div class="lbl">Avg Pre-Test Score</div>
      <div class="sub">Before instruction</div>
    </div>

    <!-- Post-Test Avg -->
    <div class="an-kpi green">
      <div class="val"><?= $postAvg ?>%</div>
      <div class="lbl">Avg Post-Test Score</div>
      <div class="sub">After instruction</div>
    </div>

    <!-- Knowledge Gain -->
    <div class="an-kpi <?= $gain > 0 ? 'green' : ($gain < 0 ? 'red' : 'amber') ?>">
      <div class="val" style="color:<?= $gain > 0 ? '#065f46' : ($gain < 0 ? '#991b1b' : '#92400e') ?>">
        <?= $gain > 0 ? '+' : '' ?><?= $gain ?>%
      </div>
      <div class="lbl">Knowledge Gain</div>
      <div class="sub">Post minus Pre avg</div>
    </div>

    <!-- Normalised Gain Index -->
    <div class="an-kpi <?= $normGain > 0 ? 'green' : 'amber' ?>">
      <div class="val">
        <span class="an-tooltip" data-tip="0% = no gain, 100% = maximum possible gain">
          <?= $normGain ?>%
        </span>
      </div>
      <div class="lbl">Normalised Gain Index</div>
      <div class="sub">(post-pre)÷(100-pre)×100</div>
    </div>

    <!-- Learners with Pre+Post -->
    <div class="an-kpi blue">
      <div class="val"><?= $bothCount ?></div>
      <div class="lbl">Learners Pre + Post</div>
      <div class="sub">Complete test pairs</div>
    </div>

    <!-- Pass Rate -->
    <div class="an-kpi <?= $passRate >= 60 ? 'green' : 'amber' ?>">
      <div class="val"><?= $passRate ?>%</div>
      <div class="lbl">Quiz Pass Rate</div>
      <div class="sub"><?= $passCount ?> / <?= $totalAttempts ?> attempts ≥60%</div>
    </div>

    <!-- Completion Rate -->
    <div class="an-kpi blue">
      <div class="val"><?= $completionRate ?>%</div>
      <div class="lbl">Completion Rate</div>
      <div class="sub"><?= $completedSome ?> / <?= $totalActive ?> learners</div>
    </div>

    <!-- Certificates -->
    <div class="an-kpi dark">
      <div class="val"><?= $totalCerts ?></div>
      <div class="lbl">Certificates Issued</div>
      <div class="sub">All time</div>
    </div>

  </div>
</div>

<?php /* ══════════════════════════════════════════════════════════════
   SECTION 2 — Completion Funnel (overall)
══════════════════════════════════════════════════════════════ */ ?>
<div class="dp-card" style="margin-bottom:28px;">
  <h2 class="an-section-head">Completion Funnel — Learning Journey</h2>
  <div class="an-funnel">
    <?php foreach ($funnelSteps as $idx => $step): ?>
      <?php
        $barPct   = $funnelTop > 0 ? min(100, round($step['count'] / $funnelTop * 100)) : 0;
        $topPct   = $funnelTop > 0 ? round($step['count'] / $funnelTop * 100, 1) : 0;
        // Drop-off from previous step
        $dropOff  = null;
        if ($idx > 0 && $funnelSteps[$idx - 1]['count'] > 0) {
            $dropOff = round((1 - $step['count'] / max(1, $funnelSteps[$idx - 1]['count'])) * 100, 1);
        }
      ?>
      <?php if ($dropOff !== null && $dropOff > 0): ?>
        <div class="an-dropoff">&#9660; <?= $dropOff ?>% drop-off from previous step</div>
      <?php endif; ?>
      <div class="an-funnel-step">
        <div class="an-funnel-label"><?= (string)($idx + 1) ?>. <?= e($step['label']) ?></div>
        <div class="an-funnel-bar-bg">
          <div class="an-funnel-bar-fill"
               style="width:<?= $barPct ?>%; background:<?= $step['color'] ?>;"></div>
        </div>
        <div class="an-funnel-count" style="color:<?= $step['color'] ?>">
          <?= number_format($step['count']) ?>
        </div>
        <div class="an-funnel-pct"><?= $topPct ?>% of total</div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php /* ══════════════════════════════════════════════════════════════
   SECTION 3 — Knowledge Gain by Module
══════════════════════════════════════════════════════════════ */ ?>
<div class="dp-card" style="margin-bottom:28px;">
  <h2 class="an-section-head">Knowledge Gain by Module</h2>
  <?php if (empty($modGainList)): ?>
    <p style="color:var(--an-muted);font-size:.88rem;">No module data available yet.</p>
  <?php else: ?>
  <div class="an-table-wrap">
    <table class="arise-table">
      <thead>
        <tr>
          <th>Module</th>
          <th>Pre-Test Avg</th>
          <th>Post-Test Avg</th>
          <th>Gain</th>
          <th>Norm. Gain Index</th>
          <th>Learners Tested</th>
          <th>Certs</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($modGainList as $i => $m): ?>
        <?php
          $gc  = gainColor($m['gain']);
          $gbg = gainBg($m['gain']);
          $gSign = ($m['gain'] !== null && $m['gain'] > 0) ? '+' : '';
        ?>
        <tr style="<?= $i % 2 === 1 ? 'background:#f9fafb;' : '' ?>">
          <td>
            <strong style="font-size:.88rem;">
              <?= e($m['icon'] ?? '') ?> <?= e($m['title']) ?>
            </strong>
          </td>
          <td><?= $m['pre_avg']  !== null ? $m['pre_avg']  . '%' : '<span style="color:#9ca3af">—</span>' ?></td>
          <td><?= $m['post_avg'] !== null ? $m['post_avg'] . '%' : '<span style="color:#9ca3af">—</span>' ?></td>
          <td>
            <?php if ($m['gain'] !== null): ?>
              <span style="background:<?= $gbg ?>;color:<?= $gc ?>;padding:3px 10px;border-radius:50px;font-size:.78rem;font-weight:800;">
                <?= $gSign . $m['gain'] ?>%
              </span>
            <?php else: ?>
              <span style="color:#9ca3af;font-size:.82rem;">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($m['norm_gain'] !== null): ?>
              <span class="an-tooltip" data-tip="0% = no gain, 100% = maximum possible gain"
                    style="font-weight:700;color:<?= gainColor($m['norm_gain']) ?>;">
                <?= $m['norm_gain'] ?>%
              </span>
            <?php else: ?>
              <span style="color:#9ca3af">—</span>
            <?php endif; ?>
          </td>
          <td><?= number_format($m['learners_tested']) ?></td>
          <td><?= number_format((int)($m['certs'] ?? 0)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ══════════════════════════════════════════════════════════════
   SECTION 4 — Cohort Comparison
══════════════════════════════════════════════════════════════ */ ?>
<div class="dp-card" style="margin-bottom:28px;">
  <h2 class="an-section-head">Cohort Comparison (Project / Cluster)</h2>
  <?php if (empty($cohortList)): ?>
    <p style="color:var(--an-muted);font-size:.88rem;">No cohort data yet.</p>
  <?php else: ?>
  <div class="an-table-wrap">
    <table class="arise-table">
      <thead>
        <tr>
          <th>Project (School)</th>
          <th>Cluster (Class)</th>
          <th>Learners</th>
          <th>Avg Quiz Score</th>
          <th>Pass Rate</th>
          <th>Certs</th>
          <th>Knowledge Gain</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cohortList as $i => $c): ?>
        <?php
          $kg = ($c['knowledge_gain'] !== null) ? (float)$c['knowledge_gain'] : null;
          $gc  = gainColor($kg);
          $gbg = gainBg($kg);
          $kSign = ($kg !== null && $kg > 0) ? '+' : '';
          $scoreColor = ($c['avg_score'] >= 60) ? '#065f46' : (($c['avg_score'] >= 40) ? '#92400e' : '#991b1b');
        ?>
        <tr style="<?= $i % 2 === 1 ? 'background:#f9fafb;' : '' ?>">
          <td style="font-weight:700;font-size:.85rem;"><?= e($c['project'] ?? '—') ?></td>
          <td style="font-size:.85rem;"><?= e($c['cluster'] ?? '—') ?></td>
          <td><?= number_format((int)($c['learners'] ?? 0)) ?></td>
          <td>
            <?php if ($c['avg_score'] !== null): ?>
              <strong style="color:<?= $scoreColor ?>"><?= $c['avg_score'] ?>%</strong>
            <?php else: ?>
              <span style="color:#9ca3af">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($c['pass_rate'] !== null): ?>
              <span style="font-weight:700;color:<?= (float)$c['pass_rate'] >= 60 ? '#065f46' : '#92400e' ?>">
                <?= $c['pass_rate'] ?>%
              </span>
            <?php else: ?>
              <span style="color:#9ca3af">—</span>
            <?php endif; ?>
          </td>
          <td><?= number_format((int)($c['certs'] ?? 0)) ?></td>
          <td>
            <?php if ($kg !== null): ?>
              <span style="background:<?= $gbg ?>;color:<?= $gc ?>;padding:3px 10px;border-radius:50px;font-size:.78rem;font-weight:800;">
                <?= $kSign . $kg ?>%
              </span>
            <?php else: ?>
              <span style="color:#9ca3af">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ══════════════════════════════════════════════════════════════
   SECTION 5 — Per-Module Mini Funnels
══════════════════════════════════════════════════════════════ */ ?>
<div class="dp-card" style="margin-bottom:28px;">
  <h2 class="an-section-head">Completion Funnel per Module (Top 8 by Views)</h2>
  <?php if (empty($topModules)): ?>
    <p style="color:var(--an-muted);font-size:.88rem;">No module view data yet.</p>
  <?php else: ?>
  <div class="an-mini-grid">
    <?php foreach ($topModules as $m): ?>
      <?php
        $base = max($m['views'], 1);
        $mini = [
          ['label' => 'Views',         'val' => (int)$m['views'],        'color' => '#052e16'],
          ['label' => 'Pre-Test Done', 'val' => (int)$m['pre_done'],     'color' => '#0ea271'],
          ['label' => 'Lessons Done',  'val' => (int)$m['lessons_done'], 'color' => '#3b82f6'],
          ['label' => 'Post-Test Done','val' => (int)$m['post_done'],    'color' => '#f59e0b'],
          ['label' => 'Cert Earned',   'val' => (int)$m['cert_earned'],  'color' => '#d97706'],
        ];
      ?>
      <div class="an-mini-card">
        <h4><?= e($m['icon'] ?? '') ?> <?= e($m['title']) ?></h4>
        <?php foreach ($mini as $row): ?>
          <?php $pct = $base > 0 ? min(100, round($row['val'] / $base * 100)) : 0; ?>
          <div class="an-mini-row">
            <span style="color:#374151;"><?= e($row['label']) ?></span>
            <strong style="color:<?= $row['color'] ?>"><?= number_format($row['val']) ?></strong>
          </div>
          <div class="an-mini-bar-bg">
            <div class="an-mini-bar-fill"
                 style="width:<?= $pct ?>%;height:5px;background:<?= $row['color'] ?>;border-radius:50px;"></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php /* ══════════════════════════════════════════════════════════════
   SECTION 6 — Daily Activity Chart
══════════════════════════════════════════════════════════════ */ ?>
<div class="dp-card" style="margin-bottom:28px;">
  <h2 class="an-section-head">Daily Activity — Last <?= $chartDays ?> Days</h2>
  <?php
    $hasAnyActivity = array_sum(array_column($dailyChart, 'sessions')) > 0;
  ?>
  <?php if (!$hasAnyActivity): ?>
    <p style="color:var(--an-muted);font-size:.88rem;">No page view activity in this period.</p>
  <?php else: ?>
  <div style="overflow-x:auto;">
    <div style="display:flex; align-items:flex-end; gap:4px; height:160px; min-width:<?= $chartDays * 16 ?>px; padding-bottom:28px; position:relative;">
      <?php foreach ($dailyChart as $idx => $day): ?>
        <?php
          $barH  = $chartMax > 0 ? max(3, round($day['sessions'] / $chartMax * 110)) : 3;
          $showLabel = ($idx % 5 === 0 || $idx === count($dailyChart) - 1);
          $dayLabel  = date('M j', strtotime($day['date']));
          $alpha = $day['sessions'] > 0 ? '1' : '0.25';
        ?>
        <div style="flex:1; min-width:14px; display:flex; flex-direction:column; align-items:center; position:relative;">
          <?php if ($day['sessions'] > 0): ?>
            <span style="font-size:.6rem;font-weight:800;color:#065f46;margin-bottom:2px;"><?= $day['sessions'] ?></span>
          <?php else: ?>
            <span style="font-size:.6rem;font-weight:800;color:transparent;">0</span>
          <?php endif; ?>
          <div style="width:100%; border-radius:4px 4px 0 0; height:<?= $barH ?>px;
               background:linear-gradient(180deg,#0ea271,#065f46); opacity:<?= $alpha ?>;"></div>
          <?php if ($showLabel): ?>
            <span style="font-size:.58rem; color:#6b7280; margin-top:3px; white-space:nowrap;
                         transform:rotate(-40deg); transform-origin:top left; position:absolute; bottom:0; left:4px;">
              <?= e($dayLabel) ?>
            </span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div style="display:flex; gap:16px; margin-top:8px; font-size:.75rem; color:#6b7280; align-items:center;">
    <span style="display:flex;align-items:center;gap:5px;">
      <span style="width:12px;height:12px;background:#0ea271;border-radius:3px;display:inline-block;"></span>
      Unique sessions per day
    </span>
    <span>Peak: <?= $chartMax ?> sessions</span>
  </div>
  <?php endif; ?>
</div>

<?php /* ══════════════════════════════════════════════════════════════
   SECTION 7 — Behavioral Survey Results
══════════════════════════════════════════════════════════════ */ ?>
<div class="dp-card" style="margin-bottom:28px;">
  <h2 class="an-section-head">Behavioral Survey Results</h2>
  <?php if ($surveyTotal === 0): ?>
    <div class="alert alert-info" style="margin-bottom:0;">
      No survey responses yet. Surveys appear after module completion.
    </div>
  <?php else: ?>
  <p style="font-size:.82rem;color:#6b7280;margin-bottom:16px;">
    Based on <?= number_format($surveyTotal) ?> survey response<?= $surveyTotal !== 1 ? 's' : '' ?>
  </p>
  <div class="an-survey-grid">
    <div class="an-survey-tile">
      <div class="sv-pct"><?= $surveyChanged ?>%</div>
      <div class="sv-lbl">Changed their behaviour after learning</div>
    </div>
    <div class="an-survey-tile" style="background:#eff6ff;border-color:#bfdbfe;">
      <div class="sv-pct" style="color:#1e40af;"><?= $surveyShared ?>%</div>
      <div class="sv-lbl">Shared knowledge with peers</div>
    </div>
    <div class="an-survey-tile" style="background:#fffbeb;border-color:#fde68a;">
      <div class="sv-pct" style="color:#92400e;"><?= $surveyConfident ?>%</div>
      <div class="sv-lbl">Feel confident applying skills</div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php /* ══════════════════════════════════════════════════════════════
   SECTION 8 — Most Difficult Questions
══════════════════════════════════════════════════════════════ */ ?>
<div class="dp-card" style="margin-bottom:28px;">
  <h2 class="an-section-head">Most Difficult Questions (Top 10 by Wrong-Answer Rate)</h2>
  <?php if (empty($difficultQs)): ?>
    <p style="color:var(--an-muted);font-size:.88rem;">Not enough quiz answer data yet (need ≥3 attempts per question).</p>
  <?php else: ?>
  <div class="an-table-wrap">
    <table class="arise-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Module</th>
          <th>Question</th>
          <th>Wrong %</th>
          <th>Attempts</th>
          <th>Difficulty</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($difficultQs as $i => $q): ?>
        <?php
          $wp     = (float)($q['wrong_pct'] ?? 0);
          $wpColor = $wp >= 70 ? '#991b1b' : ($wp >= 50 ? '#92400e' : '#374151');
          $wpBg    = $wp >= 70 ? '#fee2e2' : ($wp >= 50 ? '#fef3c7' : '#f3f4f6');
          $difficulty = $wp >= 70 ? 'Hard' : ($wp >= 50 ? 'Moderate' : 'Mild');
        ?>
        <tr style="<?= $i % 2 === 1 ? 'background:#f9fafb;' : '' ?>">
          <td style="font-weight:700;color:#6b7280;font-size:.82rem;"><?= $i + 1 ?></td>
          <td>
            <span class="badge" style="background:#f0fdf4;color:#065f46;font-size:.7rem;">
              <?= e($q['module_title'] ?? '—') ?>
            </span>
          </td>
          <td style="max-width:320px;font-size:.83rem;">
            <?php
              $qText = $q['question'] ?? '';
              echo e(strlen($qText) > 90 ? substr($qText, 0, 90) . '…' : $qText);
            ?>
          </td>
          <td>
            <span style="background:<?= $wpBg ?>;color:<?= $wpColor ?>;padding:4px 12px;
                         border-radius:50px;font-size:.82rem;font-weight:800;">
              <?= $wp ?>%
            </span>
          </td>
          <td style="font-weight:700;"><?= number_format((int)($q['total_attempts'] ?? 0)) ?></td>
          <td>
            <span style="font-size:.78rem;font-weight:700;color:<?= $wpColor ?>;">
              <?= $difficulty ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
