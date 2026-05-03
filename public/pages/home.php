<?php
$totalModules  = db()->querySingle("SELECT COUNT(*) FROM modules WHERE is_active=1") ?? 0;
$totalLessons  = db()->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1") ?? 0;
$totalStudents = db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1") ?? 0;
$totalCerts    = db()->querySingle("SELECT COUNT(*) FROM certificates") ?? 0;
$interactives  = db()->querySingle("SELECT COUNT(*) FROM lessons WHERE lesson_type='interactive' AND is_active=1") ?? 0;
$videos        = db()->querySingle("SELECT COUNT(*) FROM lessons WHERE lesson_type='video' AND is_active=1") ?? 0;
$pdfs          = db()->querySingle("SELECT COUNT(*) FROM lessons WHERE lesson_type='pdf' AND is_active=1") ?? 0;
?>

<?php if ($student):
    $firstName = explode(' ', $student['full_name'])[0];
?>
<style>
.welcome-banner {
    background: linear-gradient(135deg, #052e16, #0a5e2a);
    color: #fff;
    padding: 24px 20px;
    border-radius: 12px;
    margin: 0 auto 24px;
    max-width: 720px;
    text-align: center;
}
.welcome-banner h3 {
    font-size: 1.2rem;
    font-weight: 800;
    margin-bottom: 12px;
}
.welcome-banner .cta-group {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 12px;
}
.welcome-banner .cta-group a {
    background: rgba(255,255,255,.15);
    color: #fff;
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 700;
    font-size: .9rem;
    transition: .2s;
    border: 1px solid rgba(255,255,255,.2);
}
.welcome-banner .cta-group a:hover {
    background: rgba(255,255,255,.25);
}
.pdash {
    background: linear-gradient(145deg, #052e16, #0a5e2a, #166534);
    border-radius: 24px;
    padding: 0;
    margin-bottom: 24px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(10,94,42,.3);
    display: none;
}
.pdash-top {
    padding: 24px 22px 18px;
    position: relative;
    overflow: hidden;
}
.pdash-top::before {
    content: '';
    position: absolute;
    top: -30px; right: -30px;
    width: 160px; height: 160px;
    background: radial-gradient(circle, rgba(245,166,35,.15) 0%, transparent 70%);
    pointer-events: none;
}
.pdash-greeting {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255,255,255,.55);
    margin-bottom: 4px;
}
.pdash-name {
    font-size: 1.5rem;
    font-weight: 900;
    color: #fff;
    margin-bottom: 6px;
    letter-spacing: -.2px;
}
.pdash-motivation {
    font-size: .82rem;
    color: rgba(255,255,255,.72);
    line-height: 1.5;
    max-width: 480px;
}

/* XP BAR */
.xp-section {
    margin-top: 18px;
    padding: 14px 16px;
    background: rgba(0,0,0,.2);
    border-radius: 14px;
}
.xp-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}
.xp-label {
    font-size: .72rem;
    font-weight: 700;
    color: rgba(255,255,255,.6);
    text-transform: uppercase;
    letter-spacing: .5px;
}
.xp-level {
    font-size: .8rem;
    font-weight: 900;
    color: #f5a623;
}
.xp-bar-wrap {
    background: rgba(255,255,255,.12);
    border-radius: 20px;
    height: 10px;
    overflow: hidden;
}
.xp-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #f5a623, #fcd34d);
    border-radius: 20px;
    transition: width .8s cubic-bezier(.34,1.56,.64,1);
}
.xp-pts {
    font-size: .7rem;
    color: rgba(255,255,255,.5);
    margin-top: 5px;
    text-align: right;
}

/* STATS GRID */
.pdash-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    border-top: 1px solid rgba(255,255,255,.08);
}
.pdash-stat {
    padding: 14px 10px;
    text-align: center;
    border-right: 1px solid rgba(255,255,255,.08);
    transition: background .2s;
}
.pdash-stat:last-child { border-right: none; }
.pdash-stat:hover { background: rgba(255,255,255,.05); }
.pdash-stat .ps-num {
    font-size: 1.4rem;
    font-weight: 900;
    color: #f5a623;
    line-height: 1;
    margin-bottom: 3px;
}
.pdash-stat .ps-lbl {
    font-size: .65rem;
    font-weight: 700;
    color: rgba(255,255,255,.5);
    text-transform: uppercase;
    letter-spacing: .3px;
}

