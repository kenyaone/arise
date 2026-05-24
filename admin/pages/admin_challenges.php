<?php
$auth_ok = isset($_SESSION['arise_admin_id']);
if (!$auth_ok) { echo '<div class="alert">Not logged in.</div>'; return; }
$msg='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action']??'';
    if ($action==='add') {
        $st=db()->prepare("INSERT INTO weekly_challenges (title,description,module_id,week_start,week_end,is_active,created_by) VALUES (:t,:d,:m,:ws,:we,1,:cb)");
        $st->bindValue(':t',trim($_POST['title']??''));$st->bindValue(':d',trim($_POST['description']??''));
        $st->bindValue(':m',$_POST['module_id']?intval($_POST['module_id']):null,SQLITE3_INTEGER);
        $st->bindValue(':ws',$_POST['week_start']??date('Y-m-d'));$st->bindValue(':we',$_POST['week_end']??date('Y-m-d',strtotime('+7 days')));
        $st->bindValue(':cb',$_SESSION['arise_admin_id']??0);$st->execute();
        ariseAuditLog('add_challenge','weekly_challenges',(int)db()->lastInsertRowID(),$_POST['title']??'');
        $msg='✅ Challenge created!';
    } elseif ($action==='toggle') {
        $id=intval($_POST['id']); db()->exec("UPDATE weekly_challenges SET is_active=1-is_active WHERE id=$id");
        $msg='Challenge updated.';
    } elseif ($action==='delete') {
        $id=intval($_POST['id']); db()->exec("DELETE FROM weekly_challenges WHERE id=$id"); db()->exec("DELETE FROM challenge_responses WHERE challenge_id=$id");
        $msg='Deleted.';
    }
}

$challenges = db()->query("SELECT wc.*,m.title as module_title,(SELECT COUNT(*) FROM challenge_responses WHERE challenge_id=wc.id) as resp_count FROM weekly_challenges wc LEFT JOIN modules m ON wc.module_id=m.id ORDER BY wc.id DESC");
$cList=[]; while($r=$challenges->fetchArray(SQLITE3_ASSOC)) $cList[]=$r;
$modules=db()->query("SELECT id,title,icon FROM modules WHERE is_active=1 ORDER BY sort_order");
$modList=[]; while($r=$modules->fetchArray(SQLITE3_ASSOC)) $modList[]=$r;
?>
<h4>💪 Weekly Challenges</h4>
<?php if($msg): ?><div class="alert alert-success"><?=$msg?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-md-5">
    <div class="dp-card">
      <h5 class="fw-bold mb-3">Create Challenge</h5>
      <form method="post">
        <input type="hidden" name="action" value="add">
        <div class="mb-2"><label class="form-label fw-bold">Title</label><input type="text" name="title" class="form-control" required placeholder="e.g. Name 3 ways to prevent HIV"></div>
        <div class="mb-2"><label class="form-label fw-bold">Description</label><textarea name="description" class="form-control" rows="3" required placeholder="What should students reflect on or do?"></textarea></div>
        <div class="mb-2"><label class="form-label">Related Module (optional)</label>
          <select name="module_id" class="form-select"><option value="">— None —</option>
          <?php foreach($modList as $m): ?><option value="<?=$m['id']?>"><?=$m['icon']?> <?=htmlspecialchars($m['title'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="row g-2 mb-3">
          <div class="col"><label class="form-label">Week Start</label><input type="date" name="week_start" class="form-control" value="<?=date('Y-m-d')?>"></div>
          <div class="col"><label class="form-label">Week End</label><input type="date" name="week_end" class="form-control" value="<?=date('Y-m-d',strtotime('+7 days'))?>"></div>
        </div>
        <button type="submit" class="btn btn-primary w-100">Create Challenge</button>
      </form>
    </div>
  </div>
  <div class="col-md-7">
    <?php if($cList): foreach($cList as $c): ?>
    <div class="dp-card mb-2">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <span class="badge bg-<?=$c['is_active']?'success':'secondary'?> mb-1"><?=$c['is_active']?'Active':'Inactive'?></span>
          <div class="fw-bold"><?=htmlspecialchars($c['title'])?></div>
          <div class="text-muted small"><?=date('M j',strtotime($c['week_start']))?> – <?=date('M j, Y',strtotime($c['week_end']))?> · <?=$c['resp_count']?> responses <?=$c['module_title']?'· '.$c['module_title']:''?></div>
        </div>
        <div class="d-flex gap-1 flex-shrink-0">
          <form method="post" class="d-inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn btn-sm btn-outline-secondary"><?=$c['is_active']?'Deactivate':'Activate'?></button></form>
          <form method="post" class="d-inline" onsubmit="return confirm('Delete?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form>
        </div>
      </div>
      <?php if($c['resp_count']>0): ?>
        <details class="mt-2"><summary class="text-muted small" style="cursor:pointer">View <?=$c['resp_count']?> responses</summary>
        <?php $resps=db()->query("SELECT student_name,response_text,submitted_at FROM challenge_responses WHERE challenge_id=".intval($c['id'])." ORDER BY submitted_at DESC LIMIT 10");
        while($r=$resps->fetchArray(SQLITE3_ASSOC)): ?>
          <div class="border rounded p-2 mt-1 small"><strong><?=htmlspecialchars($r['student_name'])?></strong> · <?=date('M j',strtotime($r['submitted_at']))?><div class="mt-1"><?=htmlspecialchars($r['response_text'])?></div></div>
        <?php endwhile; ?></details>
      <?php endif; ?>
    </div>
    <?php endforeach; else: ?><div class="text-muted">No challenges yet.</div><?php endif; ?>
  </div>
</div>
