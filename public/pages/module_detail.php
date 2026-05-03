<?php
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

    <?php
    // Knowledge Assessment — pre/post test state
    $_pretestDone = false; $_postestDone = false;
    if (getStudentId()) {
        $_h = getSessionHash();
        $_pretestDone = (bool)db()->querySingle("SELECT id FROM pretest_attempts WHERE session_hash='".SQLite3::escapeString($_h)."' AND module_id=".intval($module['id'])." AND test_type='pre'");
        $_postestDone = (bool)db()->querySingle("SELECT id FROM pretest_attempts WHERE session_hash='".SQLite3::escapeString($_h)."' AND module_id=".intval($module['id'])." AND test_type='post'");
    }
    $hasQuestions = (bool)db()->querySingle("SELECT id FROM quiz_questions WHERE module_id=".intval($module['id'])." AND question_type='mcq' LIMIT 1");
    ?>

    <?php if ($hasQuestions): ?>
    <div style="background:<?= $_pretestDone ? '#f0fdf4' : '#fffbeb' ?>;border:2px solid <?= $_pretestDone ? '#86efac' : '#fcd34d' ?>;border-radius:14px;padding:18px 20px;margin-bottom:20px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
        <span style="font-size:1.5rem;"><?= $_pretestDone ? '&#10003;' : '&#128203;' ?></span>
        <div>
          <div style="font-weight:700;font-size:.95rem;color:<?= $_pretestDone ? '#166534' : '#92400e' ?>;">
            <?php if (!$_pretestDone): ?>Step 1 of 3 &mdash; Take the Pre-Test First
            <?php elseif (!$_postestDone): ?>Step 2 of 3 &mdash; Study the Lessons Below
            <?php else: ?>Assessment Complete
            <?php endif; ?>
          </div>
          <div style="font-size:.8rem;color:#6b7280;margin-top:2px;">
            <?php if (!$_pretestDone): ?>Do this before reading the lessons to measure your starting knowledge.
            <?php elseif (!$_postestDone): ?>Work through all the lessons below, then come back to take the Post-Test.
            <?php else: ?>You have completed both the pre-test and post-test for this module.
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a href="/arise/?p=pre_test&module=<?= e($module['slug']) ?>&type=pre"
           class="btn <?= $_pretestDone ? 'btn-secondary' : 'btn-primary' ?>"
           style="<?= $_pretestDone ? 'opacity:.7;' : '' ?>">
          &#128203; Pre-Test <?= $_pretestDone ? '&#10003; Done' : '&rarr; Start Here' ?>
        </a>
        <span style="color:#9ca3af;font-size:.82rem;">then study lessons &darr;</span>
        <a href="/arise/?p=pre_test&module=<?= e($module['slug']) ?>&type=post"
           class="btn <?= $_postestDone ? 'btn-secondary' : 'btn-primary' ?>"
           style="<?= !$_pretestDone ? 'opacity:.3;pointer-events:none;cursor:not-allowed;' : ($_postestDone ? 'opacity:.7;' : '') ?>"
           title="<?= !$_pretestDone ? 'Complete the Pre-Test first' : 'Measure how much you learned' ?>">
          &#128200; Post-Test <?= $_postestDone ? '&#10003; Done' : ($_pretestDone ? '&rarr; After Lessons' : '') ?>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Lessons -->
    <?php if ($totalLessons > 0): ?>

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
    ?>
    <a href="/arise/?p=lesson&slug=<?= e($lesson['slug']) ?>" class="lc">
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


    <a href="/arise/?p=modules" class="back-btn" style="margin-top:14px;display:inline-flex;">&#8592; Back to All Modules</a>

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