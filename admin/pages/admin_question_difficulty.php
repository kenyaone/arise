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

    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:16px;margin-bottom:20px">
      <h4 style="margin:0 0 10px 0">Module: <?=e($module['title'])?></h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
        <div><strong>Total Questions:</strong> <?=count($questions)?></div>
        <div><strong>Total Attempts:</strong> <?=array_sum(array_column($questions, 'total_attempts'))?></div>
        <div><strong>Avg Correct:</strong> <?=round(array_sum(array_column($questions, 'correct_pct')) / max(1, count($questions)), 1)?>%</div>
      </div>
    </div>

    <!-- Questions Table -->
    <div style="overflow-x:auto">
      <table class="arise-table" style="width:100%">
        <thead>
          <tr>
            <th>Q#</th>
            <th>Question</th>
            <th>Difficulty</th>
            <th>Correct %</th>
            <th>Attempts</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($questions as $i => $q):
            $pct = $q['correct_pct'] ?? 0;
            $attempts = intval($q['total_attempts']);
            $status = '';
            $statusColor = '#6b7280';

            if ($attempts === 0) {
              $status = '⚠️ Not used';
              $statusColor = '#9ca3af';
            } elseif ($pct >= 90) {
              $status = '🟢 Too easy';
              $statusColor = '#10b981';
            } elseif ($pct <= 20) {
              $status = '🔴 Too hard';
              $statusColor = '#ef4444';
            } elseif ($pct >= 50 && $pct <= 70) {
              $status = '✅ Good';
              $statusColor = '#059669';
            }
          ?>
          <tr>
            <td><strong><?=$i+1?></strong></td>
            <td style="max-width:400px"><?=e(substr($q['question'], 0, 60))?>...</td>
            <td>
              <select class="difficulty-select" data-qid="<?=$q['id']?>" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;">
                <option value="EASY" <?=($q['difficulty']==='EASY')?'selected':''?>>EASY</option>
                <option value="MEDIUM" <?=($q['difficulty']==='MEDIUM')?'selected':''?>>MEDIUM</option>
                <option value="HARD" <?=($q['difficulty']==='HARD')?'selected':''?>>HARD</option>
              </select>
            </td>
            <td style="text-align:center"><strong style="color:<?= $pct >= 70 ? '#10b981' : ($pct <= 30 ? '#ef4444' : '#f59e0b') ?>"><?=$pct?>%</strong></td>
            <td style="text-align:center"><?=$attempts?></td>
            <td style="color:<?=$statusColor?>;font-weight:600"><?=$status?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Guide -->
    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px;margin-top:16px;font-size:.85rem">
      <strong style="color:#166534">📌 Difficulty Guide:</strong>
      <div style="color:#15803d;margin-top:6px">
        <div><strong>EASY:</strong> 80%+ correct (confidence)</div>
        <div><strong>MEDIUM:</strong> 50-70% correct (discriminator)</div>
        <div><strong>HARD:</strong> 20-50% correct (stretch)</div>
        <div style="margin-top:6px"><strong>Pre-test target:</strong> 40% EASY + 40% MEDIUM + 20% HARD = ~50% avg</div>
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
