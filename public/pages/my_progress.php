<?php
/**
 * Student Progress & Gamification Page
 */
$student = getStudentBySession();
$notifs = $student ? getStudentNotifications($student['id']) : [];
if ($student && $notifs) markNotificationsRead($student['id']);
if (!$student) {
    echo '<div class="container"><div class="alert alert-info">Please <a href="/arise/?p=register">register</a> to see your progress.</div></div>';
    return;
}

$sid = $student['id'];

// XP data
$xp = db()->querySingle("SELECT * FROM student_xp WHERE student_id=$sid", true);
if (!$xp) {
    // Init XP row
    $stmt = db()->prepare('INSERT OR IGNORE INTO student_xp (student_id,xp_points,level,streak_days) VALUES (:s,0,1,0)');
    $stmt->bindValue(':s',$sid); $stmt->execute();
    $xp = ['xp_points'=>0,'level'=>1,'streak_days'=>0,'total_lessons_completed'=>0,'total_quizzes_passed'=>0];
}

// Stats
$quizCount   = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts WHERE student_id=$sid") ?? 0;
$passCount   = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts WHERE student_id=$sid AND percentage>=60") ?? 0;
$avgScore    = db()->querySingle("SELECT ROUND(AVG(percentage),1) FROM quiz_attempts WHERE student_id=$sid") ?? 0;
$certCount   = db()->querySingle("SELECT COUNT(*) FROM certificates WHERE student_id=$sid") ?? 0;
$bestScore   = db()->querySingle("SELECT MAX(percentage) FROM quiz_attempts WHERE student_id=$sid") ?? 0;
$forumPosts  = db()->querySingle("SELECT COUNT(*) FROM forum_posts WHERE student_id=$sid AND is_hidden=0") ?? 0;

// My badges
$myBadges = [];
$mb = db()->query("SELECT b.* FROM student_badges sb JOIN badges b ON sb.badge_id=b.id WHERE sb.student_id=$sid ORDER BY sb.earned_at DESC");
while ($r = $mb->fetchArray(SQLITE3_ASSOC)) $myBadges[] = $r;
$myBadgeCodes = array_column($myBadges,'code');

// All badges (for display)
$allBadges = [];
$ab = db()->query("SELECT * FROM badges ORDER BY xp_reward");
while ($r = $ab->fetchArray(SQLITE3_ASSOC)) $allBadges[] = $r;

// XP to next level
$xpPoints = intval($xp['xp_points'] ?? 0);
$level = intval($xp['level'] ?? 1);
$xpForNext = $level * 200;
$xpThisLevel = $xpPoints % ($level * 200);
$pct = min(100, round($xpThisLevel / $xpForNext * 100));

// Recent activity
$recentActivity = [];
$ra = db()->query("SELECT * FROM xp_log WHERE student_id=$sid ORDER BY created_at DESC LIMIT 10");
while ($r = $ra->fetchArray(SQLITE3_ASSOC)) $recentActivity[] = $r;

// Quiz history
$quizHistory = [];
$qh = db()->query("SELECT qa.*,m.title,m.icon FROM quiz_attempts qa JOIN modules m ON qa.module_id=m.id WHERE qa.student_id=$sid ORDER BY qa.completed_at DESC LIMIT 10");
while ($r = $qh->fetchArray(SQLITE3_ASSOC)) $quizHistory[] = $r;

// Leaderboard position
$myRank = db()->querySingle("SELECT COUNT(*)+1 FROM student_xp WHERE xp_points > $xpPoints") ?? '?';

trackPageView('my_progress');
?>

