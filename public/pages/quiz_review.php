<?php
$attemptId = intval($_GET['attempt'] ?? 0);
$moduleSlug = $_GET['module'] ?? '';
if (!$attemptId && !$moduleSlug) { echo '<div class="container"><p>No quiz specified.</p></div>'; return; }

// Get latest attempt for this session + module
if (!$attemptId && $moduleSlug) {
    $mod = db()->querySingle("SELECT id FROM modules WHERE slug='".SQLite3::escapeString($moduleSlug)."'", true);
    if ($mod) {
        $att = db()->querySingle("SELECT id,score,total_questions,percentage FROM quiz_attempts WHERE module_id=".intval($mod['id'])." AND session_hash='".SQLite3::escapeString(getSessionHash())."' ORDER BY id DESC LIMIT 1", true);
        if ($att) $attemptId = $att['id'];
    }
}

$attempt = db()->querySingle("SELECT qa.*,m.title as module_title,m.slug as module_slug FROM quiz_attempts qa JOIN modules m ON qa.module_id=m.id WHERE qa.id=$attemptId", true);
if (!$attempt) { echo '<div class="container"><div class="alert">Attempt not found.</div></div>'; return; }

$answers_raw = db()->query("SELECT qans.*,q.question,q.option_a,q.option_b,q.option_c,q.option_d,q.correct_option,q.explanation FROM quiz_answers qans JOIN quiz_questions q ON qans.question_id=q.id WHERE qans.attempt_id=$attemptId");
$answers = [];
while ($r = $answers_raw->fetchArray(SQLITE3_ASSOC)) $answers[] = $r;
$pct = round($attempt['percentage']);
$passed = $pct >= 60;
?>
<div class="container">
  <div class="breadcrumb"><a href="/arise/">Home</a> <span class="sep">›</span> <a href="/arise/?p=modules">Modules</a> <span class="sep">›</span> <a href="/arise/?p=module&slug=<?=htmlspecialchars($attempt['module_slug'])?>"><?=htmlspecialchars($attempt['module_title'])?></a> <span class="sep">›</span> <span>Quiz Review</span></div>
  <h1 class="page-title">Quiz Review — <?=htmlspecialchars($attempt['module_title'])?></h1>
  <div class="dp-card" style="text-align:center;margin-bottom:20px">
    <div style="font-size:3rem;font-weight:900;color:<?=$passed?'#2e7d32':'#c62828'?>"><?=$pct?>%</div>
    <div style="font-size:1rem;font-weight:700;color:<?=$passed?'#2e7d32':'#c62828'?>"><?=$passed?'✅ Passed':'❌ Needs Improvement'?></div>
    <div style="font-size:.82rem;color:#666;margin-top:6px"><?=$attempt['score']?>/<?=$attempt['total_questions']?> correct</div>
    <div style="margin-top:14px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
      <a href="/arise/?p=module&slug=<?=htmlspecialchars($attempt['module_slug'])?>" class="btn btn-secondary">← Back to Module</a>
      <a href="/arise/?p=quiz&module=<?=htmlspecialchars($attempt['module_slug'])?>" class="btn btn-primary">Retry Quiz</a>
    </div>
  </div>
  <?php if ($answers): ?>
  <h3 style="font-size:.95rem;font-weight:800;margin-bottom:12px">Question by Question</h3>
  <div class="quiz-review">
    <?php foreach ($answers as $a):
      $opts = ['a'=>$a['option_a'],'b'=>$a['option_b'],'c'=>$a['option_c'],'d'=>$a['option_d']];
    ?>
    <div class="qr-item <?=$a['is_correct']?'correct':'wrong'?>">
      <div class="qr-q"><?=$a['is_correct']?'✅':'❌'?> <?=htmlspecialchars($a['question'])?></div>
      <div class="qr-opts">
        <?php foreach($opts as $k=>$v): if(!$v) continue; ?>
          <div style="<?=$k===$a['correct_option']?'font-weight:700;color:#2e7d32':($k===$a['chosen_option']&&!$a['is_correct']?'color:#c62828;text-decoration:line-through':'')?>">
            <?=strtoupper($k)?>) <?=htmlspecialchars($v)?>
            <?php if($k===$a['correct_option']): ?> <span style="color:#2e7d32;font-size:.75rem;font-weight:700">✓ Correct answer</span><?php endif; ?>
            <?php if($k===$a['chosen_option']&&!$a['is_correct']): ?> <span style="color:#c62828;font-size:.75rem;font-weight:700">← Your answer</span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if($a['explanation']): ?>
        <div class="qr-explanation">💡 <?=htmlspecialchars($a['explanation'])?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="dp-card" style="text-align:center;color:#999">Detailed review not available for this attempt.</div>
  <?php endif; ?>
</div>
