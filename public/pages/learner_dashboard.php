<?php
/**
 * Learner Dashboard — rich student home page
 * Route: ?p=dashboard
 */
$student = getStudentBySession();
if (!$student) { header('Location: /arise/?p=login'); exit; }
$sid = $student['id'];

// Notifications (temporarily disabled due to schema mismatch)
$notifs = [];

// ── XP data (mirrored from my_progress.php) ────────────────────────────────
$xp = db()->querySingle("SELECT * FROM student_xp WHERE student_id=$sid", true);
if (!$xp) {
    $stmt = db()->prepare('INSERT OR IGNORE INTO student_xp (student_id,xp_points,level,streak_days) VALUES (:s,0,1,0)');
    $stmt->bindValue(':s', $sid);
    $stmt->execute();
    $xp = ['xp_points' => 0, 'level' => 1, 'streak_days' => 0,
           'total_lessons_completed' => 0, 'total_quizzes_passed' => 0];
}

$xpPoints    = intval($xp['xp_points'] ?? 0);
$level       = intval($xp['level'] ?? 1);
$streakDays  = intval($xp['streak_days'] ?? 0);
$xpForNext   = $level * 200;
$xpThisLevel = $xpPoints % max(1, $xpForNext);
$xpPct       = min(100, round($xpThisLevel / $xpForNext * 100));

// ── Progress stats ──────────────────────────────────────────────────────────
$modulesStarted = db()->querySingle(
    "SELECT COUNT(DISTINCT l.module_id)
     FROM lesson_progress lp
     JOIN lessons l ON l.id = lp.lesson_id
     WHERE lp.student_id=$sid"
) ?? 0;

$lessonsCompleted = db()->querySingle(
    "SELECT COUNT(*) FROM lesson_progress
     WHERE student_id=$sid AND completed=1"
) ?? 0;

$quizAttempts = db()->querySingle(
    "SELECT COUNT(*) FROM quiz_attempts WHERE student_id=$sid"
) ?? 0;

$avgScore = db()->querySingle(
    "SELECT ROUND(AVG(percentage),1) FROM quiz_attempts WHERE student_id=$sid"
) ?? 0;

$certCount = db()->querySingle(
    "SELECT COUNT(*) FROM certificates WHERE student_id=$sid"
) ?? 0;

// ── Last seen (days ago) ────────────────────────────────────────────────────
$lastSeenStr = $student['last_seen'] ?? null;
if ($lastSeenStr) {
    $diff = (int) round((time() - strtotime($lastSeenStr)) / 86400);
    $lastSeenLabel = $diff === 0 ? 'today' : ($diff === 1 ? '1 day ago' : "$diff days ago");
} else {
    $lastSeenLabel = 'first visit';
}

// ── First name ──────────────────────────────────────────────────────────────
$firstName = explode(' ', trim($student['full_name']))[0];

// ── Module progress ─────────────────────────────────────────────────────────
$moduleProgress = [];
$mpRes = db()->query(
    "SELECT m.id, m.title, m.icon, m.slug,
            COUNT(DISTINCT l.id)  AS total_lessons,
            COUNT(DISTINCT lp.id) AS completed_lessons
     FROM modules m
     LEFT JOIN lessons l  ON l.module_id = m.id AND l.is_active = 1
     LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = $sid AND lp.completed = 1
     WHERE m.is_active = 1
     GROUP BY m.id
     ORDER BY completed_lessons DESC, m.sort_order"
);
while ($r = $mpRes->fetchArray(SQLITE3_ASSOC)) {
    $moduleProgress[] = $r;
}

// Which modules have a quiz?
$modulesWithQuiz = [];
$mqRes = db()->query("SELECT DISTINCT module_id FROM quiz_questions");
while ($r = $mqRes->fetchArray(SQLITE3_ASSOC)) {
    $modulesWithQuiz[] = $r['module_id'];
}

// ── Recent quiz scores (last 5) ─────────────────────────────────────────────
$recentQuizzes = [];
$rqRes = db()->query(
    "SELECT qa.percentage, qa.completed_at, m.title, m.icon
     FROM quiz_attempts qa
     JOIN modules m ON qa.module_id = m.id
     WHERE qa.student_id = $sid
     ORDER BY qa.completed_at DESC
     LIMIT 5"
);
while ($r = $rqRes->fetchArray(SQLITE3_ASSOC)) {
    $recentQuizzes[] = $r;
}

