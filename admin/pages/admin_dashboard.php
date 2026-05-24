<?php
/**
 * ARISE Admin Dashboard v3.0
 * Rich reporting, gamification overview, vibrant UI
 */
$today  = date('Y-m-d');
$week   = date('Y-m-d', strtotime('-7 days'));
$month  = date('Y-m-d', strtotime('-30 days'));

// Core stats
$dToday   = db()->querySingle("SELECT COUNT(DISTINCT device_hash) FROM sessions WHERE DATE(started_at)='$today'") ?? 0;
$dWeek    = db()->querySingle("SELECT COUNT(DISTINCT device_hash) FROM sessions WHERE DATE(started_at)>='$week'") ?? 0;
$students = db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1") ?? 0;
$quizzes  = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts") ?? 0;
$avgScore = db()->querySingle("SELECT ROUND(AVG(percentage),1) FROM quiz_attempts") ?? 0;
$certs    = db()->querySingle("SELECT COUNT(*) FROM certificates") ?? 0;
$passRate = $quizzes > 0 ? db()->querySingle("SELECT ROUND(COUNT(*)*100.0/$quizzes,1) FROM quiz_attempts WHERE percentage>=60") : 0;
$lessons  = db()->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1") ?? 0;
$modules  = db()->querySingle("SELECT COUNT(*) FROM modules WHERE is_active=1") ?? 0;
$unanswered = db()->querySingle("SELECT COUNT(*) FROM anonymous_questions WHERE is_answered=0") ?? 0;

// 7-day activity for sparkline
$dailyData = [];
for ($i=6; $i>=0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $label = date('D', strtotime($d));
    $sessions = db()->querySingle("SELECT COUNT(DISTINCT device_hash) FROM sessions WHERE DATE(started_at)='$d'") ?? 0;
    $qcount   = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts WHERE DATE(completed_at)='$d'") ?? 0;
    $dailyData[] = ['label'=>$label,'sessions'=>$sessions,'quizzes'=>$qcount];
}

