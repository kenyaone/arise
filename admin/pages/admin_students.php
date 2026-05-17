<?php
/**
 * Admin Student Management (patched)
 * New: soft_delete, reset_password, export_csv, import_csv
 */
$msg = '';
$importResults = null;

// ============================================================
// NEW: Export CSV
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="learners_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Full Name', 'Project', 'Cluster', 'Registered']);
    $res = db()->query(
        "SELECT id, full_name, school_name, class_name, registered_at
         FROM students
         WHERE is_active=1 AND (deleted_at IS NULL OR deleted_at='')
         ORDER BY full_name"
    );
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        fputcsv($out, [
            $row['id'],
            $row['full_name'],
            $row['school_name'] ?? '',
            $row['class_name'] ?? '',
            $row['registered_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// NEW: Import CSV
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmpPath, 'r');
        $header = fgetcsv($handle); // skip header row
        // Normalize header keys
        $header = array_map('strtolower', array_map('trim', $header ?? []));

        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $lineNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (count($row) < 3) {
                $errors[] = "Line $lineNum: not enough columns.";
                continue;
            }
            // Support named columns or positional (full_name, school_name/project, class_name/cluster)
            if ($header) {
                $mapped = array_combine(array_slice($header, 0, count($row)), $row);
                $full_name  = trim($mapped['full_name'] ?? $mapped['name'] ?? $row[0] ?? '');
                $school_name = trim($mapped['school_name'] ?? $mapped['project'] ?? $row[1] ?? '');
                $class_name  = trim($mapped['class_name'] ?? $mapped['cluster'] ?? $row[2] ?? '');
            } else {
                $full_name  = trim($row[0] ?? '');
                $school_name = trim($row[1] ?? '');
                $class_name  = trim($row[2] ?? '');
            }

            if (empty($full_name) || empty($class_name)) {
                $errors[] = "Line $lineNum: full_name and class_name are required.";
                $skipped++;
                continue;
            }

            // Check for duplicate (full_name + class_name)
            $exists = db()->querySingle(
                "SELECT id FROM students WHERE LOWER(full_name)=LOWER('" . SQLite3::escapeString($full_name) . "') AND LOWER(class_name)=LOWER('" . SQLite3::escapeString($class_name) . "')"
            );
            if ($exists) {
                $skipped++;
                continue;
            }

            $stmt = db()->prepare('INSERT INTO students (full_name, school_name, class_name) VALUES (:n, :s, :c)');
            $stmt->bindValue(':n', $full_name);
            $stmt->bindValue(':s', $school_name);
            $stmt->bindValue(':c', $class_name);
            $stmt->execute();
            $inserted++;
        }
        fclose($handle);

        ariseAuditLog('import_csv', 'students', 0, "CSV import: $inserted inserted, $skipped skipped.");
        $importResults = compact('inserted', 'skipped', 'errors');
        $msg = "CSV import complete: $inserted learner(s) added, $skipped skipped.";
    } else {
        $msg = '⚠️ Upload failed or no file selected.';
    }
}

// ============================================================
// NEW: Soft Delete
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'soft_delete' && hasPermission('students_manage')) {
    $id = intval($_POST['student_id'] ?? 0);
    if ($id > 0) {
        $stmt = db()->prepare('UPDATE students SET deleted_at=CURRENT_TIMESTAMP, is_active=0 WHERE id=:id');
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        $name = db()->querySingle("SELECT full_name FROM students WHERE id=$id");
        ariseAuditLog('soft_delete_student', 'student', $id, "Soft-deleted learner: " . ($name ?? "ID $id"));
        $msg = '✅ Learner moved to recycle bin.';
    }
}

// ============================================================
// NEW: Reset Password
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password' && hasPermission('students_manage')) {
    $id = intval($_POST['student_id'] ?? 0);
    if ($id > 0) {
        $stmt = db()->prepare('UPDATE students SET password_hash=NULL WHERE id=:id');
        $stmt->bindValue(':id', $id);
        $stmt->execute();
        $name = db()->querySingle("SELECT full_name FROM students WHERE id=$id");
        ariseAuditLog('reset_student_password', 'student', $id, "Reset password for learner: " . ($name ?? "ID $id"));
        $msg = '✅ Password reset. Learner can now log in without a password.';
    }
}

