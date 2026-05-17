<?php
/**
 * Admin User Management — Create/Edit admins with custom permissions
 */
$msg = '';
$allPerms = getAllPermissions();

// Handle create user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = $_POST['role'] ?? 'teacher';
    $perms = $_POST['permissions'] ?? [];
    
    if (empty($username) || empty($password)) {
        $msg = '❌ Username and password are required.';
    } else {
        // Check uniqueness
        $check = db()->querySingle("SELECT id FROM admin_users WHERE username = '" . SQLite3::escapeString($username) . "'");
        if ($check) {
            $msg = '❌ Username already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = db()->prepare('INSERT INTO admin_users (username, full_name, password_hash, role, created_by) VALUES (:user, :name, :hash, :role, :by)');
            $stmt->bindValue(':user', $username);
            $stmt->bindValue(':name', $fullName);
            $stmt->bindValue(':hash', $hash);
            $stmt->bindValue(':role', $role);
            $stmt->bindValue(':by', $_SESSION['arise_admin_id']);
            $stmt->execute();
            $newUserId = db()->lastInsertRowID();
            
            // Save permissions
            foreach ($perms as $perm) {
                if (isset($allPerms[$perm])) {
                    $stmt = db()->prepare('INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (:uid, :perm)');
                    $stmt->bindValue(':uid', $newUserId);
                    $stmt->bindValue(':perm', $perm);
                    $stmt->execute();
                }
            }
            ariseAuditLog('create_admin_user', 'admin_user', $newUserId, "Created admin: $username | role: $role | permissions: " . implode(',', $perms));
            $msg = "✅ User '$username' created with " . count($perms) . " permissions.";
        }
    }
}

// Handle update permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_perms'])) {
    $userId = intval($_POST['user_id']);
    $perms = $_POST['permissions'] ?? [];
    
    // Don't allow editing superadmin's permissions
    $userRole = db()->querySingle("SELECT role FROM admin_users WHERE id = $userId");
    if ($userRole !== 'superadmin') {
        db()->exec("DELETE FROM admin_permissions WHERE user_id = $userId");
        foreach ($perms as $perm) {
            if (isset($allPerms[$perm])) {
                $stmt = db()->prepare('INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (:uid, :perm)');
                $stmt->bindValue(':uid', $userId);
                $stmt->bindValue(':perm', $perm);
                $stmt->execute();
            }
        }
        $uname = db()->querySingle("SELECT username FROM admin_users WHERE id=$userId") ?? "ID $userId";
        ariseAuditLog('edit_admin_permissions', 'admin_user', $userId, "Updated permissions for: $uname | perms: " . implode(',', $perms));
        $msg = '✅ Permissions updated.';
    }
}

// Handle reset password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $userId = intval($_POST['user_id']);
    $newPass = trim($_POST['new_password'] ?? '');
    if ($newPass) {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id');
        $stmt->bindValue(':hash', $hash);
        $stmt->bindValue(':id', $userId);
        $stmt->execute();
        $uname = db()->querySingle("SELECT username FROM admin_users WHERE id=$userId") ?? "ID $userId";
        ariseAuditLog('reset_admin_password', 'admin_user', $userId, "Password reset for admin: $uname");
        $msg = '✅ Password reset successfully.';
    }
}

// Handle toggle active
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $userId = intval($_GET['toggle']);
    $userRole = db()->querySingle("SELECT role FROM admin_users WHERE id = $userId");
    if ($userRole !== 'superadmin') {
        $uname = db()->querySingle("SELECT username FROM admin_users WHERE id=$userId") ?? "ID $userId";
        $wasActive = db()->querySingle("SELECT is_active FROM admin_users WHERE id=$userId");
        db()->exec("UPDATE admin_users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = $userId");
        ariseAuditLog($wasActive ? 'deactivate_admin_user' : 'activate_admin_user', 'admin_user', $userId, ($wasActive ? 'Deactivated' : 'Activated') . " admin: $uname");
        $msg = '✅ User status updated.';
    }
}

// Get all admin users
$usersResult = db()->query("SELECT * FROM admin_users ORDER BY role DESC, username");
$users = [];
while ($row = $usersResult->fetchArray(SQLITE3_ASSOC)) { $users[] = $row; }
?>

<h1 class="page-title">🔑 User Management</h1>
<?php if ($msg): ?><div class="alert <?= str_starts_with($msg, '✅') ? 'alert-success' : 'alert-danger' ?>"><?= $msg ?></div><?php endif; ?>

