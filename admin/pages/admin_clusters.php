<?php
// Cluster & Project Assignment Management
$db = $GLOBALS['db'] ?? null;
if (!$db) { echo '<p class="text-danger">Database not available.</p>'; return; }

// Ensure clusters table + column exist
$db->exec("CREATE TABLE IF NOT EXISTS clusters (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
)");
try { $db->exec("ALTER TABLE schools ADD COLUMN cluster_id INTEGER REFERENCES clusters(id)"); } catch(Exception $e){}

$msg = '';
$err = '';
$action = $_POST['action'] ?? '';

// ── CREATE CLUSTER ────────────────────────────────────────────────────────────
if ($action === 'create_cluster') {
    $name = trim($_POST['cname'] ?? '');
    $pw   = trim($_POST['cpw']   ?? '');
    if ($name === '' || $pw === '') {
        $err = 'Cluster name and password are required.';
    } else {
        $hash = hash('sha256', $pw);
        $stmt = $db->prepare("INSERT INTO clusters (name, password_hash) VALUES (?, ?)");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $hash, SQLITE3_TEXT);
        try { $stmt->execute(); $msg = "Cluster \"$name\" created."; }
        catch(Exception $e) { $err = 'Name already exists.'; }
    }
}

// ── EDIT CLUSTER (name / password) ───────────────────────────────────────────
if ($action === 'edit_cluster') {
    $id   = (int)($_POST['cid'] ?? 0);
    $name = trim($_POST['cname'] ?? '');
    $pw   = trim($_POST['cpw']   ?? '');
    if ($id && $name) {
        $db->prepare("UPDATE clusters SET name=? WHERE id=?")->execute() ?: null;
        $stmt = $db->prepare("UPDATE clusters SET name=? WHERE id=?");
        $stmt->bindValue(1, $name, SQLITE3_TEXT);
        $stmt->bindValue(2, $id,   SQLITE3_INTEGER);
        $stmt->execute();
        if ($pw !== '') {
            $stmt2 = $db->prepare("UPDATE clusters SET password_hash=? WHERE id=?");
            $stmt2->bindValue(1, hash('sha256', $pw), SQLITE3_TEXT);
            $stmt2->bindValue(2, $id, SQLITE3_INTEGER);
            $stmt2->execute();
        }
        $msg = 'Cluster updated.';
    }
}

// ── DELETE CLUSTER ────────────────────────────────────────────────────────────
if ($action === 'delete_cluster') {
    $id = (int)($_POST['cid'] ?? 0);
    if ($id) {
        $db->exec("UPDATE schools SET cluster_id=NULL WHERE cluster_id=$id");
        $db->exec("DELETE FROM clusters WHERE id=$id");
        $msg = 'Cluster deleted. Projects moved to Unassigned.';
    }
}

// ── ASSIGN PROJECT TO CLUSTER ─────────────────────────────────────────────────
if ($action === 'assign_project') {
    $sid = (int)($_POST['school_id']  ?? 0);
    $cid = $_POST['cluster_id'] ?? '';
    if ($sid) {
        if ($cid === '' || $cid === '0') {
            $db->exec("UPDATE schools SET cluster_id=NULL WHERE id=$sid");
        } else {
            $stmt = $db->prepare("UPDATE schools SET cluster_id=? WHERE id=?");
            $stmt->bindValue(1, (int)$cid, SQLITE3_INTEGER);
            $stmt->bindValue(2, $sid,      SQLITE3_INTEGER);
            $stmt->execute();
        }
        $msg = 'Project assignment updated.';
    }
}

