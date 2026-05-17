<?php
// Schema migrations (safe, idempotent)
foreach ([
    "ALTER TABLE modules ADD COLUMN require_pretest INTEGER DEFAULT 1",
    "ALTER TABLE modules ADD COLUMN require_posttest INTEGER DEFAULT 1",
] as $sql) { try { db()->exec($sql); } catch(Exception $e) {} }
db()->exec("CREATE TABLE IF NOT EXISTS module_feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_id INTEGER NOT NULL,
    session_hash TEXT NOT NULL,
    student_id INTEGER,
    rating INTEGER NOT NULL,
    most_useful TEXT,
    unclear TEXT,
    would_recommend INTEGER DEFAULT 1,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
try { db()->exec("CREATE UNIQUE INDEX uniq_feedback ON module_feedback(module_id,session_hash)"); } catch(Exception $e) {}

// Handle poll submission
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['poll_rating'])) {
    $rating = max(1, min(5, intval($_POST['poll_rating'])));
    $useful = trim($_POST['most_useful'] ?? '');
    $unclear = trim($_POST['unclear'] ?? '');
    $recommend = isset($_POST['would_recommend']) ? 1 : 0;
    $hash = getSessionHash();
    $sid  = getStudentId();
    $modSlug = $_POST['module_slug'] ?? '';
    $mod = getModule($modSlug);
    if ($mod && $hash) {
        $stmt = db()->prepare(
            'INSERT OR REPLACE INTO module_feedback (module_id,session_hash,student_id,rating,most_useful,unclear,would_recommend)
             VALUES (:mid,:hash,:sid,:rating,:useful,:unclear,:rec)'
        );
        $stmt->bindValue(':mid',   $mod['id']);
        $stmt->bindValue(':hash',  $hash);
        $stmt->bindValue(':sid',   $sid);
        $stmt->bindValue(':rating',$rating);
        $stmt->bindValue(':useful',$useful ?: null);
        $stmt->bindValue(':unclear',$unclear ?: null);
        $stmt->bindValue(':rec',   $recommend);
        $stmt->execute();
    }
    header('Location: /arise/?p=module&slug='.urlencode($modSlug).'&feedback=1');
    exit;
}

$slug = $_GET['slug'] ?? '';
$module = getModule($slug);

if (!$module) {
    echo '<div class="container"><div class="alert alert-danger">Module not found.</div><a href="/arise/?p=modules" class="btn btn-secondary">← Back to Modules</a></div>';
    return;
}

trackPageView('module', $slug, $module['id']);
$lessons = getLessons($module['id']);

$counts = ['interactive'=>0,'video'=>0,'pdf'=>0,'text'=>0];
foreach($lessons as $l) $counts[$l['lesson_type']] = ($counts[$l['lesson_type']]??0)+1;

$typeInfo = [
    'interactive' => [
        'icon'   => '🎮',
        'label'  => 'Interactive',
        'grad'   => 'linear-gradient(135deg,#0a5e2a,#1a8a40)',
        'glow'   => 'rgba(10,94,42,0.25)',
        'light'  => '#e8f5ec',
        'border' => '#a8d5b5',
        'text'   => '#0a5e2a',
        'accent' => '#f5a623',
        'time'   => '15 min',
        'cert'   => true,
    ],
    'video' => [
        'icon'   => '🎬',
        'label'  => 'Video',
        'grad'   => 'linear-gradient(135deg,#b91c1c,#ef4444)',
        'glow'   => 'rgba(185,28,28,0.2)',
        'light'  => '#fef2f2',
        'border' => '#fca5a5',
        'text'   => '#b91c1c',
        'accent' => '#fbbf24',
        'time'   => '10 min',
        'cert'   => false,
    ],
    'pdf' => [
        'icon'   => '📄',
        'label'  => 'Reading',
        'grad'   => 'linear-gradient(135deg,#1d4ed8,#3b82f6)',
        'glow'   => 'rgba(29,78,216,0.2)',
        'light'  => '#eff6ff',
        'border' => '#93c5fd',
        'text'   => '#1d4ed8',
        'accent' => '#fbbf24',
        'time'   => '5 min',
        'cert'   => false,
    ],
    'text' => [
        'icon'   => '📝',
        'label'  => 'Notes',
        'grad'   => 'linear-gradient(135deg,#0369a1,#0ea5e9)',
        'glow'   => 'rgba(3,105,161,0.2)',
        'light'  => '#f0f9ff',
        'border' => '#7dd3fc',
        'text'   => '#0369a1',
        'accent' => '#fbbf24',
        'time'   => '5 min',
        'cert'   => false,
    ],
];