// Top students by quiz score
$topStudents = [];
$ts = db()->query("SELECT s.full_name, s.class_name, s.school_name,
    COUNT(qa.id) AS quiz_count,
    ROUND(AVG(qa.percentage),1) AS avg_score,
    COUNT(c.id) AS certs
    FROM students s
    LEFT JOIN quiz_attempts qa ON qa.student_id=s.id
    LEFT JOIN certificates c ON c.student_id=s.id
    WHERE s.is_active=1
    GROUP BY s.id
    HAVING quiz_count > 0
    ORDER BY avg_score DESC, quiz_count DESC
    LIMIT 10");
while ($r = $ts->fetchArray(SQLITE3_ASSOC)) $topStudents[] = $r;

// Module performance
$modPerf = [];
$mp = db()->query("SELECT m.title,m.icon,
    COUNT(DISTINCT qa.session_hash) AS attempts,
    ROUND(AVG(qa.percentage),1) AS avg,
    COUNT(DISTINCT c.id) AS certs,
    COUNT(DISTINCT l.id) AS lesson_count
    FROM modules m
    LEFT JOIN quiz_attempts qa ON qa.module_id=m.id
    LEFT JOIN certificates c ON c.module_id=m.id
    LEFT JOIN lessons l ON l.module_id=m.id AND l.is_active=1
    WHERE m.is_active=1
    GROUP BY m.id ORDER BY attempts DESC");
while ($r = $mp->fetchArray(SQLITE3_ASSOC)) $modPerf[] = $r;

// Score distribution
$dist = ['90-100'=>0,'70-89'=>0,'60-69'=>0,'40-59'=>0,'0-39'=>0];
$dr = db()->query("SELECT percentage FROM quiz_attempts");
while ($r = $dr->fetchArray(SQLITE3_ASSOC)) {
    $p = floatval($r['percentage']);
    if ($p>=90) $dist['90-100']++;
    elseif ($p>=70) $dist['70-89']++;
    elseif ($p>=60) $dist['60-69']++;
    elseif ($p>=40) $dist['40-59']++;
    else $dist['0-39']++;
}

// Students needing attention
$struggling = [];
$sr = db()->query("SELECT s.full_name, s.class_name, s.school_name,
    COUNT(qa.id) AS quizzes,
    ROUND(AVG(qa.percentage),1) AS avg,
    MAX(s.last_seen) AS last_seen
    FROM students s
    LEFT JOIN quiz_attempts qa ON qa.student_id=s.id
    WHERE s.is_active=1
    GROUP BY s.id
    HAVING quizzes=0 OR avg < 40
    ORDER BY s.last_seen DESC LIMIT 8");
while ($r = $sr->fetchArray(SQLITE3_ASSOC)) $struggling[] = $r;

// Recent certificates
$recentCerts = [];
$rc = db()->query("SELECT c.*,m.icon FROM certificates c JOIN modules m ON c.module_id=m.id ORDER BY c.issued_at DESC LIMIT 8");
while ($r = $rc->fetchArray(SQLITE3_ASSOC)) $recentCerts[] = $r;

// XP Leaderboard
$leaderboard = [];
$lb = db()->query("SELECT s.full_name, s.class_name, sx.xp_points, sx.level, sx.streak_days, sx.total_quizzes_passed
    FROM student_xp sx JOIN students s ON sx.student_id=s.id
    ORDER BY sx.xp_points DESC LIMIT 10");
while ($r = $lb->fetchArray(SQLITE3_ASSOC)) $leaderboard[] = $r;
?>

<style>
.dash-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:24px;}
.stat-hero{background:var(--white);border-radius:var(--r);padding:20px;box-shadow:var(--shadow);border:1.5px solid var(--border);text-align:center;transition:var(--t);position:relative;overflow:hidden;}
.stat-hero::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;}
.stat-hero.purple::before{background:linear-gradient(90deg,var(--pri),var(--pri-mid));}
.stat-hero.yellow::before{background:linear-gradient(90deg,var(--acc),#f59e0b);}
.stat-hero.rose::before{background:linear-gradient(90deg,var(--rose),#f43f5e);}
.stat-hero.green::before{background:linear-gradient(90deg,#10b981,#059669);}
.stat-hero.blue::before{background:linear-gradient(90deg,#3b82f6,#1d4ed8);}
.stat-hero:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
.stat-hero .val{font-size:2.2rem;font-weight:900;line-height:1;margin-bottom:4px;}
.stat-hero .lbl{font-size:.72rem;color:var(--mid);font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.stat-hero .sub{font-size:.78rem;color:var(--dark-soft);margin-top:4px;}
.stat-hero.purple .val{color:var(--pri);}
.stat-hero.yellow .val{color:#92400e;}
.stat-hero.rose .val{color:var(--rose);}
.stat-hero.green .val{color:#065f46;}
.stat-hero.blue .val{color:#1e40af;}

.section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
.section-header h2{font-size:1.05rem;font-weight:800;color:var(--dark);}

.bar-chart{display:flex;gap:8px;align-items:flex-end;height:80px;padding:8px 0;}
.bar-item{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;}
.bar-fill{width:100%;border-radius:6px 6px 0 0;min-height:4px;transition:height .5s var(--ease);}
.bar-lbl{font-size:.68rem;color:var(--mid);font-weight:600;}
.bar-val{font-size:.72rem;font-weight:800;color:var(--pri);}

.leaderboard-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);}
.leaderboard-row:last-child{border-bottom:none;}
.rank{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.85rem;flex-shrink:0;}
.rank.gold{background:#fef3c7;color:#92400e;}
.rank.silver{background:#f3f4f6;color:#374151;}
.rank.bronze{background:#fef3c7;color:#78350f;}
.rank.other{background:var(--pri-light);color:var(--pri-dark);}

.xp-bar{height:6px;background:var(--pri-light);border-radius:50px;overflow:hidden;margin-top:3px;}
.xp-fill{height:100%;background:linear-gradient(90deg,var(--pri),var(--acc));border-radius:50px;}

.alert-student{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);gap:8px;}
.alert-student:last-child{border-bottom:none;}

.score-dist{display:flex;gap:6px;margin-top:8px;}
.score-seg{flex:1;text-align:center;}
.score-seg .bar{border-radius:6px 6px 0 0;margin-bottom:4px;}
.score-seg .lbl{font-size:.65rem;color:var(--mid);font-weight:600;}
.score-seg .num{font-size:.8rem;font-weight:800;}

.cert-pill{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);}
.cert-pill:last-child{border-bottom:none;}

.module-perf-row{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);}
.module-perf-row:last-child{border-bottom:none;}
.perf-bar-wrap{flex:1;height:8px;background:var(--pri-light);border-radius:50px;overflow:hidden;}
.perf-bar{height:100%;border-radius:50px;background:linear-gradient(90deg,var(--pri),var(--rose));}
</style>

<h1 class="page-title" style="margin-bottom:20px;">Dashboard</h1>

<!-- ── STAT CARDS ──────────────────────────────── -->
<div class="dash-grid">
    <div class="stat-hero purple">
        <div class="val"><?= $dToday ?></div>
        <div class="lbl">Active Today</div>
        <div class="sub"><?= $dWeek ?> this week</div>
    </div>
    <div class="stat-hero purple">
        <div class="val"><?= $students ?></div>
        <div class="lbl">Students</div>
        <div class="sub">Registered</div>
    </div>
    <div class="stat-hero yellow">
        <div class="val"><?= $lessons ?></div>
        <div class="lbl">Lessons</div>
        <div class="sub"><?= $modules ?> modules</div>
    </div>
    <div class="stat-hero rose">
        <div class="val"><?= $quizzes ?></div>
        <div class="lbl">Quizzes Taken</div>
        <div class="sub"><?= $avgScore ?>% avg score</div>
    </div>
    <div class="stat-hero green">
        <div class="val"><?= $passRate ?>%</div>
        <div class="lbl">Pass Rate</div>
        <div class="sub">60%+ threshold</div>
    </div>
    <div class="stat-hero blue">
        <div class="val"><?= $certs ?></div>
        <div class="lbl">Certificates</div>
        <div class="sub">Issued total</div>
    </div>
    <?php if ($unanswered > 0): ?>
    <div class="stat-hero rose">
        <div class="val"><?= $unanswered ?></div>
        <div class="lbl">Unanswered Qs</div>
        <div class="sub"><a href="?p=questions" style="color:var(--rose);font-weight:700;">Answer now</a></div>
    </div>
    <?php endif; ?>
</div>

<!-- ── 7-DAY ACTIVITY CHART + SCORE DISTRIBUTION ── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

    <!-- Activity chart -->
    <div class="dp-card">
        <div class="section-header">
            <h2>7-Day Activity</h2>
            <span class="chip">Last 7 days</span>
        </div>
        <?php
        $maxSessions = max(array_column($dailyData,'sessions') ?: [1]);
        $maxQ = max(array_column($dailyData,'quizzes') ?: [1]);
        $maxVal = max($maxSessions, $maxQ, 1);
        ?>
        <div style="display:flex;gap:6px;align-items:flex-end;height:100px;">
            <?php foreach ($dailyData as $d): ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;">
                <span style="font-size:.68rem;font-weight:800;color:var(--pri);"><?= $d['sessions'] ?></span>
                <div style="width:100%;border-radius:6px 6px 0 0;background:linear-gradient(180deg,var(--pri),var(--pri-mid));height:<?= max(4,round($d['sessions']/$maxVal*70)) ?>px;"></div>
                <div style="width:60%;border-radius:6px 6px 0 0;background:linear-gradient(180deg,var(--acc),#f59e0b);height:<?= max(2,round($d['quizzes']/$maxVal*70)) ?>px;margin-top:-<?= max(2,round($d['quizzes']/$maxVal*70)) ?>px;opacity:.7;"></div>
                <span style="font-size:.68rem;color:var(--mid);font-weight:600;"><?= $d['label'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:16px;margin-top:10px;font-size:.75rem;">
            <span style="display:flex;align-items:center;gap:4px;"><span style="width:12px;height:12px;background:var(--pri);border-radius:3px;display:inline-block;"></span>Sessions</span>
            <span style="display:flex;align-items:center;gap:4px;"><span style="width:12px;height:12px;background:var(--acc);border-radius:3px;display:inline-block;"></span>Quizzes</span>
        </div>
    </div>

    <!-- Score distribution -->
    <div class="dp-card">
        <div class="section-header">
            <h2>Score Distribution</h2>
            <span class="chip"><?= $quizzes ?> attempts</span>
        </div>
        <?php
        $maxDist = max(array_values($dist) ?: [1]);
        $distColors = ['90-100'=>'#065f46','70-89'=>'#10b981','60-69'=>'#f59e0b','40-59'=>'#f97316','0-39'=>'#ef4444'];
        ?>
        <div style="display:flex;gap:8px;align-items:flex-end;height:100px;margin-bottom:8px;">
            <?php foreach ($dist as $range=>$count):
                $col = $distColors[$range];
                $h = $maxDist > 0 ? max(4,round($count/$maxDist*80)) : 4;
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;">
                <span style="font-size:.75rem;font-weight:800;color:<?= $col ?>;"><?= $count ?></span>
                <div style="width:100%;border-radius:6px 6px 0 0;background:<?= $col ?>;height:<?= $h ?>px;opacity:.85;"></div>
                <span style="font-size:.62rem;color:var(--mid);font-weight:600;"><?= $range ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:center;gap:4px;margin-top:6px;">
            <span class="badge badge-green" style="font-size:.65rem;">Pass: <?= $dist['90-100']+$dist['70-89']+$dist['60-69'] ?></span>
            <span class="badge badge-red" style="font-size:.65rem;">Fail: <?= $dist['40-59']+$dist['0-39'] ?></span>
        </div>
    </div>
</div>

<!-- ── LEADERBOARD + STRUGGLING ── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

    <!-- XP Leaderboard -->
    <div class="dp-card">
        <div class="section-header">
            <h2>🏆 XP Leaderboard</h2>
            <span class="chip">Top students</span>
        </div>
        <?php if (count($leaderboard) === 0): ?>
            <p class="text-muted text-small">No XP data yet — students earn XP by completing lessons and quizzes.</p>
        <?php else: ?>
        <?php foreach ($leaderboard as $i=>$s):
            $rankClass = $i===0?'gold':($i===1?'silver':($i===2?'bronze':'other'));
            $lvlXp = ($s['level']*100);
            $pct = min(100, round(($s['xp_points'] % 100)));
        ?>
        <div class="leaderboard-row">
            <div class="rank <?= $rankClass ?>"><?= $i+1 ?></div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($s['full_name']) ?></div>
                <div style="font-size:.72rem;color:var(--mid);"><?= e($s['class_name']??'') ?> &middot; Level <?= $s['level'] ?> &middot; <?= $s['streak_days'] ?>d streak</div>
                <div class="xp-bar"><div class="xp-fill" style="width:<?= $pct ?>%"></div></div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <div style="font-weight:900;color:var(--pri);font-size:.95rem;"><?= number_format($s['xp_points']) ?></div>
                <div style="font-size:.68rem;color:var(--mid);">XP</div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Students needing attention -->
    <div class="dp-card">
        <div class="section-header">
            <h2>⚠️ Needs Attention</h2>
            <span class="chip">0 quizzes or avg &lt;40%</span>
        </div>
        <?php if (count($struggling) === 0): ?>
            <p class="text-muted text-small" style="color:#065f46;font-weight:600;">All students progressing well! 🎉</p>
        <?php else: ?>
        <?php foreach ($struggling as $s): ?>
        <div class="alert-student">
            <div>
                <div style="font-weight:700;font-size:.88rem;"><?= e($s['full_name']) ?></div>
                <div style="font-size:.75rem;color:var(--mid);"><?= e($s['class_name']??'') ?> &middot; <?= e($s['school_name']??'') ?></div>
            </div>
            <?php if ($s['quizzes']==0): ?>
                <span class="badge badge-red">No quizzes</span>
            <?php else: ?>
                <span class="badge badge-amber"><?= $s['avg'] ?>% avg</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── MODULE PERFORMANCE ── -->
<?php if (count($modPerf) > 0): ?>
<div class="dp-card" style="margin-bottom:20px;">
    <div class="section-header">
        <h2>📚 Module Performance</h2>
        <span class="chip"><?= count($modPerf) ?> modules</span>
    </div>
    <?php foreach ($modPerf as $m):
        $sc = floatval($m['avg'] ?? 0);
        $col = $sc>=60?'#065f46':($sc>=40?'#92400e':'#991b1b');
        $barW = $sc > 0 ? min(100,$sc) : 0;
        $barCol = $sc>=60?'linear-gradient(90deg,#10b981,#059669)':($sc>=40?'linear-gradient(90deg,#f59e0b,#d97706)':'linear-gradient(90deg,#ef4444,#dc2626)');
    ?>
    <div class="module-perf-row">
        <div style="width:28px;font-size:1.2rem;text-align:center;"><?= htmlspecialchars($m['icon']) ?></div>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($m['title']) ?></div>
            <div class="perf-bar-wrap"><div class="perf-bar" style="width:<?= $barW ?>%;background:<?= $barCol ?>;"></div></div>
        </div>
        <div style="text-align:right;flex-shrink:0;min-width:100px;">
            <span style="font-weight:800;color:<?= $col ?>;font-size:.9rem;"><?= $sc>0?"$sc%":'No data' ?></span>
            <div style="font-size:.68rem;color:var(--mid);"><?= $m['attempts'] ?> attempts &middot; <?= $m['certs'] ?> certs</div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── TOP STUDENTS + RECENT CERTS ── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

    <!-- Top students -->
    <div class="dp-card">
        <div class="section-header">
            <h2>⭐ Top Students</h2>
            <a href="?p=students" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <?php if (count($topStudents) === 0): ?>
            <p class="text-muted text-small">No quiz data yet.</p>
        <?php else: ?>
        <?php foreach ($topStudents as $i=>$s): ?>
        <div class="leaderboard-row">
            <div class="rank <?= $i===0?'gold':($i===1?'silver':($i===2?'bronze':'other')) ?>"><?= $i+1 ?></div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:.88rem;"><?= e($s['full_name']) ?></div>
                <div style="font-size:.72rem;color:var(--mid);"><?= e($s['class_name']??'') ?></div>
            </div>
            <div style="text-align:right;">
                <div style="font-weight:800;color:var(--pri);"><?= $s['avg_score'] ?>%</div>
                <div style="font-size:.68rem;color:var(--mid);"><?= $s['quiz_count'] ?> quizzes &middot; <?= $s['certs'] ?> certs</div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Recent certificates -->
    <div class="dp-card">
        <div class="section-header">
            <h2>🎓 Recent Certificates</h2>
            <a href="?p=certificates" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <?php if (count($recentCerts) === 0): ?>
            <p class="text-muted text-small">No certificates yet.</p>
        <?php else: ?>
        <?php foreach ($recentCerts as $c): ?>
        <div class="cert-pill">
            <span style="font-size:1.2rem;"><?= htmlspecialchars($c['icon']) ?></span>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($c['student_name']) ?></div>
                <div style="font-size:.72rem;color:var(--mid);"><?= e($c['module_title']) ?></div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <span class="badge badge-green"><?= $c['percentage'] ?>%</span>
                <div style="font-size:.65rem;color:var(--mid);margin-top:2px;"><?= date('M j', strtotime($c['issued_at'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── STUDENT ACCESS INFO ── -->
<div class="enroll-box">
    <h2 class="section-title" style="margin-bottom:14px;">How Students Access ARISE</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
        <?php foreach([
            ['1','Connect to school WiFi','Same network as this server'],
            ['2','Open browser &rarr; <strong>192.168.0.118/arise/</strong>','Any phone, tablet or laptop'],
            ['3','Click Register','Name + school + class &mdash; 15 seconds'],
            ['4','Start learning &amp; earning XP!','Lessons, quizzes, badges, certificates'],
        ] as [$n,$t,$d]): ?>
        <div class="enroll-step" style="margin-bottom:0;">
            <div class="num"><?= $n ?></div>
            <div><strong style="font-size:.88rem;"><?= $t ?></strong><p style="font-size:.78rem;color:#6b7280;margin-top:2px;"><?= $d ?></p></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// Animate bars on load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.xp-fill, .perf-bar, .progress-wrap .fill').forEach(function(el) {
        var w = el.style.width;
        el.style.width = '0%';
        setTimeout(function() { el.style.width = w; }, 100);
    });
});
</script>