// ============================================================
// EXISTING: Handle add student manually
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $name = trim($_POST['full_name'] ?? '');
    $school = trim($_POST['school_name'] ?? '');
    $class = trim($_POST['class_name'] ?? '');
    if ($name) {
        $stmt = db()->prepare('INSERT INTO students (full_name, school_name, class_name) VALUES (:n, :s, :c)');
        $stmt->bindValue(':n', $name);
        $stmt->bindValue(':s', $school);
        $stmt->bindValue(':c', $class);
        $stmt->execute();
        $newId = db()->lastInsertRowID();
        ariseAuditLog('add_student', 'student', $newId, "Added: $name | School: $school | Cluster: $class");
        $msg = '✅ Student added.';
    }
}

// ============================================================
// EXISTING: Handle edit
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student'])) {
    $id = intval($_POST['student_id']);
    $stmt = db()->prepare('UPDATE students SET full_name = :n, school_name = :s, class_name = :c WHERE id = :id');
    $stmt->bindValue(':n', trim($_POST['full_name']));
    $stmt->bindValue(':s', trim($_POST['school_name']));
    $stmt->bindValue(':c', trim($_POST['class_name']));
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    ariseAuditLog('edit_student', 'student', $id, "Updated: " . trim($_POST['full_name']) . " | School: " . trim($_POST['school_name']));
    $msg = '✅ Student updated.';
}

// ============================================================
// EXISTING: Handle deactivate (legacy GET delete)
// ============================================================
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && hasPermission('students_manage')) {
    $stmt = db()->prepare('UPDATE students SET is_active = 0 WHERE id = :id');
    $delId = intval($_GET['delete']);
    $delName = db()->querySingle("SELECT full_name FROM students WHERE id=$delId") ?? "ID $delId";
    $stmt->bindValue(':id', $delId);
    $stmt->execute();
    ariseAuditLog('deactivate_student', 'student', $delId, "Deactivated: $delName");
    $msg = '✅ Student deactivated.';
}

// ============================================================
// FILTERS
// ============================================================
$search = $_GET['search'] ?? '';
$filterClass = $_GET['class'] ?? '';

$where = "WHERE s.is_active = 1 AND (s.deleted_at IS NULL OR s.deleted_at = '')";
if ($search) $where .= " AND s.full_name LIKE '%" . SQLite3::escapeString($search) . "%'";
if ($filterClass) $where .= " AND s.class_name = '" . SQLite3::escapeString($filterClass) . "'";

$totalStudents = db()->querySingle("SELECT COUNT(*) FROM students s $where");

$result = db()->query("SELECT s.*,
    (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.student_id = s.id) AS quiz_count,
    (SELECT ROUND(AVG(qa.percentage),1) FROM quiz_attempts qa WHERE qa.student_id = s.id) AS avg_score,
    (SELECT COUNT(*) FROM essay_responses er WHERE er.student_id = s.id) AS essay_count
    FROM students s $where ORDER BY s.full_name");
$students = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $students[] = $row; }

// Get unique classes for filter
$classResult = db()->query("SELECT DISTINCT class_name FROM students WHERE is_active = 1 AND (deleted_at IS NULL OR deleted_at = '') AND class_name IS NOT NULL AND class_name != '' ORDER BY class_name");
$classes = [];
while ($row = $classResult->fetchArray(SQLITE3_ASSOC)) { $classes[] = $row['class_name']; }

// Show import toggle
$showImportForm = isset($_GET['import_form']);
?>

<h1 class="page-title">👥 Learners (<?= $totalStudents ?>)</h1>

<?php if ($msg): ?>
    <div class="alert alert-success" style="margin-bottom:14px; padding:12px 16px; border-radius:6px; border-left:4px solid #16a34a; background:#f0fdf4; color:#166534;">
        <?= e($msg) ?>
    </div>
<?php endif; ?>