// ── Certificates ─────────────────────────────────────────────────────────────
$certs = [];
$cStmt = db()->prepare(
    "SELECT c.*, m.title AS module_title, m.icon, m.slug AS module_slug
     FROM certificates c
     JOIN modules m ON c.module_id = m.id
     WHERE c.student_id = :sid
     ORDER BY c.issued_at DESC"
);
$cStmt->bindValue(':sid', $sid);
$cRes = $cStmt->execute();
while ($r = $cRes->fetchArray(SQLITE3_ASSOC)) {
    $certs[] = $r;
}

// ── XP Leaderboard (top 10 active learners by XP) ──────────────────────────
$leaderboard = [];
$lbRes = db()->query(
    "SELECT s.id, x.xp_points, x.level
     FROM student_xp x
     JOIN students s ON s.id = x.student_id
     WHERE s.is_active = 1
     ORDER BY x.xp_points DESC
     LIMIT 10"
);
$lbRank = 1;
$myLeaderboardRank = null;
while ($r = $lbRes->fetchArray(SQLITE3_ASSOC)) {
    if (intval($r['id']) === intval($sid)) {
        $myLeaderboardRank = $lbRank;
    }
    $leaderboard[] = array_merge($r, ['rank' => $lbRank]);
    $lbRank++;
}

// ── Next Step smart action ──────────────────────────────────────────────────
$nextAction = null;
$postTestReady = db()->querySingle(
    "SELECT m.id, m.title, m.icon, m.slug FROM modules m WHERE m.is_active=1
     AND (SELECT COUNT(*) FROM pretest_attempts WHERE student_id=$sid AND module_id=m.id AND test_type='pre')>0
     AND (SELECT COUNT(*) FROM pretest_attempts WHERE student_id=$sid AND module_id=m.id AND test_type='post')=0
     ORDER BY m.sort_order LIMIT 1", true
);
if ($postTestReady) {
    $nextAction = ['type'=>'post_test','module'=>$postTestReady];
} else {
    $inProgress = db()->querySingle(
        "SELECT m.id, m.title, m.icon, m.slug FROM modules m WHERE m.is_active=1
         AND (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id WHERE lp.student_id=$sid AND l.module_id=m.id AND lp.completed=1) > 0
         AND (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id WHERE lp.student_id=$sid AND l.module_id=m.id AND lp.completed=1) < (SELECT COUNT(*) FROM lessons l2 WHERE l2.module_id=m.id AND l2.is_active=1)
         ORDER BY m.sort_order LIMIT 1", true
    );
    if ($inProgress) {
        $nextAction = ['type'=>'continue','module'=>$inProgress];
    } else {
        $unstarted = db()->querySingle(
            "SELECT m.id, m.title, m.icon, m.slug FROM modules m WHERE m.is_active=1
             AND (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id=lp.lesson_id WHERE lp.student_id=$sid AND l.module_id=m.id)=0
             ORDER BY m.sort_order LIMIT 1", true
        );
        if ($unstarted) $nextAction = ['type'=>'start','module'=>$unstarted];
    }
}

// ── Pre vs Post improvement ─────────────────────────────────────────────────
$testComparison = [];
$tcRes = db()->query(
    "SELECT m.title, m.icon, m.slug,
            MAX(CASE WHEN pa.test_type='pre'  THEN pa.percentage END) AS pre_score,
            MAX(CASE WHEN pa.test_type='post' THEN pa.percentage END) AS post_score
     FROM pretest_attempts pa JOIN modules m ON m.id=pa.module_id
     WHERE pa.student_id=$sid
     GROUP BY pa.module_id
     HAVING pre_score IS NOT NULL OR post_score IS NOT NULL
     ORDER BY m.sort_order"
);
while ($r = $tcRes->fetchArray(SQLITE3_ASSOC)) $testComparison[] = $r;

