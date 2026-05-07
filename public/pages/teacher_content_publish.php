<?php
/**
 * Teacher Content Publishing Dashboard
 * Teachers can publish/unpublish lessons and quizzes for their students
 */

// Check if logged in as teacher/admin (via admin panel OR student portal)
$isTeacher = false;

// Check admin session (for admin panel logins)
if (isset($_SESSION['arise_admin_id']) && isset($_SESSION['arise_admin_role'])) {
    $isTeacher = in_array($_SESSION['arise_admin_role'], ['teacher', 'admin']);
}

// Check student session with teacher role (for student portal logins)
if (!$isTeacher) {
    $student = getStudentBySession();
    if ($student && in_array($student['role'] ?? '', ['teacher', 'admin'])) {
        $isTeacher = true;
    }
}

if (!$isTeacher) {
    echo '<div class="container"><div class="alert alert-danger">Teachers only.</div></div>';
    return;
}

$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? '';  // 'lesson' or 'quiz'
$id = intval($_GET['id'] ?? 0);

// Handle publish/unpublish toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggle') {
    $newStatus = intval($_POST['status'] ?? 0);
    $type = $_POST['type'] ?? '';
    $id = intval($_POST['id'] ?? 0);

    if ($type === 'lesson') {
        $st = db()->prepare("UPDATE lessons SET is_published = :s WHERE id = :id");
        $st->bindValue(':s', $newStatus);
        $st->bindValue(':id', $id);
        $st->execute();
        $msg = 'Lesson ' . ($newStatus ? 'published' : 'unpublished');
    } elseif ($type === 'quiz') {
        $st = db()->prepare("UPDATE quiz_questions SET is_published = :s WHERE id = :id");
        $st->bindValue(':s', $newStatus);
        $st->bindValue(':id', $id);
        $st->execute();
        $msg = 'Quiz questions ' . ($newStatus ? 'published' : 'unpublished');
    }

    echo json_encode(['status' => 'ok', 'message' => $msg]);
    exit;
}

// Get all modules for tabs
$modules = [];
$res = db()->query("SELECT id, title, slug FROM modules WHERE is_active=1 ORDER BY title");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $modules[] = $r;

$moduleSlug = $_GET['module'] ?? ($modules[0]['slug'] ?? '');
$module = $moduleSlug ? db()->querySingle("SELECT * FROM modules WHERE slug='".SQLite3::escapeString($moduleSlug)."'", true) : null;

if (!$module) {
    echo '<div class="container"><div class="alert alert-info">No modules available.</div></div>';
    return;
}
?>

