<?php
/**
 * ARISE Teacher Dashboard — No auth required (read-only stats)
 * Replace the top session check with this version for open access
 */

// REMOVED: admin session requirement
// Teachers access this directly — it is read-only, no sensitive data modified

$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-7 days'));

// ── Overview stats ──────────────────────────────────────────────────────
$totalStudents  = db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active = 1") ?? 0;
$activeToday    = db()->querySingle("SELECT COUNT(DISTINCT device_hash) FROM sessions WHERE DATE(started_at) = '$today'") ?? 0;
$activeThisWeek = db()->querySingle("SELECT COUNT(DISTINCT device_hash) FROM sessions WHERE DATE(started_at) >= '$weekAgo'") ?? 0;
$totalQuizzes   = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts") ?? 0;
$avgScore       = db()->querySingle("SELECT ROUND(AVG(percentage),1) FROM quiz_attempts") ?? 0;
$pendingEssays  = db()->querySingle("SELECT COUNT(*) FROM essay_responses WHERE is_graded = 0") ?? 0;
$totalCerts     = db()->querySingle("SELECT COUNT(*) FROM certificates") ?? 0;

// ── Module engagement ───────────────────────────────────────────────────
$moduleStats = [];
$mResult = db()->query("
    SELECT m.id, m.title, m.icon,
        COUNT(DISTINCT pv.session_hash) AS views,
        COUNT(DISTINCT qa.session_hash) AS quiz_attempts,
        ROUND(AVG(qa.percentage), 1)    AS avg_score,
        COUNT(DISTINCT c.id)            AS certs
    FROM modules m
    LEFT JOIN page_views pv ON pv.module_id = m.id
    LEFT JOIN quiz_attempts qa ON qa.module_id = m.id
    LEFT JOIN certificates c ON c.module_id = m.id
    WHERE m.is_active = 1
    GROUP BY m.id
    ORDER BY views DESC
");
while ($row = $mResult->fetchArray(SQLITE3_ASSOC)) {
    $moduleStats[] = $row;
}

// ── Recent quiz attempts (last 20) ──────────────────────────────────────
$recentQuizzes = [];
$rqResult = db()->query("
    SELECT qa.*, m.title AS module_title, m.icon, s.full_name, s.class_name
    FROM quiz_attempts qa
    JOIN modules m ON qa.module_id = m.id
    LEFT JOIN students s ON qa.student_id = s.id
    ORDER BY qa.completed_at DESC LIMIT 20
");
while ($row = $rqResult->fetchArray(SQLITE3_ASSOC)) {
    $recentQuizzes[] = $row;
}

// ── Students needing attention ──────────────────────────────────────────
$strugglingStudents = [];
$ssResult = db()->query("
    SELECT s.full_name, s.class_name, s.school_name,
        COUNT(qa.id) AS total_quizzes,
        ROUND(AVG(qa.percentage),1) AS avg_score,
        MAX(s.last_seen) AS last_seen
    FROM students s
    LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
    WHERE s.is_active = 1
    GROUP BY s.id
    HAVING total_quizzes = 0 OR avg_score < 40
    ORDER BY s.last_seen DESC
    LIMIT 15
");
while ($row = $ssResult->fetchArray(SQLITE3_ASSOC)) {
    $strugglingStudents[] = $row;
}

// ── Pending essay responses ─────────────────────────────────────────────
$pendingEssayList = [];
$peResult = db()->query("
    SELECT er.id, er.response_text, er.word_count, er.submitted_at,
        qq.question, qq.max_marks,
        m.title AS module_title, m.icon,
        s.full_name, s.class_name
    FROM essay_responses er
    JOIN quiz_questions qq ON er.question_id = qq.id
    JOIN modules m ON er.module_id = m.id
    LEFT JOIN students s ON er.student_id = s.id
    WHERE er.is_graded = 0
    ORDER BY er.submitted_at DESC
    LIMIT 10
");
while ($row = $peResult->fetchArray(SQLITE3_ASSOC)) {
    $pendingEssayList[] = $row;
}

// ── Anonymous questions (unanswered) ────────────────────────────────────
$unansweredQs = [];
$uqResult = db()->query("
    SELECT aq.*, m.title AS module_title, m.icon
    FROM anonymous_questions aq
    LEFT JOIN modules m ON aq.module_id = m.id
    WHERE aq.is_answered = 0
    ORDER BY aq.submitted_at DESC
    LIMIT 8
");
while ($row = $uqResult->fetchArray(SQLITE3_ASSOC)) {
    $unansweredQs[] = $row;
}
?>

<div class="container">
    <h1 class="page-title">👩‍🏫 Teacher Dashboard</h1>
    <p class="text-muted" style="margin-top:-10px; margin-bottom:20px;">
        <?= date('l, F j, Y') ?> &nbsp;·&nbsp;
        <a href="/arise/admin/" class="btn btn-sm btn-secondary" style="padding:3px 10px;" target="_blank">⚙️ Admin Panel</a>
        <?php if ($pendingEssays > 0): ?>
            <a href="/arise/admin/?p=content&action=grade_essays" class="btn btn-sm" style="padding:3px 10px; background:#FFF0ED; color:var(--danger); border:1px solid #f5c6b0;" target="_blank">
                ✍️ <?= $pendingEssays ?> Essays to Grade
            </a>
        <?php endif; ?>
    </p>

    <!-- Stat Cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:12px; margin-bottom:24px;">
        <?php
        $stats = [
            ['📱', 'Today',       $activeToday . ' devices'],
            ['📅', 'This Week',   $activeThisWeek . ' devices'],
            ['👥', 'Students',    $totalStudents],
            ['📝', 'Quizzes',     $totalQuizzes],
            ['📊', 'Avg Score',   $avgScore . '%'],
            ['🎓', 'Certificates',$totalCerts],
        ];
        foreach ($stats as $s): ?>
        <div class="dp-card dp-stat" style="text-align:center; padding:16px 10px;">
            <div style="font-size:1.5rem; margin-bottom:4px;"><?= $s[0] ?></div>
            <div style="font-size:1.4rem; font-weight:700; color:var(--primary);"><?= $s[2] ?></div>
            <div style="font-size:0.75rem; color:var(--mid); margin-top:2px;"><?= $s[1] ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Module Engagement -->
    <div class="dp-card" style="margin-bottom:20px;">
        <h2 class="section-title">📚 Module Engagement</h2>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead>
                    <tr style="background:var(--light); text-align:left;">
                        <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Module</th>
                        <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Views</th>
                        <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Quizzes</th>
                        <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Avg Score</th>
                        <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Certs</th>
                        <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Engagement</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($moduleStats as $m): ?>
                    <?php
                    $score = floatval($m['avg_score'] ?? 0);
                    $scoreColor = $score >= 60 ? '#2e7d32' : ($score >= 40 ? '#e65100' : '#c62828');
                    $eng = $m['views'] > 20 ? '🔥 High' : ($m['views'] > 5 ? '📈 Medium' : '📉 Low');
                    ?>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:8px 12px; font-weight:600;"><?= $m['icon'] ?> <?= e($m['title']) ?></td>
                        <td style="padding:8px 12px;"><?= $m['views'] ?: 0 ?></td>
                        <td style="padding:8px 12px;"><?= $m['quiz_attempts'] ?: 0 ?></td>
                        <td style="padding:8px 12px; font-weight:600; color:<?= $scoreColor ?>;"><?= $score > 0 ? $score . '%' : '—' ?></td>
                        <td style="padding:8px 12px;"><?= $m['certs'] ?><?= $m['certs'] > 0 ? ' 🎓' : '' ?></td>
                        <td style="padding:8px 12px; font-size:0.8rem;"><?= $eng ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">

        <!-- Recent Quiz Activity -->
        <div class="dp-card">
            <h2 class="section-title">📝 Recent Quiz Activity</h2>
            <?php if (count($recentQuizzes) === 0): ?>
                <p class="text-muted text-small">No quiz attempts yet.</p>
            <?php else: ?>
                <?php foreach ($recentQuizzes as $q): ?>
                <div class="dp-log-item" style="flex-wrap:wrap; gap:4px;">
                    <div>
                        <span style="font-weight:600;"><?= $q['icon'] ?> <?= e($q['module_title']) ?></span>
                        <?php if ($q['full_name']): ?>
                            <span class="text-small" style="color:var(--primary);"> — <?= e($q['full_name']) ?>
                            <?= $q['class_name'] ? '(' . e($q['class_name']) . ')' : '' ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span style="font-weight:700; color:<?= $q['percentage'] >= 60 ? '#2e7d32' : '#c62828' ?>;"><?= $q['percentage'] ?>%</span>
                        <span class="text-small text-muted"> <?= date('M j, g:i A', strtotime($q['completed_at'])) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Students Needing Attention -->
        <div class="dp-card">
            <h2 class="section-title">⚠️ Students Needing Attention</h2>
            <p class="text-small text-muted" style="margin-top:-8px; margin-bottom:12px;">No quizzes taken or avg below 40%</p>
            <?php if (count($strugglingStudents) === 0): ?>
                <p class="text-muted text-small">All students progressing well 🎉</p>
            <?php else: ?>
                <?php foreach ($strugglingStudents as $s): ?>
                <div class="dp-log-item" style="flex-wrap:wrap; gap:4px;">
                    <div>
                        <span style="font-weight:600;"><?= e($s['full_name']) ?></span>
                        <?= $s['class_name'] ? '<span class="text-small"> — ' . e($s['class_name']) . '</span>' : '' ?>
                    </div>
                    <div>
                        <?php if ($s['total_quizzes'] == 0): ?>
                            <span style="font-size:0.78rem; color:#c62828; font-weight:600;">No quizzes taken</span>
                        <?php else: ?>
                            <span style="font-size:0.78rem; color:#e65100; font-weight:600;"><?= $s['avg_score'] ?>% avg</span>
                        <?php endif; ?>
                        <?php if ($s['last_seen']): ?>
                            <span class="text-small text-muted"> · Last seen <?= date('M j', strtotime($s['last_seen'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pending Essays -->
    <?php if (count($pendingEssayList) > 0): ?>
    <div class="dp-card" style="margin-bottom:20px;">
        <h2 class="section-title">✍️ Essays Awaiting Grading
            <a href="/arise/admin/?p=content&action=grade_essays" class="btn btn-sm btn-primary" style="float:right; margin-top:-2px;" target="_blank">Grade All →</a>
        </h2>
        <?php foreach ($pendingEssayList as $e): ?>
        <div class="dp-log-item" style="flex-wrap:wrap; gap:6px; align-items:flex-start; padding:12px 0;">
            <div style="flex:1; min-width:200px;">
                <span style="font-size:0.78rem; color:var(--primary); font-weight:600;">
                    <?= $e['icon'] ?> <?= e($e['module_title']) ?><?= $e['full_name'] ? ' — ' . e($e['full_name']) : '' ?><?= $e['class_name'] ? ' (' . e($e['class_name']) . ')' : '' ?>
                </span>
                <p style="margin:4px 0 0; font-size:0.85rem; font-weight:600;"><?= e(substr($e['question'], 0, 80)) ?>...</p>
                <p style="margin:4px 0 0; font-size:0.82rem; color:var(--mid);"><?= e(substr($e['response_text'], 0, 100)) ?>... <span style="color:var(--primary);"><?= $e['word_count'] ?> words</span></p>
            </div>
            <a href="/arise/admin/?p=content&action=grade_essays" class="btn btn-sm btn-secondary" style="padding:4px 10px;" target="_blank">✅ Grade (<?= $e['max_marks'] ?> marks)</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Unanswered Questions -->
    <?php if (count($unansweredQs) > 0): ?>
    <div class="dp-card">
        <h2 class="section-title">❓ Student Questions Awaiting Answers
            <a href="/arise/admin/?p=questions" class="btn btn-sm btn-primary" style="float:right; margin-top:-2px;" target="_blank">Answer All →</a>
        </h2>
        <?php foreach ($unansweredQs as $q): ?>
        <div class="dp-log-item" style="flex-wrap:wrap; gap:6px;">
            <div style="flex:1;">
                <?php if ($q['module_title']): ?><span style="font-size:0.78rem; color:var(--primary);"><?= $q['icon'] ?> <?= e($q['module_title']) ?></span><br><?php endif; ?>
                <span style="font-size:0.9rem;"><?= e($q['question']) ?></span>
            </div>
            <span class="text-small text-muted"><?= date('M j', strtotime($q['submitted_at'])) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
