<?php
$moduleSlug = $_GET['module'] ?? '';
$testType   = $_GET['type'] ?? 'pre';  // 'pre' | 'post' | 'lesson'
$module = $moduleSlug ? db()->querySingle("SELECT * FROM modules WHERE slug='".SQLite3::escapeString($moduleSlug)."' AND is_active=1", true) : null;
if (!$module) { echo '<div class="container"><div class="alert">Module not found.</div></div>'; return; }

$hash = getSessionHash();

function parseCorrectAnswers($str) {
    return $str ? array_map('strtoupper', array_filter(array_map('trim', preg_split('/[,\s]+/', $str)))) : [];
}
function isAnswerCorrect($selected, $correctStr) {
    $correctSet = array_flip(parseCorrectAnswers($correctStr));
    if (empty($correctSet)) return false;
    if (count($selected) !== count($correctSet)) return false;
    foreach ($selected as $ans) {
        if (!isset($correctSet[strtoupper($ans)])) return false;
    }
    return true;
}
function getOpts($q) {
    $opts = [];
    foreach (['a','b','c','d','e'] as $k) {
        $col = 'option_' . $k;
        if (!empty($q[$col])) $opts[$k] = $q[$col];
    }
    return $opts;
}

// ── POST: grade submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qids = [];
    $answers = [];
    foreach ($_POST as $k => $v) {
        if (preg_match('/^q(\d+)$/', $k, $m)) {
            $qid = intval($m[1]);
            $qids[] = $qid;
            $answers[$qid] = is_array($v) ? $v : [$v];
        }
    }
    if (!$qids) { echo '<div class="container"><div class="alert">No answers submitted.</div></div>'; return; }

    $idList = implode(',', $qids);
    $qMap = [];
    $res = db()->query("SELECT * FROM quiz_questions WHERE id IN ($idList) AND module_id=" . intval($module['id']));
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $qMap[$r['id']] = $r;

    $score = 0; $total = count($qids);
    $answerRows = [];
    foreach ($qids as $qid) {
        $ans = $answers[$qid] ?? [];
        $q   = $qMap[$qid] ?? null;
        $isCorrect = $q && isAnswerCorrect($ans, $q['correct_option']) ? 1 : 0;
        if ($isCorrect) $score++;
        $answerRows[] = ['qid' => $qid, 'chosen' => implode(',', $ans), 'correct' => $isCorrect];
    }

    $pct = $total > 0 ? round($score / $total * 100) : 0;
    $sid = getStudentId();

    // Save to pretest_attempts
    $st = db()->prepare("INSERT INTO pretest_attempts (student_id,session_hash,module_id,test_type,score,total,percentage) VALUES (:s,:h,:m,:t,:sc,:tot,:p)");
    $st->bindValue(':s', $sid); $st->bindValue(':h', $hash);
    $st->bindValue(':m', $module['id']); $st->bindValue(':t', $testType);
    $st->bindValue(':sc', $score); $st->bindValue(':tot', $total); $st->bindValue(':p', $pct);
    $st->execute();

    // Save quiz_attempt + per-question answers
    $at = db()->prepare("INSERT INTO quiz_attempts (session_hash,student_id,module_id,score,total_questions,percentage,test_type) VALUES (:h,:s,:m,:sc,:tot,:p,:tt)");
    $at->bindValue(':h', $hash); $at->bindValue(':s', $sid);
    $at->bindValue(':m', $module['id']); $at->bindValue(':sc', $score);
    $at->bindValue(':tot', $total); $at->bindValue(':p', $pct);
    $at->bindValue(':tt', $testType);
    $at->execute();
    $attemptId = db()->lastInsertRowID();
    foreach ($answerRows as $ar) {
        $aq = db()->prepare("INSERT INTO quiz_answers (attempt_id,question_id,chosen_option,is_correct) VALUES (:a,:q,:c,:i)");
        $aq->bindValue(':a', $attemptId); $aq->bindValue(':q', $ar['qid']);
        $aq->bindValue(':c', $ar['chosen']); $aq->bindValue(':i', $ar['correct']);
        $aq->execute();
    }

    // Auto-issue certificate after post-test using weighted scoring model
    // Weights: Knowledge Gain 35%, Post-test 25%, Branching 25%, Matching 15% — threshold 65%
    $finalPct = $pct; // default; overwritten below when weighted formula runs
    if ($testType === 'post' && $sid) {
        $mid = intval($module['id']);
        $escapedHash = SQLite3::escapeString($hash);

        // Knowledge gain: normalized improvement from pre-test
        $prePctRaw = db()->querySingle(
            "SELECT percentage FROM pretest_attempts WHERE session_hash='$escapedHash' AND module_id=$mid AND test_type='pre' ORDER BY id DESC LIMIT 1"
        );
        $prePct = ($prePctRaw !== null) ? (float)$prePctRaw : null;
        if ($prePct !== null) {
            $kgScore = $prePct < 100
                ? max(0.0, ($pct - $prePct) / (100.0 - $prePct) * 100.0)
                : ($pct >= 100 ? 100.0 : 0.0);
        } else {
            $kgScore = null;
        }

        // Matching score: average percentage across all matching interactions for this module/session
        $matchAvg = db()->querySingle(
            "SELECT AVG(CAST(score AS REAL)/NULLIF(total,0)*100) FROM lesson_interactions
             WHERE session_hash='$escapedHash' AND module_id=$mid AND interaction_type='matching'"
        );
        $matchScore = ($matchAvg !== null && $matchAvg !== false) ? (float)$matchAvg : null;

        // Branching score: 100 if student reached a good outcome at least once, else 0 (null if no branching in module)
        $branchDone = db()->querySingle(
            "SELECT MAX(done) FROM lesson_interactions
             WHERE session_hash='$escapedHash' AND module_id=$mid AND interaction_type='branching'"
        );
        $branchScore = ($branchDone !== null && $branchDone !== false) ? (float)($branchDone * 100) : null;

        // Compute weighted score — redistribute weight of absent components
        $weighted    = 0.0;
        $totalWeight = 0.0;

        if ($kgScore !== null) { $weighted += $kgScore * 0.35; $totalWeight += 0.35; }
        $weighted += $pct * 0.25; $totalWeight += 0.25;
        if ($branchScore !== null) { $weighted += $branchScore * 0.25; $totalWeight += 0.25; }
        if ($matchScore  !== null) { $weighted += $matchScore  * 0.15; $totalWeight += 0.15; }

        $finalPct = $totalWeight > 0 ? round($weighted / $totalWeight) : $pct;

        if ($finalPct >= 65) {
            $student = getStudentBySession();
            if ($student) {
                $existing = db()->querySingle("SELECT id FROM certificates WHERE student_id=$sid AND module_id=$mid");
                if (!$existing) {
                    do {
                        $certNum = 'ARISE-' . date('Y') . '-' . str_pad(mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
                    } while (db()->querySingle("SELECT id FROM certificates WHERE cert_number='" . SQLite3::escapeString($certNum) . "'"));
                    $stmt2 = db()->prepare('INSERT INTO certificates (cert_number,student_id,student_name,module_id,module_title,score,percentage) VALUES (:cert,:sid,:name,:mid,:mtitle,:score,:pct)');
                    $stmt2->bindValue(':cert',   $certNum);
                    $stmt2->bindValue(':sid',    $sid);
                    $stmt2->bindValue(':name',   $student['full_name']);
                    $stmt2->bindValue(':mid',    $mid);
                    $stmt2->bindValue(':mtitle', $module['title']);
                    $stmt2->bindValue(':score',  $score);
                    $stmt2->bindValue(':pct',    $finalPct);
                    $stmt2->execute();
                } elseif ($finalPct > (int)db()->querySingle("SELECT percentage FROM certificates WHERE student_id=$sid AND module_id=$mid")) {
                    $stmt2 = db()->prepare('UPDATE certificates SET score=:s, percentage=:p WHERE student_id=:sid AND module_id=:mid');
                    $stmt2->bindValue(':s', $score); $stmt2->bindValue(':p', $finalPct);
                    $stmt2->bindValue(':sid', $sid); $stmt2->bindValue(':mid', $mid);
                    $stmt2->execute();
                }
            }
        }
    }

    // After post-test: redirect to behavioral survey if not yet done
    if ($testType === 'post') {
        $surveyDone = (bool)db()->querySingle("SELECT id FROM behavioral_surveys WHERE session_hash='".SQLite3::escapeString($hash)."' AND module_id=".intval($module['id']));
        if (!$surveyDone) {
            $prePctRaw = db()->querySingle("SELECT percentage FROM pretest_attempts WHERE session_hash='".SQLite3::escapeString($hash)."' AND module_id=".intval($module['id'])." AND test_type='pre' ORDER BY id DESC LIMIT 1");
            $gainParam = $prePctRaw !== null ? '&gain=' . ($pct - (int)$prePctRaw) : '';
            $surveyUrl = '/arise/?p=survey&module=' . urlencode($moduleSlug) . '&pct=' . $pct . $gainParam;
            // Clear any buffered HTML (navbar etc.) so the redirect goes cleanly
            while (ob_get_level()) ob_end_clean();
            header('Location: ' . $surveyUrl);
            echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($surveyUrl) . '">';
            exit;
        }
    }

    // Show result
    $prePct = null;
    if ($testType === 'post') {
        $pre = db()->querySingle("SELECT percentage FROM pretest_attempts WHERE session_hash='".SQLite3::escapeString($hash)."' AND module_id=".intval($module['id'])." AND test_type='pre' ORDER BY id DESC LIMIT 1", true);
        if ($pre) $prePct = round($pre['percentage']);
    }

    $typeLabel = ['pre' => 'Pre-Test', 'post' => 'Post-Test', 'lesson' => 'Lesson Quiz'][$testType] ?? 'Quiz';
    ?>
<style>
.test-type-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px}
.badge-pre{background:#dbeafe;color:#1e40af}
.badge-post{background:#dcfce7;color:#166534}
.badge-lesson{background:#ede9fe;color:#5b21b6}
.gain-pill{display:inline-block;background:#e0fdf4;color:#065f46;border-radius:20px;padding:5px 14px;font-size:.85rem;font-weight:700}
</style>
    <div class="container">
      <div class="dp-card" style="text-align:center;margin-bottom:16px">
        <div style="font-size:2.5rem;font-weight:900;color:var(--green)"><?= $pct ?>%</div>
        <div style="font-size:.9rem;color:#555;margin-top:4px"><?= $score ?>/<?= $total ?> correct &mdash; <?= $typeLabel ?></div>
        <?php if ($prePct !== null): $gain = $pct - $prePct; ?>
          <div class="gain-pill" style="margin-top:14px"><?= $gain >= 0 ? '+' . $gain : $gain ?>% <?= $gain > 0 ? '&#128200; improvement' : '&#128202; change' ?> from pre-test (<?= $prePct ?>% &rarr; <?= $pct ?>%)</div>
        <?php endif; ?>
      </div>

      <?php foreach ($qids as $qid):
        $q = $qMap[$qid] ?? null; if (!$q) continue;
        $chosen = $answers[$qid] ?? [];
        $correctAnswers = parseCorrectAnswers($q['correct_option']);
        $isRight = isAnswerCorrect($chosen, $q['correct_option']);
        $opts = getOpts($q);
        $bg = $isRight ? '#f0fdf4' : '#fff7ed';
        $border = $isRight ? '#86efac' : '#fed7aa';
        $chosenUpper = array_map('strtoupper', $chosen);
      ?>
      <div style="background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:10px;padding:14px;margin-bottom:10px;">
        <div style="font-size:.85rem;font-weight:700;margin-bottom:8px;">
          <?= $isRight ? '&#10003;' : '&#10007;' ?> <?= e($q['question']) ?>
        </div>
        <?php foreach ($opts as $k => $v): $kUpper = strtoupper($k); $isCorrect = in_array($kUpper, $correctAnswers); $isChosen = in_array($kUpper, $chosenUpper); ?>
          <div style="font-size:.8rem;padding:3px 0;color:<?= $isCorrect ? '#166534' : ($isChosen && !$isRight ? '#991b1b' : '#555') ?>;font-weight:<?= ($isCorrect || ($isChosen && !$isRight)) ? '700' : '400' ?>;">
            <?= $kUpper ?>. <?= e($v) ?>
            <?= $isCorrect ? ' &#10003;' : '' ?>
            <?= ($isChosen && !$isRight) ? ' &larr; your answer' : '' ?>
            <?= ($isChosen && $isRight) ? ' &#10003; your answer' : '' ?>
          </div>
        <?php endforeach; ?>
        <?php if ($q['explanation']): ?>
          <div style="font-size:.78rem;color:#6b7280;margin-top:8px;border-top:1px solid <?= $border ?>;padding-top:6px;">
            &#128161; <?= e($q['explanation']) ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if ($testType === 'post' && $finalPct >= 65): ?>
        <div style="background:#d1fae5;border:2px solid #34d399;border-radius:12px;padding:14px 18px;margin-top:12px;text-align:center;">
          <div style="font-weight:900;color:#065f46;font-size:1.1rem;margin-bottom:4px;">&#127881; Certificate Earned!</div>
          <div style="font-size:.85rem;color:#047857;margin-bottom:10px;">Weighted score: <?= $finalPct ?>% &mdash; Post-test: <?= $pct ?>%</div>
          <a href="/arise/?p=certificates" class="btn btn-primary">View Your Certificate &rarr;</a>
        </div>
      <?php elseif ($testType === 'post' && $finalPct < 65): ?>
        <div style="background:#fff7ed;border:2px solid #fed7aa;border-radius:12px;padding:14px 18px;margin-top:12px;text-align:center;">
          <div style="font-weight:900;color:#92400e;font-size:1rem;margin-bottom:4px;">&#128218; Keep Going!</div>
          <div style="font-size:.82rem;color:#78350f;">Weighted score: <?= $finalPct ?>% — need 65%+. Complete the matching &amp; refusal activities in the lessons to boost your score.</div>
        </div>
      <?php endif; ?>

      <?php if ($testType === 'post'): ?>
        <div style="background:#f0fdf4;border:2px solid #86efac;border-radius:12px;padding:12px 18px;margin-top:12px;text-align:center;">
          <div style="font-size:.85rem;color:#166534;font-weight:700;">&#10003; Survey submitted &mdash; all steps complete!</div>
        </div>
      <?php endif; ?>

      <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:12px">
        <a href="/arise/?p=module&slug=<?= htmlspecialchars($moduleSlug) ?>" class="btn btn-secondary">&#8592; Back to Module</a>
        <?php if ($testType === 'lesson'): ?>
          <a href="/arise/?p=pre_test&module=<?= htmlspecialchars($moduleSlug) ?>&type=post" class="btn btn-primary">Take Post-Test &rarr;</a>
        <?php elseif ($testType === 'pre'): ?>
          <a href="/arise/?p=module&slug=<?= htmlspecialchars($moduleSlug) ?>" class="btn btn-primary">Start Lessons &rarr;</a>
        <?php endif; ?>
      </div>
    </div>
    <?php return;
}

// ── GET: select questions by section ─────────────────────────────────────────
$questions = [];
$mid = intval($module['id']);

$sectionMap = ['pre' => 'pre', 'post' => 'post', 'lesson' => 'lesson'];
$section = $sectionMap[$testType] ?? 'pre';
$limit   = ($testType === 'lesson') ? 10 : 5;

$res = db()->query(
    "SELECT * FROM quiz_questions
     WHERE module_id=$mid AND section='" . SQLite3::escapeString($section) . "' AND is_published=1
     ORDER BY sort_order ASC"
);
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $questions[] = $r;

// Fallback: if section column not populated, fall back to any published questions
if (!$questions) {
    $res = db()->query("SELECT * FROM quiz_questions WHERE module_id=$mid AND is_published=1 ORDER BY RANDOM() LIMIT $limit");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $questions[] = $r;
}

if (!$questions) { echo '<div class="container"><div class="alert alert-info">No questions for this module yet.</div></div>'; return; }

$typeLabel = ['pre' => 'Pre-Test', 'post' => 'Post-Test', 'lesson' => 'Lesson Quiz'][$testType] ?? 'Quiz';
$typeDesc  = ['pre' => 'Before you start &mdash; check what you already know', 'post' => 'After completing &mdash; see how much you\'ve learned', 'lesson' => 'In-lesson knowledge check &mdash; 10 questions'][$testType] ?? '';
$badgeClass = ['pre' => 'badge-pre', 'post' => 'badge-post', 'lesson' => 'badge-lesson'][$testType] ?? 'badge-pre';
?>
<style>
.test-type-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px}
.badge-pre{background:#dbeafe;color:#1e40af}
.badge-post{background:#dcfce7;color:#166534}
.badge-lesson{background:#ede9fe;color:#5b21b6}
.qr-q{font-size:.9rem;font-weight:700;color:#111;margin-bottom:12px;line-height:1.45}
.test-card{background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.gain-pill{display:inline-block;background:#e0fdf4;color:#065f46;border-radius:20px;padding:5px 14px;font-size:.85rem;font-weight:700}
</style>
<div class="container">
  <div class="breadcrumb"><a href="/arise/">Home</a> <span class="sep">&#8250;</span> <a href="/arise/?p=module&slug=<?= e($moduleSlug) ?>"><?= e($module['title']) ?></a> <span class="sep">&#8250;</span> <span><?= $typeLabel ?></span></div>

  <?php if (($_GET['cert'] ?? '') === '1'): ?>
  <div style="background:#dbeafe;border:2px solid #0284c7;border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:center;">
    <span style="font-size:1.5rem">&#127891;</span>
    <div>
      <div style="font-weight:700;color:#0c4a6e;font-size:.95rem">Almost there!</div>
      <div style="font-size:.82rem;color:#075985">Complete this post-test with 60% or higher to unlock your certificate.</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="dp-card" style="margin-bottom:16px">
    <span class="test-type-badge <?= $badgeClass ?>"><?= $typeLabel ?></span>
    <h2 style="font-size:1.05rem;font-weight:800;margin-bottom:4px"><?= e($module['title']) ?></h2>
    <p class="text-muted" style="font-size:.82rem"><?= count($questions) ?> questions &middot; <?= $typeDesc ?></p>
    <?php if ($testType === 'lesson'): ?>
      <div style="font-size:.78rem;color:#6b7280;margin-top:4px;">&#9432; Some questions may have more than one correct answer &mdash; read each carefully.</div>
    <?php endif; ?>
  </div>

  <form method="post">
    <?php foreach ($questions as $i => $q):
      $opts = getOpts($q);
      $isMulti = ($q['question_type'] ?? 'mcq') === 'msq';
      $inputType = $isMulti ? 'checkbox' : 'radio';
      $inputName = $isMulti ? "q{$q['id']}[]" : "q{$q['id']}";
    ?>
    <div class="test-card">
      <div class="qr-q"><?= $i + 1 ?>. <?= e($q['question']) ?>
        <?php if ($isMulti): ?>
          <span style="font-size:.78rem;color:#7c3aed;font-weight:600;margin-left:6px;">(Select all that apply)</span>
        <?php endif; ?>
      </div>
      <?php foreach ($opts as $k => $v): ?>
        <label style="display:flex;align-items:flex-start;gap:8px;padding:7px 0;cursor:pointer;font-size:.85rem">
          <input type="<?= $inputType ?>" name="<?= $inputName ?>" value="<?= $k ?>"
                 style="width:16px;height:16px;margin-top:2px;flex-shrink:0;accent-color:var(--green)">
          <span><strong><?= strtoupper($k) ?>.</strong> <?= e($v) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-primary" style="width:100%;padding:14px">
      Submit <?= $typeLabel ?> &#8594;
    </button>
  </form>
</div>
<script>
(function(){
  var DRAFT_KEY='arise_quiz_draft_<?= addslashes($moduleSlug) ?>_<?= addslashes($testType) ?>';
  function saveDraft(){
    var data={};
    document.querySelectorAll('form input[type=radio]:checked,form input[type=checkbox]:checked').forEach(function(el){
      if(!data[el.name])data[el.name]=[];
      data[el.name].push(el.value);
    });
    localStorage.setItem(DRAFT_KEY,JSON.stringify(data));
  }
  function restoreDraft(){
    var raw=localStorage.getItem(DRAFT_KEY);
    if(!raw)return;
    try{
      var data=JSON.parse(raw);
      Object.keys(data).forEach(function(name){
        data[name].forEach(function(val){
          var el=document.querySelector('form input[name="'+name+'"][value="'+val+'"]');
          if(el)el.checked=true;
        });
      });
      showResumeBanner();
    }catch(e){}
  }
  function showResumeBanner(){
    var b=document.createElement('div');
    b.style.cssText='background:#fef9c3;border:1px solid #f59e0b;border-radius:8px;padding:10px 14px;font-size:.82rem;color:#92400e;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:8px;';
    b.innerHTML='⏸️ Your previous answers have been restored. <button onclick="clearDraft()" style="margin-left:auto;background:none;border:none;color:#92400e;font-size:.78rem;cursor:pointer;text-decoration:underline;">Start fresh</button>';
    var form=document.querySelector('form');
    if(form)form.insertBefore(b,form.firstChild);
  }
  window.clearDraft=function(){
    localStorage.removeItem(DRAFT_KEY);
    document.querySelectorAll('form input[type=radio],form input[type=checkbox]').forEach(function(el){el.checked=false;});
    var b=document.querySelector('form > div[style*="fef9c3"]');
    if(b)b.remove();
  };
  document.querySelectorAll('form input').forEach(function(el){
    el.addEventListener('change',saveDraft);
  });
  document.querySelector('form').addEventListener('submit',function(){
    localStorage.removeItem(DRAFT_KEY);
  });
  restoreDraft();
})();
</script>