<style>
.xp-hero{background:linear-gradient(135deg,var(--green-deeper),var(--pri),var(--rose));color:#fff;padding:32px 24px;border-radius:var(--r-lg);margin-bottom:24px;position:relative;overflow:hidden;}
.xp-hero::after{content:'💜';position:absolute;right:-20px;bottom:-30px;font-size:120px;opacity:.08;line-height:1;}
.level-badge{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;background:var(--acc);color:var(--green-deeper);border-radius:50%;font-size:1.4rem;font-weight:900;box-shadow:0 4px 16px rgba(245,230,66,.4);margin-bottom:10px;}
.xp-bar-outer{background:rgba(255,255,255,.2);border-radius:50px;height:12px;overflow:hidden;margin:12px 0 6px;}
.xp-bar-inner{background:linear-gradient(90deg,var(--acc),#f59e0b);height:100%;border-radius:50px;transition:width .8s var(--ease);}

.badge-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;}
.badge-card{text-align:center;padding:16px 10px;border-radius:var(--r);border:2px solid var(--border);background:var(--white);transition:var(--t);}
.badge-card.earned{border-color:var(--acc);background:var(--orange-pale);}
.badge-card.locked{opacity:.45;filter:grayscale(1);}
.badge-card .badge-icon{font-size:2rem;margin-bottom:6px;}
.badge-card h4{font-size:.78rem;font-weight:700;margin-bottom:3px;}
.badge-card p{font-size:.68rem;color:var(--mid);}
.badge-card .xp-val{font-size:.7rem;font-weight:800;color:var(--pri);margin-top:4px;}

.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:20px;}
.mini-stat{background:var(--white);border-radius:var(--r);padding:16px;text-align:center;box-shadow:var(--shadow);border:1.5px solid var(--border);}
.mini-stat .val{font-size:1.8rem;font-weight:900;color:var(--pri);}
.mini-stat .lbl{font-size:.7rem;color:var(--mid);font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
</style>

<div class="container">
    <div class="breadcrumb">
        <a href="/arise/">Home</a> <span class="sep">›</span>
        <span>My Progress</span>
    </div>

    <?php if (!empty($notifs)): foreach($notifs as $n): ?>
    <div class="notif-banner">
        <div class="notif-banner-icon"><?= $n['type']==='essay' ? '📝' : '💬' ?></div>
        <div class="notif-banner-body">
            <div class="notif-banner-title"><?= $n['type']==='essay' ? 'Essay Feedback Received!' : 'Your Question Was Answered!' ?></div>
            <div class="notif-banner-text"><?= htmlspecialchars($n['text'] ?? '') ?><?= isset($n['score']) && $n['score'] ? ' — Score: '.$n['score'].'%' : '' ?></div>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <!-- XP Hero -->
    <div class="xp-hero">
        <div class="level-badge"><?= $level ?></div>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 style="font-size:1.4rem;font-weight:900;margin-bottom:4px;"><?= e($student['full_name']) ?></h1>
                <p style="opacity:.85;font-size:.9rem;"><?= e($student['class_name']??'') ?><?= $student['school_name']?' &middot; '.e($student['school_name']):'' ?></p>
                <p style="font-size:.82rem;opacity:.75;margin-top:4px;">Rank #<?= $myRank ?> on leaderboard &middot; <?= $xp['streak_days'] ?> day streak 🔥</p>
            </div>
            <div style="text-align:right;">
                <div style="font-size:2.2rem;font-weight:900;color:var(--acc);"><?= number_format($xpPoints) ?></div>
                <div style="font-size:.75rem;opacity:.8;">XP Points</div>
                <div style="font-size:.72rem;opacity:.65;margin-top:2px;"><?= count($myBadges) ?> badges earned</div>
            </div>
        </div>
        <div class="xp-bar-outer">
            <div class="xp-bar-inner" style="width:<?= $pct ?>%"></div>
        </div>
        <div style="font-size:.75rem;opacity:.8;">Level <?= $level ?> &rarr; Level <?= $level+1 ?> &nbsp;&bull;&nbsp; <?= $xpThisLevel ?>/<?= $xpForNext ?> XP</div>
    </div>

    <!-- Stats -->
    <div class="stat-row">
        <div class="mini-stat"><div class="val"><?= $quizCount ?></div><div class="lbl">Quizzes Taken</div></div>
        <div class="mini-stat"><div class="val"><?= $passCount ?></div><div class="lbl">Quizzes Passed</div></div>
        <div class="mini-stat"><div class="val"><?= $avgScore ?>%</div><div class="lbl">Avg Score</div></div>
        <div class="mini-stat"><div class="val"><?= $bestScore ?>%</div><div class="lbl">Best Score</div></div>
        <div class="mini-stat"><div class="val"><?= $certCount ?></div><div class="lbl">Certificates</div></div>
        <div class="mini-stat"><div class="val"><?= $xp['streak_days'] ?></div><div class="lbl">Day Streak</div></div>
    </div>

    <!-- Badges -->
    <div class="dp-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 class="section-title" style="margin:0;">Badges &amp; Achievements</h2>
            <span class="chip"><?= count($myBadges) ?>/<?= count($allBadges) ?> earned</span>
        </div>
        <div class="badge-grid">
            <?php foreach ($allBadges as $b):
                $earned = in_array($b['code'], $myBadgeCodes);
            ?>
            <div class="badge-card <?= $earned?'earned':'locked' ?>">
                <div class="badge-icon"><?= $earned ? htmlspecialchars($b['icon']) : '🔒' ?></div>
                <h4><?= e($b['name']) ?></h4>
                <p><?= e($b['description']) ?></p>
                <div class="xp-val">+<?= $b['xp_reward'] ?> XP</div>
                <?php if ($earned): ?>
                    <span class="badge badge-green" style="font-size:.62rem;margin-top:4px;">Earned!</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Quiz history -->
    <?php if (count($quizHistory) > 0): ?>
    <div class="dp-card">
        <h2 class="section-title">Recent Quiz Results</h2>
        <?php foreach ($quizHistory as $q):
            $pass = $q['percentage'] >= 60;
            $col  = $pass ? '#065f46' : '#991b1b';
        ?>
        <div class="dp-log-item" style="flex-wrap:wrap;gap:6px;">
            <div>
                <strong><?= htmlspecialchars($q['icon']) ?> <?= e($q['title']) ?></strong>
                <span class="badge <?= $pass?'badge-green':'badge-red' ?>" style="margin-left:6px;"><?= $pass?'Passed':'Failed' ?></span>
            </div>
            <div style="text-align:right;">
                <span style="font-weight:800;color:<?= $col ?>;font-size:1rem;"><?= $q['percentage'] ?>%</span>
                <div style="font-size:.72rem;color:var(--mid);"><?= date('M j, Y', strtotime($q['completed_at'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- XP Activity Log -->
    <?php if (count($recentActivity) > 0): ?>
    <div class="dp-card">
        <h2 class="section-title">XP Activity</h2>
        <?php foreach ($recentActivity as $a): ?>
        <div class="dp-log-item">
            <span style="font-size:.88rem;"><?= e($a['description']) ?></span>
            <span style="font-weight:800;color:var(--pri);white-space:nowrap;">+<?= $a['xp_earned'] ?> XP</span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;">
        <a href="/arise/?p=modules" class="btn btn-primary">📚 Continue Learning</a>
        <a href="/arise/?p=certificates" class="btn btn-secondary">🎓 My Certificates</a>
    </div>
</div>