<?php if (!empty($importResults['errors'])): ?>
    <div class="alert alert-error" style="margin-bottom:14px; padding:12px 16px; border-radius:6px; border-left:4px solid #dc2626; background:#fef2f2; color:#991b1b;">
        <strong>Import warnings:</strong>
        <ul style="margin:6px 0 0; padding-left:20px;">
            <?php foreach (array_slice($importResults['errors'], 0, 10) as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Search & Filter -->
<div class="dp-card" style="margin-bottom:15px;">
    <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <input type="hidden" name="p" value="students">
        <div style="flex:2; min-width:200px;">
            <label class="text-small"><strong>Search</strong></label>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by name..." class="form-control" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
        </div>
        <div style="flex:1; min-width:150px;">
            <label class="text-small"><strong>Cluster</strong></label>
            <select name="class" class="form-control" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
                <option value="">All Clusters</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= e($c) ?>" <?= $filterClass === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">🔍 Filter</button>
        <a href="?p=students" class="btn btn-secondary">Clear</a>
    </form>
</div>

<!-- Quick Add -->
<?php if (hasPermission('students_manage')): ?>
<div class="dp-card" style="margin-bottom:15px;">
    <h2 class="section-title">➕ Add Learner</h2>
    <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <input type="hidden" name="add_student" value="1">
        <div style="flex:2; min-width:180px;">
            <label class="text-small"><strong>Full Name *</strong></label>
            <input type="text" name="full_name" required placeholder="Jane Wanjiku" class="form-control" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
        </div>
        <div style="flex:1; min-width:150px;">
            <label class="text-small"><strong>Project</strong></label>
            <input type="text" name="school_name" placeholder="Project name" class="form-control" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
        </div>
        <div style="flex:1; min-width:120px;">
            <label class="text-small"><strong>Cluster</strong></label>
            <input type="text" name="class_name" placeholder="Form 2" class="form-control" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
        </div>
        <button type="submit" class="btn btn-primary">➕ Add</button>
    </form>
</div>
<?php endif; ?>

<!-- Learner List -->
<div class="dp-card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
        <h2 class="section-title" style="margin:0;">📋 Learner Register</h2>
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <!-- Export CSV -->
            <a href="?p=students&amp;action=export_csv" class="btn btn-primary" style="font-size:0.85rem; padding:6px 14px;">
                📥 Export CSV
            </a>
            <!-- Import CSV toggle -->
            <a href="?p=students&amp;import_form=1" class="btn btn-secondary" style="font-size:0.85rem; padding:6px 14px;">
                📤 Import CSV
            </a>
        </div>
    </div>

    <!-- Import Form (inline, shown when ?import_form=1) -->
    <?php if ($showImportForm): ?>
    <div style="background:#f8fafc; border:2px dashed #cbd5e1; border-radius:8px; padding:16px; margin-bottom:16px;">
        <h3 style="margin:0 0 10px; font-size:0.95rem; color:#374151;">📤 Import Learners from CSV</h3>
        <p style="font-size:0.82rem; color:#6b7280; margin:0 0 12px;">
            CSV must have columns: <strong>full_name</strong>, <strong>school_name</strong> (or "project"), <strong>class_name</strong> (or "cluster"). First row treated as header.
            Duplicates (same full_name + class_name) are skipped.
        </p>
        <form method="POST" action="?p=students" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="action" value="import_csv">
            <input type="file" name="csv_file" accept=".csv,text/csv" required
                   style="border:2px solid var(--border); border-radius:8px; padding:8px; background:#fff; flex:1; min-width:200px;">
            <button type="submit" class="btn btn-primary">⬆️ Upload &amp; Import</button>
            <a href="?p=students" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
    <?php endif; ?>

    <?php if (count($students) === 0): ?>
        <div class="alert alert-success" style="background:#f0fdf4; color:#166534; border-left:4px solid #16a34a; padding:12px 16px; border-radius:6px;">
            No learners found. Learners register through the main site or can be added above.
        </div>
    <?php else: ?>

    <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead>
                <tr style="background:var(--light); text-align:left;">
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">#</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Name</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Project</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Cluster</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Quizzes</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Avg %</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Essays</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Registered</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Last Seen</th>
                    <?php if (hasPermission('students_manage')): ?>
                        <th style="padding:10px 12px; border-bottom:2px solid var(--border); min-width:200px;">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $i => $s): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:8px 12px;"><?= $i + 1 ?></td>
                    <td style="padding:8px 12px; font-weight:600;"><?= e($s['full_name']) ?></td>
                    <td style="padding:8px 12px;"><?= e($s['school_name'] ?? '—') ?></td>
                    <td style="padding:8px 12px;"><?= e($s['class_name'] ?? '—') ?></td>
                    <td style="padding:8px 12px;"><?= $s['quiz_count'] ?? 0 ?></td>
                    <td style="padding:8px 12px;">
                        <?php $avg = $s['avg_score'] ?? 0; ?>
                        <span style="color:<?= $avg >= 50 ? 'var(--success)' : ($avg > 0 ? 'var(--danger)' : 'var(--dark-soft)') ?>; font-weight:600;">
                            <?= $avg > 0 ? $avg . '%' : '—' ?>
                        </span>
                    </td>
                    <td style="padding:8px 12px;"><?= $s['essay_count'] ?? 0 ?></td>
                    <td style="padding:8px 12px; font-size:0.8rem;"><?= $s['registered_at'] ? date('M j', strtotime($s['registered_at'])) : '—' ?></td>
                    <td style="padding:8px 12px; font-size:0.8rem;"><?= $s['last_seen'] ? date('M j, g:i A', strtotime($s['last_seen'])) : '—' ?></td>
                    <?php if (hasPermission('students_manage')): ?>
                    <td style="padding:8px 12px; white-space:nowrap;">
                        <!-- Edit -->
                        <a href="?p=students&action=edit&id=<?= $s['id'] ?>"
                           class="btn btn-secondary"
                           style="padding:3px 8px; font-size:0.75rem; display:inline-block;">✏️ Edit</a>

                        <!-- Reset Password -->
                        <form method="POST" style="display:inline; margin-left:4px;">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                            <button type="submit" class="btn btn-secondary"
                                    style="padding:3px 8px; font-size:0.75rem; background:#f59e0b; border-color:#f59e0b; color:#fff;"
                                    onclick="return confirm('Reset password for <?= addslashes(htmlspecialchars($s['full_name'], ENT_QUOTES)) ?>? They will be able to log in without a password.')">
                                🔑 Reset Pass
                            </button>
                        </form>

                        <!-- Soft Delete -->
                        <form method="POST" style="display:inline; margin-left:4px;">
                            <input type="hidden" name="action" value="soft_delete">
                            <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                            <button type="submit" class="btn btn-secondary"
                                    style="padding:3px 8px; font-size:0.75rem; background:#dc2626; border-color:#dc2626; color:#fff;"
                                    onclick="return confirm('Move <?= addslashes(htmlspecialchars($s['full_name'], ENT_QUOTES)) ?> to recycle bin?')">
                                🗑 Delete
                            </button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
