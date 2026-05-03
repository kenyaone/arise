<?php
$auth_ok = isset($_SESSION['arise_admin_id']);
if (!$auth_ok) { echo '<div class="alert">Not logged in.</div>'; return; }

$filter = trim($_GET['filter']??'');
$where = $filter ? "WHERE action LIKE '%".SQLite3::escapeString($filter)."%' OR admin_name LIKE '%".SQLite3::escapeString($filter)."%'" : '';
$total = (int)(db()->querySingle("SELECT COUNT(*) FROM arise_audit_log $where")??0);
$rows = db()->query("SELECT * FROM arise_audit_log $where ORDER BY created_at DESC LIMIT 100");
$list=[]; while($r=$rows->fetchArray(SQLITE3_ASSOC)) $list[]=$r;
?>
<h4>🔍 Admin Audit Log</h4>

<!-- Filter form -->
<form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
  <input type="hidden" name="p" value="audit">
  <input type="text" name="filter"
         placeholder="Filter by action or admin..."
         value="<?=htmlspecialchars($filter)?>"
         style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;min-width:220px;">
  <button type="submit" class="btn btn-primary">Filter</button>
  <?php if($filter): ?>
    <a href="?p=audit" class="btn btn-secondary">Clear</a>
  <?php endif; ?>
  <span style="font-size:.82rem;color:#6b7280;align-self:center;"><?=$total?> entries</span>
</form>

<?php if($list): ?>
<div style="overflow-x:auto">
  <table class="arise-table">
    <thead>
      <tr>
        <th>Time</th>
        <th>Admin</th>
        <th>Action</th>
        <th>Target</th>
        <th>Details</th>
        <th>IP</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($list as $r):
      // Determine badge colour based on action type
      if (str_contains($r['action'], 'delete')) {
        $badgeBg = '#fee2e2'; $badgeColor = '#991b1b';
      } elseif (str_contains($r['action'], 'add')) {
        $badgeBg = '#dcfce7'; $badgeColor = '#166534';
      } else {
        $badgeBg = '#dbeafe'; $badgeColor = '#1e40af';
      }
    ?>
    <tr>
      <td style="font-size:.8rem;white-space:nowrap;"><?=date('d/m H:i',strtotime($r['created_at']))?></td>
      <td style="font-size:.8rem;"><?=htmlspecialchars($r['admin_name']??'')?></td>
      <td>
        <span class="badge" style="background:<?=$badgeBg?>;color:<?=$badgeColor?>;">
          <?=htmlspecialchars($r['action'])?>
        </span>
      </td>
      <td style="font-size:.8rem;">
        <?=htmlspecialchars($r['target_type']??'')?><?=$r['target_id']?' #'.$r['target_id']:''?>
      </td>
      <td style="font-size:.8rem;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
        <?=htmlspecialchars(substr($r['details']??'',0,60))?>
      </td>
      <td style="font-size:.8rem;color:#6b7280;"><?=htmlspecialchars($r['ip_address']??'')?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
  <div class="alert alert-info">No audit log entries yet.</div>
<?php endif; ?>