<div class="container" style="max-width:1000px">
  <h2>📚 Manage Content Publishing</h2>
  <p style="color:#6b7280;font-size:.9rem">Publish lessons and quizzes to make them visible to students. Unpublished content is hidden.</p>

  <!-- Module Tabs -->
  <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;border-bottom:2px solid #e5e7eb;padding-bottom:12px">
    <?php foreach ($modules as $m): ?>
      <a href="?p=teacher_content_publish&module=<?=e($m['slug'])?>"
         style="padding:8px 14px;border-radius:6px;text-decoration:none;font-weight:<?=($module && $module['id']==$m['id'])?'700':'500'?>;background:<?=($module && $module['id']==$m['id'])?'#dbeafe':'transparent'?>;color:<?=($module && $module['id']==$m['id'])?'#0284c7':'#6b7280'?>;cursor:pointer;border:<?=($module && $module['id']==$m['id'])?'2px solid #0284c7':'2px solid transparent'?>">
        <?=e($m['title'])?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Content Grid -->
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden">

    <!-- LESSONS -->
    <div style="padding:16px;border-bottom:1px solid #e5e7eb">
      <h3 style="margin:0 0 12px 0;font-size:1.1rem">📖 Lessons</h3>

      <?php
      $lessons = [];
      $res = db()->query("SELECT id, title, lesson_type, is_published FROM lessons WHERE module_id = ".intval($module['id'])." ORDER BY id");
      while ($r = $res->fetchArray(SQLITE3_ASSOC)) $lessons[] = $r;

      if (empty($lessons)): ?>
        <div style="color:#9ca3af;font-size:.9rem">No lessons in this module</div>
      <?php else: ?>
        <div style="display:grid;gap:10px">
          <?php foreach ($lessons as $lesson): ?>
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:center">
              <div>
                <div style="font-weight:600;color:#111"><?=e($lesson['title'])?></div>
                <div style="font-size:.8rem;color:#6b7280"><?=ucfirst($lesson['lesson_type'])?></div>
              </div>
              <div style="display:flex;gap:8px;align-items:center">
                <span style="font-size:.85rem;color:<?=$lesson['is_published']?'#10b981':'#ef4444'?>;font-weight:600">
                  <?=$lesson['is_published']?'Published':'Unpublished'?>
                </span>
                <button class="lesson-toggle" data-id="<?=$lesson['id']?>" data-status="<?=$lesson['is_published']?'0':'1'?>"
                  style="padding:6px 12px;border:1px solid <?=$lesson['is_published']?'#ef4444':'#10b981'?>;background:transparent;color:<?=$lesson['is_published']?'#ef4444':'#10b981'?>;border-radius:6px;cursor:pointer;font-weight:600;font-size:.85rem">
                  <?=$lesson['is_published']?'Unpublish':'Publish'?>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- QUIZZES -->
    <div style="padding:16px">
      <h3 style="margin:0 0 12px 0;font-size:1.1rem">❓ Pre/Post Tests</h3>

      <?php
      $qCount = db()->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE module_id = ".intval($module['id']));
      $qPublished = db()->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE module_id = ".intval($module['id'])." AND is_published=1");
      ?>

      <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px;margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div>
            <div style="font-weight:600;color:#0c4a6e">Pre/Post Test Questions</div>
            <div style="font-size:.85rem;color:#075985"><?=$qPublished?> of <?=$qCount?> questions published</div>
          </div>
          <button class="quiz-toggle" data-status="<?=($qPublished == $qCount)?'0':'1'?>"
            style="padding:8px 16px;background:<?=($qPublished == $qCount)?'#ef4444':'#10b981'?>;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:.85rem">
            <?=($qPublished == $qCount)?'Unpublish All':'Publish All'?>
          </button>
        </div>
      </div>

      <div style="font-size:.85rem;color:#6b7280;line-height:1.6">
        <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px">
          💡 <strong>Tip:</strong> When you publish tests, the pre-test becomes required before students can access lessons. Post-test is required before earning certificates.
        </div>
      </div>
    </div>

  </div>

  <!-- Summary -->
  <div style="margin-top:20px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:.85rem;color:#166534">
    ✅ <strong>What happens when you publish:</strong>
    <div style="margin-top:6px">
      - Lessons appear in student module view
      - Pre-test becomes required (locked until completed)
      - Post-test becomes required for certificate
      - Knowledge gain data is recorded
    </div>
  </div>

</div>

<script>
document.querySelectorAll('.lesson-toggle').forEach(btn => {
  btn.addEventListener('click', function() {
    const id = this.dataset.id;
    const newStatus = this.dataset.status;
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('type', 'lesson');
    formData.append('id', id);
    formData.append('status', newStatus);

    fetch('?p=teacher_content_publish', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(d => {
        if (d.status === 'ok') location.reload();
      });
  });
});

document.querySelectorAll('.quiz-toggle').forEach(btn => {
  btn.addEventListener('click', function() {
    const newStatus = this.dataset.status;
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('type', 'quiz');
    formData.append('id', <?=intval($module['id'])?>>);
    formData.append('status', newStatus);

    fetch('?p=teacher_content_publish', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(d => {
        if (d.status === 'ok') location.reload();
      });
  });
});
</script>
