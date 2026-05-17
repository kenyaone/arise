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
$baseUpload = '/arise/data/uploads/';

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

    // Build the community panel injected after quiz completion
    $modId      = intval($lesson['module_id']);
    $modSlug    = addslashes($lesson['module_slug']);
    $modTitle   = htmlspecialchars($lesson['module_title'], ENT_QUOTES);
    $alreadyVoted = $hash
        ? (bool)db()->querySingle("SELECT id FROM module_feedback WHERE module_id=$modId AND session_hash='" . SQLite3::escapeString($hash) . "'")
        : false;

    if ($alreadyVoted) {
        $existingRating = (int)db()->querySingle("SELECT rating FROM module_feedback WHERE module_id=$modId AND session_hash='" . SQLite3::escapeString($hash) . "'");
        $pollHtml = '<p style="color:#166534;font-weight:600;margin-bottom:10px;">✅ You already rated this module (' . $existingRating . '/5 ⭐). Thanks!</p>';
    } else {
        $pollHtml = <<<HTML
<form id="arise-poll-form" style="margin-bottom:12px;">
  <p style="font-size:.88rem;color:#374151;margin-bottom:8px;font-weight:600;">Rate this module:</p>
  <div id="star-row" style="display:flex;gap:8px;margin-bottom:10px;font-size:1.6rem;cursor:pointer;">
    <span class="star" data-v="1">☆</span>
    <span class="star" data-v="2">☆</span>
    <span class="star" data-v="3">☆</span>
    <span class="star" data-v="4">☆</span>
    <span class="star" data-v="5">☆</span>
  </div>
  <input type="hidden" id="poll-rating" name="poll_rating" value="">
  <textarea id="poll-useful" name="most_useful" placeholder="What was most useful? (optional)" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;margin-bottom:8px;resize:none;height:60px;box-sizing:border-box;"></textarea>
  <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;margin-bottom:10px;cursor:pointer;">
    <input type="checkbox" id="poll-rec" name="would_recommend" value="1"> I would recommend this module
  </label>
  <button id="poll-submit" type="submit" disabled style="background:#7c3aed;color:white;border:none;padding:9px 22px;border-radius:8px;font-weight:700;cursor:pointer;opacity:.5;">Submit Feedback</button>
</form>
<div id="poll-thanks" style="display:none;color:#166534;font-weight:600;">✅ Thanks for your feedback!</div>
<script>
(function(){
  var stars=document.querySelectorAll('#arise-poll-form .star');
  var ratingInput=document.getElementById('poll-rating');
  var submitBtn=document.getElementById('poll-submit');
  stars.forEach(function(s){
    s.addEventListener('click',function(){
      var v=parseInt(s.getAttribute('data-v'));
      ratingInput.value=v;
      stars.forEach(function(x,i){x.textContent=i<v?'★':'☆';x.style.color=i<v?'#f59e0b':'#9ca3af';});
      submitBtn.disabled=false;submitBtn.style.opacity='1';
    });
  });
  document.getElementById('arise-poll-form').addEventListener('submit',function(e){
    e.preventDefault();
    var rating=ratingInput.value;
    if(!rating)return;
    var useful=document.getElementById('poll-useful').value;
    var rec=document.getElementById('poll-rec').checked?1:0;
    var fd=new FormData();
    fd.append('poll_rating',rating);
    fd.append('most_useful',useful);
    fd.append('would_recommend',rec);
    fd.append('module_slug',window.ARISE_MODULE_SLUG||'');
    fetch('/arise/?p=module&slug='+(window.ARISE_MODULE_SLUG||''),{method:'POST',body:fd})
      .then(function(){document.getElementById('arise-poll-form').style.display='none';document.getElementById('poll-thanks').style.display='block';})
      .catch(function(){});
  });
})();
</script>
HTML;
    }

    $communityPanel = <<<HTML
<div id="arise-community" style="display:none;position:fixed;bottom:0;left:0;right:0;background:white;border-top:3px solid #7c3aed;padding:16px 20px 20px;box-shadow:0 -6px 24px rgba(0,0,0,.18);z-index:9999;max-height:70vh;overflow-y:auto;">
  <button onclick="document.getElementById('arise-community').style.display='none'" style="position:absolute;top:10px;right:14px;background:none;border:none;font-size:1.3rem;cursor:pointer;color:#6b7280;">✕</button>
  <h3 style="color:#7c3aed;margin:0 0 12px;font-size:1rem;">🎉 Great work! How was <em>{$modTitle}</em>?</h3>
  {$pollHtml}
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
    <a href="/arise/?p=forum&module={$modSlug}" style="background:#0ea271;color:white;padding:9px 16px;border-radius:8px;font-size:.85rem;font-weight:700;text-decoration:none;">💬 Discuss this Module</a>
    <a href="/arise/?p=ask&module={$modSlug}" style="background:#6b7280;color:white;padding:9px 16px;border-radius:8px;font-size:.85rem;font-weight:700;text-decoration:none;">🔒 Ask Anonymously</a>
  </div>