// Edit form
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && hasPermission('students_manage')):
    $editId = intval($_GET['id']);
    $editStudent = db()->querySingle("SELECT * FROM students WHERE id = $editId", true);
    if ($editStudent):
?>
<div class="dp-card" id="edit-form" style="margin-top:16px;">
    <h2 class="section-title">✏️ Edit Learner</h2>
    <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <input type="hidden" name="edit_student" value="1">
        <input type="hidden" name="student_id" value="<?= $editId ?>">
        <div style="flex:2; min-width:180px;">
            <label class="text-small"><strong>Full Name</strong></label>
            <input type="text" name="full_name" value="<?= e($editStudent['full_name']) ?>" required
                   class="form-control" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
        </div>
        <div style="flex:1; min-width:150px;">
            <label class="text-small"><strong>Project</strong></label>
            <input type="text" name="school_name" value="<?= e($editStudent['school_name'] ?? '') ?>"
                   class="form-control" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
        </div>
        <div style="flex:1; min-width:120px;">
            <label class="text-small"><strong>Cluster</strong></label>
            <input type="text" name="class_name" value="<?= e($editStudent['class_name'] ?? '') ?>"
                   class="form-control" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
        </div>
        <button type="submit" class="btn btn-primary">💾 Save</button>
        <a href="?p=students" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<?php endif; endif; ?>