// ── Pregnancy prevention progress ──────────────────────────────────────────
$pgTotal = (int)(db()->querySingle("SELECT COUNT(DISTINCT module_id) FROM quiz_questions WHERE competency='Pregnancy Prevention' AND section='post' AND is_published=1") ?? 0);
$pgDone  = (int)(db()->querySingle("SELECT COUNT(DISTINCT pa.module_id) FROM pretest_attempts pa JOIN quiz_questions qq ON qq.module_id=pa.module_id WHERE pa.student_id=$sid AND pa.test_type='post' AND qq.competency='Pregnancy Prevention'") ?? 0);
$pgPct   = $pgTotal > 0 ? round($pgDone / $pgTotal * 100) : 0;

trackPageView('dashboard');
?>

<style>
/* ── Dashboard variables ── */
:root{--dash-radius:14px;}

/* Hero */
.dash-hero{
    background:linear-gradient(135deg,var(--green-deeper),var(--pri),var(--rose));
    color:#fff;
    padding:28px 24px;
    border-radius:var(--dash-radius);
    margin-bottom:24px;
    position:relative;
    overflow:hidden;
}
.dash-hero::after{
    content:'💜';
    position:absolute;right:-18px;bottom:-28px;
    font-size:110px;opacity:.08;line-height:1;pointer-events:none;
}
.dash-welcome{font-size:1.45rem;font-weight:900;margin:0 0 4px;}
.dash-sub{font-size:.85rem;opacity:.8;margin:0;}
.dash-meta{font-size:.78rem;opacity:.6;margin-top:6px;}

