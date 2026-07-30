<?php
$auth_ok = isset($_SESSION['arise_admin_id']);
if (!$auth_ok) { echo '<div class="alert">Not logged in.</div>'; return; }

$moduleSlug = $_GET['module'] ?? '';
$modules = [];
$res = db()->query("SELECT id, title, slug FROM modules WHERE is_active=1 ORDER BY title");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $modules[] = $r;

$module = null;
if ($moduleSlug) {
    $module = db()->querySingle("SELECT * FROM modules WHERE slug='".SQLite3::escapeString($moduleSlug)."'", true);
}
if (!$module && $modules) {
    $module = $modules[0];
}

// Handle AJAX difficulty update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_difficulty') {
    $qid = intval($_POST['question_id'] ?? 0);
    $diff = $_POST['difficulty'] ?? 'MEDIUM';
    if (in_array($diff, ['EASY', 'MEDIUM', 'HARD'])) {
        $st = db()->prepare("UPDATE quiz_questions SET difficulty=:d WHERE id=:id");
        $st->bindValue(':d', $diff);
        $st->bindValue(':id', $qid);
        $st->execute();
        echo json_encode(['status' => 'ok']);
    }
    exit;
}
?>

<style>
.qperf-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;align-items:start}
.qperf-main{min-width:0}
.qperf-help{position:sticky;top:16px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;box-shadow:0 4px 14px rgba(0,0,0,.05);max-height:calc(100vh - 32px);overflow:auto}
.qperf-help h5{margin:0 0 4px 0;font-size:1rem;color:#111;font-weight:800}
.qperf-help .sub{font-size:.78rem;color:#6b7280;margin-bottom:14px}
.qperf-help details{border-top:1px solid #f1f5f9;padding:10px 0}
.qperf-help details:first-of-type{border-top:0;padding-top:0}
.qperf-help summary{cursor:pointer;font-weight:700;color:#111;font-size:.9rem;list-style:none;display:flex;justify-content:space-between;align-items:center}
.qperf-help summary::-webkit-details-marker{display:none}
.qperf-help summary::after{content:"＋";color:#9ca3af;font-weight:400;transition:transform .15s}
.qperf-help details[open] summary::after{content:"−"}
.qperf-help .body{padding-top:8px;font-size:.85rem;color:#374151;line-height:1.55}
.qperf-help .badge{display:inline-block;padding:1px 8px;border-radius:999px;font-size:.72rem;font-weight:700;margin-right:4px;vertical-align:1px}
.qperf-help ul{margin:6px 0 0 18px;padding:0}
.qperf-help li{margin:3px 0}
@media (max-width: 900px){
  .qperf-layout{grid-template-columns:1fr}
  .qperf-help{position:static;max-height:none}
}
</style>

<div class="qperf-layout">
<div class="qperf-main">

<h4>📊 Question Difficulty & Performance</h4>

<!-- Module selector -->
<div style="margin-bottom:20px;display:flex;gap:12px;flex-wrap:wrap">
    <?php foreach ($modules as $m): ?>
      <a href="?p=admin_question_difficulty&module=<?=e($m['slug'])?>"
         class="btn <?= ($module && $module['id']==$m['id']) ? 'btn-primary' : 'btn-secondary' ?>"
         style="padding:10px 16px;border-radius:6px;font-weight:600;text-decoration:none;">
        <?=e($m['title'])?>
      </a>
    <?php endforeach; ?>
</div>

<?php if ($module): ?>

    <?php
    // Get questions with performance stats
    $questions = [];
    $res = db()->query("
      SELECT
        qq.id, qq.question, qq.difficulty, qq.option_a, qq.option_b, qq.option_c, qq.option_d,
        COUNT(qa.id) as total_attempts,
        SUM(CASE WHEN qa.is_correct=1 THEN 1 ELSE 0 END) as correct_count,
        ROUND(100.0 * SUM(CASE WHEN qa.is_correct=1 THEN 1 ELSE 0 END) / NULLIF(COUNT(qa.id), 0), 1) as correct_pct
      FROM quiz_questions qq
      LEFT JOIN quiz_answers qa ON qa.question_id = qq.id
      WHERE qq.module_id = ".intval($module['id'])."
      GROUP BY qq.id
      ORDER BY qq.id
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $questions[] = $r;
    ?>

    <div style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);border:1px solid #93c5fd;border-radius:12px;padding:18px;margin-bottom:20px">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
        <div>
          <div style="font-size:.75rem;font-weight:700;color:#0c4a6e;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Module</div>
          <div style="font-size:1.1rem;font-weight:800;color:#1e40af"><?=e($module['title'])?></div>
        </div>
        <div>
          <div style="font-size:.75rem;font-weight:700;color:#0c4a6e;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Questions</div>
          <div style="font-size:1.8rem;font-weight:800;color:#1e40af"><?=count($questions)?></div>
        </div>
        <div>
          <div style="font-size:.75rem;font-weight:700;color:#0c4a6e;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Avg Success Rate</div>
          <div style="font-size:1.8rem;font-weight:800;color:<?=round(array_sum(array_column($questions, 'correct_pct')) / max(1, count($questions)), 1) >= 60 ? '#10b981' : '#f59e0b'?>"><?=round(array_sum(array_column($questions, 'correct_pct')) / max(1, count($questions)), 1)?>%</div>
        </div>
      </div>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(30,64,175,.2);font-size:.85rem;color:#0c4a6e">
        <strong>📊 Total Attempts:</strong> <?=array_sum(array_column($questions, 'total_attempts'))?>
      </div>
    </div>

    <!-- Questions Grid -->
    <div style="display:grid;gap:12px">
      <?php foreach ($questions as $i => $q):
        $pct = $q['correct_pct'] ?? 0;
        $attempts = intval($q['total_attempts']);
        $status = '';
        $statusIcon = '';
        $statusColor = '#6b7280';
        $bgColor = '#f3f4f6';

        if ($attempts === 0) {
          $status = 'Not used';
          $statusIcon = '⚠️';
          $statusColor = '#9ca3af';
          $bgColor = '#f9fafb';
        } elseif ($pct >= 90) {
          $status = 'Too easy';
          $statusIcon = '🟢';
          $statusColor = '#10b981';
          $bgColor = '#f0fdf4';
        } elseif ($pct <= 20) {
          $status = 'Too hard';
          $statusIcon = '🔴';
          $statusColor = '#ef4444';
          $bgColor = '#fef2f2';
        } elseif ($pct >= 50 && $pct <= 70) {
          $status = 'Good';
          $statusIcon = '✅';
          $statusColor = '#059669';
          $bgColor = '#ecfdf5';
        } else {
          $status = 'Review';
          $statusIcon = '⚡';
          $statusColor = '#f59e0b';
          $bgColor = '#fffbeb';
        }

        $diffColor = $q['difficulty'] === 'EASY' ? '#10b981' : ($q['difficulty'] === 'HARD' ? '#ef4444' : '#f59e0b');
      ?>
      <div style="background:<?=$bgColor?>;border:1px solid #e5e7eb;border-radius:10px;padding:14px;display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start">
        <!-- Question Info -->
        <div>
          <div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px">
            <span style="background:#111;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0"><?=$i+1?></span>
            <div style="flex:1">
              <div style="font-weight:600;color:#111;margin-bottom:4px;font-size:.9rem"><?=e(substr($q['question'], 0, 80))?>...</div>
              <div style="font-size:.8rem;color:#6b7280;line-height:1.4"><?=$attempts?> attempt<?=$attempts!==1?'s':''?> &middot; <?=$pct?>% correct</div>
            </div>
          </div>

          <!-- Progress Bar -->
          <div style="width:100%;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;margin-bottom:8px">
            <div style="width:<?=$pct?>%;height:100%;background:<?=$pct >= 70 ? '#10b981' : ($pct <= 30 ? '#ef4444' : '#f59e0b')?>;transition:.3s"></div>
          </div>
        </div>

        <!-- Controls -->
        <div style="display:flex;flex-direction:column;gap:8px;min-width:150px">
          <!-- Difficulty Selector -->
          <select class="difficulty-select" data-qid="<?=$q['id']?>" style="padding:8px 10px;border:2px solid <?=$diffColor?>;background:#fff;color:<?=$diffColor?>;border-radius:6px;font-weight:600;font-size:.85rem;cursor:pointer;">
            <option value="EASY" <?=($q['difficulty']==='EASY')?'selected':''?> style="color:#10b981">🟢 EASY</option>
            <option value="MEDIUM" <?=($q['difficulty']==='MEDIUM')?'selected':''?> style="color:#f59e0b">🟡 MEDIUM</option>
            <option value="HARD" <?=($q['difficulty']==='HARD')?'selected':''?> style="color:#ef4444">🔴 HARD</option>
          </select>

          <!-- Status Badge -->
          <div style="background:#fff;border:1px solid <?=$statusColor?>;color:<?=$statusColor?>;padding:6px 10px;border-radius:6px;text-align:center;font-weight:600;font-size:.8rem">
            <?=$statusIcon?> <?=$status?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Guide -->
    <div style="margin-top:20px;padding:16px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #86efac;border-radius:12px;">
      <div style="font-weight:800;color:#166534;margin-bottom:12px;font-size:1rem">📋 How to Balance Your Quiz</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
        <div style="background:#fff;padding:12px;border-radius:8px;border-left:4px solid #10b981">
          <div style="font-weight:700;color:#10b981;margin-bottom:4px">🟢 EASY (Confidence)</div>
          <div style="font-size:.85rem;color:#6b7280;margin-bottom:6px">80%+ students answer correctly</div>
          <div style="font-size:.8rem;color:#9ca3af">Use for: Building confidence, warm-up questions</div>
        </div>
        <div style="background:#fff;padding:12px;border-radius:8px;border-left:4px solid #f59e0b">
          <div style="font-weight:700;color:#f59e0b;margin-bottom:4px">🟡 MEDIUM (Discriminator)</div>
          <div style="font-size:.85rem;color:#6b7280;margin-bottom:6px">50-70% students answer correctly</div>
          <div style="font-size:.8rem;color:#9ca3af">Use for: Assessing true learning, core concepts</div>
        </div>
        <div style="background:#fff;padding:12px;border-radius:8px;border-left:4px solid #ef4444">
          <div style="font-weight:700;color:#ef4444;margin-bottom:4px">🔴 HARD (Challenge)</div>
          <div style="font-size:.85rem;color:#6b7280;margin-bottom:6px">20-50% students answer correctly</div>
          <div style="font-size:.8rem;color:#9ca3af">Use for: Advanced learners, knowledge gaps</div>
        </div>
      </div>
      <div style="margin-top:12px;padding:12px;background:#fff;border-radius:8px;border-left:4px solid #0284c7">
        <div style="font-weight:700;color:#0c4a6e;margin-bottom:4px">🎯 Ideal Mix for Pre-Test:</div>
        <div style="font-size:.85rem;color:#6b7280">40% EASY + 40% MEDIUM + 20% HARD = Expected ~50% average</div>
      </div>
    </div>

<?php else: ?>
    <div class="alert alert-info">No modules found.</div>
<?php endif; ?>

</div><!-- /qperf-main -->

<aside class="qperf-help" aria-label="How Question Performance works">
  <h5>ℹ️ How this page works</h5>
  <div class="sub">Read this once — no need to ask again.</div>

  <details open>
    <summary>What am I looking at?</summary>
    <div class="body">
      Every card is one quiz question in this module. The tabs at the top switch modules — you're viewing one module at a time.
    </div>
  </details>

  <details>
    <summary>The two numbers per question</summary>
    <div class="body">
      <ul>
        <li><b>Attempts</b> — how many learners have ever answered this question, across all sittings and all schools.</li>
        <li><b>% correct</b> — of those attempts, the share who picked the right option.</li>
      </ul>
      <div style="margin-top:6px;color:#6b7280;font-size:.8rem">Example: "24 attempts · 75% correct" → 18 learners got it right, 6 got it wrong.</div>
    </div>
  </details>

  <details>
    <summary>The status badge (colour on the right)</summary>
    <div class="body">
      This is <b>reality</b> — a plain-English read of the % correct so you don't have to squint at numbers.
      <ul style="margin-top:8px">
        <li><span class="badge" style="background:#f3f4f6;color:#6b7280">⚠️ Not used</span> nobody has answered it yet</li>
        <li><span class="badge" style="background:#f0fdf4;color:#10b981">🟢 Too easy</span> ≥ 90% get it right — doesn't test anything</li>
        <li><span class="badge" style="background:#ecfdf5;color:#059669">✅ Good</span> 50–70% — the sweet spot</li>
        <li><span class="badge" style="background:#fffbeb;color:#f59e0b">⚡ Review</span> in-between — worth a second look</li>
        <li><span class="badge" style="background:#fef2f2;color:#ef4444">🔴 Too hard</span> ≤ 20% — question or teaching is broken</li>
      </ul>
    </div>
  </details>

  <details>
    <summary>The EASY / MEDIUM / HARD dropdown</summary>
    <div class="body">
      That's <b>your label</b> — the difficulty you intended when you wrote the question. It saves instantly when you change it.
      <div style="margin-top:8px;padding:8px;background:#fffbeb;border-left:3px solid #f59e0b;border-radius:4px;font-size:.8rem;color:#78350f">
        <b>Watch for mismatches.</b> If you marked a question <b>HARD</b> but the badge shows <b>Too easy</b>, either the label is wrong or the answer is obvious. Fix one or the other.
      </div>
    </div>
  </details>

  <details>
    <summary>What should I actually do?</summary>
    <div class="body">
      <ol style="margin:0 0 0 18px;padding:0">
        <li style="margin:4px 0">Fix the 🔴 <b>Too hard</b> ones — usually the wording is confusing or the answer key is wrong.</li>
        <li style="margin:4px 0">Replace or retire 🟢 <b>Too easy</b> ones — they don't tell you who learned.</li>
        <li style="margin:4px 0">Aim for most questions to end up ✅ <b>Good</b> (50–70%).</li>
        <li style="margin:4px 0">Target mix: <b>40% EASY + 40% MEDIUM + 20% HARD</b> for a pre-test that produces ~50% average — low enough to show growth, high enough that learners don't give up.</li>
      </ol>
    </div>
  </details>

  <details>
    <summary>How to read "Avg Success Rate"</summary>
    <div class="body">
      The blue tile at the top averages % correct across every question in this module.
      <ul style="margin-top:6px">
        <li><b>Above 80%</b> — the quiz is too easy to tell strong learners from weak ones.</li>
        <li><b>Below 30%</b> — either teaching or the questions themselves need work.</li>
        <li><b>40–60%</b> — healthy, discriminating quiz.</li>
      </ul>
    </div>
  </details>

  <details>
    <summary>Where does this data come from?</summary>
    <div class="body">
      Every time a learner submits an answer, we log it (question + right/wrong). This page just tallies those logs live for the module you're viewing — no manual refresh needed, but changes only appear after learners actually take the quiz.
    </div>
  </details>
</aside>

</div><!-- /qperf-layout -->

<script>
document.querySelectorAll('.difficulty-select').forEach(sel => {
  sel.addEventListener('change', function() {
    const qid = this.dataset.qid;
    const difficulty = this.value;
    const formData = new FormData();
    formData.append('action', 'update_difficulty');
    formData.append('question_id', qid);
    formData.append('difficulty', difficulty);

    fetch('?p=admin_question_difficulty', {
      method: 'POST',
      body: formData
    }).then(r => r.json()).then(d => {
      if (d.status === 'ok') {
        console.log('Updated Q' + qid);
      }
    }).catch(e => console.error(e));
  });
});
</script>
