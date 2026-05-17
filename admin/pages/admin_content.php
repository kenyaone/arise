<?php
$action = $_GET['action'] ?? 'list';

// ── Handle Add Video to Slide 6 ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_slide6_video'])) {
    $lessonId = intval($_POST['lesson_id'] ?? 0);
    $row = db()->querySingle("SELECT file_path FROM lessons WHERE id=$lessonId AND lesson_type='interactive'", true);
    if ($row && !empty($row['file_path']) && isset($_FILES['slide6_video']) && $_FILES['slide6_video']['error'] === UPLOAD_ERR_OK) {
        $vfile = $_FILES['slide6_video'];
        $vname = time() . '_' . preg_replace('/[^a-z0-9._-]/i', '', $vfile['name']);
        $vdest = __DIR__ . '/../../data/uploads/videos/' . $vname;
        if (!is_dir(dirname($vdest))) mkdir(dirname($vdest), 0775, true);
        if (move_uploaded_file($vfile['tmp_name'], $vdest)) {
            $videoUrl = '/arise/uploads/videos/' . $vname;
            $htmlFile = __DIR__ . '/../../data/uploads/' . $row['file_path'];
            if (file_exists($htmlFile)) {
                $html = file_get_contents($htmlFile);
                // Replace video placeholder src or inject src
                $newSource = '<source src="' . $videoUrl . '" type="video/mp4">';
                if (strpos($html, 'id="lessonVideo"') !== false) {
                    // Update existing video element
                    $html = preg_replace(
                        '/(<video[^>]*id="lessonVideo"[^>]*>).*?(<\/video>)/s',
                        '$1' . "
  " . $newSource . "
" . '$2',
                        $html
                    );
                    // Make video visible by default
                    $html = preg_replace('/id="lessonVideo"[^>]*style="[^"]*display:none[^"]*"/', 'id="lessonVideo" controls preload="metadata" style="width:100%;max-height:340px;background:#000;"', $html);
                }
                file_put_contents($htmlFile, $html);
                $success = "✅ Video added to slide 6 of this lesson!";
            }
        }
    }
}

$msg = '';
$uploadDir = __DIR__ . '/../../data/uploads/';
if (!is_dir($uploadDir . 'lessons')) mkdir($uploadDir . 'lessons', 0775, true);

// Handle creating a new module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_module']) && hasPermission('content_manage')) {
    $title = trim($_POST['title'] ?? '');
    $icon = trim($_POST['icon'] ?? '📚');
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $title));
    if ($title) {
        $maxSort = db()->querySingle("SELECT MAX(sort_order) FROM modules") ?? 0;
        $stmt = db()->prepare('INSERT INTO modules (title, slug, description, icon, sort_order, created_by) VALUES (:title, :slug, :desc, :icon, :sort, :by)');
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':desc', trim($_POST['description'] ?? ''));
        $stmt->bindValue(':icon', $icon);
        $stmt->bindValue(':sort', $maxSort + 1);
        $stmt->bindValue(':by', $_SESSION['arise_admin_id'] ?? 0);
        $newMid = db()->lastInsertRowID();
        ariseAuditLog('add_module', 'module', $newMid, "Created module: $title | slug: $slug");
        $msg = "✅ Module '$title' created!";
    }
}

// Handle editing a module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_module']) && hasPermission('content_manage')) {
    $id = intval($_POST['module_id']);
    $cw = trim($_POST['content_warning'] ?? '');
    $sid = trim($_POST['school_id'] ?? '');
    $stmt = db()->prepare('UPDATE modules SET title=:title, description=:desc, icon=:icon, is_active=:active, content_warning=:cw, school_id=:sid WHERE id=:id');
    $stmt->bindValue(':title', trim($_POST['title']));
    $stmt->bindValue(':desc', trim($_POST['description'] ?? ''));
    $stmt->bindValue(':icon', trim($_POST['icon'] ?? '📚'));
    $stmt->bindValue(':active', isset($_POST['is_active']) ? 1 : 0);
    $stmt->bindValue(':cw', $cw ?: null);
    $stmt->bindValue(':sid', $sid ?: null);
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    ariseAuditLog('edit_module', 'module', $id, "title=" . trim($_POST['title']));
    $msg = '✅ Module updated!';
}

// Handle lesson edit (text lessons only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_lesson']) && hasPermission('content_manage')) {
    $lid = intval($_POST['lesson_id']);
    $newTitle = trim($_POST['title'] ?? '');
    $newContent = $_POST['content'] ?? '';
    // Snapshot current version before overwriting
    $old = db()->querySingle("SELECT title, content FROM lessons WHERE id=$lid", true);
    if ($old) {
        $admin = $_SESSION['arise_admin_name'] ?? 'admin';
        $stmt = db()->prepare('INSERT INTO lesson_versions (lesson_id, title, content, saved_by, saved_at) VALUES (:lid,:t,:c,:by,CURRENT_TIMESTAMP)');
        $stmt->bindValue(':lid', $lid);
        $stmt->bindValue(':t', $old['title']);
        $stmt->bindValue(':c', $old['content']);
        $stmt->bindValue(':by', $admin);
        $stmt->execute();
    }
    $stmt2 = db()->prepare('UPDATE lessons SET title=:t, content=:c WHERE id=:id');
    $stmt2->bindValue(':t', $newTitle);
    $stmt2->bindValue(':c', $newContent);
    $stmt2->bindValue(':id', $lid);
    $stmt2->execute();
    ariseAuditLog('edit_lesson', 'lesson', $lid, "title=$newTitle");
    $msg = '✅ Lesson updated! Previous version saved.';
}

