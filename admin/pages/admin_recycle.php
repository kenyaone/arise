<?php
/**
 * ARISE Admin — Recycle Bin
 * Soft-deleted learners and admin users
 */
if (!isset($_SESSION['arise_admin_id'])) {
    header('Location: /arise/admin/');
    exit;
}

$msg = '';
$msgType = 'alert-success';

// ============================================================
// POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);

    if ($action === 'restore_student' && $id > 0) {
        $stmt = db()->prepare('UPDATE students SET is_active=1, deleted_at=NULL WHERE id=:id AND deleted_at IS NOT NULL');
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        if (db()->changes() > 0) {
            $name = db()->querySingle("SELECT full_name FROM students WHERE id=$id");
            ariseAuditLog('restore_student', 'student', $id, "Restored learner: " . ($name ?? "ID $id"));
            $msg = '✅ Learner restored successfully.';
        } else {
            $msg = 'No changes made — learner not found or already active.';
            $msgType = 'alert-error';
        }
    }

    elseif ($action === 'purge_student' && $id > 0) {
        $name = db()->querySingle("SELECT full_name FROM students WHERE id=$id AND deleted_at IS NOT NULL");
        if ($name) {
            $stmt = db()->prepare('DELETE FROM students WHERE id=:id AND deleted_at IS NOT NULL');
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            ariseAuditLog('purge_student', 'student', $id, "Permanently deleted learner: $name");
            $msg = '🗑 Learner permanently deleted.';
        } else {
            $msg = 'Learner not found in recycle bin.';
            $msgType = 'alert-error';
        }
    }

    elseif ($action === 'restore_admin' && $id > 0) {
        $stmt = db()->prepare('UPDATE admin_users SET is_active=1, deleted_at=NULL WHERE id=:id AND deleted_at IS NOT NULL');
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        if (db()->changes() > 0) {
            $uname = db()->querySingle("SELECT username FROM admin_users WHERE id=$id");
            ariseAuditLog('restore_admin', 'admin_user', $id, "Restored admin user: " . ($uname ?? "ID $id"));
            $msg = '✅ Admin user restored successfully.';
        } else {
            $msg = 'No changes made — admin user not found or already active.';
            $msgType = 'alert-error';
        }
    }

    elseif ($action === 'purge_admin' && $id > 0) {
        $uname = db()->querySingle("SELECT username FROM admin_users WHERE id=$id AND deleted_at IS NOT NULL");
        if ($uname) {
            // Prevent self-purge
            if ($id === (int)($_SESSION['arise_admin_id'] ?? 0)) {
                $msg = 'You cannot purge your own account.';
                $msgType = 'alert-error';
            } else {
                $stmt = db()->prepare('DELETE FROM admin_users WHERE id=:id AND deleted_at IS NOT NULL');
                $stmt->bindValue(':id', $id);
                $stmt->execute();
                ariseAuditLog('purge_admin', 'admin_user', $id, "Permanently deleted admin user: $uname");
                $msg = '🗑 Admin user permanently deleted.';
            }
        } else {
            $msg = 'Admin user not found in recycle bin.';
            $msgType = 'alert-error';
        }
    }
}

// ============================================================
// FETCH DATA
// ============================================================
$deletedStudents = [];
try {
    $res = db()->query("SELECT * FROM students WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $deletedStudents[] = $row;
    }
} catch (Exception $e) {
    // deleted_at column may not exist yet — silently skip
}

$deletedAdmins = [];
try {
    $res = db()->query("SELECT * FROM admin_users WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $deletedAdmins[] = $row;
    }
} catch (Exception $e) {
    // deleted_at column may not exist yet — silently skip
}
?>

<h1 class="page-title">♻️ Recycle Bin</h1>

<?php if ($msg): ?>
    <div class="alert <?= $msgType ?>" style="margin-bottom:16px; padding:12px 16px; border-radius:6px; border-left:4px solid <?= $msgType === 'alert-success' ? '#16a34a' : '#dc2626' ?>; background:<?= $msgType === 'alert-success' ? '#f0fdf4' : '#fef2f2' ?>; color:<?= $msgType === 'alert-success' ? '#166534' : '#991b1b' ?>;">
        <?= e($msg) ?>
    </div>
<?php endif; ?>

<!-- ============================================================
     SECTION 1: Deleted Learners
     ============================================================ -->
