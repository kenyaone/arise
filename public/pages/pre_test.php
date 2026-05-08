<?php
$moduleSlug = $_GET['module'] ?? '';
$testType   = $_GET['type'] ?? 'pre';
$module = $moduleSlug ? db()->querySingle("SELECT * FROM modules WHERE slug='".SQLite3::escapeString($moduleSlug)."' AND is_active=1",true) : null;
if (!$module) { echo '<div class="container"><div class="alert">Module not found.</div></div>'; return; }

$hash = getSessionHash();

// Helper functions for multi-select validation
function parseCorrectAnswers($str) { return $str ? array_map('strtoupper', array_filter(array_map('trim', preg_split('/[,\s]+/', $str)))) : []; }
function isAnswerCorrect($selected, $correctStr) {
    $correctSet = array_flip(parseCorrectAnswers($correctStr));
    if (empty($correctSet)) return false;
    if (count($selected) !== count($correctSet)) return false;
    foreach ($selected as $ans) {
        if (!isset($correctSet[strtoupper($ans)])) return false;
    }
    return true;
}

// Handle POST submission — fetch questions by submitted IDs, not random
if ($_SERVER['REQUEST_METHOD']==='POST') {
    // Extract question IDs and their answers
    $qids = [];
    $answers = [];
    foreach ($_POST as $k => $v) {
        if (preg_match('/^q(\d+)$/', $k, $m)) {
            $qid = intval($m[1]);
            $qids[] = $qid;
            // Support both single answer (string) and multi-answer (array)
            $answers[$qid] = is_array($v) ? $v : [$v];
        }
    }
    if (!$qids) { echo '<div class="container"><div class="alert">No answers submitted.</div></div>'; return; }

    // Fetch those exact questions (must belong to this module)
    $idList = implode(',', $qids);
    $qMap = [];
    $res = db()->query("SELECT * FROM quiz_questions WHERE id IN ($idList) AND module_id=".intval($module['id']));
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $qMap[$r['id']] = $r;

    $score = 0; $total = count($qids);
    $answerRows = []; // for per-question tracking
    foreach ($qids as $qid) {
        $ans = $answers[$qid] ?? [];
        $q   = $qMap[$qid] ?? null;
        $isCorrect = $q && isAnswerCorrect($ans, $q['correct_option']) ? 1 : 0;
        if ($isCorrect) $score++;
        $answerRows[] = ['qid'=>$qid,'chosen'=>implode(',', $ans),'correct'=>$isCorrect];
    }

    $pct = $total > 0 ? round($score / $total * 100) : 0;
    $sid = getStudentId();

    // Save aggregate to pretest_attempts
    $st = db()->prepare("INSERT INTO pretest_attempts (student_id,session_hash,module_id,test_type,score,total,percentage) VALUES (:s,:h,:m,:t,:sc,:tot,:p)");
    $st->bindValue(':s', $sid); $st->bindValue(':h', $hash);
    $st->bindValue(':m', $module['id']); $st->bindValue(':t', $testType);
    $st->bindValue(':sc', $score); $st->bindValue(':tot', $total); $st->bindValue(':p', $pct);
    $st->execute();

    // Save per-question answers to quiz_answers via a quiz_attempt record
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

    // Auto-issue certificate after post-test if score >= 60%
    if ($testType === 'post' && $pct >= 60 && $sid) {
        $student = getStudentBySession();
        if ($student) {
            $existing = db()->querySingle(
                "SELECT id FROM certificates
                 WHERE student_id = $sid AND module_id = ".intval($module['id'])
            );
            if (!$existing) {
                do {
                    $certNum = 'ARISE-' . date('Y') . '-' . str_pad(mt_rand(10000,99999), 5, '0', STR_PAD_LEFT);
                } while (db()->querySingle("SELECT id FROM certificates WHERE cert_number = '" . SQLite3::escapeString($certNum) . "'"));

                $stmt2 = db()->prepare(
                    'INSERT INTO certificates
                     (cert_number, student_id, student_name, module_id, module_title, score, percentage)
                     VALUES (:cert, :sid, :name, :mid, :mtitle, :score, :pct)'
                );
                $stmt2->bindValue(':cert',   $certNum);
                $stmt2->bindValue(':sid',    $sid);
                $stmt2->bindValue(':name',   $student['full_name']);
                $stmt2->bindValue(':mid',    intval($module['id']));
                $stmt2->bindValue(':mtitle', $module['title']);
                $stmt2->bindValue(':score',  $score);
                $stmt2->bindValue(':pct',    $pct);
                $stmt2->execute();
            }
        }
        // Redirect to certificate
        header("Location: /arise/certificate&module=".urlencode($moduleSlug)."&score=$pct");
        exit;
    }

    // Auto-redirect to survey after post-test if score < 60 (collect qualitative data)
    if ($testType === 'post' && $pct < 60) {
        header("Location: /arise/survey&module=".urlencode($moduleSlug)."&from=post_test");
        exit;
    }

    // Show result
    $prePct = null;
    if ($testType === 'post') {
        $pre = db()->querySingle("SELECT percentage FROM pretest_attempts WHERE session_hash='".SQLite3::escapeString($hash)."' AND module_id=".intval($module['id'])." AND test_type='pre' ORDER BY id DESC LIMIT 1", true);
        if ($pre) $prePct = round($pre['percentage']);
    }

    // Show per-question feedback
    ?>
    <div class="container">
      <div class="dp-card" style="text-align:center;margin-bottom:16px">
        <div style="font-size:2.5rem;font-weight:900;color:var(--green)"><?=$pct?>%</div>
        <div style="font-size:.9rem;color:#555;margin-top:4px"><?=$score?>/<?=$total?> correct on your <?= ($testType==='pre'?'Pre':'Post') ?>-Test</div>
        <?php if ($prePct !== null): $gain = $pct - $prePct; ?>
          <div class="gain-pill" style="margin-top:14px"><?=$gain>=0?'+'.$gain:$gain?>% <?=$gain>0?'&#128200; improvement':'&#128202; change'?> from pre-test (<?=$prePct?>% &rarr; <?=$pct?>%)</div>
        <?php endif; ?>
      </div>

      <!-- Per-question review -->
      <?php foreach ($qids as $qid):
        $q = $qMap[$qid] ?? null; if (!$q) continue;
        $chosen = $answers[$qid] ?? [];
        $correctAnswers = parseCorrectAnswers($q['correct_option']);
        $isRight = isAnswerCorrect($chosen, $q['correct_option']);
        $opts = ['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']];
        $bg = $isRight ? '#f0fdf4' : '#fff7ed';
        $border = $isRight ? '#86efac' : '#fed7aa';
        $chosenUpper = array_map('strtoupper', $chosen);
      ?>
      <div style="background:<?=$bg?>;border:1px solid <?=$border?>;border-radius:10px;padding:14px;margin-bottom:10px;">
        <div style="font-size:.85rem;font-weight:700;margin-bottom:8px;">
          <?=$isRight?'&#10003;':'&#10007;'?> <?=e($q['question'])?>
        </div>
        <?php foreach ($opts as $k=>$v): if (!$v) continue; $kUpper = strtoupper($k); $isCorrect = in_array($kUpper, $correctAnswers); $isChosen = in_array($kUpper, $chosenUpper); ?>
          <div style="font-size:.8rem;padding:3px 0;color:<?= $isCorrect?'#166534':($isChosen&&!$isRight?'#991b1b':'#555') ?>;font-weight:<?= ($isCorrect||($isChosen&&!$isRight))?'700':'400' ?>;">
            <?= $kUpper ?>. <?=e($v)?>
            <?= $isCorrect ? ' &#10003;' : '' ?>
            <?= ($isChosen&&!$isRight) ? ' &larr; your answer' : '' ?>
          </div>
        <?php endforeach; ?>
        <?php if ($q['explanation']): ?>
          <div style="font-size:.78rem;color:#6b7280;margin-top:8px;border-top:1px solid <?=$border?>;padding-top:6px;">
            &#128161; <?=e($q['explanation'])?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if ($testType === 'post'):
        $surveyDone = (bool)db()->querySingle("SELECT id FROM behavioral_surveys WHERE session_hash='".SQLite3::escapeString($hash)."' AND module_id=".intval($module['id']));
        if (!$surveyDone): ?>
        <div style="background:#fffbeb;border:2px solid #fcd34d;border-radius:12px;padding:14px 18px;margin-top:12px;text-align:center;">
          <div style="font-weight:700;font-size:.9rem;color:#92400e;margin-bottom:6px;">&#128221; One Last Step &mdash; Quick Survey</div>
          <div style="font-size:.82rem;color:#6b7280;margin-bottom:10px;">3 questions &middot; 60 seconds &middot; Helps measure real-world impact</div>
          <a href="/arise/?p=survey&module=<?=htmlspecialchars($moduleSlug)?>" class="btn btn-primary">Take the Impact Survey &rarr;</a>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:12px">
        <a href="/arise/?p=module&slug=<?=htmlspecialchars($moduleSlug)?>" class="btn <?=$testType==='post'?'btn-secondary':'btn-primary'?>">&#8592; Back to Module</a>
        <?php if ($testType==='pre'): ?>
          <a href="/arise/?p=quiz&module=<?=htmlspecialchars($moduleSlug)?>" class="btn btn-secondary">Take Full Quiz</a>
        <?php endif; ?>
      </div>
    </div>
    <?php return;
}