// Toggle lesson active/inactive
if ($action === 'toggle_lesson' && hasPermission('content_manage')) {
    $lid = intval($_GET['lesson'] ?? 0);
    $modId = intval($_GET['mod'] ?? 0);
    $cur = db()->querySingle("SELECT is_active FROM lessons WHERE id=$lid");
    db()->exec("UPDATE lessons SET is_active=" . ($cur ? 0 : 1) . " WHERE id=$lid");
    $ltitle = db()->querySingle("SELECT title FROM lessons WHERE id=$lid") ?? "ID $lid";
    ariseAuditLog($cur ? 'deactivate_lesson' : 'activate_lesson', 'lesson', $lid, ($cur ? 'Deactivated' : 'Activated') . " lesson: $ltitle");
    header("Location: ?p=content&action=edit_module&mod=$modId"); exit;
}

if (isset($_GET['deactivate_module']) && hasPermission('content_manage')) {
    $dmid = intval($_GET['deactivate_module']);
    $dmtitle = db()->querySingle("SELECT title FROM modules WHERE id=$dmid") ?? "ID $dmid";
    db()->exec("UPDATE modules SET is_active = 0 WHERE id = $dmid");
    ariseAuditLog('deactivate_module', 'module', $dmid, "Deactivated module: $dmtitle");
    $msg = '✅ Module deactivated.';
}

// Handle adding a lesson (text, video, or PDF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_lesson'])) {
    $title = trim($_POST['title']);
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $title)) . '-' . time();
    $lessonType = $_POST['lesson_type'] ?? 'text';
    $filePath = null;
    $fileName = null;
    $fileSize = null;

    // Handle file upload
    if (in_array($lessonType,['video','pdf','interactive']) && isset($_FILES['lesson_file']) && $_FILES['lesson_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['lesson_file'];
        $allowedVideo = ['video/mp4', 'video/webm', 'video/ogg', 'video/avi', 'video/x-msvideo'];
        $allowedHtml = ['text/html', 'application/octet-stream'];
        $allowedPdf = ['application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $valid = false;
        if ($lessonType === 'video' && in_array($mime, $allowedVideo)) $valid = true;
        if ($lessonType === 'pdf' && in_array($mime, $allowedPdf)) $valid = true;
        if ($lessonType === 'interactive' && (str_ends_with($file['name'],'.html') || str_ends_with($file['name'],'.htm'))) $valid = true;

        if ($valid) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $safeName = preg_replace('/[^a-z0-9_.-]/', '', strtolower($file['name']));
            $destName = time() . '_' . $safeName;
            $destPath = $uploadDir . 'lessons/' . $destName;
            move_uploaded_file($file['tmp_name'], $destPath);
            chmod($destPath, 0664);
            $filePath = 'lessons/' . $destName;
            $fileName = $file['name'];
            $fileSize = round($file['size'] / 1024, 1);
        } else {
            $msg = '❌ Invalid file type. Videos: MP4, WebM, OGG. Documents: PDF.';
        }
    }

    if (!$msg || str_starts_with($msg, '✅')) {
        $stmt = db()->prepare('INSERT INTO lessons (module_id, title, slug, content, lesson_type, file_path, file_name, file_size_kb, sort_order) VALUES (:mod, :title, :slug, :content, :type, :path, :fname, :fsize, :sort)');
        $stmt->bindValue(':mod', intval($_POST['module_id']));
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':slug', $slug);
        $stmt->bindValue(':content', $_POST['content'] ?? '');
        $stmt->bindValue(':type', $lessonType);
        $stmt->bindValue(':path', $filePath);
        $stmt->bindValue(':fname', $fileName);
        $stmt->bindValue(':fsize', $fileSize);
        $stmt->bindValue(':sort', intval($_POST['sort_order'] ?? 0));
        $stmt->execute();
        $newLid = db()->lastInsertRowID();
        ariseAuditLog('add_lesson', 'lesson', $newLid, "Added lesson: $title | type: $lessonType | module_id: " . intval($_POST['module_id']));
        $msg = '✅ Lesson added!';
    }
}