$totalLessons = count($lessons);
$interactiveCount = $counts['interactive'];
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap');

.arise-module-page {
    font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
    max-width: 720px;
    margin: 0 auto;
    padding: 0 16px 40px;
}

/* BREADCRUMB */
.arise-breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .78rem;
    color: #6b7280;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.arise-breadcrumb a { color: #0a5e2a; text-decoration: none; font-weight: 600; }
.arise-breadcrumb a:hover { text-decoration: underline; }
.arise-breadcrumb .sep { color: #d1d5db; }

/* HERO */
.mod-hero-new {
    background: linear-gradient(145deg, #052e16, #0a5e2a, #166534);
    border-radius: 24px;
    padding: 28px 24px 24px;
    margin-bottom: 24px;
    color: #fff;
    position: relative;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(10,94,42,.35), 0 0 0 1px rgba(255,255,255,.05) inset;
}
.mod-hero-new::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(245,166,35,.15) 0%, transparent 70%);
    pointer-events: none;
}
.mod-hero-new::after {
    content: '<?= addslashes($module['icon']) ?>';
    position: absolute;
    right: 20px; bottom: -10px;
    font-size: 100px;
    opacity: .07;
    line-height: 1;
    pointer-events: none;
    filter: blur(2px);
}
.hero-top { display: flex; align-items: flex-start; gap: 16px; }
.hero-icon {
    width: 64px; height: 64px;
    background: rgba(245,166,35,.2);
    border: 2px solid rgba(245,166,35,.5);
    border-radius: 18px;
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem;
    flex-shrink: 0;
    box-shadow: 0 4px 20px rgba(245,166,35,.2);
}
.hero-label {
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: #f5a623;
    margin-bottom: 5px;
}
.hero-title {
    font-size: 1.55rem;
    font-weight: 900;
    color: #fff;
    line-height: 1.2;
    margin-bottom: 8px;
    letter-spacing: -.3px;
}
.hero-desc {
    font-size: .88rem;
    color: rgba(255,255,255,.78);
    line-height: 1.6;
    max-width: 520px;
}
.hero-pills {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 18px;
}
.hero-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 20px;
    padding: 5px 14px;
    font-size: .75rem;
    font-weight: 700;
    color: rgba(255,255,255,.9);
    backdrop-filter: blur(4px);
}
.hero-pill.gold {
    background: rgba(245,166,35,.2);
    border-color: rgba(245,166,35,.4);
    color: #fcd34d;
}

/* STATS ROW */
.stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 24px;
}
.stat-card {
    background: #fff;
    border: 1.5px solid #e5e7eb;
    border-radius: 16px;
    padding: 14px 12px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
    transition: transform .2s, box-shadow .2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.08); }