// GET — smart question selection (no repeats between pre/post; fresh pool on retakes)
$questions = [];
$mid = intval($module['id']);

if ($testType === 'post') {
    // Post-test: show the EXACT same questions the learner saw in their pre-test
    // This gives a valid apples-to-apples knowledge gain measurement
    $preAttempt = db()->querySingle(
        "SELECT id FROM quiz_attempts WHERE session_hash='".SQLite3::escapeString($hash)."'
         AND module_id=$mid AND test_type='pre' ORDER BY id ASC LIMIT 1", true
    );
    if ($preAttempt) {
        $res = db()->query(
            "SELECT qq.* FROM quiz_answers qa
             JOIN quiz_questions qq ON qq.id = qa.question_id
             WHERE qa.attempt_id = ".intval($preAttempt['id'])."
             AND qq.module_id = $mid AND qq.question_type='mcq'"
        );
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $questions[] = $r;
    }
    // Fallback: if pre-test attempt not linked, pick fresh random
    if (!$questions) {
        $res = db()->query("SELECT * FROM quiz_questions WHERE module_id=$mid AND question_type='mcq' ORDER BY RANDOM() LIMIT 5");
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $questions[] = $r;
    }
} else {
    // Pre-test (or retake): exclude questions seen in ANY previous attempt for this module+session
    $seenIds = [];
    $seenRes = db()->query(
        "SELECT DISTINCT qa.question_id FROM quiz_answers qa
         JOIN quiz_attempts qat ON qat.id = qa.attempt_id
         WHERE qat.session_hash='".SQLite3::escapeString($hash)."' AND qat.module_id=$mid"
    );
    while ($r = $seenRes->fetchArray(SQLITE3_NUM)) $seenIds[] = intval($r[0]);

    $exclude = $seenIds ? 'AND id NOT IN ('.implode(',', $seenIds).')' : '';
    $res = db()->query("SELECT * FROM quiz_questions WHERE module_id=$mid AND question_type='mcq' $exclude ORDER BY RANDOM() LIMIT 5");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $questions[] = $r;

    // If pool exhausted (all questions seen), reset — start fresh with full pool
    if (count($questions) < 3 && $seenIds) {
        $res = db()->query("SELECT * FROM quiz_questions WHERE module_id=$mid AND question_type='mcq' ORDER BY RANDOM() LIMIT 5");
        $questions = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $questions[] = $r;
    }
}

