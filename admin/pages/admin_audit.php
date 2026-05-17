<?php
$auth_ok = isset($_SESSION['arise_admin_id']);
if (!$auth_ok) { echo '<div class="alert">Not logged in.</div>'; return; }

$filter   = trim($_GET['filter'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$page     = max(1, intval($_GET['pg'] ?? 1));
$perPage  = 50;
$offset   = ($page - 1) * $perPage;

// Build WHERE clause
$conditions = [];
if ($filter) {
    $f = SQLite3::escapeString($filter);
    $conditions[] = "(action LIKE '%$f%' OR admin_name LIKE '%$f%' OR details LIKE '%$f%')";
}
if ($typeFilter === 'delete') {
    $conditions[] = "(action LIKE '%delete%' OR action LIKE '%deactivate%' OR action LIKE '%remove%')";
} elseif ($typeFilter === 'add') {
    $conditions[] = "(action LIKE '%add%' OR action LIKE '%create%' OR action LIKE '%import%' OR action LIKE '%activate%')";
} elseif ($typeFilter === 'edit') {
    $conditions[] = "(action LIKE '%edit%' OR action LIKE '%update%' OR action LIKE '%reset%' OR action LIKE '%assign%')";
} elseif ($typeFilter === 'login') {
    $conditions[] = "(action LIKE '%login%' OR action LIKE '%logout%' OR action LIKE '%auth%' OR action LIKE '%password%')";
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$total   = (int)(db()->querySingle("SELECT COUNT(*) FROM arise_audit_log $where") ?? 0);
$pages   = max(1, (int)ceil($total / $perPage));
$rows    = db()->query("SELECT * FROM arise_audit_log $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$list    = [];
while ($r = $rows->fetchArray(SQLITE3_ASSOC)) $list[] = $r;

function auditBadge(string $action): array {
    if (preg_match('/delete|deactivate|remove/', $action)) return ['#fee2e2', '#991b1b'];
    if (preg_match('/add|create|import|activate/', $action))  return ['#dcfce7', '#166534'];
    if (preg_match('/edit|update|reset|assign/', $action))    return ['#fef9c3', '#854d0e'];
    if (preg_match('/login|logout|auth|password/', $action))  return ['#ede9fe', '#5b21b6'];
    return ['#dbeafe', '#1e40af'];
}

$qs = function(array $extra) use ($filter, $typeFilter, $page): string {
    $p = array_merge(['p' => 'audit', 'filter' => $filter, 'type' => $typeFilter, 'pg' => $page], $extra);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== 0 && $v !== '0'));
};
?>
<h4>🔍 Admin Audit Log</h4>

<!-- Filter form -->
<form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
  <input type="hidden" name="p" value="audit">
  <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>">
  <input type="text" name="filter"
         placeholder="Search action, admin, details..."
         value="<?= htmlspecialchars($filter) ?>"
         style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;min-width:240px;">
  <button type="submit" class="btn btn-primary">Search</button>
  <?php if ($filter || $typeFilter): ?>
    <a href="?p=audit" class="btn btn-secondary">Clear</a>
  <?php endif; ?>
  <span style="font-size:.82rem;color:#6b7280;align-self:center;"><?= number_format($total) ?> entries</span>
</form>

<!-- Action type filter buttons -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
  <?php
  $types = [
      '' => ['All', '#f3f4f6', '#374151'],
      'delete' => ['Deletions', '#fee2e2', '#991b1b'],
      'add'    => ['Additions', '#dcfce7', '#166534'],
      'edit'   => ['Edits', '#fef9c3', '#854d0e'],
      'login'  => ['Security', '#ede9fe', '#5b21b6'],
  ];
  foreach ($types as $key => [$label, $bg, $color]):
      $active = $typeFilter === $key;
      $url = '?' . http_build_query(array_filter(['p' => 'audit', 'filter' => $filter, 'type' => $key], fn($v) => $v !== ''));
  ?>
  <a href="<?= $url ?>"
     style="padding:6px 14px;border-radius:20px;font-size:.8rem;font-weight:600;text-decoration:none;
            background:<?= $active ? $color : $bg ?>;color:<?= $active ? 'white' : $color ?>;
            border:1.5px solid <?= $color ?>;">
    <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($list): ?>
<div style="overflow-x:auto">
  <table class="arise-table" style="font-size:.83rem;">
    <thead>
      <tr>
        <th style="width:110px;">Time</th>
        <th>Admin</th>
        <th>Action</th>
        <th>Target</th>
        <th>Details</th>
        <th>IP</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($list as $r):
      [$badgeBg, $badgeColor] = auditBadge($r['action']);
      $detailsFull = $r['details'] ?? '';
      $detailsShort = mb_substr($detailsFull, 0, 55) . (mb_strlen($detailsFull) > 55 ? '…' : '');
      $rowId = 'al' . $r['id'];
    ?>
    <tr onclick="toggleDetail('<?= $rowId ?>')" style="cursor:pointer;" title="Click to expand">
      <td style="white-space:nowrap;color:#6b7280;"><?= date('d/m H:i', strtotime($r['created_at'])) ?></td>
      <td><?= htmlspecialchars($r['admin_name'] ?? '') ?></td>
      <td>
        <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:.75rem;font-weight:700;background:<?= $badgeBg ?>;color:<?= $badgeColor ?>;">
          <?= htmlspecialchars($r['action']) ?>
        </span>
      </td>
      <td style="color:#6b7280;">
        <?= htmlspecialchars($r['target_type'] ?? '') ?><?= $r['target_id'] ? ' #' . $r['target_id'] : '' ?>
      </td>
      <td style="max-width:240px;color:#374151;">
        <span class="detail-short"><?= htmlspecialchars($detailsShort) ?></span>
      </td>
      <td style="color:#9ca3af;font-size:.75rem;"><?= htmlspecialchars($r['ip_address'] ?? '') ?></td>
    </tr>
    <?php if ($detailsFull): ?>
    <tr id="<?= $rowId ?>" style="display:none;background:#f9fafb;">
      <td colspan="6" style="padding:10px 16px;font-size:.82rem;color:#374151;border-top:none;">
        <strong>Full details:</strong><br>
        <span style="white-space:pre-wrap;word-break:break-word;"><?= htmlspecialchars($detailsFull) ?></span>
      </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div style="display:flex;align-items:center;gap:6px;margin-top:14px;flex-wrap:wrap;">
  <?php if ($page > 1): ?>
    <a href="<?= $qs(['pg' => $page - 1]) ?>" class="btn btn-secondary" style="padding:5px 12px;font-size:.82rem;">← Prev</a>
  <?php endif; ?>
  <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
    <a href="<?= $qs(['pg' => $i]) ?>"
       style="display:inline-block;padding:5px 11px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;
              background:<?= $i === $page ? '#0a5e2a' : '#f3f4f6' ?>;color:<?= $i === $page ? 'white' : '#374151' ?>;">
      <?= $i ?>
    </a>
  <?php endfor; ?>
  <?php if ($page < $pages): ?>
    <a href="<?= $qs(['pg' => $page + 1]) ?>" class="btn btn-secondary" style="padding:5px 12px;font-size:.82rem;">Next →</a>
  <?php endif; ?>
  <span style="font-size:.8rem;color:#9ca3af;">Page <?= $page ?> of <?= $pages ?></span>
</div>
<?php endif; ?>

<?php else: ?>
  <div class="alert alert-info">No audit log entries <?= ($filter || $typeFilter) ? 'matching your filter' : 'yet' ?>.</div>
<?php endif; ?>

<script>
function toggleDetail(id) {
  var row = document.getElementById(id);
  if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}
</script>