.stat-num {
    font-size: 1.8rem;
    font-weight: 900;
    color: #0a5e2a;
    line-height: 1;
    margin-bottom: 4px;
}
.stat-label { font-size: .72rem; font-weight: 600; color: #6b7280; }
.pg-banner {
    background: linear-gradient(135deg, #450a0a, #991b1b);
    border-radius: 16px;
    padding: 22px 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    margin: 24px 0 28px;
    color: #fff;
    box-shadow: 0 6px 24px rgba(120,0,0,.35);
    border-left: 6px solid #fca5a5;
}
.pg-banner-icon { font-size: 2.4rem; flex-shrink: 0; }
.pg-banner-body { flex: 1; }
.pg-banner-body strong { font-size: 1.05rem; display: block; margin-bottom: 8px; letter-spacing: .01em; }
.pg-banner-body span { font-size: .88rem; opacity: .95; line-height: 1.6; }

/* SECTION HEADER */
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}
.section-title {
    font-size: 1rem;
    font-weight: 800;
    color: #111827;
    display: flex;
    align-items: center;
    gap: 8px;
}
.count-badge {
    background: #e8f5ec;
    color: #0a5e2a;
    border-radius: 20px;
    padding: 2px 12px;
    font-size: .72rem;
    font-weight: 800;
}

/* LESSON CARDS */
.lesson-list { display: flex; flex-direction: column; gap: 12px; }

.lc {
    display: block;
    text-decoration: none;
    color: inherit;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 3px 12px rgba(0,0,0,.06);
    transition: transform .22s cubic-bezier(.34,1.56,.64,1), box-shadow .22s ease;
    position: relative;
}
.lc:hover { transform: translateY(-5px) scale(1.01); box-shadow: 0 16px 40px rgba(0,0,0,.13); }
.lc:active { transform: translateY(-2px) scale(1.005); }

.lc-inner {
    display: flex;
    align-items: stretch;
    background: #fff;
    border: 1.5px solid #f0f0f0;
    border-radius: 18px;
    overflow: hidden;
}

.lc-stripe {
    width: 5px;
    flex-shrink: 0;
}

.lc-num {
    width: 58px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 16px 8px;
    position: relative;
}
.lc-num-val {
    font-size: 1.5rem;
    font-weight: 900;
    line-height: 1;
    margin-bottom: 2px;
}
.lc-num-of {
    font-size: .6rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
    opacity: .6;
}

.lc-body {
    flex: 1;
    padding: 14px 16px 14px 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.lc-content { flex: 1; min-width: 0; }
.lc-title {
    font-size: .97rem;
    font-weight: 800;
    color: #111827;
    margin-bottom: 4px;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.lc-desc {
    font-size: .78rem;
    color: #9ca3af;
    margin-bottom: 8px;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.lc-tags {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.lc-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: .7rem;
    font-weight: 700;
}
.lc-time { font-size: .7rem; color: #9ca3af; font-weight: 600; }
.lc-cert { font-size: .7rem; font-weight: 700; color: #d97706; }

.lc-arrow {
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    font-weight: 900;
    color: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,.15);
    transition: transform .2s;
}
.lc:hover .lc-arrow { transform: translateX(3px); }

/* CERT CTA */
.cert-cta-new {
    margin-top: 20px;
    background: linear-gradient(135deg, #f5a623, #e8891a);
    border-radius: 20px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 8px 30px rgba(245,166,35,.35);
    position: relative;
    overflow: hidden;
}
.cert-cta-new::before {
    content: '🎓';
    position: absolute;
    right: 20px;
    font-size: 80px;
    opacity: .1;
    pointer-events: none;
}
.cert-cta-text .t1 {
    font-size: 1rem;
    font-weight: 900;
    color: #fff;
    margin-bottom: 3px;
}
.cert-cta-text .t2 {
    font-size: .8rem;
    color: rgba(255,255,255,.85);
}

/* BACK BUTTON */
.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 24px;
    background: #f3f4f6;
    color: #374151;
    border-radius: 12px;
    padding: 10px 18px;
    font-size: .85rem;
    font-weight: 700;
    text-decoration: none;
    transition: background .2s, transform .15s;
}
.back-btn:hover { background: #e5e7eb; transform: translateX(-3px); }
.lc:hover [style*="▶ START"], .lc:hover .start-btn {
    box-shadow: 0 8px 28px rgba(245,166,35,.7) !important;
    transform: scale(1.05);
}

@media(max-width:500px){
    .stats-row { grid-template-columns: repeat(3,1fr); gap:8px; }
    .stat-num { font-size:1.4rem; }
    .hero-title { font-size:1.3rem; }
    .lc-title { font-size:.9rem; }
    .lc-num { width:48px; }
    .lc-num-val { font-size:1.2rem; }
}
</style>

<div class="arise-module-page">

    <!-- Breadcrumb -->
    <div class="arise-breadcrumb">
        <a href="/arise/">🏠 Home</a>
        <span class="sep">›</span>
        <a href="/arise/?p=modules">Modules</a>
        <span class="sep">›</span>
        <span><?= e($module['title']) ?></span>
    </div>

    <!-- Hero -->
    <div class="mod-hero-new">
        <div class="hero-top">
            <div class="hero-icon"><?= $module['icon'] ?></div>
            <div style="flex:1;min-width:0;">
                <div class="hero-label">Health Module</div>
                <div class="hero-title"><?= e($module['title']) ?></div>
                <?php if($module['description']): ?>
                    <div class="hero-desc"><?= e($module['description']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-pills">
            <span class="hero-pill">📱 100% Offline</span>
            <span class="hero-pill">🌍 EN · SW · Sheng</span>
            <?php if($interactiveCount > 0): ?>
                <span class="hero-pill gold">🏆 Certificate Available</span>
            <?php endif; ?>
            <span class="hero-pill">⚡ Free</span>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-num"><?= $totalLessons ?></div>
            <div class="stat-label">Lessons</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $interactiveCount > 0 ? ($interactiveCount * 15) : ($totalLessons * 10) ?>m</div>
            <div class="stat-label">Est. Time</div>
        </div>
        <div class="stat-card">
            <div class="stat-num">60%</div>
            <div class="stat-label">Pass Mark</div>
        </div>
    </div>

    <!-- Teenage Pregnancy Prevention Banner -->
    <div class="pg-banner">
        <div class="pg-banner-icon">🚨</div>
        <div class="pg-banner-body">
            <strong>Kenya Teenage Pregnancy Reality</strong>
            <span>1 in 4 Kenyan girls is pregnant or already a mother by age 19. Every healthy choice you make today protects your future. <strong>Abstinence is the only 100% reliable protection.</strong></span>
        </div>
    </div>

    <?php
    // Knowledge Assessment — pre/post test state
    $_pretestDone = false; $_lessonQuizDone = false; $_postestDone = false; $_lessonPassed = false;
    if (getStudentId()) {
        $_h = getSessionHash();
        $_mid = intval($module['id']);
        $_hEsc = SQLite3::escapeString($_h);
        $_pretestDone    = (bool)db()->querySingle("SELECT id FROM pretest_attempts WHERE session_hash='$_hEsc' AND module_id=$_mid AND test_type='pre'");
        $_lessonQuizDone = (bool)db()->querySingle("SELECT id FROM pretest_attempts WHERE session_hash='$_hEsc' AND module_id=$_mid AND test_type='lesson'");
        $_postestDone    = (bool)db()->querySingle("SELECT id FROM pretest_attempts WHERE session_hash='$_hEsc' AND module_id=$_mid AND test_type='post'");
        $_surveyDone     = (bool)db()->querySingle("SELECT id FROM behavioral_surveys WHERE session_hash='$_hEsc' AND module_id=$_mid");
        $_lessonPassed   = $_lessonQuizDone; // lesson quiz replaces the old ≥60% gate
    }
    $hasQuestions = (bool)db()->querySingle("SELECT id FROM quiz_questions WHERE module_id=".intval($module['id'])." AND is_published=1 LIMIT 1");
    ?>

    <?php if ($hasQuestions): ?>
    <?php
        if ($_surveyDone)            { $_bgCol='#f0fdf4'; $_bdCol='#86efac'; $_stepColor='#166534'; $_stepIcon='&#10003;'; $_stepLabel='All Complete &#10003;'; $_stepDesc='You have completed all steps for this module. Well done!'; }
        elseif ($_postestDone)      { $_bgCol='#fef9c3'; $_bdCol='#fde047'; $_stepColor='#854d0e'; $_stepIcon='&#128172;'; $_stepLabel='Step 5 of 5 &mdash; Complete the Reflection Survey'; $_stepDesc='Last step! Share how this module affected you — takes 60 seconds.'; }
        elseif ($_lessonQuizDone)   { $_bgCol='#eff6ff'; $_bdCol='#93c5fd'; $_stepColor='#1d4ed8'; $_stepIcon='&#128200;'; $_stepLabel='Step 4 of 5 &mdash; Take the Post-Test'; $_stepDesc='Great work! Now complete the post-test to earn your certificate.'; }
        elseif ($_pretestDone)      { $_bgCol='#faf5ff'; $_bdCol='#d8b4fe'; $_stepColor='#6b21a8'; $_stepIcon='&#127918;'; $_stepLabel='Step 2 &amp; 3 of 5 &mdash; Study Lessons, Then Take Lesson Quiz'; $_stepDesc='Go through the lessons below, then take the 10-question lesson quiz.'; }
        else                        { $_bgCol='#fffbeb'; $_bdCol='#fcd34d'; $_stepColor='#92400e'; $_stepIcon='&#128203;'; $_stepLabel='Step 1 of 5 &mdash; Take the Pre-Test First'; $_stepDesc='Do this before lessons to measure your starting knowledge.'; }
    ?>
    <div style="background:<?= $_bgCol ?>;border:2px solid <?= $_bdCol ?>;border-radius:14px;padding:18px 20px;margin-bottom:20px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
        <span style="font-size:1.5rem;"><?= $_stepIcon ?></span>
        <div>
          <div style="font-weight:700;font-size:.95rem;color:<?= $_stepColor ?>;"><?= $_stepLabel ?></div>
          <div style="font-size:.8rem;color:#6b7280;margin-top:2px;"><?= $_stepDesc ?></div>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <!-- Step 1: Pre-Test -->
        <a href="/arise/?p=pre_test&module=<?= e($module['slug']) ?>&type=pre"
           class="btn <?= $_pretestDone ? 'btn-secondary' : 'btn-primary' ?>"
           style="<?= $_pretestDone ? 'opacity:.7;' : '' ?>font-size:.82rem">
          &#128203; Pre-Test<?= $_pretestDone ? ' &#10003;' : ' &rarr;' ?>
        </a>
        <span style="color:#d1d5db;font-size:.9rem">&#8594;</span>
        <!-- Step 2: Lessons (no button, user scrolls down) -->
        <span style="font-size:.82rem;color:<?= ($_pretestDone && !$_lessonQuizDone) ? '#6b21a8' : '#9ca3af' ?>;font-weight:600">&#128218; Lessons</span>
        <span style="color:#d1d5db;font-size:.9rem">&#8594;</span>
        <!-- Step 3: Lesson Quiz -->
        <a href="/arise/?p=pre_test&module=<?= e($module['slug']) ?>&type=lesson"
           class="btn <?= $_lessonQuizDone ? 'btn-secondary' : 'btn-primary' ?>"
           style="<?= !$_pretestDone ? 'opacity:.3;pointer-events:none;cursor:not-allowed;' : ($_lessonQuizDone ? 'opacity:.7;' : '') ?>font-size:.82rem"
           title="<?= !$_pretestDone ? 'Complete the Pre-Test first' : 'In-lesson quiz — 10 questions' ?>">
          &#127919; Lesson Quiz<?= $_lessonQuizDone ? ' &#10003;' : (!$_pretestDone ? ' &#128274;' : ' &rarr;') ?>
        </a>
        <span style="color:#d1d5db;font-size:.9rem">&#8594;</span>
        <!-- Step 4: Post-Test -->
        <a href="/arise/?p=pre_test&module=<?= e($module['slug']) ?>&type=post"
           class="btn <?= $_postestDone ? 'btn-secondary' : 'btn-primary' ?>"
           style="<?= (!$_lessonQuizDone) ? 'opacity:.3;pointer-events:none;cursor:not-allowed;' : ($_postestDone ? 'opacity:.7;' : '') ?>font-size:.82rem"
           title="<?= !$_lessonQuizDone ? 'Complete the Lesson Quiz first' : 'Measure how much you learned' ?>">
          &#128200; Post-Test<?= $_postestDone ? ' &#10003;' : (!$_lessonQuizDone ? ' &#128274;' : ' &rarr;') ?>
        </a>
        <span style="color:#d1d5db;font-size:.9rem">&#8594;</span>
        <!-- Step 5: Survey -->
        <a href="/arise/?p=survey&module=<?= e($module['slug']) ?>"
           class="btn <?= $_surveyDone ? 'btn-secondary' : 'btn-primary' ?>"
           style="<?= (!$_postestDone) ? 'opacity:.3;pointer-events:none;cursor:not-allowed;' : ($_surveyDone ? 'opacity:.7;' : 'background:linear-gradient(135deg,#f59e0b,#d97706);border-color:#d97706;') ?>font-size:.82rem"
           title="<?= !$_postestDone ? 'Complete the Post-Test first' : 'Quick 3-question reflection' ?>">
          &#128172; Survey<?= $_surveyDone ? ' &#10003;' : (!$_postestDone ? ' &#128274;' : ' &rarr;') ?>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Lessons -->
    <?php if ($totalLessons > 0): ?>

    <?php if ($hasQuestions && !$_pretestDone && ($module['require_pretest'] ?? 1)): ?>
    <div style="background:#fffbeb;border:2px solid #fcd34d;border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:center;justify-content:space-between;">
      <div style="display:flex;gap:12px;align-items:center;">
        <span style="font-size:1.3rem;">🔒</span>
        <div>
          <strong style="color:#92400e;">Lessons Locked</strong>
          <div style="color:#8b5cf6;font-size:.8rem;">Complete the pre-test above to unlock</div>
        </div>
      </div>
      <a href="/arise/?p=pre_test&module=<?= e($module['slug']) ?>&type=pre" style="background:#fcd34d;color:#92400e;padding:6px 12px;border-radius:6px;text-decoration:none;font-weight:600;font-size:.85rem;white-space:nowrap;">Start Pre-Test →</a>
    </div>
    <?php endif; ?>

    <div class="section-header">
        <div class="section-title">
            &#128214; Lessons
            <span class="count-badge"><?= $totalLessons ?> total</span>
        </div>
    </div>

    <div class="lesson-list">
    <?php foreach ($lessons as $i => $lesson):
        $type = $lesson['lesson_type'];
        $info = $typeInfo[$type] ?? $typeInfo['text'];
        $thumb = !empty($lesson['thumbnail']) ? '/arise/uploads/' . $lesson['thumbnail'] : null;
        $desc = $lesson['content']
            ? e(substr(strip_tags($lesson['content']),0,80)).(strlen(strip_tags($lesson['content']))>80?'…':'')
            : ($type === 'interactive' ? 'Slides · questions · instant score' : ($type === 'video' ? 'Watch offline anytime' : 'Read at your own pace'));

        // M&E Gate: Lock lesson if pre-test required but not done
        $lessonLocked = $hasQuestions && !$_pretestDone && ($module['require_pretest'] ?? 1);
        $lessonHref = $lessonLocked
            ? "/arise/?p=pre_test&module=".e($module['slug'])."&type=pre"
            : "/arise/?p=lesson&slug=".e($lesson['slug']);
    ?>
    <a href="<?= $lessonHref ?>" class="lc" style="<?= $lessonLocked ? 'opacity:0.6;' : '' ?>" title="<?= $lessonLocked ? '🔒 Take the pre-test first to unlock' : '' ?>">
        <div class="lc-inner">
            <!-- stripe -->
            <div class="lc-stripe" style="background:<?= $info['grad'] ?>;"></div>

            <!-- number / thumbnail -->
            <?php if ($thumb): ?>
            <div class="lc-thumb">
                <img src="<?= e($thumb) ?>" alt="<?= e($lesson['title']) ?>" loading="lazy">
            </div>
            <?php else: ?>
            <div class="lc-num" style="background:<?= $info['light'] ?>;">
                <div class="lc-num-val" style="color:<?= $info['text'] ?>"><?= $i+1 ?></div>
                <div class="lc-num-of" style="color:<?= $info['text'] ?>">of <?= $totalLessons ?></div>
            </div>
            <?php endif; ?>

            <!-- body -->
            <div class="lc-body">
                <div class="lc-content">
                    <div class="lc-title"><?= e($lesson['title']) ?></div>
                    <div class="lc-desc"><?= $desc ?></div>
                    <div class="lc-tags">
                        <span class="lc-tag" style="background:<?= $info['light'] ?>;color:<?= $info['text'] ?>;border:1px solid <?= $info['border'] ?>;">
                            <?= $info['icon'] ?> <?= $info['label'] ?>
                        </span>
                        <span class="lc-time">⏱ <?= $info['time'] ?></span>
                        <?php if($type === 'interactive'): ?>
                            <span class="lc-cert">🏆 Certificate</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- arrow / start -->
                <?php if($type === 'interactive'): ?>
                <div style="flex-shrink:0;background:linear-gradient(135deg,#f5a623,#e8891a);color:#fff;padding:10px 20px;border-radius:14px;font-size:.85rem;font-weight:900;white-space:nowrap;box-shadow:0 6px 18px rgba(245,166,35,.5);letter-spacing:.3px;">▶ START</div>
                <?php else: ?>
                <div class="lc-arrow" style="background:<?= $info['grad'] ?>;box-shadow:0 4px 14px <?= $info['glow'] ?>;">→</div>
                <?php endif; ?>
            </div>

            <!-- Lock Overlay -->
            <?php if ($lessonLocked): ?>
            <div style="position:absolute;inset:0;background:rgba(0,0,0,0.6);border-radius:12px;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
              <div style="background:#fff;color:#92400e;padding:12px 16px;border-radius:8px;font-weight:700;font-size:.95rem;text-align:center;">
                🔒<br>Locked
              </div>
            </div>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
    </div>

    <!-- Cert CTA -->
    <?php if ($interactiveCount > 0): ?>
    <div class="cert-cta-new">
        <span style="font-size:2.2rem;flex-shrink:0;">🎓</span>
        <div class="cert-cta-text">
            <div class="t1">Complete this module → Earn your Certificate!</div>
            <div class="t2">Score 60%+ on the quiz · Print &amp; share your achievement</div>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div style="text-align:center;padding:40px 20px;background:#f9fafb;border-radius:16px;color:#6b7280;">
        <div style="font-size:2.5rem;margin-bottom:12px;">📚</div>
        <div style="font-weight:700;margin-bottom:6px;">Coming Soon</div>
        <div style="font-size:.85rem;">Lessons for this module are being prepared. Check back soon!</div>
    </div>
    <?php endif; ?>



    <a href="/arise/?p=modules" class="back-btn" style="margin-top:20px;display:inline-flex;">&#8592; Back to All Modules</a>

</div>

<style>
.lc-thumb {
    flex-shrink: 0;
    width: 90px;
    height: 90px;
    border-radius: 10px;
    overflow: hidden;
    margin-right: 4px;
}
.lc-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}
.lc:hover .lc-thumb img {
    transform: scale(1.08);
}
</style>
<style>
.lc-thumb {
    flex-shrink: 0;
    width: 90px;
    height: 90px;
    border-radius: 10px;
    overflow: hidden;
    margin-right: 4px;
}
.lc-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}
.lc:hover .lc-thumb img {
    transform: scale(1.08);
}
</style>