<div class="dp-card" style="margin-bottom:24px;">
    <h2 class="section-title" style="margin-bottom:14px;">
        👥 Deleted Learners
        <span class="badge" style="background:#fee2e2; color:#b91c1c; padding:2px 8px; border-radius:12px; font-size:0.8rem; margin-left:8px;"><?= count($deletedStudents) ?></span>
    </h2>

    <?php if (empty($deletedStudents)): ?>
        <div style="padding:24px; text-align:center; color:#6b7280; background:#f9fafb; border-radius:8px; border:2px dashed #e5e7eb;">
            <div style="font-size:2rem; margin-bottom:8px;">✅</div>
            <p style="margin:0; font-weight:600;">No deleted learners</p>
            <p style="margin:4px 0 0; font-size:0.85rem;">The learner recycle bin is empty.</p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="arise-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Project</th>
                    <th>Cluster</th>
                    <th>Deleted At</th>
                    <th style="min-width:180px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deletedStudents as $s): ?>
                <tr>
                    <td style="font-weight:600;"><?= e($s['full_name']) ?></td>
                    <td><?= e($s['school_name'] ?? '—') ?></td>
                    <td><?= e($s['class_name'] ?? '—') ?></td>
                    <td style="font-size:0.85rem;"><?= $s['deleted_at'] ? date('M j, Y g:i A', strtotime($s['deleted_at'])) : '—' ?></td>
                    <td>
                        <!-- Restore -->
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="restore_student">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button type="submit" class="btn btn-primary"
                                    style="padding:4px 10px; font-size:0.8rem; background:#16a34a; border-color:#16a34a;">
                                ♻️ Restore
                            </button>
                        </form>
                        <!-- Purge -->
                        <form method="POST" style="display:inline; margin-left:6px;">
                            <input type="hidden" name="action" value="purge_student">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button type="submit" class="btn btn-secondary"
                                    style="padding:4px 10px; font-size:0.8rem; background:#dc2626; border-color:#dc2626; color:#fff;"
                                    onclick="return confirm('Permanently delete <?= addslashes(htmlspecialchars($s['full_name'], ENT_QUOTES)) ?>? This cannot be undone.')">
                                🗑 Purge
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     SECTION 2: Deleted Admin Users
     ============================================================ -->
<div class="dp-card" style="margin-bottom:24px;">
    <h2 class="section-title" style="margin-bottom:14px;">
        🔑 Deleted Admin Users
        <span class="badge" style="background:#fee2e2; color:#b91c1c; padding:2px 8px; border-radius:12px; font-size:0.8rem; margin-left:8px;"><?= count($deletedAdmins) ?></span>
    </h2>

    <?php if (empty($deletedAdmins)): ?>
        <div style="padding:24px; text-align:center; color:#6b7280; background:#f9fafb; border-radius:8px; border:2px dashed #e5e7eb;">
            <div style="font-size:2rem; margin-bottom:8px;">✅</div>
            <p style="margin:0; font-weight:600;">No deleted admin users</p>
            <p style="margin:4px 0 0; font-size:0.85rem;">The admin recycle bin is empty.</p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="arise-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Deleted At</th>
                    <th style="min-width:180px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deletedAdmins as $a): ?>
                <tr>
                    <td style="font-family:monospace; font-weight:600;"><?= e($a['username']) ?></td>
                    <td><?= e($a['full_name'] ?? '—') ?></td>
                    <td>
                        <span class="badge" style="background:#ede9fe; color:#5b21b6; padding:2px 8px; border-radius:12px; font-size:0.8rem;">
                            <?= e(ucfirst($a['role'] ?? 'teacher')) ?>
                        </span>
                    </td>
                    <td style="font-size:0.85rem;"><?= $a['deleted_at'] ? date('M j, Y g:i A', strtotime($a['deleted_at'])) : '—' ?></td>
                    <td>
                        <!-- Restore -->
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="restore_admin">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="btn btn-primary"
                                    style="padding:4px 10px; font-size:0.8rem; background:#16a34a; border-color:#16a34a;">
                                ♻️ Restore
                            </button>
                        </form>
                        <!-- Purge -->
                        <form method="POST" style="display:inline; margin-left:6px;">
                            <input type="hidden" name="action" value="purge_admin">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="btn btn-secondary"
                                    style="padding:4px 10px; font-size:0.8rem; background:#dc2626; border-color:#dc2626; color:#fff;"
                                    onclick="return confirm('Permanently delete admin &quot;<?= addslashes(htmlspecialchars($a['username'], ENT_QUOTES)) ?>&quot;? This cannot be undone.')">
                                🗑 Purge
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