/* BADGES */
.badges-section {
    background: linear-gradient(145deg, #1a1a2e, #16213e, #0f3460);
    border-radius: 24px;
    padding: 22px 20px;
    margin-bottom: 16px;
    box-shadow: 0 12px 40px rgba(0,0,0,.25);
    position: relative;
    overflow: hidden;
}
.badges-section::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(245,166,35,.12) 0%, transparent 70%);
    pointer-events: none;
}
.badges-section::after {
    content: '';
    position: absolute;
    bottom: -40px; left: -40px;
    width: 160px; height: 160px;
    background: radial-gradient(circle, rgba(99,102,241,.1) 0%, transparent 70%);
    pointer-events: none;
}
.badges-title {
    font-size: .95rem;
    font-weight: 900;
    color: #fff;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.badges-count {
    background: rgba(245,166,35,.2);
    border: 1px solid rgba(245,166,35,.4);
    color: #fcd34d;
    padding: 2px 12px;
    border-radius: 20px;
    font-size: .72rem;
    font-weight: 800;
}
.badges-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 10px;
}
.badge-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 14px 8px 12px;
    border-radius: 16px;
    text-align: center;
    transition: transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s;
    cursor: default;
    position: relative;
}
.badge-item.earned {
    background: linear-gradient(145deg, #2d1f00, #3d2a00);
    border: 1.5px solid rgba(245,166,35,.5);
    box-shadow: 0 0 20px rgba(245,166,35,.15), inset 0 1px 0 rgba(255,255,255,.05);
}
.badge-item.earned:hover {
    transform: translateY(-5px) scale(1.08);
    box-shadow: 0 12px 30px rgba(245,166,35,.35), 0 0 0 2px rgba(245,166,35,.3);
}
.badge-item.locked {
    background: rgba(255,255,255,.04);
    border: 1.5px solid rgba(255,255,255,.07);
}
.badge-icon {
    font-size: 2.2rem;
    line-height: 1;
    filter: drop-shadow(0 2px 8px rgba(245,166,35,.4));
}
.badge-item.locked .badge-icon {
    font-size: 1.6rem;
    opacity: .25;
    filter: none;
}
.badge-name {
    font-size: .65rem;
    font-weight: 800;
    color: #fcd34d;
    line-height: 1.2;
    letter-spacing: .2px;
}
.badge-item.locked .badge-name {
    color: rgba(255,255,255,.2);
}
.badge-new {
    position: absolute;
    top: -6px; right: -6px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    font-size: .55rem;
    font-weight: 900;
    padding: 2px 6px;
    border-radius: 10px;
    text-transform: uppercase;
    letter-spacing: .3px;
    box-shadow: 0 2px 8px rgba(239,68,68,.5);
    animation: pulse-badge 1.5s ease infinite;
}
@keyframes pulse-badge {
    0%,100% { transform: scale(1); }
    50% { transform: scale(1.15); }
}
.badges-progress-bar {
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid rgba(255,255,255,.07);
}
.badges-progress-label {
    display: flex;
    justify-content: space-between;
    font-size: .7rem;
    color: rgba(255,255,255,.4);
    margin-bottom: 6px;
    font-weight: 600;
}
.badges-bar-wrap {
    background: rgba(255,255,255,.08);
    border-radius: 20px;
    height: 6px;
    overflow: hidden;
}
.badges-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #f5a623, #fcd34d);
    border-radius: 20px;
    transition: width 1s cubic-bezier(.34,1.56,.64,1);
}