/* Level pill */
.level-pill{
    display:inline-flex;align-items:center;gap:5px;
    background:var(--acc);color:var(--green-deeper);
    font-size:.75rem;font-weight:800;
    padding:4px 10px;border-radius:50px;
    margin-right:4px;
}
/* XP bar */
.dash-xp-bar-outer{background:rgba(255,255,255,.22);border-radius:50px;height:8px;overflow:hidden;margin:12px 0 4px;}
.dash-xp-bar-inner{background:linear-gradient(90deg,var(--acc),#f59e0b);height:100%;border-radius:50px;transition:width .8s var(--ease,ease);}

/* Stat mini-cards */
.stat-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(140px,1fr));
    gap:12px;
    margin-bottom:24px;
}
.stat-card{
    background:#fff;
    border-radius:var(--dash-radius);
    padding:18px 12px;
    text-align:center;
    box-shadow:0 2px 8px rgba(0,0,0,.06);
    border:1.5px solid var(--border,#e5e7eb);
}
.stat-card .s-val{font-size:2rem;font-weight:900;color:var(--pri,#4f46e5);line-height:1.1;}
.stat-card .s-lbl{font-size:.68rem;color:var(--mid,#6b7280);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-top:4px;}

/* Module progress cards */
.mod-card{
    background:#fff;
    border-radius:var(--dash-radius);
    border:1.5px solid var(--border,#e5e7eb);
    padding:16px;
    margin-bottom:12px;
    display:flex;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
}
.mod-icon{font-size:1.8rem;flex-shrink:0;width:44px;text-align:center;}
.mod-info{flex:1;min-width:160px;}
.mod-title{font-weight:700;font-size:.95rem;margin-bottom:6px;color:var(--dark,#111827);}
.prog-bar-outer{background:var(--border,#e5e7eb);border-radius:50px;height:8px;overflow:hidden;margin-bottom:4px;}
.prog-bar-inner{background:linear-gradient(90deg,var(--green,#16a34a),var(--pri,#4f46e5));height:100%;border-radius:50px;transition:width .6s ease;}
.prog-label{font-size:.7rem;color:var(--mid,#6b7280);font-weight:600;}

/* Quiz table */
.quiz-table{width:100%;border-collapse:collapse;}
.quiz-table th{font-size:.72rem;text-transform:uppercase;color:var(--mid,#6b7280);font-weight:700;letter-spacing:.4px;padding:8px 10px;border-bottom:2px solid var(--border,#e5e7eb);text-align:left;}
.quiz-table td{padding:10px;font-size:.88rem;border-bottom:1px solid var(--border,#e5e7eb);}
.quiz-table tr:last-child td{border-bottom:none;}

/* Cert cards */
.cert-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;}
.cert-card{
    background:#fff;
    border-radius:var(--dash-radius);
    border-top:4px solid var(--green,#16a34a);
    border-left:1.5px solid var(--border,#e5e7eb);
    border-right:1.5px solid var(--border,#e5e7eb);
    border-bottom:1.5px solid var(--border,#e5e7eb);
    padding:18px;
    text-align:center;
}
.cert-card .c-icon{font-size:2rem;margin-bottom:6px;}
.cert-card .c-title{font-weight:700;font-size:.93rem;margin-bottom:4px;}
.cert-card .c-score{font-size:1.6rem;font-weight:900;color:var(--green,#16a34a);}
.cert-card .c-date{font-size:.72rem;color:var(--mid,#6b7280);margin:4px 0 12px;}

/* Streak banner */
.streak-banner{
    background:linear-gradient(135deg,#ff6b35,#f59e0b);
    color:#fff;
    border-radius:var(--dash-radius);
    padding:18px 22px;
    display:flex;
    align-items:center;
    gap:14px;
    margin-bottom:24px;
}
.streak-flame{font-size:2.4rem;line-height:1;}
.streak-num{font-size:1.8rem;font-weight:900;line-height:1;}
.streak-lbl{font-size:.8rem;opacity:.9;font-weight:600;}

/* Empty states */
.empty-state{text-align:center;padding:28px 16px;color:var(--mid,#6b7280);font-size:.88rem;}
.empty-state .e-icon{font-size:2.2rem;margin-bottom:8px;}

/* Next Step card */
.next-step-card{
    background:linear-gradient(135deg,#0f4c35,#0ea271);
    color:#fff;border-radius:var(--dash-radius);
    padding:20px 22px;margin-bottom:24px;
    display:flex;align-items:center;gap:18px;flex-wrap:wrap;
    box-shadow:0 4px 20px rgba(14,162,113,.3);
}
.next-step-icon{font-size:2.4rem;flex-shrink:0;}
.next-step-body{flex:1;min-width:160px;}
.next-step-label{font-size:.7rem;font-weight:800;opacity:.75;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;}
.next-step-title{font-size:1.05rem;font-weight:900;margin-bottom:6px;}
.next-step-sub{font-size:.82rem;opacity:.85;}
.next-step-btn{background:#fff;color:#0f4c35;font-weight:800;font-size:.85rem;padding:9px 18px;border-radius:8px;text-decoration:none;flex-shrink:0;white-space:nowrap;}

/* Pregnancy progress bar */
.pg-prog-wrap{background:#fff;border-radius:var(--dash-radius);border:1.5px solid #fca5a5;padding:18px 20px;margin-bottom:24px;}
.pg-prog-bar-outer{background:#fee2e2;border-radius:50px;height:12px;overflow:hidden;margin:10px 0 6px;}
.pg-prog-bar-inner{background:linear-gradient(90deg,#dc2626,#991b1b);height:100%;border-radius:50px;transition:width .8s ease;}

/* Improvement table */
.imp-table{width:100%;border-collapse:collapse;}
.imp-table th{font-size:.7rem;text-transform:uppercase;color:var(--mid,#6b7280);font-weight:700;letter-spacing:.4px;padding:8px 10px;border-bottom:2px solid var(--border,#e5e7eb);text-align:left;}
.imp-table td{padding:10px;font-size:.88rem;border-bottom:1px solid var(--border,#e5e7eb);}
.imp-table tr:last-child td{border-bottom:none;}
.score-pill{display:inline-block;padding:3px 9px;border-radius:50px;font-size:.78rem;font-weight:800;}
.score-pill.green{background:#dcfce7;color:#166534;}
.score-pill.red{background:#fee2e2;color:#991b1b;}
.score-pill.grey{background:#f3f4f6;color:#6b7280;}
.imp-arrow{font-size:1rem;color:#9ca3af;margin:0 4px;}
.imp-gain{font-size:.82rem;font-weight:800;}
.imp-gain.pos{color:#16a34a;}.imp-gain.neg{color:#dc2626;}.imp-gain.neu{color:#6b7280;}

/* ── Leaderboard ── */
.lb-table{width:100%;border-collapse:collapse;}
.lb-table th{
    font-size:.7rem;text-transform:uppercase;color:var(--mid,#6b7280);
    font-weight:700;letter-spacing:.5px;
    padding:8px 12px;border-bottom:2px solid var(--border,#e5e7eb);text-align:left;
}
.lb-table td{padding:10px 12px;font-size:.88rem;border-bottom:1px solid var(--border,#e5e7eb);}
.lb-table tr:last-child td{border-bottom:none;}
.lb-table tr.lb-me td{
    background:linear-gradient(90deg,#ede9fe,#f5f3ff);
    font-weight:700;
}
.lb-rank{
    font-size:1.1rem;font-weight:900;
    display:inline-block;width:28px;text-align:center;
}
.lb-xp{
    font-weight:800;color:var(--pri,#4f46e5);
    font-variant-numeric:tabular-nums;
}
.lb-level{
    display:inline-flex;align-items:center;gap:3px;
    background:#ede9fe;color:#5b21b6;
    font-size:.7rem;font-weight:800;
    padding:2px 8px;border-radius:50px;
}
</style>

<div class="container">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="/arise/">Home</a> <span class="sep">›</span>
        <span>My Dashboard</span>
    </div>

    <!-- Notification banners -->
    <?php if (!empty($notifs)): foreach ($notifs as $n): ?>
    <div class="notif-banner">
        <div class="notif-banner-icon"><?= $n['type'] === 'essay' ? '📝' : '💬' ?></div>
        <div class="notif-banner-body">
            <div class="notif-banner-title"><?= $n['type'] === 'essay' ? 'Essay Feedback Received!' : 'Your Question Was Answered!' ?></div>
            <div class="notif-banner-text">
                <?= htmlspecialchars($n['text'] ?? '') ?>
                <?= isset($n['score']) && $n['score'] ? ' — Score: ' . $n['score'] . '%' : '' ?>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- ── Hero ───────────────────────────────────────────────────── -->
    <div class="dash-hero">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:14px;">
            <div>
                <p class="dash-welcome">👋 Welcome back, <?= e($firstName) ?>!</p>
                <p class="dash-sub">
                    <?= e($student['class_name'] ?? '') ?>
                    <?= !empty($student['school_name']) ? ' &middot; ' . e($student['school_name']) : '' ?>
                </p>
                <p class="dash-meta">Last seen: <?= $lastSeenLabel ?></p>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                <span class="level-pill">⚡ Level <?= $level ?></span>
                <?php if ($streakDays > 0): ?>
                    <span class="level-pill" style="background:#f59e0b;">🔥 <?= $streakDays ?>-day streak</span>
                <?php endif; ?>
                <?php if ($certCount > 0): ?>
                    <span class="level-pill" style="background:#10b981;">🎓 <?= $certCount ?> cert<?= $certCount !== 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </div>
        </div>
        <!-- XP bar -->
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;">
            <span style="font-size:.78rem;opacity:.85;font-weight:700;">
                <?= number_format($xpPoints) ?> XP &nbsp;&bull;&nbsp; Level <?= $level ?> &rarr; <?= $level + 1 ?>
            </span>
            <span style="font-size:.72rem;opacity:.65;"><?= $xpThisLevel ?>/<?= $xpForNext ?> XP</span>
        </div>
        <div class="dash-xp-bar-outer">
            <div class="dash-xp-bar-inner" style="width:<?= $xpPct ?>%"></div>
        </div>
        <div style="font-size:.7rem;opacity:.6;"><?= $xpPct ?>% to next level</div>
    </div>

    <!-- ── Next Step ────────────────────────────────────────────── -->
    <?php if ($nextAction): ?>
    <?php
        $na = $nextAction;
        $nm = $na['module'];
        if ($na['type'] === 'post_test') {
            $nsLabel = 'Ready for Post-Test';
            $nsTitle = htmlspecialchars($nm['icon']??'📘') . ' ' . e($nm['title']);
            $nsSub   = 'You have completed your lessons. Take your post-test now to earn your certificate.';
            $nsHref  = '/arise/?p=module&slug=' . urlencode($nm['slug']);
            $nsBtn   = '🧠 Take Post-Test';
        } elseif ($na['type'] === 'continue') {
            $nsLabel = 'Continue Learning';
            $nsTitle = htmlspecialchars($nm['icon']??'📘') . ' ' . e($nm['title']);
            $nsSub   = 'You are in the middle of this module — pick up where you left off.';
            $nsHref  = '/arise/?p=module&slug=' . urlencode($nm['slug']);
            $nsBtn   = '▶ Continue Module';
        } else {
            $nsLabel = 'Start Your Journey';
            $nsTitle = htmlspecialchars($nm['icon']??'📘') . ' ' . e($nm['title']);
            $nsSub   = 'Begin your first lesson in this module.';
            $nsHref  = '/arise/?p=module&slug=' . urlencode($nm['slug']);
            $nsBtn   = '🚀 Start Module';
        }
    ?>
    <div class="next-step-card">
        <div class="next-step-icon">🎯</div>
        <div class="next-step-body">
            <div class="next-step-label"><?= $nsLabel ?></div>
            <div class="next-step-title"><?= $nsTitle ?></div>
            <div class="next-step-sub"><?= $nsSub ?></div>
        </div>
        <a href="<?= $nsHref ?>" class="next-step-btn"><?= $nsBtn ?></a>
    </div>
    <?php endif; ?>

    <!-- ── Learning Streak (prominent if active) ─────────────────── -->
    <?php if ($streakDays > 0): ?>
    <div class="streak-banner">
        <div class="streak-flame">🔥</div>
        <div>
            <div class="streak-num"><?= $streakDays ?> day<?= $streakDays !== 1 ? 's' : '' ?></div>
            <div class="streak-lbl">Learning Streak — keep it going!</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Progress Overview ──────────────────────────────────────── -->
    <h2 class="section-title">📊 Progress Overview</h2>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="s-val"><?= $modulesStarted ?></div>
            <div class="s-lbl">Modules Started</div>
        </div>
        <div class="stat-card">
            <div class="s-val"><?= $lessonsCompleted ?></div>
            <div class="s-lbl">Lessons Completed</div>
        </div>
        <div class="stat-card">
            <div class="s-val"><?= $quizAttempts ?></div>
            <div class="s-lbl">Quiz Attempts</div>
        </div>
        <div class="stat-card">
            <div class="s-val"><?= $avgScore > 0 ? $avgScore . '%' : '—' ?></div>
            <div class="s-lbl">Avg Quiz Score</div>
        </div>
    </div>


    <!-- ── Pregnancy Prevention Progress ───────────────────────── -->
    <div class="pg-prog-wrap">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span style="font-size:1.6rem;">🛡️</span>
            <div style="flex:1;">
                <div style="font-size:.75rem;font-weight:800;color:#991b1b;text-transform:uppercase;letter-spacing:.05em;">Pregnancy Prevention Journey</div>
                <div style="font-size:.88rem;color:#374151;margin-top:2px;">
                    You have completed post-tests covering pregnancy prevention in
                    <strong><?= $pgDone ?> of <?= $pgTotal ?> modules</strong>.
                </div>
            </div>
            <div style="font-size:1.6rem;font-weight:900;color:#dc2626;"><?= $pgPct ?>%</div>
        </div>
        <div class="pg-prog-bar-outer">
            <div class="pg-prog-bar-inner" style="width:<?= $pgPct ?>%"></div>
        </div>
        <div style="font-size:.72rem;color:#9ca3af;">Every module connects to protecting your health and future — <?= $pgTotal - $pgDone ?> module<?= ($pgTotal-$pgDone)!==1?'s':'' ?> remaining.</div>
    </div>

    <!-- ── Module Progress ───────────────────────────────────────── -->
    <div class="dp-card" style="margin-bottom:24px;">
        <h2 class="section-title" style="margin-bottom:16px;">📚 Module Progress</h2>

        <?php if (empty($moduleProgress)): ?>
            <div class="empty-state">
                <div class="e-icon">📖</div>
                <p>No modules available yet.</p>
                <a href="/arise/?p=modules" class="btn btn-primary" style="margin-top:8px;">Browse Modules</a>
            </div>
        <?php else: ?>
            <?php foreach ($moduleProgress as $mod):
                $total     = intval($mod['total_lessons']);
                $completed = intval($mod['completed_lessons']);
                $pct       = $total > 0 ? min(100, round($completed / $total * 100)) : 0;
                $allDone   = $total > 0 && $completed >= $total;
                $hasQuiz   = in_array($mod['id'], $modulesWithQuiz);
            ?>
            <div class="mod-card">
                <div class="mod-icon"><?= htmlspecialchars($mod['icon'] ?? '📘') ?></div>
                <div class="mod-info">
                    <div class="mod-title"><?= e($mod['title']) ?></div>
                    <div class="prog-bar-outer">
                        <div class="prog-bar-inner" style="width:<?= $pct ?>%"></div>
                    </div>
                    <div class="prog-label">
                        <?= $completed ?>/<?= $total ?> lessons &nbsp;&bull;&nbsp; <?= $pct ?>% complete
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap;">
                    <a href="/arise/?p=module&slug=<?= urlencode($mod['slug']) ?>"
                       class="btn btn-secondary" style="font-size:.78rem;padding:6px 12px;">
                        <?= $allDone ? '🔄 Review' : '▶ Continue' ?>
                    </a>
                    <?php if ($allDone && $hasQuiz): ?>
                    <a href="/arise/?p=quiz&module=<?= urlencode($mod['slug']) ?>"
                       class="btn btn-primary" style="font-size:.78rem;padding:6px 12px;">
                        🧠 Take Quiz
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── Recent Quiz Scores ────────────────────────────────────── -->
    <div class="dp-card" style="margin-bottom:24px;">
        <h2 class="section-title" style="margin-bottom:14px;">🧠 Recent Quiz Scores</h2>
        <?php if (!empty($recentQuizzes)): ?>
        <div style="overflow-x:auto;">
            <table class="quiz-table arise-table">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>Score</th>
                        <th>Result</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentQuizzes as $q):
                        $pass = $q['percentage'] >= 60;
                    ?>
                    <tr>
                        <td style="font-weight:600;">
                            <?= htmlspecialchars($q['icon'] ?? '') ?>
                            <?= e($q['title']) ?>
                        </td>
                        <td style="font-weight:800;color:<?= $pass ? 'var(--green,#16a34a)' : 'var(--rose,#e11d48)' ?>;">
                            <?= $q['percentage'] ?>%
                        </td>
                        <td>
                            <span class="badge <?= $pass ? 'badge-green' : 'badge-red' ?>">
                                <?= $pass ? 'Passed' : 'Failed' ?>
                            </span>
                        </td>
                        <td style="font-size:.78rem;color:var(--mid,#6b7280);">
                            <?= date('M j, Y', strtotime($q['completed_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="e-icon">🎯</div>
            <p>Take your first quiz to see your scores here!</p>
            <p style="font-size:.85rem;color:var(--mid);margin-top:4px;">Complete a lesson and take the quiz to start tracking your progress.</p>
            <a href="/arise/?p=modules" class="btn btn-primary" style="margin-top:10px;">📚 Browse Modules</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Certificates Earned ───────────────────────────────────── -->
    <div class="dp-card" style="margin-bottom:24px;">
        <h2 class="section-title" style="margin-bottom:16px;">🎓 Certificates Earned</h2>

        <?php if (empty($certs)): ?>
            <div class="empty-state">
                <div class="e-icon">🏆</div>
                <p>No certificates yet. Complete a module quiz (score 70%+) to earn one!</p>
                <a href="/arise/?p=modules" class="btn btn-primary" style="margin-top:10px;">📚 Start a Module</a>
            </div>
        <?php else: ?>
            <div class="cert-grid">
                <?php foreach ($certs as $c): ?>
                <div class="cert-card">
                    <div class="c-icon"><?= htmlspecialchars($c['icon'] ?? '🎓') ?></div>
                    <div class="c-title"><?= e($c['module_title']) ?></div>
                    <div class="c-score"><?= intval($c['percentage']) ?>%</div>
                    <div class="c-date">
                        Issued <?= date('M j, Y', strtotime($c['issued_at'])) ?><br>
                        <span style="font-family:monospace;font-size:.7rem;"><?= e($c['cert_number'] ?? '') ?></span>
                    </div>
                    <a href="/arise/?p=certificate&module=<?= urlencode($c['module_slug']) ?>&score=<?= intval($c['percentage']) ?>"
                       class="btn btn-primary" style="font-size:.78rem;padding:7px 14px;" target="_blank">
                        🖨️ View Certificate
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Pre vs Post Improvement ─────────────────────────────── -->
    <?php if (!empty($testComparison)): ?>
    <div class="dp-card" style="margin-bottom:24px;">
        <h2 class="section-title" style="margin-bottom:4px;">📈 Your Growth — Pre vs Post Test</h2>
        <p style="font-size:.8rem;color:var(--mid);margin-bottom:14px;">How much you improved from before to after each module.</p>
        <div style="overflow-x:auto;">
            <table class="imp-table">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>Pre-Test</th>
                        <th></th>
                        <th>Post-Test</th>
                        <th>Growth</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($testComparison as $tc):
                    $pre  = $tc['pre_score']  !== null ? intval($tc['pre_score'])  : null;
                    $post = $tc['post_score'] !== null ? intval($tc['post_score']) : null;
                    $gain = ($pre !== null && $post !== null) ? ($post - $pre) : null;
                ?>
                <tr>
                    <td style="font-weight:600;">
                        <?= htmlspecialchars($tc['icon']??'📘') ?> <?= e($tc['title']) ?>
                    </td>
                    <td>
                        <?php if ($pre !== null): ?>
                            <span class="score-pill <?= $pre>=60?'green':'red' ?>"><?= $pre ?>%</span>
                        <?php else: ?>
                            <span class="score-pill grey">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td class="imp-arrow">→</td>
                    <td>
                        <?php if ($post !== null): ?>
                            <span class="score-pill <?= $post>=60?'green':'red' ?>"><?= $post ?>%</span>
                        <?php else: ?>
                            <span class="score-pill grey">Not yet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($gain !== null): ?>
                            <span class="imp-gain <?= $gain>0?'pos':($gain<0?'neg':'neu') ?>">
                                <?= $gain>0?'+':'' ?><?= $gain ?>%
                                <?= $gain>0?'⬆':'';?><?= $gain<0?'⬇':'';?><?= $gain===0?'➡':'';?>
                            </span>
                        <?php else: ?>
                            <span style="color:#9ca3af;font-size:.8rem;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── XP Leaderboard ───────────────────────────────────────── -->
    <?php if (!empty($leaderboard)): ?>
    <div class="dp-card" style="margin-bottom:24px;">
        <h2 class="section-title" style="margin-bottom:4px;">🏆 XP Leaderboard</h2>
        <p style="font-size:.8rem;color:var(--mid);margin-bottom:14px;">
            Top learners by XP.<?= $myLeaderboardRank ? ' You are ranked <strong>#' . $myLeaderboardRank . '</strong>.' : ' Keep going to reach the top 10!' ?>
        </p>
        <table class="lb-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Learner</th>
                    <th>Level</th>
                    <th>XP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leaderboard as $lb):
                $isMe = intval($lb['id']) === intval($sid);
                $medal = $lb['rank']===1?'🥇':($lb['rank']===2?'🥈':($lb['rank']===3?'🥉':''));
            ?>
            <tr class="<?= $isMe?'lb-me':'' ?>">
                <td><span class="lb-rank"><?= $medal ?: '#'.$lb['rank'] ?></span></td>
                <td><?= $isMe ? '<strong>You</strong>' : 'Learner #' . intval($lb['id']) ?></td>
                <td><span class="lb-level">⚡ Lvl <?= $lb['level'] ?></span></td>
                <td class="lb-xp"><?= number_format($lb['xp_points']) ?> XP</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$myLeaderboardRank): ?>
        <p style="font-size:.78rem;color:var(--mid);margin-top:10px;text-align:center;">
            Complete lessons and quizzes to earn XP and appear on the leaderboard.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>
<!-- End .container -->