// ── BULK ASSIGN ───────────────────────────────────────────────────────────────
if ($action === 'bulk_assign') {
    $cid  = (int)($_POST['bulk_cluster_id'] ?? 0);
    $sids = $_POST['school_ids'] ?? [];
    if ($cid && !empty($sids)) {
        foreach ($sids as $sid) {
            $sid = (int)$sid;
            $db->exec("UPDATE schools SET cluster_id=$cid WHERE id=$sid");
        }
        $msg = count($sids) . ' project(s) assigned to cluster.';
    }
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$clusters = [];
$r = $db->query("SELECT * FROM clusters ORDER BY name");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $clusters[] = $row;

$projects = [];
$r2 = $db->query("SELECT s.id, s.name, s.county, s.cluster_id, COALESCE(cl.name,'') AS cluster_name
                  FROM schools s
                  LEFT JOIN clusters cl ON cl.id = s.cluster_id
                  WHERE s.is_active=1
                  ORDER BY cl.name, s.name");
while ($row = $r2->fetchArray(SQLITE3_ASSOC)) $projects[] = $row;

$clusterMap = [];
foreach ($clusters as $c) $clusterMap[$c['id']] = $c['name'];
?>

<style>
.cl-section{background:#fff;border-radius:10px;padding:22px;margin-bottom:22px;box-shadow:0 1px 4px rgba(0,0,0,.08);}
.cl-section h3{font-size:1rem;color:#0a5e2a;font-weight:700;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid #e5e7eb;}
.cl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;}
.cl-card{border:1.5px solid #e5e7eb;border-radius:8px;padding:15px;}
.cl-card h4{color:#0a5e2a;font-size:.95rem;font-weight:700;margin-bottom:4px;}
.cl-card .meta{font-size:.78rem;color:#999;margin-bottom:10px;}
.cl-card .actions{display:flex;gap:8px;flex-wrap:wrap;}
.btn-sm{padding:5px 12px;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer;border:none;}
.btn-pri{background:#0a5e2a;color:#fff;}
.btn-sec{background:#f3f4f6;color:#333;}
.btn-dan{background:#fee2e2;color:#dc2626;}
.btn-pri:hover{background:#0d7a38;}
.btn-dan:hover{background:#dc2626;color:#fff;}
.form-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;}
.form-row label{font-size:.82rem;font-weight:600;color:#444;display:block;margin-bottom:3px;}
.form-row input,.form-row select{padding:8px 11px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:.85rem;min-width:160px;}
.form-row input:focus,.form-row select:focus{outline:none;border-color:#0a5e2a;}
.msg{padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:.88rem;}
.msg.ok{background:#dcfce7;color:#166534;}
.msg.err{background:#fee2e2;color:#991b1b;}
.proj-table{width:100%;border-collapse:collapse;font-size:.85rem;}
.proj-table th{background:#f8f8f8;padding:8px 12px;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #e5e7eb;}
.proj-table td{padding:8px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.proj-table tr:hover td{background:#f9fafb;}
.cluster-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:.75rem;font-weight:700;}
.unassigned{background:#f3f4f6;color:#9ca3af;}
.assigned{background:#dcfce7;color:#166534;}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:12px;padding:28px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-box h3{margin-bottom:16px;color:#0a5e2a;}
.modal-box input{width:100%;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:.9rem;margin-bottom:12px;}
.modal-box input:focus{outline:none;border-color:#0a5e2a;}
.modal-actions{display:flex;gap:10px;margin-top:4px;}
</style>

<?php if ($msg): ?><div class="msg ok">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg err">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- ── CREATE CLUSTER ──────────────────────────────────────────────── -->
<div class="cl-section">
    <h3>+ Create New Cluster</h3>
    <form method="POST">
        <input type="hidden" name="action" value="create_cluster">
        <div class="form-row">
            <div>
                <label>Cluster Name</label>
                <input type="text" name="cname" placeholder="e.g. Nairobi Cluster" required>
            </div>
            <div>
                <label>Manager Password</label>
                <input type="password" name="cpw" placeholder="Set a password" required>
            </div>
            <div style="padding-bottom:1px;">
                <button type="submit" class="btn-sm btn-pri" style="padding:9px 20px;">Create Cluster</button>
            </div>
        </div>
    </form>
</div>

<!-- ── EXISTING CLUSTERS ───────────────────────────────────────────── -->
<?php if ($clusters): ?>
<div class="cl-section">
    <h3>📁 Clusters (<?= count($clusters) ?>)</h3>
    <div class="cl-grid">
    <?php foreach ($clusters as $c):
        $count = count(array_filter($projects, fn($p) => (int)$p['cluster_id'] === (int)$c['id']));
    ?>
        <div class="cl-card">
            <h4><?= htmlspecialchars($c['name']) ?></h4>
            <p class="meta"><?= $count ?> project<?= $count !== 1 ? 's' : '' ?> &nbsp;|&nbsp; Created <?= substr($c['created_at'],0,10) ?></p>
            <div class="actions">
                <button class="btn-sm btn-sec" onclick="openEdit(<?= $c['id'] ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')">✏ Edit</button>
                <form method="POST" onsubmit="return confirm('Delete cluster? Projects will be unassigned.');" style="display:inline;">
                    <input type="hidden" name="action" value="delete_cluster">
                    <input type="hidden" name="cid"    value="<?= $c['id'] ?>">
                    <button type="submit" class="btn-sm btn-dan">🗑 Delete</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── ASSIGN PROJECTS ─────────────────────────────────────────────── -->
<div class="cl-section">
    <h3>📌 Assign Projects to Clusters</h3>

    <?php if (empty($clusters)): ?>
    <p style="color:#999;font-size:.88rem;">Create at least one cluster above before assigning projects.</p>
    <?php else: ?>

    <!-- Bulk assign -->
    <form method="POST" style="margin-bottom:18px;padding:14px;background:#f8fafb;border-radius:8px;border:1.5px dashed #cbd5e1;">
        <input type="hidden" name="action" value="bulk_assign">
        <p style="font-size:.83rem;font-weight:700;color:#555;margin-bottom:10px;">Bulk Assign: tick projects below then choose a cluster</p>
        <div class="form-row">
            <div>
                <label>Assign selected to</label>
                <select name="bulk_cluster_id" required>
                    <option value="">— choose cluster —</option>
                    <?php foreach ($clusters as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="padding-bottom:1px;">
                <button type="submit" class="btn-sm btn-pri" style="padding:9px 18px;">Assign Selected</button>
            </div>
        </div>

    <!-- Projects table -->
    <table class="proj-table">
        <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" id="checkAll" onclick="toggleAll(this)"></th>
                <th>Project</th>
                <th>County</th>
                <th>Current Cluster</th>
                <th>Change</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($projects as $p): ?>
        <tr>
            <td><input type="checkbox" name="school_ids[]" value="<?= $p['id'] ?>"></td>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td><?= htmlspecialchars($p['county'] ?? '—') ?></td>
            <td>
                <?php if ($p['cluster_id']): ?>
                <span class="cluster-badge assigned">📁 <?= htmlspecialchars($p['cluster_name']) ?></span>
                <?php else: ?>
                <span class="cluster-badge unassigned">Unassigned</span>
                <?php endif; ?>
            </td>
            <td>
                <form method="POST" style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" name="action"    value="assign_project">
                    <input type="hidden" name="school_id" value="<?= $p['id'] ?>">
                    <select name="cluster_id" onchange="this.form.submit()" style="padding:5px 8px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:.8rem;">
                        <option value="0" <?= !$p['cluster_id'] ? 'selected' : '' ?>>— Unassigned —</option>
                        <?php foreach ($clusters as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= (int)$p['cluster_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </form>
    <?php endif; ?>
</div>

<!-- ── EDIT CLUSTER MODAL ──────────────────────────────────────────── -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3>✏ Edit Cluster</h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit_cluster">
            <input type="hidden" name="cid"    id="editCid">
            <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:3px;">Cluster Name</label>
            <input type="text"     name="cname" id="editName" required>
            <label style="font-size:.82rem;font-weight:600;display:block;margin-bottom:3px;">New Password <span style="color:#999;font-weight:400;">(leave blank to keep current)</span></label>
            <input type="password" name="cpw"   placeholder="Leave blank to keep">
            <div class="modal-actions">
                <button type="submit" class="btn-sm btn-pri" style="padding:9px 18px;">Save Changes</button>
                <button type="button" class="btn-sm btn-sec" style="padding:9px 18px;" onclick="closeEdit()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(id, name) {
    document.getElementById('editCid').value  = id;
    document.getElementById('editName').value = name;
    document.getElementById('editModal').classList.add('open');
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('open');
}
function toggleAll(cb) {
    document.querySelectorAll('input[name="school_ids[]"]').forEach(c => c.checked = cb.checked);
}
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEdit();
});
</script>
