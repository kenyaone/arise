<?php
/**
 * ARISE — Retention Test (PUBLIC page)
 * URL: /arise/?p=retention&module=SLUG
 * 5-question re-test shown ~30 days after module completion.
 * Saves results to retention_tests table.
 * Shows comparison with original post-test score.
 */

$moduleSlug = $_GET['module'] ?? '';
$module = $moduleSlug
    ? db()->querySingle(
        "SELECT * FROM modules WHERE slug='" . SQLite3::escapeString($moduleSlug) . "' AND is_active=1",
        true
      )
    : null;

if (!$module) {
    echo '<div class="container"><div class="alert">Module not found.</div></div>';
    return;
}

$mid  = intval($module['id']);
$sid  = getStudentId();
$hash = getSessionHash();

// ── Guard: learner must have completed module (has cert OR post-test) ─────────
$hasCert = db()->querySingle(
    "SELECT id FROM certificates
     WHERE (student_id=" . intval($sid ?? 0) . " OR session_hash='" . SQLite3::escapeString($hash) . "')
       AND module_id=$mid LIMIT 1"
);

$hasPostTest = db()->querySingle(
    "SELECT id FROM pretest_attempts
     WHERE session_hash='" . SQLite3::escapeString($hash) . "'
       AND module_id=$mid
       AND test_type='post'
     LIMIT 1"
);

if (!$hasCert && !$hasPostTest) {
    // Not completed — redirect to module page
    header('Location: /arise/?p=module&slug=' . urlencode($moduleSlug));
    exit;
}

// ── Original post-test score ─────────────────────────────────────────────────
$origPost = db()->querySingle(
    "SELECT percentage, taken_at, score, total FROM pretest_attempts
     WHERE session_hash='" . SQLite3::escapeString($hash) . "'
       AND module_id=$mid
       AND test_type='post'
     ORDER BY id DESC LIMIT 1",
    true
);

$origScore = $origPost ? floatval($origPost['percentage']) : null;