// Handle adding a quiz question (MCQ or Essay)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_quiz'])) {
    $qType = $_POST['question_type'] ?? 'mcq';
    $stmt = db()->prepare('INSERT INTO quiz_questions (module_id, question_type, question, option_a, option_b, option_c, option_d, correct_option, explanation, essay_hint, min_words, max_marks, sort_order) VALUES (:mod, :type, :q, :a, :b, :c, :d, :correct, :exp, :hint, :minw, :marks, :sort)');
    $stmt->bindValue(':mod', intval($_POST['module_id']));
    $stmt->bindValue(':type', $qType);
    $stmt->bindValue(':q', trim($_POST['question']));
    $stmt->bindValue(':a', $qType === 'mcq' ? trim($_POST['option_a'] ?? '') : null);
    $stmt->bindValue(':b', $qType === 'mcq' ? trim($_POST['option_b'] ?? '') : null);
    $stmt->bindValue(':c', $qType === 'mcq' ? trim($_POST['option_c'] ?? '') : null);
    $stmt->bindValue(':d', $qType === 'mcq' ? trim($_POST['option_d'] ?? '') : null);
    $stmt->bindValue(':correct', $qType === 'mcq' ? ($_POST['correct_option'] ?? '') : null);
    $stmt->bindValue(':exp', trim($_POST['explanation'] ?? ''));
    $stmt->bindValue(':hint', $qType === 'essay' ? trim($_POST['essay_hint'] ?? '') : null);
    $stmt->bindValue(':minw', intval($_POST['min_words'] ?? 0));
    $stmt->bindValue(':marks', intval($_POST['max_marks'] ?? ($qType === 'essay' ? 5 : 1)));
    $stmt->bindValue(':sort', intval($_POST['sort_order'] ?? 0));
    $stmt->execute();
    $msg = $qType === 'essay' ? '✅ Essay question added!' : '✅ MCQ question added!';
}

// Handle CSV/Text question import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_questions'])) {
    $modId = intval($_POST['module_id']);
    $imported = 0;
    $errors = 0;

    // Handle file upload
    if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['import_file']['tmp_name']);
    } else {
        $content = $_POST['import_text'] ?? '';
    }

    if (!empty($content)) {
        $lines = preg_split('/\r?\n/', trim($content));
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#') || str_starts_with($line, '//')) continue;

            // CSV format: question,optionA,optionB,optionC,optionD,correct(A/B/C/D),explanation
            $parts = str_getcsv($line);
            if (count($parts) >= 6) {
                try {
                    $stmt = db()->prepare('INSERT INTO quiz_questions (module_id, question_type, question, option_a, option_b, option_c, option_d, correct_option, explanation) VALUES (:mod, :type, :q, :a, :b, :c, :d, :correct, :exp)');
                    $stmt->bindValue(':mod', $modId);
                    $stmt->bindValue(':type', 'mcq');
                    $stmt->bindValue(':q', trim($parts[0]));
                    $stmt->bindValue(':a', trim($parts[1]));
                    $stmt->bindValue(':b', trim($parts[2]));
                    $stmt->bindValue(':c', trim($parts[3]));
                    $stmt->bindValue(':d', trim($parts[4]));
                    $stmt->bindValue(':correct', strtoupper(trim($parts[5])));
                    $stmt->bindValue(':exp', trim($parts[6] ?? ''));
                    $stmt->execute();
                    $imported++;
                } catch (Exception $e) {
                    $errors++;
                }
            } else {
                $errors++;
            }
        }
        $msg = "✅ Imported $imported questions." . ($errors > 0 ? " ($errors skipped due to errors)" : '');
    } else {
        $msg = '❌ No data to import.';
    }
}
?>

<h1 class="page-title">📚 Content Management</h1>
<?php if ($msg): ?><div class="alert <?= str_starts_with($msg, '✅') ? 'alert-success' : 'alert-danger' ?>"><?= $msg ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>

<?php if (hasPermission('content_manage')): ?>
<div class="dp-card mb-2">
    <h2 class="section-title">➕ Create New Module (Lesson)</h2>
    <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <input type="hidden" name="add_module" value="1">
        <div style="width:60px;">
            <label class="text-small"><strong>Icon</strong></label>
            <input type="text" name="icon" value="📚" maxlength="5" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px; text-align:center; font-size:1.3rem;">
        </div>
        <div style="flex:2; min-width:200px;">
            <label class="text-small"><strong>Module Title *</strong></label>
            <input type="text" name="title" required placeholder="e.g. Nutrition & Health" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
        </div>
        <div style="flex:3; min-width:200px;">
            <label class="text-small"><strong>Description</strong></label>
            <input type="text" name="description" placeholder="Brief description" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
        </div>
        <button type="submit" class="btn btn-primary">📚 Create Module</button>
    </form>
</div>
<?php endif; ?>