/* RECENT ACTIVITY */
.recent-section {
    background: #fff;
    border-radius: 20px;
    padding: 18px 20px;
    margin-bottom: 16px;
    box-shadow: 0 3px 12px rgba(0,0,0,.06);
}
.recent-title {
    font-size: .92rem;
    font-weight: 800;
    color: #111827;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.recent-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f3f4f6;
}
.recent-item:last-child { border-bottom: none; padding-bottom: 0; }
.recent-score {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .85rem;
    font-weight: 900;
    flex-shrink: 0;
}
.recent-score.pass { background: #dcfce7; color: #166534; }
.recent-score.fail { background: #fee2e2; color: #991b1b; }
.recent-info { flex: 1; min-width: 0; }
.recent-slug {
    font-size: .85rem;
    font-weight: 700;
    color: #111827;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-transform: capitalize;
}
.recent-meta { font-size: .72rem; color: #9ca3af; margin-top: 2px; }
.recent-badge {
    font-size: .68rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    flex-shrink: 0;
}
.recent-badge.pass { background: #dcfce7; color: #166534; }
.recent-badge.fail { background: #fee2e2; color: #991b1b; }

/* CONTINUE CTA */
.continue-cta {
    background: linear-gradient(135deg, #f5a623, #e8891a);
    border-radius: 16px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 24px;
    box-shadow: 0 8px 24px rgba(245,166,35,.3);
    text-decoration: none;
    color: inherit;
    transition: transform .2s, box-shadow .2s;
}
.continue-cta:hover { transform: translateY(-3px); box-shadow: 0 14px 32px rgba(245,166,35,.4); }
.continue-cta-text .t1 { font-size: .95rem; font-weight: 900; color: #fff; margin-bottom: 2px; }
.continue-cta-text .t2 { font-size: .78rem; color: rgba(255,255,255,.85); }

@media(max-width:500px){
    .pdash-stats { grid-template-columns: repeat(2,1fr); }
    .pdash-stat { padding: 12px 8px; }
    .badges-grid { grid-template-columns: repeat(auto-fill, minmax(64px,1fr)); }
}
</style>

<!-- ══════════════ WELCOME BANNER ══════════════ -->
<div class="welcome-banner">
    <h3>👋 Welcome back, <?= e($firstName) ?>!</h3>
    <p style="margin-bottom: 0; font-size: .95rem; opacity: .95;">Pick up where you left off or start a new module.</p>
    <div class="cta-group">
        <a href="/arise/?p=dashboard">📊 My Dashboard</a>
        <a href="/arise/?p=modules">▶ Browse Modules</a>
    </div>
</div>
<!-- ══════════════ END WELCOME BANNER ══════════════ -->
<?php endif; ?>

<!-- HERO (shown to all, adapted for logged-in users) -->
<?php if (!$student): ?>
<section class="hero">
    <div class="hero-badge">🌟 Adolescent Health Education Platform</div>
    <h2>Your Health.<br><span>Your Future. Your Power.</span></h2>
    <p>Interactive lessons, videos, quizzes and certificates — all offline, in English, Kiswahili and Sheng. Built for you.</p>
    <div class="hero-btns">
        <a href="/arise/?p=modules" class="btn-hero-primary">📚 Start Learning →</a>
        <a href="/arise/?p=register" class="btn-hero-secondary">📝 Register Free</a>
    </div>
    <div class="hero-stats">
        <?php foreach([
            [$totalModules, 'Modules'],
            [$totalLessons, 'Lessons'],
            [$interactives, 'Interactive'],
            [$videos,       'Videos'],
            [$pdfs,         'PDFs'],
            [$totalCerts,   'Certificates'],
        ] as [$n,$lb]): ?>
        <div class="hero-stat">
            <div class="num"><?= $n ?></div>
            <div class="lbl"><?= $lb ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="container">

    <!-- WHAT'S INSIDE -->
    <div style="text-align:center;margin-bottom:8px;">
        <span class="badge badge-purple" style="font-size:.82rem;padding:6px 16px;">Everything You Need</span>
    </div>
    <h2 class="page-title text-center" style="margin-bottom:6px;">What's Inside ARISE</h2>
    <p class="text-center text-muted" style="margin-bottom:28px;">One platform. All your health education needs. Works offline.</p>

    <div class="features-grid">

        <!-- PHOTO CARDS -->
        <?php foreach([
            ['🎮','Interactive Lessons','10-slide lessons with scenarios, smart quizzes and instant feedback. Switch between English, Kiswahili and Sheng.','thumbnails/career-guidance.jpg'],
            ['🎬','Video Lessons','Watch health education videos uploaded by your teacher. Plays fully online.','thumbnails/life-skills.jpg'],
            ['📄','PDF Resources','Reference documents, notes and study guides. Read anytime, anywhere.','thumbnails/life-skills.jpg'],
            ['🔒','Ask Anonymously','Too shy to ask in person? Submit health questions anonymously. Educators answer privately.','thumbnails/Cigarette.jpg'],
            ['💬','Community Forum','Share ideas, ask questions, and support classmates in the open discussion board.','thumbnails/healthy.jpg'],
        ] as [$ic,$t,$d,$img]): ?>
        <div class="feature-card feat-photo" style="background-image:url('/arise/uploads/<?= $img ?>');">
            <div class="feat-overlay"></div>
            <div class="feat-content">
                <div class="feat-icon-sm"><?= $ic ?></div>
                <h3><?= $t ?></h3>
                <p><?= $d ?></p>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- PLAIN EMOJI CARDS -->
        <?php foreach([
            ['📝','Built-in Quizzes','MCQ, fill-in-blanks, and short answer questions with auto-grading and explanations.'],
            ['🎓','Earn Certificates','Score 60%+ on a module and earn a printable certificate with a unique verification code.'],
            ['🌍','3 Languages','Every interactive lesson toggles between English, Kiswahili and Sheng with one tap.'],
        ] as [$ic,$t,$d]): ?>
        <div class="feature-card">
            <div class="feat-icon"><?= $ic ?></div>
            <h3><?= $t ?></h3>
            <p><?= $d ?></p>
        </div>
        <?php endforeach; ?>

    </div>

    <style>
    .feat-photo {
        position: relative;
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        padding: 0 !important;
        min-height: 220px;
        border-radius: 16px;
        overflow: hidden;
        border: none !important;
        box-shadow: 0 4px 20px rgba(0,0,0,0.18);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .feat-photo:hover {
        transform: translateY(-6px);
        box-shadow: 0 12px 32px rgba(0,0,0,0.28);
    }
    .feat-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(160deg, rgba(10,94,42,0.82) 0%, rgba(10,94,42,0.65) 50%, rgba(0,0,0,0.75) 100%);
        border-radius: 16px;
    }
    .feat-content {
        position: relative;
        z-index: 2;
        padding: 24px 20px;
        color: #fff;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        min-height: 220px;
    }
    .feat-icon-sm {
        font-size: 2rem;
        margin-bottom: 10px;
        filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4));
    }
    .feat-content h3 {
        font-size: 1.05rem;
        font-weight: 800;
        margin: 0 0 8px 0;
        color: #fff;
        text-shadow: 0 1px 4px rgba(0,0,0,0.5);
    }
    .feat-content p {
        font-size: .82rem;
        color: rgba(255,255,255,0.88);
        margin: 0;
        line-height: 1.5;
        text-shadow: 0 1px 3px rgba(0,0,0,0.4);
    }
    </style>

    <!-- HOW IT WORKS -->
    <div class="dp-card mt-3" style="background:linear-gradient(135deg,var(--green-light),var(--orange-pale));border:2px solid var(--border);">
        <h2 class="section-title text-center" style="margin-bottom:24px;font-size:1.3rem;">✨ How It Works</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:20px;text-align:center;">
            <?php foreach([
                ['1️⃣','Register','Name, school, class — 10 seconds. No password needed.'],
                ['2️⃣','Browse Modules','Pick any health topic that interests you.'],
                ['3️⃣','Open a Lesson','Interactive, video, or PDF — all in one place.'],
                ['4️⃣','Take the Quiz','Instant feedback on every answer with explanations.'],
                ['5️⃣','Get Certified','Score 60%+ and earn your printable certificate.'],
            ] as [$n,$t,$d]): ?>
            <div>
                <div style="font-size:1.9rem;margin-bottom:8px;"><?= $n ?></div>
                <div style="font-weight:800;font-size:.92rem;margin-bottom:4px;color:var(--green-dark);"><?= $t ?></div>
                <div style="font-size:.8rem;color:var(--mid);"><?= $d ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>


    <!-- ABOUT -->
    <div class="dp-card mt-3" style="text-align:center;border:2px solid var(--green-light);background:linear-gradient(135deg,#fff,var(--green-light));">
        <div style="font-size:3rem;margin-bottom:12px;">💜</div>
        <h2 class="section-title" style="font-size:1.3rem;margin-bottom:6px;">About ARISE</h2>
        <p class="gradient-text" style="font-size:1rem;font-weight:800;margin-bottom:10px;font-style:normal;">
            Adolescent Reproductive Health Information Support and Empowerment
        </p>
        <p class="text-muted" style="max-width:600px;margin:0 auto 20px;line-height:1.7;">
            ARISE delivers comprehensive health education to adolescents through interactive digital lessons — designed for Kenyan students, available in three languages, working fully offline on any device.
        </p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <a href="/arise/?p=modules" class="btn btn-primary">📚 Browse Modules</a>
            <a href="/arise/?p=forum" class="btn btn-secondary">💬 Join the Forum</a>
            <a href="/arise/?p=ask" class="btn btn-secondary">🔒 Ask Anonymously</a>
        </div>
    </div>

</div>