// ── Check if already done a retention test recently ──────────────────────────
$lastRetention = db()->querySingle(
    "SELECT * FROM retention_tests
     WHERE session_hash='" . SQLite3::escapeString($hash) . "'
       AND module_id=$mid
     ORDER BY id DESC LIMIT 1",
    true
);

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extract submitted question IDs
    $qids = [];
    foreach ($_POST as $k => $v) {
        if (preg_match('/^q(\d+)$/', $k, $m)) $qids[] = intval($m[1]);
    }

    if (!$qids) {
        echo '<div class="container"><div class="alert">No answers submitted.</div></div>';
        return;
    }

    $idList = implode(',', $qids);
    $qMap   = [];
    $qRes   = db()->query("SELECT * FROM quiz_questions WHERE id IN ($idList) AND module_id=$mid");
    while ($r = $qRes->fetchArray(SQLITE3_ASSOC)) $qMap[$r['id']] = $r;

    $score = 0;
    $total = count($qids);
    $answerRows = [];

    foreach ($qids as $qid) {
        $ans     = strtolower(trim($_POST['q'.$qid] ?? ''));
        $q       = $qMap[$qid] ?? null;
        $correct = $q ? strtolower($q['correct_option']) : '';
        $isOk    = ($q && $ans === $correct) ? 1 : 0;
        if ($isOk) $score++;
        $answerRows[] = ['qid' => $qid, 'chosen' => $ans, 'correct' => $isOk];
    }

    $pct = $total > 0 ? round($score / $total * 100) : 0;

    // Save to retention_tests
    $st = db()->prepare(
        "INSERT INTO retention_tests (student_id, session_hash, module_id, score, total, percentage)
         VALUES (:s, :h, :m, :sc, :tot, :p)"
    );
    $st->bindValue(':s',   $sid ?? 0, SQLITE3_INTEGER);
    $st->bindValue(':h',   $hash,     SQLITE3_TEXT);
    $st->bindValue(':m',   $mid,      SQLITE3_INTEGER);
    $st->bindValue(':sc',  $score,    SQLITE3_INTEGER);
    $st->bindValue(':tot', $total,    SQLITE3_INTEGER);
    $st->bindValue(':p',   $pct,      SQLITE3_FLOAT);
    $st->execute();

    // ── Show results ───────────────────────────────────────────────────────
    $diff        = ($origScore !== null) ? ($pct - $origScore) : null;
    $retained    = ($diff !== null && $diff >= 0);
    $diffLabel   = $diff !== null ? ($diff >= 0 ? '+'.round($diff).'%' : round($diff).'%') : null;
    ?>
    <style>
    .retention-result .gain-pill {
        display: inline-block;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: .85rem;
        font-weight: 700;
        margin-top: 10px;
    }
    .gain-pill.up   { background:#dcfce7; color:#166534; }
    .gain-pill.down { background:#fee2e2; color:#991b1b; }
    .gain-pill.flat { background:#f3f4f6; color:#374151; }
    .test-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 10px;
    }
    </style>
    <div class="container retention-result">
      <div class="breadcrumb">
        <a href="/arise/">Home</a>
        <span class="sep">&#8250;</span>
        <a href="/arise/?p=module&slug=<?= e($moduleSlug) ?>"><?= e($module['title']) ?></a>
        <span class="sep">&#8250;</span>
        <span>Retention Test Results</span>
      </div>

      <!-- Score card -->
      <div class="dp-card" style="text-align:center;margin-bottom:16px;">
        <div style="font-size:2.6rem;font-weight:900;color:<?= $pct >= 60 ? 'var(--green,#0ea271)' : '#dc2626' ?>">
          <?= $pct ?>%
        </div>
        <div style="font-size:.9rem;color:#555;margin-top:4px;">
          <?= $score ?>/<?= $total ?> correct on your Retention Test
        </div>

        <?php if ($diff !== null): ?>
        <div style="margin-top:14px;">
          <?php
          $pillClass = $diff > 2 ? 'up' : ($diff < -2 ? 'down' : 'flat');
          $icon      = $diff > 2 ? '&#128200;' : ($diff < -2 ? '&#128201;' : '&#10145;');
          ?>
          <span class="gain-pill <?= $pillClass ?>">
            <?= $icon ?> <?= $diffLabel ?>
            <?= $diff > 2 ? 'Knowledge retained &amp; growing!' : ($diff < -2 ? 'Some knowledge faded' : 'Stable knowledge') ?>
          </span>
          <div style="font-size:.82rem;color:#6b7280;margin-top:10px;">
            Original post-test: <strong><?= round($origScore) ?>%</strong>
            &rarr; Today: <strong><?= $pct ?>%</strong>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($pct >= 60): ?>
        <div style="font-size:.85rem;color:#166534;margin-top:12px;font-weight:600;">
          &#127881; Well done — you have retained the knowledge from this module!
        </div>
        <?php else: ?>
        <div style="font-size:.85rem;color:#d97706;margin-top:12px;font-weight:600;">
          &#128218; You may benefit from reviewing this module again.
        </div>
        <?php endif; ?>
      </div>

      <!-- Per-question review -->
      <?php foreach ($qids as $qid):
        $q       = $qMap[$qid] ?? null;
        if (!$q) continue;
        $chosen  = strtolower(trim($_POST['q'.$qid] ?? ''));
        $correct = strtolower($q['correct_option']);
        $isRight = ($chosen === $correct);
        $opts    = ['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']];
        $bg      = $isRight ? '#f0fdf4' : '#fff7ed';
        $border  = $isRight ? '#86efac' : '#fed7aa';
      ?>
      <div style="background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:10px;padding:14px;margin-bottom:10px;">
        <div style="font-size:.85rem;font-weight:700;margin-bottom:8px;">
          <?= $isRight ? '&#10003;' : '&#10007;' ?> <?= e($q['question']) ?>
        </div>
        <?php foreach ($opts as $k => $v): if (!$v) continue; ?>
          <div style="font-size:.8rem;padding:3px 0;
            color:<?= ($k===$correct)?'#166534':(($k===$chosen&&!$isRight)?'#991b1b':'#555') ?>;
            font-weight:<?= ($k===$correct||($k===$chosen&&!$isRight))?'700':'400' ?>;">
            <?= strtoupper($k) ?>. <?= e($v) ?>
            <?= $k===$correct ? ' &#10003;' : '' ?>
            <?= ($k===$chosen&&!$isRight) ? ' &larr; your answer' : '' ?>
          </div>
        <?php endforeach; ?>
        <?php if ($q['explanation']): ?>
          <div style="font-size:.78rem;color:#6b7280;margin-top:8px;border-top:1px solid <?= $border ?>;padding-top:6px;">
            &#128161; <?= e($q['explanation']) ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:16px;">
        <a href="/arise/?p=module&slug=<?= e($moduleSlug) ?>" class="btn btn-primary">
          &#8592; Back to Module
        </a>
        <?php if ($pct < 60): ?>
        <a href="/arise/?p=pre_test&module=<?= e($moduleSlug) ?>&type=post" class="btn btn-secondary">
          Retake Post-Test
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return;
}