<h2 class="section-title">Modules Overview</h2>
<div class="dp-card mb-2">
    <?php 
    $allModulesResult = db()->query("SELECT * FROM modules ORDER BY sort_order");
    while ($m = $allModulesResult->fetchArray(SQLITE3_ASSOC)):
        $lc = db()->querySingle("SELECT COUNT(*) FROM lessons WHERE module_id = {$m['id']}");
        $mcqc = db()->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE module_id = {$m['id']} AND (question_type = 'mcq' OR question_type IS NULL)");
        $essc = db()->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE module_id = {$m['id']} AND question_type = 'essay'");
    ?>
        <div class="dp-log-item" style="flex-wrap:wrap; gap:8px; <?= !$m['is_active'] ? 'opacity:0.5;' : '' ?>">
            <span>
                <?= $m['icon'] ?> <strong><?= e($m['title']) ?></strong> — <?= $lc ?> lessons, <?= $mcqc ?> MCQ, <?= $essc ?> essay
                <?php if (!$m['is_active']): ?><span style="color:var(--danger); font-size:0.8rem;">(inactive)</span><?php endif; ?>
            </span>
            <div style="display:flex; gap:5px; flex-wrap:wrap;">
                <?php if (hasPermission('content_manage')): ?>
                    <a href="?p=content&action=edit_module&mod=<?= $m['id'] ?>" class="btn btn-sm btn-secondary" style="padding:3px 8px;">✏️</a>
                    <a href="?p=content&action=add_lesson&mod=<?= $m['id'] ?>" class="btn btn-sm btn-primary">+ Lesson</a>
                    <a href="?p=content&action=add_quiz&mod=<?= $m['id'] ?>" class="btn btn-sm btn-secondary">+ MCQ</a>
                    <a href="?p=content&action=add_essay&mod=<?= $m['id'] ?>" class="btn btn-sm btn-secondary" style="background:#F0EEFF; color:var(--primary);">+ Essay</a>
                    <a href="?p=content&action=import_questions&mod=<?= $m['id'] ?>" class="btn btn-sm btn-secondary" style="background:#FFF8E1; color:#7A6200;">📥 Import</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endwhile; ?>
</div>
<?php endif; ?>

<?php if ($action === 'edit_module' && hasPermission('content_manage')):
    $modId = intval($_GET['mod'] ?? 0);
    $editMod = db()->querySingle("SELECT * FROM modules WHERE id = $modId", true);
    if ($editMod):
?>
<div class="dp-card">
    <h2 class="section-title">✏️ Edit Module: <?= e($editMod['title']) ?></h2>
    <form method="POST">
        <input type="hidden" name="edit_module" value="1">
        <input type="hidden" name="module_id" value="<?= $modId ?>">
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
            <div style="width:60px;">
                <label class="text-small"><strong>Icon</strong></label>
                <input type="text" name="icon" value="<?= e($editMod['icon']) ?>" maxlength="5" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px; text-align:center; font-size:1.3rem;">
            </div>
            <div style="flex:2; min-width:200px;">
                <label class="text-small"><strong>Title</strong></label>
                <input type="text" name="title" value="<?= e($editMod['title']) ?>" required style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <div style="flex:3; min-width:200px;">
                <label class="text-small"><strong>Description</strong></label>
                <input type="text" name="description" value="<?= e($editMod['description'] ?? '') ?>" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
            </div>
        </div>
        <label style="display:flex; align-items:center; gap:8px; margin-bottom:15px; cursor:pointer;">
            <input type="checkbox" name="is_active" <?= $editMod['is_active'] ? 'checked' : '' ?>> <strong>Active</strong> (visible to students)
        </label>
        <div style="margin-bottom:12px;">
            <label class="text-small"><strong>⚠️ Content Warning</strong> <span style="font-weight:400;color:#6b7280;">(leave blank for none)</span></label>
            <textarea name="content_warning" rows="2" placeholder="e.g. This module contains discussions of sexual abuse and violence. Reader discretion is advised." style="width:100%;padding:10px;border:2px solid #fbbf24;border-radius:8px;font-size:.9rem;resize:vertical;"><?= htmlspecialchars($editMod['content_warning'] ?? '') ?></textarea>
        </div>
        <div style="margin-bottom:15px;">
            <label class="text-small"><strong>🏫 School Tag</strong> <span style="font-weight:400;color:#6b7280;">(optional — restrict to a school)</span></label>
            <input type="text" name="school_id" value="<?= htmlspecialchars($editMod['school_id'] ?? '') ?>" placeholder="e.g. school_nairobi_001" style="width:100%;padding:10px;border:2px solid var(--border);border-radius:8px;">
        </div>
        <button type="submit" class="btn btn-primary">💾 Save Changes</button>
        <a href="?p=content" class="btn btn-secondary">Cancel</a>
    </form>
</div>