if (!$questions) { echo '<div class="container"><div class="alert alert-info">No test questions for this module yet.</div></div>'; return; }

$prev = db()->querySingle("SELECT * FROM pretest_attempts WHERE session_hash='".SQLite3::escapeString($hash)."' AND module_id=".intval($module['id'])." AND test_type='".SQLite3::escapeString($testType)."' ORDER BY id DESC LIMIT 1", true);
?>
<div class="container">
  <div class="breadcrumb"><a href="/arise/">Home</a> <span class="sep">&#8250;</span> <a href="/arise/?p=module&slug=<?=e($moduleSlug)?>"><?=e($module['title'])?></a> <span class="sep">&#8250;</span> <span><?=$testType==='pre'?'Pre-Test':'Post-Test'?></span></div>

  <?php if (($_GET['cert'] ?? '') === '1'): ?>
  <div style="background:#dbeafe;border:2px solid #0284c7;border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:center;">
    <span style="font-size:1.5rem">🎓</span>
    <div>
      <div style="font-weight:700;color:#0c4a6e;font-size:.95rem">Almost there!</div>
      <div style="font-size:.82rem;color:#075985">Complete this post-test with 60% or higher to unlock your certificate.</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="dp-card" style="margin-bottom:16px">
    <span class="test-type-badge <?=$testType==='pre'?'badge-pre':'badge-post'?>"><?=$testType==='pre'?'Pre-Test':'Post-Test'?></span>
    <h2 style="font-size:1.05rem;font-weight:800;margin-bottom:4px"><?=$module['icon']?> <?=e($module['title'])?></h2>
    <p class="text-muted" style="font-size:.82rem"><?=count($questions)?> quick questions &middot; <?=$testType==='pre'?'Before you start &mdash; check what you already know':'After completing &mdash; see how much you\'ve learned'?></p>
  </div>
  <form method="post">
    <?php foreach ($questions as $i => $q):
      $opts = ['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']];
      $correctAnswers = parseCorrectAnswers($q['correct_option']);
      $isMultiSelect = count($correctAnswers) > 1;
    ?>
    <div class="test-card">
      <div class="qr-q"><?=$i+1?>. <?=e($q['question'])?><?=$isMultiSelect?' <span style="font-size:.8rem;color:#666;font-weight:400">(Select all that apply)</span>':''?></div>
      <?php foreach ($opts as $k=>$v): if (!$v) continue; ?>
        <label style="display:flex;align-items:center;gap:8px;padding:7px 0;cursor:pointer;font-size:.85rem">
          <input type="checkbox" name="q<?=$q['id']?>[]" value="<?=$k?>" style="width:16px;height:16px">
          <span><?=strtoupper($k)?>. <?=e($v)?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-primary" style="width:100%;padding:14px">Submit <?=$testType==='pre'?'Pre-Test':'Post-Test'?></button>
  </form>
</div>