// ── GET — show test form ──────────────────────────────────────────────────────
$questions = [];
$qRes = db()->query(
    "SELECT * FROM quiz_questions
     WHERE module_id=$mid AND question_type='mcq'
     ORDER BY RANDOM() LIMIT 5"
);
while ($r = $qRes->fetchArray(SQLITE3_ASSOC)) $questions[] = $r;

if (!$questions) {
    echo '<div class="container"><div class="alert alert-info">No questions available for this module yet.</div></div>';
    return;
}

// Date of original post-test (for "30 days since" copy)
$postTestDate = $origPost ? date('F j, Y', strtotime($origPost['taken_at'] ?? $origPost['completed_at'] ?? 'now')) : null;

?>
<style>
.test-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 18px;
    margin-bottom: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.qr-q {
    font-size: .9rem;
    font-weight: 700;
    color: #111;
    margin-bottom: 12px;
    line-height: 1.45;
}
.test-type-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 10px;
}
.badge-retention {
    background: #ede9fe;
    color: #5b21b6;
}
</style>

<div class="container">
  <div class="breadcrumb">
    <a href="/arise/">Home</a>
    <span class="sep">&#8250;</span>
    <a href="/arise/?p=module&slug=<?= e($moduleSlug) ?>"><?= e($module['title']) ?></a>
    <span class="sep">&#8250;</span>
    <span>Retention Test</span>
  </div>

  <!-- Info card -->
  <div class="dp-card" style="margin-bottom:16px;">
    <span class="test-type-badge badge-retention">&#128260; Retention Test</span>
    <h2 style="font-size:1.05rem;font-weight:800;margin-bottom:4px;">
      <?= e($module['icon'] ?? '') ?> <?= e($module['title']) ?>
    </h2>
    <p class="text-muted" style="font-size:.82rem;margin-bottom:0;">
      <?= count($questions) ?> questions &middot;
      How much do you still remember?
      <?php if ($origScore !== null): ?>
        &middot; Your original post-test score was <strong><?= round($origScore) ?>%</strong>
        <?php if ($postTestDate): ?>
          (taken <?= $postTestDate ?>)
        <?php endif; ?>
      <?php endif; ?>
    </p>
  </div>

  <?php if ($lastRetention): ?>
  <div class="alert" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.88rem;">
    &#9432; You took a retention test previously and scored <strong><?= round($lastRetention['percentage']) ?>%</strong>
    (<?= date('F j, Y', strtotime($lastRetention['taken_at'])) ?>).
    You can take it again below.
  </div>
  <?php endif; ?>

  <form method="POST">
    <?php foreach ($questions as $i => $q):
      $opts = ['a' => $q['option_a'], 'b' => $q['option_b'],
               'c' => $q['option_c'], 'd' => $q['option_d']];
    ?>
    <div class="test-card">
      <div class="qr-q"><?= $i+1 ?>. <?= e($q['question']) ?></div>
      <?php foreach ($opts as $k => $v): if (!$v) continue; ?>
        <label style="display:flex;align-items:center;gap:8px;padding:7px 0;cursor:pointer;font-size:.85rem;">
          <input type="radio" name="q<?= $q['id'] ?>" value="<?= $k ?>" required style="width:16px;height:16px;">
          <span><?= strtoupper($k) ?>. <?= e($v) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-primary" style="width:100%;padding:14px;">
      &#128260; Submit Retention Test
    </button>
  </form>
</div>
