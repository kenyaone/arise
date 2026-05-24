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
