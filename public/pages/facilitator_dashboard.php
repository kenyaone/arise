<?php
/**
 * ARISE — Facilitator Live Dashboard (PUBLIC page)
 * URL: /arise/?p=facilitator
 * Open to anyone on the LAN — shows real names to facilitators who know their learners.
 * Auto-refreshes every 20 seconds when a cluster is selected.
 */

$today    = date('Y-m-d');
$todayTs  = date('Y-m-d H:i:s');

// ── Fetch projects & clusters ───────────────────────────────────────────────
$projects = [];
try {
    $pRes = db()->query("SELECT DISTINCT s.school_name FROM students s INNER JOIN schools sc ON sc.name = s.school_name AND sc.is_active=1 WHERE s.is_active=1 AND s.school_name IS NOT NULL AND s.school_name != '' ORDER BY s.school_name");
    while ($r = $pRes->fetchArray(SQLITE3_ASSOC)) {
        $projects[] = $r['school_name'];
    }
} catch (Exception $e) { /* table may not exist yet */ }

$clusters = [];
$selProject = trim($_GET['project'] ?? '');
$selCluster = trim($_GET['cluster'] ?? '');

if ($selProject) {
    try {
        $cRes = db()->query(
            "SELECT DISTINCT class_name FROM students
             WHERE is_active=1
               AND school_name='" . SQLite3::escapeString($selProject) . "'
               AND class_name IS NOT NULL AND class_name != ''
             ORDER BY class_name"
        );
        while ($r = $cRes->fetchArray(SQLITE3_ASSOC)) {
            $clusters[] = $r['class_name'];
        }
    } catch (Exception $e) {}
}

// ── Learner grid data ────────────────────────────────────────────────────────
$learners     = [];
$clusterStats = ['total' => 0, 'active_today' => 0, 'avg_score' => 0, 'certs' => 0];
$hasCluster   = ($selProject !== '' && $selCluster !== '');

if ($hasCluster) {
    try {
        $lRes = db()->query(
            "SELECT s.id, s.full_name, s.class_name, s.school_name, s.last_seen,
                    (SELECT COUNT(DISTINCT module_id) FROM lesson_progress lp
                     WHERE lp.student_id=s.id) AS modules_started,
                    (SELECT COUNT(*) FROM lesson_progress lp
                     WHERE lp.student_id=s.id AND lp.completed=1) AS lessons_done,
                    (SELECT COUNT(*) FROM pretest_attempts pa
                     WHERE pa.student_id=s.id) AS pretests_done,
                    (SELECT ROUND(AVG(qa.percentage),1) FROM quiz_attempts qa
                     WHERE qa.student_id=s.id) AS avg_quiz_score,
                    (SELECT qa2.percentage FROM quiz_attempts qa2
                     WHERE qa2.student_id=s.id ORDER BY qa2.completed_at DESC LIMIT 1) AS latest_score,
                    (SELECT COUNT(*) FROM certificates c
                     WHERE c.student_id=s.id) AS certs_earned
             FROM students s
             WHERE s.is_active=1
               AND s.school_name='" . SQLite3::escapeString($selProject) . "'
               AND s.class_name='"  . SQLite3::escapeString($selCluster) . "'
             ORDER BY s.last_seen DESC"
        );
        while ($r = $lRes->fetchArray(SQLITE3_ASSOC)) {
            $learners[] = $r;
        }
    } catch (Exception $e) {}

    // Cluster summary
    $clusterStats['total'] = count($learners);
    foreach ($learners as $l) {
        if ($l['last_seen'] && substr($l['last_seen'], 0, 10) === $today) {
            $clusterStats['active_today']++;
        }
        $clusterStats['certs'] += intval($l['certs_earned']);
    }
    $scores = array_filter(array_column($learners, 'avg_quiz_score'), fn($v) => $v !== null && $v !== '');
    $clusterStats['avg_score'] = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

    // Pregnancy Prevention Reach — students who completed branching + passed post-test on any module
    $pgStudentIds = [];
    if (count($learners) > 0) {
        $sidList = implode(',', array_map(fn($l) => intval($l['id']), $learners));
        $pgRes = db()->query(
            "SELECT DISTINCT li.student_id
             FROM lesson_interactions li
             WHERE li.student_id IN ($sidList)
               AND li.interaction_type='branching' AND li.done=1
               AND li.student_id IN (
                   SELECT pa.student_id FROM pretest_attempts pa
                   WHERE pa.student_id IN ($sidList) AND pa.test_type='post' AND pa.percentage >= 65
               )"
        );
        while ($pgRow = $pgRes->fetchArray(SQLITE3_ASSOC)) {
            $pgStudentIds[] = $pgRow['student_id'];
        }
    }
    $clusterStats['pg_reach'] = count($pgStudentIds);
    $clusterStats['pg_pct']   = $clusterStats['total'] > 0
        ? round($clusterStats['pg_reach'] / $clusterStats['total'] * 100)
        : 0;
}