<?php
$interactiveLessons2 = [];
$lq2 = db()->query("SELECT id, title, file_path FROM lessons WHERE module_id={$editMod['id']} AND lesson_type='interactive' AND is_active=1");
while($lr2 = $lq2->fetchArray(SQLITE3_ASSOC)) $interactiveLessons2[] = $lr2;
if ($interactiveLessons2):
?>
<div style="margin-top:16px;padding:16px;background:#f0f8e8;border:2px solid #3D6318;border-radius:10px;">
    <h3 style="font-size:.95rem;font-weight:800;margin-bottom:12px;color:#3D6318;">🎬 Add Video to Slide 6</h3>
    <?php if(isset($success)) echo '<div class="alert alert-success">'.$success.'</div>'; ?>
    <?php foreach($interactiveLessons2 as $il2): ?>
    <form method="POST" enctype="multipart/form-data" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;padding:10px;background:#fff;border-radius:8px;border:1px solid #ccc;">
        <input type="hidden" name="add_slide6_video" value="1">
        <input type="hidden" name="lesson_id" value="<?= $il2['id'] ?>">
        <span style="font-weight:700;font-size:.85rem;">🎮 <?= e($il2['title']) ?></span>
        <input type="file" name="slide6_video" accept="video/mp4,video/webm" style="flex:1;">
        <button type="submit" style="background:#3D6318;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-weight:700;cursor:pointer;">Upload</button>
    </form>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// List all lessons with edit links
$allLessons = [];
$lqAll = db()->query("SELECT id, title, lesson_type, sort_order, is_active FROM lessons WHERE module_id={$editMod['id']} ORDER BY sort_order, id");
while ($ll = $lqAll->fetchArray(SQLITE3_ASSOC)) $allLessons[] = $ll;
if ($allLessons):
?>
<div style="margin-top:20px;padding:16px;background:#f9fafb;border:1px solid var(--border);border-radius:10px;">
    <h3 style="font-size:.95rem;font-weight:800;margin-bottom:12px;">📚 Lessons in this Module</h3>
    <?php foreach ($allLessons as $ll): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:6px;">
        <span style="font-size:.88rem;<?= !$ll['is_active'] ? 'opacity:.5;' : '' ?>">
            <?php $ti = ['text'=>'📝','video'=>'🎬','pdf'=>'📄','interactive'=>'🎮'][$ll['lesson_type']] ?? '📄'; echo $ti; ?>
            <strong><?= htmlspecialchars($ll['title']) ?></strong>
            <span style="color:#9ca3af;font-size:.78rem;"> · #<?= $ll['sort_order'] ?></span>
        </span>
        <div style="display:flex;gap:6px;">
            <?php if ($ll['lesson_type'] === 'text'): ?>
                <a href="?p=content&action=edit_lesson&lesson=<?= $ll['id'] ?>" class="btn btn-sm btn-secondary" style="padding:3px 8px;font-size:.8rem;">✏️ Edit</a>
            <?php endif; ?>
            <a href="?p=content&action=toggle_lesson&lesson=<?= $ll['id'] ?>&mod=<?= $editMod['id'] ?>" class="btn btn-sm" style="padding:3px 8px;font-size:.8rem;background:<?= $ll['is_active'] ? '#fee2e2;color:#991b1b' : '#ecfdf5;color:#065f46' ?>;"><?= $ll['is_active'] ? 'Hide' : 'Show' ?></a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; endif; ?>

<?php if ($action === 'add_lesson'): $modId = intval($_GET['mod'] ?? 0); $modTitle = db()->querySingle("SELECT title FROM modules WHERE id = $modId"); ?>
<div class="dp-card">
    <h2 class="section-title">📖 Add Lesson to: <?= e($modTitle) ?></h2>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="add_lesson" value="1">
        <input type="hidden" name="module_id" value="<?= $modId ?>">

        <label class="text-small"><strong>Lesson Title *</strong></label>
        <input type="text" name="title" required placeholder="e.g. Understanding Puberty"
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:12px;">

        <label class="text-small"><strong>Lesson Type</strong></label>
        <div style="display:flex; gap:10px; margin-bottom:15px; flex-wrap:wrap;">
            <label style="display:flex; align-items:center; gap:6px; padding:10px 18px; background:var(--light); border:2px solid var(--border); border-radius:8px; cursor:pointer;">
                <input type="radio" name="lesson_type" value="text" checked onchange="toggleLessonType(this.value)"> 📝 Text / HTML
            </label>
            <label style="display:flex; align-items:center; gap:6px; padding:10px 18px; background:var(--light); border:2px solid var(--border); border-radius:8px; cursor:pointer;">
                <input type="radio" name="lesson_type" value="video" onchange="toggleLessonType(this.value)"> 🎬 Video
            </label>
            <label style="display:flex; align-items:center; gap:6px; padding:10px 18px; background:var(--light); border:2px solid var(--border); border-radius:8px; cursor:pointer;">
                <input type="radio" name="lesson_type" value="pdf" onchange="toggleLessonType(this.value)"> 📄 PDF Document
            </label>
            <label style="display:flex; align-items:center; gap:6px; padding:10px 18px; background:#E6FFF5; border:2px solid #2e7d32; border-radius:8px; cursor:pointer;">
                <input type="radio" name="lesson_type" value="interactive" onchange="toggleLessonType(this.value)"> 🎮 Interactive HTML
            </label>
        </div>

        <div id="text-section">
            <label class="text-small"><strong>Content (HTML allowed)</strong></label>
            <textarea name="content" rows="12" placeholder="Write lesson content here. HTML tags allowed: &lt;h2&gt;, &lt;p&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;strong&gt;, &lt;img&gt; etc."
                style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:12px; font-family:monospace; font-size:0.9rem;"></textarea>
        </div>

        <div id="file-section" style="display:none;">
            <label class="text-small"><strong>Upload File</strong></label>
            <input type="file" name="lesson_file" accept=".mp4,.webm,.ogg,.avi,.pdf,.html,.htm"
                style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:8px;">
            <p class="text-muted text-small" id="file-hint">Videos: MP4/WebM/OGG. PDFs: any PDF. Interactive: .html files.</p>
            
            <label class="text-small" style="margin-top:10px;"><strong>Description / Notes (optional)</strong></label>
            <textarea name="content" rows="4" placeholder="Brief description of this video/document..."
                style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:12px;"></textarea>
        </div>

        <label class="text-small"><strong>Sort Order</strong></label>
        <input type="number" name="sort_order" value="0" style="width:100px; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:20px;">

        <br><button type="submit" class="btn btn-primary">💾 Save Lesson</button>
        <a href="?p=content" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<script>