</div>
<script>
(function(){
  var shown=false;
  var _orig=window.fetch;
  window.fetch=function(url,opts){
    var p=_orig.apply(this,arguments);
    if(!shown&&typeof url==='string'&&url.indexOf('save_quiz_score')!==-1){
      p.then(function(res){
        return res.clone().json();
      }).then(function(data){
        if(data&&data.status==='ok'){
          shown=true;
          document.getElementById('arise-community').style.display='block';
        }
      }).catch(function(){});
    }
    return p;
  };
})();
</script>
HTML;

    // Read and output the HTML file with injected context
    $htmlFile = '/var/www/arise/data/uploads/' . $filePath;
    if (file_exists($htmlFile)) { error_log('ARISE: serving ' . $htmlFile . ' videoUrl=' . $videoUrl);
        $html = file_get_contents($htmlFile);
        $inject = "<style>
/* ARISE fullscreen override — injected at serve time */
body{margin:0!important;padding-bottom:80px!important;}
.slides-wrap,.lang-bar-inner,.nav-inner{max-width:100%!important;width:100%!important;box-sizing:border-box!important;}
.slide{border-radius:0!important;}
.lang-bar,.nav-bar{left:0!important;right:0!important;}
#arise-fs-btn{position:fixed;top:8px;right:10px;z-index:99999;background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:8px;padding:6px 11px;font-size:.78rem;cursor:pointer;backdrop-filter:blur(4px);}
#arise-fs-btn:hover{background:rgba(0,0,0,.8);}
</style>
<script>
window.ARISE_LESSON_ID=" . intval($lesson['id']) . ";
window.ARISE_LESSON_SLUG='" . addslashes($lesson['slug']) . "';
window.ARISE_MODULE_SLUG='" . addslashes($lesson['module_slug']) . "';
window.ARISE_RESUME_SLIDE=" . $resumeSlide . ";
window.ARISE_VIDEO_URL='" . addslashes($videoUrl) . "';
(function(){
  function tryFS(el){
    if(!el)return;
    var fn=el.requestFullscreen||el.webkitRequestFullscreen||el.mozRequestFullScreen;
    if(fn)fn.call(el).catch(function(){});
  }
  function exitFS(){
    var fn=document.exitFullscreen||document.webkitExitFullscreen||document.mozCancelFullScreen;
    if(fn)fn.call(document);
  }
  function isFS(){return !!(document.fullscreenElement||document.webkitFullscreenElement);}
  document.addEventListener('DOMContentLoaded',function(){
    /* fullscreen button */
    var btn=document.createElement('button');
    btn.id='arise-fs-btn';
    btn.textContent='⛶ Fullscreen';
    btn.onclick=function(){isFS()?exitFS():tryFS(document.documentElement);};
    document.body.appendChild(btn);
    document.addEventListener('fullscreenchange',function(){btn.textContent=isFS()?'✕ Exit Fullscreen':'⛶ Fullscreen';});
    document.addEventListener('webkitfullscreenchange',function(){btn.textContent=isFS()?'✕ Exit Fullscreen':'⛶ Fullscreen';});
    /* video auto-fullscreen */
    var v=document.getElementById('lessonVideo');
    if(v){
      v.removeAttribute('playsinline');
      v.addEventListener('play',function(){
        if(!isFS())tryFS(v);
      },{once:false});
    }
  });
})();
</script>";
        // Inject before </head>
        $html = str_replace('</head>', $inject . '</head>', $html);
        // Required-lesson notice banner (injected after <body>)
        if (!empty($_GET['required'])) {
            $noticeBanner = '<div style="background:#fef9c3;border-bottom:2px solid #f59e0b;padding:12px 20px;text-align:center;font-size:.9rem;font-weight:600;color:#92400e;">
                ⚠️ Complete this lesson and pass the quiz (≥60%) to unlock your certificate for the related module.
            </div>';
            $html = preg_replace('/<body([^>]*)>/', '<body$1>' . $noticeBanner, $html, 1);
        }
        // Community panel: inject before </body>
        $html = str_replace('</body>', $communityPanel . '</body>', $html);
        ob_end_clean();
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
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
                <video id="lessonVideoStd" controls style="width:100%;max-height:520px;display:block;" preload="metadata"
                       onclick="this.requestFullscreen&&this.requestFullscreen()">
                    <source src="<?= $baseUpload . e($filePath) ?>" type="video/mp4">
                </video>
            </div>
            <script>
            (function(){
              var v=document.getElementById('lessonVideoStd');
              var vKey='arise_vpos_<?= addslashes($lesson['slug']) ?>';
              v.addEventListener('loadedmetadata',function(){
                var t=parseFloat(localStorage.getItem(vKey)||'0');
                if(t>5&&t<v.duration-5)v.currentTime=t;
              });
              v.addEventListener('timeupdate',function(){
                if(v.currentTime>5)localStorage.setItem(vKey,v.currentTime);
              });
              v.addEventListener('ended',function(){localStorage.removeItem(vKey);});
            })();
            </script>
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