// ── Row colour helper ────────────────────────────────────────────────────────
function rowStatus(array $l): string {
    if ($l['certs_earned'] > 0) return 'green';
    $score = floatval($l['latest_score'] ?? 0);
    if ($score >= 60)            return 'green';
    if ($l['lessons_done'] > 0 || $l['modules_started'] > 0) return 'amber';
    return 'grey';
}

function statusLabel(string $s): string {
    return match($s) {
        'green' => '&#10004; Passed / Certified',
        'amber' => '&#8987; In Progress',
        default => '&#8213; No Activity',
    };
}

// ── Last-active formatter ────────────────────────────────────────────────────
function lastSeen(?string $ts): string {
    if (!$ts) return 'Never';
    $diff = time() - strtotime($ts);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    return date('M j', strtotime($ts));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ARISE — Facilitator Dashboard</title>
<link rel="stylesheet" href="/arise/css/style.css">
<style>
/* ── Page shell ─────────────────────────────────────────── */
body { background: #f1f5f2; }
.fac-wrap { max-width: 1100px; margin: 0 auto; padding: 20px 16px 48px; }

/* ── Header bar ─────────────────────────────────────────── */
.fac-header {
    background: linear-gradient(135deg,#052e16,#0a5e2a);
    color: #fff;
    border-radius: 16px;
    padding: 20px 24px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.fac-header h1 { font-size: 1.25rem; font-weight: 800; margin: 0; }
.fac-header p  { font-size: .82rem; color: rgba(255,255,255,.6); margin: 3px 0 0; }
.live-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 20px;
    padding: 6px 14px;
    font-size: .8rem;
    font-weight: 700;
    color: #6ee7b7;
}
.live-dot {
    width: 8px; height: 8px;
    background: #6ee7b7;
    border-radius: 50%;
    animation: pulse 1.6s ease-in-out infinite;
}
@keyframes pulse {
    0%,100% { opacity:1; transform:scale(1); }
    50%      { opacity:.4; transform:scale(.7); }
}

/* ── Filter bar ─────────────────────────────────────────── */
.filter-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-end;
    margin-bottom: 20px;
}
.filter-bar label {
    font-size: .75rem;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .4px;
    display: block;
    margin-bottom: 5px;
}
.filter-bar select {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: .9rem;
    font-family: inherit;
    background: #fff;
    min-width: 200px;
}
.filter-bar select:focus {
    outline: none;
    border-color: #0ea271;
}

/* ── Summary tiles ──────────────────────────────────────── */
.summary-row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px,1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.s-tile {
    background: #fff;
    border-radius: 12px;
    padding: 16px;
    border: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    gap: 12px;
}
.s-tile .ico {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.s-tile .val { font-size: 1.4rem; font-weight: 800; line-height: 1; }
.s-tile .lbl { font-size: .72rem; color: #6b7280; margin-top: 3px; }

/* ── Learner grid ───────────────────────────────────────── */
.learner-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px,1fr));
    gap: 12px;
}
.learner-card {
    background: #fff;
    border-radius: 12px;
    border: 2px solid #e5e7eb;
    padding: 16px;
    position: relative;
    transition: box-shadow .15s;
}
.learner-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }

/* status borders */
.learner-card.green { border-color: #86efac; background: #f0fdf4; }
.learner-card.amber { border-color: #fde68a; background: #fffbeb; }
.learner-card.grey  { border-color: #e5e7eb; background: #f9fafb; }

.lc-name {
    font-size: .95rem;
    font-weight: 800;
    color: #111;
    margin-bottom: 2px;
    padding-right: 70px;
}
.lc-meta {
    font-size: .75rem;
    color: #9ca3af;
    margin-bottom: 10px;
}
.lc-badge {
    position: absolute;
    top: 14px;
    right: 14px;
    font-size: .7rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
}
.lc-badge.green { background:#dcfce7; color:#166534; }
.lc-badge.amber { background:#fef3c7; color:#92400e; }
.lc-badge.grey  { background:#f3f4f6; color:#6b7280; }

.lc-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}
.lc-stat {
    background: rgba(0,0,0,.04);
    border-radius: 8px;
    padding: 7px 10px;
}
.lc-stat .sv { font-size: 1.1rem; font-weight: 800; color: #111; }
.lc-stat .sl { font-size: .7rem; color: #6b7280; }

.lc-last {
    margin-top: 10px;
    font-size: .73rem;
    color: #9ca3af;
    display: flex;
    align-items: center;
    gap: 4px;
}
.score-val { color: #166534; }
.score-val.low { color: #dc2626; }
.score-val.mid { color: #d97706; }

/* ── Empty states ───────────────────────────────────────── */
.empty-msg {
    text-align: center;
    padding: 40px 24px;
    color: #9ca3af;
    font-size: .9rem;
}
.empty-msg .big { font-size: 2.5rem; margin-bottom: 10px; }

/* ── Table view toggle ──────────────────────────────────── */
.view-toggle {
    display: flex;
    gap: 6px;
    margin-bottom: 16px;
    align-items: center;
}
.view-toggle button {
    padding: 6px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: .8rem;
    font-weight: 600;
    background: #fff;
    cursor: pointer;
    font-family: inherit;
    color: #374151;
}
.view-toggle button.active {
    border-color: #0ea271;
    background: #f0fdf4;
    color: #065f46;
}

/* ── Arise table ────────────────────────────────────────── */
.learner-tbl { overflow-x: auto; }
.learner-tbl table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.learner-tbl th {
    background: #f3f4f6;
    padding: 10px 12px;
    text-align: left;
    font-size: .75rem;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}
.learner-tbl td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.learner-tbl tr:hover td { background: #f9fafb; }
.row-green td:first-child { border-left: 3px solid #86efac; }
.row-amber td:first-child { border-left: 3px solid #fde68a; }
.row-grey  td:first-child { border-left: 3px solid #e5e7eb; }

@media (max-width: 600px) {
    .learner-grid { grid-template-columns: 1fr; }
    .summary-row  { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<div class="fac-wrap">

  <!-- Header -->
  <div class="fac-header">
    <div>
      <h1>&#128247; Facilitator Live View</h1>
      <p><?= date('l, F j, Y \a\t g:i A') ?> &middot; ARISE Health Education Platform</p>
    </div>
    <?php if ($hasCluster): ?>
    <div class="live-badge">
      <div class="live-dot"></div>
      Live &mdash; updates every 20s
    </div>
    <?php endif; ?>
  </div>

  <!-- Filter bar -->
  <form method="GET" id="filterForm">
    <input type="hidden" name="p" value="facilitator">
    <div class="filter-bar">
      <div>
        <label for="sel_project">Project / School</label>
        <select name="project" id="sel_project" onchange="this.form.submit()">
          <option value="">-- Select project --</option>
          <?php foreach ($projects as $proj): ?>
            <option value="<?= e($proj) ?>" <?= ($selProject === $proj) ? 'selected' : '' ?>>
              <?= e($proj) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($selProject && count($clusters)): ?>
      <div>
        <label for="sel_cluster">Cluster / Class</label>
        <select name="cluster" id="sel_cluster" onchange="this.form.submit()">
          <option value="">-- Select cluster --</option>
          <?php foreach ($clusters as $cl): ?>
            <option value="<?= e($cl) ?>" <?= ($selCluster === $cl) ? 'selected' : '' ?>>
              <?= e($cl) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php elseif ($selProject && !count($clusters)): ?>
      <div style="align-self:center;font-size:.84rem;color:#9ca3af;">
        No clusters found for this project.
      </div>
      <?php endif; ?>

      <?php if ($hasCluster): ?>
      <div style="align-self:flex-end;">
        <button type="submit" class="btn btn-secondary" style="padding:10px 18px;">
          &#8635; Refresh
        </button>
      </div>
      <?php endif; ?>
    </div>
  </form>

  <?php if (!$selProject): ?>
  <!-- Prompt to select -->
  <div class="dp-card">
    <div class="empty-msg">
      <div class="big">&#128247;</div>
      <strong style="display:block;font-size:1rem;color:#374151;margin-bottom:6px;">
        Select a project and cluster to view learner activity
      </strong>
      <span>This screen auto-refreshes every 20 seconds to show live progress.</span>
    </div>
  </div>

  <?php elseif (!$selCluster): ?>
  <!-- Project selected, need cluster -->
  <div class="dp-card">
    <div class="empty-msg">
      <div class="big">&#128101;</div>
      <strong style="display:block;font-size:1rem;color:#374151;margin-bottom:6px;">
        Now choose a cluster for <?= e($selProject) ?>
      </strong>
    </div>
  </div>

  <?php else: ?>

  <!-- ── Summary stats ─────────────────────────────────────────── -->
  <div class="summary-row">
    <?php
    $tiles = [
      ['#dcfce7','#166534','&#128101;', $clusterStats['total'],        '#111',     'Total Learners'],
      ['#dbeafe','#1e40af','&#128197;', $clusterStats['active_today'], '#1e40af',  'Active Today'],
      ['#fef9c3','#92400e','&#128202;', $clusterStats['avg_score'].'%','#92400e',  'Avg Score'],
      ['#fce7f3','#9d174d','&#127891;', $clusterStats['certs'],        '#9d174d',  'Certs Earned'],
    ];
    foreach ($tiles as [$bg,$col,$ico,$val,$vcol,$lbl]):
    ?>
    <div class="s-tile" style="background:<?= $bg ?>22;border-color:<?= $bg ?>;">
      <div class="ico" style="background:<?= $bg ?>;color:<?= $col ?>"><?= $ico ?></div>
      <div>
        <div class="val" style="color:<?= $vcol ?>"><?= $val ?></div>
        <div class="lbl"><?= $lbl ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pregnancy Prevention Reach Card -->
  <div class="dp-card" style="background:linear-gradient(135deg,#450a0a,#7c1d1d);color:#fff;margin-bottom:16px;border:none;">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
      <div style="font-size:2rem;">🛡️</div>
      <div style="flex:1;">
        <div style="font-size:.78rem;font-weight:700;opacity:.8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;">Pregnancy Prevention Reach</div>
        <div style="font-size:1.6rem;font-weight:900;line-height:1.1;">
          <?= $clusterStats['pg_reach'] ?? 0 ?> <span style="font-size:1rem;font-weight:600;opacity:.8;">/ <?= $clusterStats['total'] ?> learners</span>
        </div>
        <div style="font-size:.8rem;opacity:.85;margin-top:4px;">
          <?= $clusterStats['pg_pct'] ?? 0 ?>% completed abstinence branching <em>and</em> passed their post-test (≥65%)
        </div>
      </div>
      <div style="text-align:center;padding:10px 18px;background:rgba(255,255,255,.12);border-radius:10px;">
        <div style="font-size:1.4rem;font-weight:900;"><?= $clusterStats['pg_pct'] ?? 0 ?>%</div>
        <div style="font-size:.7rem;opacity:.8;">Reach Rate</div>
      </div>
    </div>
  </div>

  <!-- Legend + view toggle -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
    <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:.8rem;font-weight:600;">
      <span style="color:#166534;">&#9646; Passed / Certified</span>
      <span style="color:#d97706;">&#9646; In Progress</span>
      <span style="color:#9ca3af;">&#9646; No Activity</span>
    </div>
    <div class="view-toggle">
      <button type="button" class="active" id="btnGrid" onclick="setView('grid')">&#9638; Cards</button>
      <button type="button" id="btnTable" onclick="setView('table')">&#9776; Table</button>
    </div>
  </div>

  <?php if (!count($learners)): ?>
  <div class="dp-card">
    <div class="empty-msg">
      <div class="big">&#128101;</div>
      No learners registered in this cluster yet.
    </div>
  </div>

  <?php else: ?>

  <!-- ── Card grid view ──────────────────────────────────────────── -->
  <div class="learner-grid" id="viewGrid">
    <?php foreach ($learners as $l):
      $status = rowStatus($l);
      $score  = $l['latest_score'] !== null ? floatval($l['latest_score']) : null;
      $scoreClass = ($score === null) ? '' : ($score >= 60 ? 'score-val' : ($score >= 40 ? 'score-val mid' : 'score-val low'));
    ?>
    <div class="learner-card <?= $status ?>">
      <span class="lc-badge <?= $status ?>"><?= statusLabel($status) ?></span>
      <div class="lc-name"><?= e($l['full_name']) ?></div>
      <div class="lc-meta"><?= e($l['class_name'] ?? '') ?></div>
      <div class="lc-stats">
        <div class="lc-stat">
          <div class="sv"><?= intval($l['modules_started']) ?></div>
          <div class="sl">Modules started</div>
        </div>
        <div class="lc-stat">
          <div class="sv"><?= intval($l['lessons_done']) ?></div>
          <div class="sl">Lessons done</div>
        </div>
        <div class="lc-stat">
          <div class="sv"><?= intval($l['pretests_done']) ?></div>
          <div class="sl">Tests taken</div>
        </div>
        <div class="lc-stat">
          <div class="sv <?= $scoreClass ?>"><?= $score !== null ? $score.'%' : '—' ?></div>
          <div class="sl">Latest score</div>
        </div>
      </div>
      <?php if ($l['certs_earned'] > 0): ?>
      <div style="margin-top:10px;font-size:.8rem;font-weight:700;color:#166534;">
        &#127891; <?= $l['certs_earned'] ?> certificate<?= $l['certs_earned'] > 1 ? 's' : '' ?> earned
      </div>
      <?php endif; ?>
      <div class="lc-last">
        &#9200; Last active: <?= lastSeen($l['last_seen'] ?? null) ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Table view ────────────────────────────────────────────────── -->
  <div class="learner-tbl dp-card" id="viewTable" style="display:none;padding:0;">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Modules</th>
          <th>Lessons</th>
          <th>Tests</th>
          <th>Latest Score</th>
          <th>Certs</th>
          <th>Last Active</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($learners as $i => $l):
          $status = rowStatus($l);
          $score  = $l['latest_score'] !== null ? floatval($l['latest_score']) : null;
          $scoreColor = ($score === null) ? '#9ca3af' : ($score >= 60 ? '#166534' : ($score >= 40 ? '#d97706' : '#dc2626'));
        ?>
        <tr class="row-<?= $status ?>">
          <td style="color:#9ca3af;font-size:.8rem;"><?= $i+1 ?></td>
          <td><strong><?= e($l['full_name']) ?></strong></td>
          <td><?= intval($l['modules_started']) ?></td>
          <td><?= intval($l['lessons_done']) ?></td>
          <td><?= intval($l['pretests_done']) ?></td>
          <td style="font-weight:700;color:<?= $scoreColor ?>;">
            <?= $score !== null ? $score.'%' : '&mdash;' ?>
          </td>
          <td><?= intval($l['certs_earned']) ?><?= $l['certs_earned'] > 0 ? ' &#127891;' : '' ?></td>
          <td style="font-size:.8rem;color:#6b7280;"><?= lastSeen($l['last_seen'] ?? null) ?></td>
          <td>
            <span style="font-size:.75rem;font-weight:700;padding:3px 10px;border-radius:20px;
              background:<?= $status==='green'?'#dcfce7':($status==='amber'?'#fef3c7':'#f3f4f6') ?>;
              color:<?= $status==='green'?'#166534':($status==='amber'?'#92400e':'#6b7280') ?>;">
              <?= $status==='green' ? 'Passed' : ($status==='amber' ? 'In Progress' : 'No Activity') ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php endif; // learners ?>
  <?php endif; // hasCluster ?>

  <!-- Footer note -->
  <div style="text-align:center;margin-top:24px;font-size:.78rem;color:#9ca3af;">
    ARISE Facilitator View &mdash; Read-only &middot; Accessible on local network
  </div>

</div><!-- /fac-wrap -->

<script>
// ── View toggle ──────────────────────────────────────────────────────────────
function setView(v) {
    var grid  = document.getElementById('viewGrid');
    var table = document.getElementById('viewTable');
    var btnG  = document.getElementById('btnGrid');
    var btnT  = document.getElementById('btnTable');
    if (!grid) return;
    if (v === 'grid') {
        grid.style.display  = '';
        table.style.display = 'none';
        btnG.classList.add('active');
        btnT.classList.remove('active');
        localStorage.setItem('fac_view','grid');
    } else {
        grid.style.display  = 'none';
        table.style.display = '';
        btnT.classList.add('active');
        btnG.classList.remove('active');
        localStorage.setItem('fac_view','table');
    }
}
// Restore saved view
(function() {
    var saved = localStorage.getItem('fac_view');
    if (saved === 'table') setView('table');
})();

// ── Auto-refresh when cluster is selected ────────────────────────────────────
<?php if ($hasCluster): ?>
var _refreshTimer = setInterval(function() {
    location.reload();
}, 20000);

// Show countdown
(function() {
    var badge = document.querySelector('.live-badge');
    if (!badge) return;
    var secs = 20;
    setInterval(function() {
        secs--;
        if (secs <= 0) secs = 20;
        badge.innerHTML = '<div class="live-dot"></div> Live &mdash; refreshing in ' + secs + 's';
    }, 1000);
})();
<?php endif; ?>
</script>

</body>
</html>