function toggleLessonType(type) {
    document.getElementById('text-section').style.display = type === 'text' ? '' : 'none';
    document.getElementById('file-section').style.display = type !== 'text' ? '' : 'none';
    if(type==='interactive') document.getElementById('file-hint').textContent='Upload the .html interactive lesson file.';
    if(type==='video') document.getElementById('file-hint').textContent='Videos: MP4, WebM, OGG (recommended: MP4).';
    if(type==='pdf') document.getElementById('file-hint').textContent='Any PDF file.';
}
</script>
<?php endif; ?>

<?php if ($action === 'edit_lesson' && hasPermission('content_manage')):
    $lid = intval($_GET['lesson'] ?? 0);
    $editLesson = db()->querySingle("SELECT l.*, m.title AS mod_title FROM lessons l JOIN modules m ON l.module_id=m.id WHERE l.id=$lid", true);
    if ($editLesson):
        $versions = [];
        $vres = db()->query("SELECT id, saved_by, saved_at FROM lesson_versions WHERE lesson_id=$lid ORDER BY saved_at DESC LIMIT 10");
        while ($vr = $vres->fetchArray(SQLITE3_ASSOC)) $versions[] = $vr;
?>
<div class="dp-card">
    <h2 class="section-title">✏️ Edit Lesson: <?= htmlspecialchars($editLesson['title']) ?></h2>
    <p style="color:#6b7280;font-size:.88rem;margin-bottom:16px;">Module: <strong><?= htmlspecialchars($editLesson['mod_title']) ?></strong></p>
    <form method="POST">
        <input type="hidden" name="edit_lesson" value="1">
        <input type="hidden" name="lesson_id" value="<?= $lid ?>">
        <div class="form-group" style="margin-bottom:12px;">
            <label class="text-small"><strong>Title</strong></label>
            <input type="text" name="title" value="<?= htmlspecialchars($editLesson['title']) ?>" required style="width:100%;padding:10px;border:2px solid var(--border);border-radius:8px;">
        </div>
        <div class="form-group">
            <label class="text-small"><strong>Content (HTML allowed)</strong></label>
            <textarea name="content" rows="16" style="width:100%;padding:12px;border:2px solid var(--border);border-radius:8px;font-family:monospace;font-size:.88rem;resize:vertical;"><?= htmlspecialchars($editLesson['content'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">💾 Save &amp; Snapshot Version</button>
        <a href="?p=content&action=edit_module&mod=<?= $editLesson['module_id'] ?>" class="btn btn-secondary">Cancel</a>
    </form>
    <?php if ($versions): ?>
    <details style="margin-top:20px;">
        <summary style="cursor:pointer;font-weight:700;color:var(--primary);">🕐 Version History (<?= count($versions) ?>)</summary>
        <table class="arise-table" style="margin-top:10px;">
            <thead><tr><th>Saved At</th><th>By</th><th>Restore</th></tr></thead>
            <tbody>
            <?php foreach ($versions as $v): ?>
                <tr>
                    <td style="font-size:.85rem;"><?= date('M j Y H:i', strtotime($v['saved_at'])) ?></td>
                    <td><?= htmlspecialchars($v['saved_by']) ?></td>
                    <td><a href="?p=content&action=restore_version&ver=<?= $v['id'] ?>&lesson=<?= $lid ?>" class="btn btn-sm btn-secondary" onclick="return confirm('Restore this version? Current content will be snapshotted first.')">Restore</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>
</div>
<?php endif; endif; ?>

<?php if ($action === 'restore_version' && hasPermission('content_manage')):
    $vid = intval($_GET['ver'] ?? 0);
    $lid = intval($_GET['lesson'] ?? 0);
    $ver = db()->querySingle("SELECT * FROM lesson_versions WHERE id=$vid AND lesson_id=$lid", true);
    if ($ver) {
        $cur = db()->querySingle("SELECT title, content FROM lessons WHERE id=$lid", true);
        if ($cur) {
            $admin = $_SESSION['arise_admin_name'] ?? 'admin';
            $st = db()->prepare('INSERT INTO lesson_versions (lesson_id,title,content,saved_by,saved_at) VALUES (:lid,:t,:c,:by,CURRENT_TIMESTAMP)');
            $st->bindValue(':lid', $lid); $st->bindValue(':t', $cur['title']);
            $st->bindValue(':c', $cur['content']); $st->bindValue(':by', $admin . ' (pre-restore)');
            $st->execute();
        }
        $st2 = db()->prepare('UPDATE lessons SET title=:t, content=:c WHERE id=:id');
        $st2->bindValue(':t', $ver['title']); $st2->bindValue(':c', $ver['content']); $st2->bindValue(':id', $lid);
        $st2->execute();
        ariseAuditLog('restore_version', 'lesson', $lid, "version_id=$vid");
        $msg = '✅ Version restored.';
    }
    header("Location: ?p=content&action=edit_lesson&lesson=$lid"); exit;
endif; ?>

<?php if ($action === 'add_quiz'): $modId = intval($_GET['mod'] ?? 0); ?>
<div class="dp-card">
    <h2 class="section-title">📝 Add MCQ Question</h2>
    <form method="POST">
        <input type="hidden" name="add_quiz" value="1">
        <input type="hidden" name="question_type" value="mcq">
        <input type="hidden" name="module_id" value="<?= $modId ?>">

        <label class="text-small"><strong>Question *</strong></label>
        <textarea name="question" required rows="3" style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:12px;"></textarea>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
            <div><label class="text-small"><strong>A</strong></label><input type="text" name="option_a" required style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;"></div>
            <div><label class="text-small"><strong>B</strong></label><input type="text" name="option_b" required style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;"></div>
            <div><label class="text-small"><strong>C</strong></label><input type="text" name="option_c" required style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;"></div>
            <div><label class="text-small"><strong>D</strong></label><input type="text" name="option_d" required style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;"></div>
        </div>

        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <div><label class="text-small"><strong>Correct Answer</strong></label>
                <select name="correct_option" required style="padding:10px; border:2px solid var(--border); border-radius:8px;"><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option></select>
            </div>
            <div><label class="text-small"><strong>Marks</strong></label><input type="number" name="max_marks" value="1" min="1" style="width:80px; padding:10px; border:2px solid var(--border); border-radius:8px;"></div>
            <div><label class="text-small"><strong>Sort</strong></label><input type="number" name="sort_order" value="0" style="width:80px; padding:10px; border:2px solid var(--border); border-radius:8px;"></div>
        </div>

        <label class="text-small"><strong>Explanation (optional)</strong></label>
        <textarea name="explanation" rows="2" style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:20px;"></textarea>

        <button type="submit" class="btn btn-primary">💾 Save Question</button>
        <a href="?p=content" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<?php endif; ?>

<?php if ($action === 'add_essay'): $modId = intval($_GET['mod'] ?? 0); ?>
<div class="dp-card">
    <h2 class="section-title">✍️ Add Essay Question</h2>
    <form method="POST">
        <input type="hidden" name="add_quiz" value="1">
        <input type="hidden" name="question_type" value="essay">
        <input type="hidden" name="module_id" value="<?= $modId ?>">

        <label class="text-small"><strong>Question *</strong></label>
        <textarea name="question" required rows="3" placeholder="e.g. Explain three ways adolescents can protect themselves from HIV/AIDS."
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:12px;"></textarea>

        <label class="text-small"><strong>Hint / Guidance (optional)</strong></label>
        <textarea name="essay_hint" rows="2" placeholder="e.g. Consider prevention methods, testing, and treatment."
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:12px;"></textarea>

        <div style="display:flex; gap:15px; flex-wrap:wrap; margin-bottom:12px;">
            <div style="flex:1; min-width:120px;"><label class="text-small"><strong>Min Words</strong></label><input type="number" name="min_words" value="50" min="0" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;"></div>
            <div style="flex:1; min-width:120px;"><label class="text-small"><strong>Max Marks</strong></label><input type="number" name="max_marks" value="5" min="1" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;"></div>
            <div style="flex:1; min-width:120px;"><label class="text-small"><strong>Sort</strong></label><input type="number" name="sort_order" value="0" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;"></div>
        </div>

        <label class="text-small"><strong>Model Answer (optional)</strong></label>
        <textarea name="explanation" rows="3" style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:20px;"></textarea>

        <button type="submit" class="btn btn-primary">💾 Save Essay Question</button>
        <a href="?p=content" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<?php endif; ?>

<?php if ($action === 'import_questions'): $modId = intval($_GET['mod'] ?? 0); $modTitle = db()->querySingle("SELECT title FROM modules WHERE id = $modId"); ?>
<div class="dp-card">
    <h2 class="section-title">📥 Import Questions to: <?= e($modTitle) ?></h2>
    <p class="text-muted text-small mb-2">Import multiple MCQ questions at once from a CSV file or paste them below.</p>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="import_questions" value="1">
        <input type="hidden" name="module_id" value="<?= $modId ?>">

        <label class="text-small"><strong>Option 1: Upload CSV File</strong></label>
        <input type="file" name="import_file" accept=".csv,.txt"
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:15px;">

        <label class="text-small"><strong>Option 2: Paste Questions Below</strong></label>
        <textarea name="import_text" rows="12" placeholder='CSV format — one question per line:
Question,Option A,Option B,Option C,Option D,Correct (A/B/C/D),Explanation

Example:
"What is the main cause of HIV?","Mosquito bites","Sharing food","Unprotected sex","Handshakes",C,"HIV is transmitted through unprotected sexual contact, blood, and mother to child."
"At what age does puberty typically begin for girls?","5-7 years","8-13 years","15-18 years","20+ years",B,"Most girls begin puberty between ages 8 and 13."'
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-bottom:12px; font-family:monospace; font-size:0.85rem;"></textarea>

        <div style="padding:12px; background:#F0F7FF; border-radius:8px; margin-bottom:15px; font-size:0.85rem; border-left:3px solid var(--primary);">
            <strong>📋 CSV Format:</strong><br>
            <code>Question,Option A,Option B,Option C,Option D,Correct Letter,Explanation</code><br><br>
            <strong>Rules:</strong> One question per line. Correct answer = A, B, C, or D. Use quotes around text with commas. Lines starting with # are skipped.
        </div>

        <button type="submit" class="btn btn-primary">📥 Import Questions</button>
        <a href="?p=content" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<?php endif; ?>

<?php if ($action === 'grade_essays'): ?>
<?php
$modId = intval($_GET['mod'] ?? 0);
$ungradedResult = db()->query("SELECT er.*, qq.question, qq.max_marks, qq.explanation AS model_answer, m.title AS module_title, m.icon, s.full_name AS student_name
    FROM essay_responses er 
    JOIN quiz_questions qq ON er.question_id = qq.id 
    JOIN modules m ON er.module_id = m.id
    LEFT JOIN students s ON er.student_id = s.id
    " . ($modId > 0 ? "WHERE er.module_id = $modId AND" : "WHERE") . " er.is_graded = 0
    ORDER BY er.submitted_at DESC");
$ungraded = [];
while ($row = $ungradedResult->fetchArray(SQLITE3_ASSOC)) { $ungraded[] = $row; }
?>
<h2 class="section-title">✍️ Essay Responses to Grade (<?= count($ungraded) ?>)</h2>
<?php if (count($ungraded) === 0): ?>
    <div class="alert alert-success">All essays have been graded! 🎉</div>
<?php endif; ?>
<?php foreach ($ungraded as $er): ?>
<div class="dp-card">
    <span class="text-small text-muted"><?= $er['icon'] ?> <?= e($er['module_title']) ?> • <?= e($er['student_name'] ?? 'Anonymous') ?> • <?= date('M j, Y g:i A', strtotime($er['submitted_at'])) ?> • <?= $er['word_count'] ?> words</span>
    <p style="font-weight:600; margin:8px 0;">Q: <?= e($er['question']) ?></p>
    <?php if ($er['model_answer']): ?>
        <div class="essay-hint" style="margin-bottom:10px;">📋 <strong>Model Answer:</strong> <?= e($er['model_answer']) ?></div>
    <?php endif; ?>
    <div style="background:var(--light); padding:14px; border-radius:8px; margin-bottom:12px; white-space:pre-wrap; font-size:0.95rem;"><?= e($er['response_text']) ?></div>
    <form method="POST" action="?p=content&action=save_grade">
        <input type="hidden" name="essay_id" value="<?= $er['id'] ?>">
        <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
            <div>
                <label class="text-small"><strong>Grade (/ <?= $er['max_marks'] ?>)</strong></label>
                <input type="number" name="grade" min="0" max="<?= $er['max_marks'] ?>" required style="width:80px; padding:8px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <div style="flex:1; min-width:200px;">
                <label class="text-small"><strong>Feedback</strong></label>
                <input type="text" name="feedback" placeholder="Brief feedback..." style="width:100%; padding:8px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <button type="submit" class="btn btn-success btn-sm">✅ Grade</button>
        </div>
    </form>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($action === 'save_grade' && $_SERVER['REQUEST_METHOD'] === 'POST'):
    $essayId = intval($_POST['essay_id'] ?? 0);
    if ($essayId > 0) {
        $stmt = db()->prepare('UPDATE essay_responses SET is_graded = 1, grade = :grade, feedback = :fb, graded_by = :by, graded_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->bindValue(':grade', intval($_POST['grade'] ?? 0));
        $stmt->bindValue(':fb', trim($_POST['feedback'] ?? ''));
        $stmt->bindValue(':by', $_SESSION['arise_admin_id'] ?? 0);
        $stmt->bindValue(':id', $essayId);
        $stmt->execute();
    }
    header('Location: ?p=content&action=grade_essays');
    exit;
endif; ?>
