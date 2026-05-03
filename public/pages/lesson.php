<?php
$slug = $_GET['slug'] ?? '';
$stmt = db()->prepare('SELECT l.*, m.title AS module_title, m.slug AS module_slug, m.icon FROM lessons l JOIN modules m ON l.module_id = m.id WHERE l.slug = :slug AND l.is_active = 1');
$stmt->bindValue(':slug', $slug);
$lesson = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$lesson) {
    echo '<div class="container"><div class="alert alert-danger">Lesson not found.</div><a href="/arise/?p=modules" class="btn btn-secondary">&#8592; Back</a></div>';
    return;
}

trackPageView('lesson', $slug, $lesson['module_id']);
$contentWarning = null;
$modInfo = db()->querySingle("SELECT content_warning FROM modules WHERE id=".intval($lesson['module_id']),true);
if ($modInfo && !empty($modInfo['content_warning'])) {
    $seenKey = 'cw_'.$lesson['module_id'];
    if (empty($_SESSION[$seenKey])) {
        $contentWarning = $modInfo['content_warning'];
    }
}

$lessonType = $lesson['lesson_type'] ?? 'text';
$filePath   = $lesson['file_path'] ?? null;
$baseUpload = '/arise/uploads/';

if ($lessonType === 'interactive' && $filePath) {
    // Find video lesson in same module to inject into slide 6
    $vRow = db()->querySingle(
        "SELECT file_path FROM lessons WHERE module_id=" . intval($lesson['module_id']) . " AND lesson_type='video' AND is_active=1 LIMIT 1",
        true
    );
    $videoUrl = ($vRow && !empty($vRow['file_path'])) ? $baseUpload . $vRow['file_path'] : '';
    $resumeSlide = 0;
    $hash = getSessionHash();
    if ($hash) {
        $prog = db()->querySingle("SELECT last_slide FROM lesson_progress WHERE session_hash='" . SQLite3::escapeString($hash) . "' AND lesson_id=" . intval($lesson['id']));
        if ($prog) $resumeSlide = intval($prog);
    }
    // Read and output the HTML file with injected context
    $htmlFile = '/var/www/arise/data/uploads/' . $filePath;
    if (file_exists($htmlFile)) { error_log('ARISE: serving ' . $htmlFile . ' videoUrl=' . $videoUrl);
        $html = file_get_contents($htmlFile);
        $inject = "<script>
window.ARISE_LESSON_ID=" . intval($lesson['id']) . ";
window.ARISE_MODULE_SLUG='" . addslashes($lesson['module_slug']) . "';
window.ARISE_RESUME_SLIDE=" . $resumeSlide . ";
window.ARISE_VIDEO_URL='" . addslashes($videoUrl) . "';
</script>";
        // Inject before </head>
        $html = str_replace('</head>', $inject . '</head>', $html);
        ob_end_clean();
        echo $html;
        exit;
    }
    // Fallback redirect
    header('Location: ' . $baseUpload . $filePath);
    exit;
}

// Non-interactive lessons
$allLessons = getLessons($lesson['module_id']);
$currentIndex = -1;
foreach ($allLessons as $i => $l) {
    if ($l['id'] === $lesson['id']) { $currentIndex = $i; break; }
}
$prevLesson = $currentIndex > 0 ? $allLessons[$currentIndex - 1] : null;
$nextLesson = $currentIndex < count($allLessons) - 1 ? $allLessons[$currentIndex + 1] : null;
?>
<div class="container">
    <div class="breadcrumb">
        <a href="/arise/">Home</a> <span class="sep">›</span>
        <a href="/arise/?p=modules">Modules</a> <span class="sep">›</span>
        <a href="/arise/?p=module&slug=<?= e($lesson['module_slug']) ?>"><?= e($lesson['module_title']) ?></a> <span class="sep">›</span>
        <span><?= e($lesson['title']) ?></span>
    </div>
    <?php if ($contentWarning): ?>
    <div class="cw-overlay" id="cwOverlay">
      <div class="cw-box">
        <div class="cw-icon">⚠️</div>
        <div class="cw-title">Content Notice</div>
        <div class="cw-text"><?= e($contentWarning) ?></div>
        <div class="cw-btns">
          <a href="/arise/?p=module&slug=<?= e($lesson['module_slug']) ?>" class="btn btn-secondary">Go Back</a>
          <button class="btn btn-primary" onclick="dismissCW(<?= $lesson['module_id'] ?>)">I understand — Continue</button>
        </div>
      </div>
    </div>
    <script>function dismissCW(mid){fetch('/arise/?p=api_lesson&action=ack_cw&mid='+mid);document.getElementById('cwOverlay').style.display='none';}</script>
    <?php endif; ?>
    <div class="lesson-content">
        <h1 style="font-size:1.35rem;margin-bottom:20px;"><?= e($lesson['title']) ?></h1>
        <?php if ($lessonType === 'video' && $filePath): ?>
            <div style="background:#000;border-radius:var(--r2);overflow:hidden;margin-bottom:20px;">
                <video controls playsinline style="width:100%;max-height:520px;display:block;" preload="metadata">
                    <source src="<?= $baseUpload . e($filePath) ?>" type="video/mp4">
                </video>
            </div>
        <?php elseif ($lessonType === 'pdf' && $filePath): ?>
            <iframe src="<?= $baseUpload . e($filePath) ?>" style="width:100%;height:650px;border:none;border-radius:var(--r2);"></iframe>
        <?php else: ?>
            <div class="lesson-body"><?= !empty($lesson['content']) ? $lesson['content'] : '<p class="text-muted">Content coming soon.</p>' ?></div>
        <?php endif; ?>
        <div class="private-notes-panel">
          <h4>🔒 Private Notes <span style="font-size:.68rem;font-weight:400;color:#bbb">(saved only on this device — never shared)</span></h4>
          <textarea class="private-notes-ta" id="privNotes" placeholder="Write personal reflections here..." oninput="saveNote(this.value,'<?= addslashes($lesson['slug']) ?>')"></textarea>
          <div class="notes-hint">✓ Auto-saved locally · Not visible to anyone else</div>
        </div>
        <script>
        (function(){
          var k='arise_note_<?= addslashes($lesson['slug']) ?>';
          var ta=document.getElementById('privNotes');
          if(ta){ta.value=localStorage.getItem(k)||'';}
        })();
        function saveNote(v,slug){localStorage.setItem('arise_note_'+slug,v);}
        </script>
        <div class="lesson-nav" style="display:flex;justify-content:space-between;margin-top:28px;padding-top:22px;border-top:1px solid var(--border);">
            <?php if ($prevLesson): ?>
                <a href="/arise/?p=lesson&slug=<?= e($prevLesson['slug']) ?>" class="btn btn-secondary">&#8592; <?= e($prevLesson['title']) ?></a>
            <?php else: ?>
                <a href="/arise/?p=module&slug=<?= e($lesson['module_slug']) ?>" class="btn btn-secondary">&#8592; Back to Module</a>
            <?php endif; ?>
            <?php if ($nextLesson): ?>
                <a href="/arise/?p=lesson&slug=<?= e($nextLesson['slug']) ?>" class="btn btn-primary"><?= e($nextLesson['title']) ?> &#8594;</a>
            <?php endif; ?>
        </div>
    </div>
</div>