<!-- Create New User -->
<?php if (!isset($_GET['action'])): ?>
<div class="dp-card">
    <h2 class="section-title">➕ Create New Admin User</h2>
    <form method="POST">
        <input type="hidden" name="create_user" value="1">
        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <div style="flex:1; min-width:180px;">
                <label class="text-small"><strong>Username *</strong></label>
                <input type="text" name="username" required placeholder="e.g. teacher1" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <div style="flex:1; min-width:180px;">
                <label class="text-small"><strong>Full Name</strong></label>
                <input type="text" name="full_name" placeholder="e.g. Mary Wanjiku" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <div style="flex:1; min-width:180px;">
                <label class="text-small"><strong>Password *</strong></label>
                <input type="password" name="password" required placeholder="Min 6 characters" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
            </div>
        </div>
        
        <div style="margin-bottom:12px;">
            <label class="text-small"><strong>Role</strong></label>
            <select name="role" style="padding:10px; border:2px solid var(--border); border-radius:8px;">
                <option value="teacher">Teacher</option>
                <option value="moderator">Moderator</option>
            </select>
        </div>

        <label class="text-small"><strong>Permissions</strong></label>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:8px; margin:10px 0 15px;">
            <?php foreach ($allPerms as $key => $info): ?>
                <label style="display:flex; align-items:center; gap:8px; padding:8px 12px; background:var(--light); border-radius:8px; cursor:pointer; font-size:0.9rem;">
                    <input type="checkbox" name="permissions[]" value="<?= $key ?>" <?= in_array($key, ['dashboard','content_view','essays_grade','questions_view']) ? 'checked' : '' ?>>
                    <span><?= $info['label'] ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <button type="submit" class="btn btn-primary">👤 Create User</button>
    </form>
</div>
<?php endif; ?>

<!-- Existing Users -->
<div class="dp-card">
    <h2 class="section-title">👥 Admin Users (<?= count($users) ?>)</h2>
    
    <?php foreach ($users as $u): 
        $userPerms = getUserPermissions($u['id']);
    ?>
    <div style="padding:15px; margin-bottom:10px; background:var(--light); border-radius:var(--radius); border-left:4px solid <?= $u['role']==='superadmin' ? 'var(--primary)' : ($u['is_active'] ? 'var(--success)' : 'var(--danger)') ?>;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <div>
                <strong><?= e($u['full_name'] ?? $u['username']) ?></strong>
                <span class="text-muted text-small"> @<?= e($u['username']) ?></span>
                <span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:0.7rem; font-weight:600; background:<?= $u['role']==='superadmin' ? '#F0EEFF' : '#E6FFF5' ?>; color:<?= $u['role']==='superadmin' ? 'var(--primary)' : 'var(--success)' ?>;">
                    <?= strtoupper($u['role']) ?>
                </span>
                <?php if (!$u['is_active']): ?>
                    <span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:0.7rem; font-weight:600; background:#FFF0ED; color:var(--danger);">DISABLED</span>
                <?php endif; ?>
            </div>
            <?php if ($u['role'] !== 'superadmin'): ?>
                <div style="display:flex; gap:5px;">
                    <a href="?p=users&action=edit&id=<?= $u['id'] ?>" class="btn btn-sm btn-secondary">✏️ Edit</a>
                    <a href="?p=users&toggle=<?= $u['id'] ?>" class="btn btn-sm btn-secondary"><?= $u['is_active'] ? '🔒 Disable' : '🔓 Enable' ?></a>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:4px;">
            <?php 
            $displayPerms = $u['role'] === 'superadmin' ? array_keys($allPerms) : $userPerms;
            foreach ($displayPerms as $p): 
                if (!isset($allPerms[$p])) continue;
            ?>
                <span style="font-size:0.7rem; padding:2px 6px; background:white; border-radius:6px; color:var(--dark-soft);"><?= $allPerms[$p]['label'] ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])): 
    $editId = intval($_GET['id']);
    $editUser = db()->querySingle("SELECT * FROM admin_users WHERE id = $editId", true);
    $editPerms = getUserPermissions($editId);
    if ($editUser && $editUser['role'] !== 'superadmin'):
?>
<div class="dp-card" id="edit-form">
    <h2 class="section-title">✏️ Edit: <?= e($editUser['full_name'] ?? $editUser['username']) ?></h2>
    
    <!-- Update Permissions -->
    <form method="POST" style="margin-bottom:20px;">
        <input type="hidden" name="update_perms" value="1">
        <input type="hidden" name="user_id" value="<?= $editId ?>">
        <label class="text-small"><strong>Permissions</strong></label>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:8px; margin:10px 0 15px;">
            <?php foreach ($allPerms as $key => $info): ?>
                <label style="display:flex; align-items:center; gap:8px; padding:8px 12px; background:var(--light); border-radius:8px; cursor:pointer; font-size:0.9rem;">
                    <input type="checkbox" name="permissions[]" value="<?= $key ?>" <?= in_array($key, $editPerms) ? 'checked' : '' ?>>
                    <span><?= $info['label'] ?> <span class="text-muted text-small">— <?= $info['desc'] ?></span></span>
                </label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary">💾 Save Permissions</button>
        <a href="?p=users" class="btn btn-secondary">Cancel</a>
    </form>
    
    <hr style="margin:20px 0; border:none; border-top:1px solid var(--border);">
    
    <!-- Reset Password -->
    <form method="POST">
        <input type="hidden" name="reset_password" value="1">
        <input type="hidden" name="user_id" value="<?= $editId ?>">
        <label class="text-small"><strong>Reset Password</strong></label>
        <div style="display:flex; gap:10px; margin-top:5px;">
            <input type="password" name="new_password" placeholder="New password" required style="flex:1; padding:10px; border:2px solid var(--border); border-radius:8px;">
            <button type="submit" class="btn btn-secondary">🔑 Reset</button>
        </div>
    </form>
</div>
<?php endif; endif; ?>
