<?php
/**
 * ARISE Admin — Reports & Data Export
 */
if (!isset($_SESSION['arise_admin_id'])) {
    header('Location: /arise/admin/');
    exit;
}

// ============================================================
// CSV EXPORTS — handle before any HTML output
// ============================================================
if (isset($_GET['export'])) {
    $export = $_GET['export'];

    if ($export === 'learners') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="arise_learners_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Full Name', 'Project', 'Cluster', 'Registered', 'Quizzes', 'Avg Score %', 'Certificates']);
        $res = db()->query(
            "SELECT s.id, s.full_name, s.school_name, s.class_name, s.registered_at,
                    COUNT(DISTINCT qa.id) AS quizzes,
                    ROUND(AVG(qa.percentage),1) AS avg_score,
                    COUNT(DISTINCT c.id) AS certs
             FROM students s
             LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
             LEFT JOIN certificates c ON c.student_id = s.id
             WHERE s.is_active = 1 AND s.deleted_at IS NULL
             GROUP BY s.id
             ORDER BY s.full_name"
        );
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($out, [
                $row['id'],
                $row['full_name'],
                $row['school_name'] ?? '',
                $row['class_name'] ?? '',
                $row['registered_at'],
                $row['quizzes'] ?? 0,
                $row['avg_score'] ?? 0,
                $row['certs'] ?? 0,
            ]);
        }
        fclose($out);
        exit;
    }

    if ($export === 'quiz') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="arise_quiz_results_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Student', 'Project', 'Module', 'Score %', 'Passed', 'Date']);
        $res = db()->query(
            "SELECT s.full_name, s.school_name, m.title AS module,
                    qa.score, qa.percentage,
                    CASE WHEN qa.percentage >= 60 THEN 'Yes' ELSE 'No' END AS passed,
                    qa.completed_at
             FROM quiz_attempts qa
             JOIN modules m ON qa.module_id = m.id
             LEFT JOIN students s ON qa.student_id = s.id
             ORDER BY qa.completed_at DESC"
        );
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($out, [
                $row['full_name'] ?? 'Anonymous',
                $row['school_name'] ?? '',
                $row['module'],
                $row['percentage'],
                $row['passed'],
                $row['completed_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    if ($export === 'difficult') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="arise_difficult_questions_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Module', 'Question', 'Correct Answer', 'Attempts', 'Wrong', 'Wrong %']);
        $res = db()->query(
            "SELECT qq.question, m.title AS module,
                    CASE qq.correct_option
                        WHEN 'a' THEN qq.option_a WHEN 'b' THEN qq.option_b
                        WHEN 'c' THEN qq.option_c WHEN 'd' THEN qq.option_d
                    END AS correct_text,
                    COUNT(qa.id) AS attempts,
                    SUM(CASE WHEN qa.is_correct=0 THEN 1 ELSE 0 END) AS wrong,
                    ROUND(SUM(CASE WHEN qa.is_correct=0 THEN 1 ELSE 0 END)*100.0/COUNT(qa.id),1) AS wrong_pct
             FROM quiz_questions qq
             JOIN quiz_answers qa ON qa.question_id=qq.id
             JOIN modules m ON m.id=qq.module_id
             GROUP BY qq.id HAVING attempts >= 3 ORDER BY wrong_pct DESC"
        );
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($out, [$row['module'],$row['question'],$row['correct_text'],$row['attempts'],$row['wrong'],$row['wrong_pct'].'%']);
        }
        fclose($out); exit;
    }

    if ($export === 'certs') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="arise_certificates_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Cert Number', 'Student', 'Module', 'Score %', 'Date Issued']);
        $res = db()->query(
            "SELECT c.cert_number, c.student_name, m.title AS module, c.percentage, c.issued_at
             FROM certificates c
             JOIN modules m ON c.module_id = m.id
             ORDER BY c.issued_at DESC"
        );
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            fputcsv($out, [
                $row['cert_number'],
                $row['student_name'],
                $row['module'],
                $row['percentage'],
                $row['issued_at'],
            ]);
        }
        fclose($out);
        exit;
    }
}

// ============================================================
// FETCH DATA FOR DISPLAY (limit to 100 rows)
// ============================================================

// Learners
$learnersData = [];
$learnersRes = db()->query(
    "SELECT s.id, s.full_name, s.school_name, s.class_name, s.registered_at,
            COUNT(DISTINCT qa.id) AS quizzes,
            ROUND(AVG(qa.percentage),1) AS avg_score,
            COUNT(DISTINCT c.id) AS certs
     FROM students s
     LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
     LEFT JOIN certificates c ON c.student_id = s.id
     WHERE s.is_active = 1 AND s.deleted_at IS NULL
     GROUP BY s.id
     ORDER BY s.full_name
     LIMIT 100"
);
while ($row = $learnersRes->fetchArray(SQLITE3_ASSOC)) {
    $learnersData[] = $row;
}

// Quiz results
$quizData = [];
$quizRes = db()->query(
    "SELECT s.full_name, s.school_name, m.title AS module,
            qa.score, qa.percentage,
            CASE WHEN qa.percentage >= 60 THEN 'Yes' ELSE 'No' END AS passed,
            qa.completed_at
     FROM quiz_attempts qa
     JOIN modules m ON qa.module_id = m.id
     LEFT JOIN students s ON qa.student_id = s.id
     ORDER BY qa.completed_at DESC
     LIMIT 100"
);
while ($row = $quizRes->fetchArray(SQLITE3_ASSOC)) {
    $quizData[] = $row;
}

// Certificates
$certsData = [];
$certsRes = db()->query(
    "SELECT c.cert_number, c.student_name, m.title AS module, c.percentage, c.issued_at
     FROM certificates c
     JOIN modules m ON c.module_id = m.id
     ORDER BY c.issued_at DESC
     LIMIT 100"
);
while ($row = $certsRes->fetchArray(SQLITE3_ASSOC)) {
    $certsData[] = $row;
}

// Totals for display note
$totalLearners  = db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1 AND deleted_at IS NULL") ?? 0;
$totalQuiz      = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts") ?? 0;
$totalCerts     = db()->querySingle("SELECT COUNT(*) FROM certificates") ?? 0;

// Difficult questions — questions with highest wrong-answer rate (min 3 attempts)
$difficultData = [];
$diffRes = db()->query(
    "SELECT qq.id, qq.question, m.title AS module,
            COUNT(qa.id) AS attempts,
            SUM(CASE WHEN qa.is_correct=0 THEN 1 ELSE 0 END) AS wrong,
            ROUND(SUM(CASE WHEN qa.is_correct=0 THEN 1 ELSE 0 END)*100.0/COUNT(qa.id),1) AS wrong_pct,
            qq.correct_option,
            CASE qq.correct_option
                WHEN 'a' THEN qq.option_a WHEN 'b' THEN qq.option_b
                WHEN 'c' THEN qq.option_c WHEN 'd' THEN qq.option_d
            END AS correct_text
     FROM quiz_questions qq
     JOIN quiz_answers qa ON qa.question_id = qq.id
     JOIN modules m ON m.id = qq.module_id
     GROUP BY qq.id
     HAVING attempts >= 3
     ORDER BY wrong_pct DESC
     LIMIT 30"
);
while ($row = $diffRes->fetchArray(SQLITE3_ASSOC)) $difficultData[] = $row;
?>

<h1 class="page-title">📋 Reports &amp; Data Export</h1>

<!-- ========================================================
     SECTION 1: Learner Report
     ======================================================== -->
<div class="dp-card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
        <h2 class="section-title" style="margin:0;">👥 Learner Report
            <span style="font-size:0.85rem; font-weight:400; color:#666;">(showing <?= count($learnersData) ?> of <?= $totalLearners ?>)</span>
        </h2>
        <a href="?p=reports&amp;export=learners" class="btn btn-primary">📥 Export All Learners CSV</a>
    </div>

    <?php if (empty($learnersData)): ?>
        <div class="alert alert-success" style="background:#f0fdf4; color:#166534; border-left:4px solid #16a34a; padding:12px 16px; border-radius:6px;">
            No active learners found.
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="arise-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Project</th>
                    <th>Cluster</th>
                    <th>Registered</th>
                    <th>Quizzes</th>
                    <th>Avg Score %</th>
                    <th>Certs</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($learnersData as $row): ?>
                <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td style="font-weight:600;"><?= e($row['full_name']) ?></td>
                    <td><?= e($row['school_name'] ?? '—') ?></td>
                    <td><?= e($row['class_name'] ?? '—') ?></td>
                    <td style="font-size:0.85rem;"><?= $row['registered_at'] ? date('M j, Y', strtotime($row['registered_at'])) : '—' ?></td>
                    <td><?= (int)($row['quizzes'] ?? 0) ?></td>
                    <td>
                        <?php $avg = (float)($row['avg_score'] ?? 0); ?>
                        <span style="font-weight:600; color:<?= $avg >= 60 ? '#16a34a' : ($avg > 0 ? '#dc2626' : '#9ca3af') ?>;">
                            <?= $avg > 0 ? $avg . '%' : '—' ?>
                        </span>
                    </td>
                    <td><?= (int)($row['certs'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ========================================================
     SECTION 2: Quiz Results Report
     ======================================================== -->
<div class="dp-card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
        <h2 class="section-title" style="margin:0;">📊 Quiz Results Report
            <span style="font-size:0.85rem; font-weight:400; color:#666;">(showing <?= count($quizData) ?> of <?= $totalQuiz ?>)</span>
        </h2>
        <a href="?p=reports&amp;export=quiz" class="btn btn-primary">📥 Export All Quiz CSV</a>
    </div>

    <?php if (empty($quizData)): ?>
        <div class="alert alert-success" style="background:#f0fdf4; color:#166534; border-left:4px solid #16a34a; padding:12px 16px; border-radius:6px;">
            No quiz attempts recorded yet.
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="arise-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Project</th>
                    <th>Module</th>
                    <th>Score %</th>
                    <th>Passed</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quizData as $row): ?>
                <tr>
                    <td style="font-weight:600;"><?= e($row['full_name'] ?? 'Anonymous') ?></td>
                    <td><?= e($row['school_name'] ?? '—') ?></td>
                    <td><?= e($row['module']) ?></td>
                    <td>
                        <span style="font-weight:600; color:<?= (float)$row['percentage'] >= 60 ? '#16a34a' : '#dc2626' ?>;">
                            <?= number_format((float)$row['percentage'], 1) ?>%
                        </span>
                    </td>
                    <td>
                        <span class="badge" style="background:<?= $row['passed'] === 'Yes' ? '#dcfce7' : '#fee2e2' ?>; color:<?= $row['passed'] === 'Yes' ? '#15803d' : '#b91c1c' ?>; padding:2px 8px; border-radius:12px; font-size:0.8rem; font-weight:600;">
                            <?= $row['passed'] ?>
                        </span>
                    </td>
                    <td style="font-size:0.85rem;"><?= $row['completed_at'] ? date('M j, Y g:i A', strtotime($row['completed_at'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ========================================================
     SECTION 3: Certificates Report
     ======================================================== -->
<div class="dp-card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
        <h2 class="section-title" style="margin:0;">🎓 Certificates Report
            <span style="font-size:0.85rem; font-weight:400; color:#666;">(showing <?= count($certsData) ?> of <?= $totalCerts ?>)</span>
        </h2>
        <a href="?p=reports&amp;export=certs" class="btn btn-primary">📥 Export All Certs CSV</a>
    </div>

    <?php if (empty($certsData)): ?>
        <div class="alert alert-success" style="background:#f0fdf4; color:#166534; border-left:4px solid #16a34a; padding:12px 16px; border-radius:6px;">
            No certificates issued yet.
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="arise-table">
            <thead>
                <tr>
                    <th>Cert Number</th>
                    <th>Student</th>
                    <th>Module</th>
                    <th>Score %</th>
                    <th>Date Issued</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($certsData as $row): ?>
                <tr>
                    <td style="font-family:monospace; font-size:0.85rem;"><?= e($row['cert_number']) ?></td>
                    <td style="font-weight:600;"><?= e($row['student_name']) ?></td>
                    <td><?= e($row['module']) ?></td>
                    <td>
                        <span style="font-weight:600; color:<?= (float)$row['percentage'] >= 60 ? '#16a34a' : '#dc2626' ?>;">
                            <?= number_format((float)$row['percentage'], 1) ?>%
                        </span>
                    </td>
                    <td style="font-size:0.85rem;"><?= $row['issued_at'] ? date('M j, Y g:i A', strtotime($row['issued_at'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ========================================================
     SECTION 4: Most Difficult Questions
     ======================================================== -->
<div class="dp-card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 class="section-title" style="margin:0;">&#128683; Most Difficult Questions</h2>
            <div style="font-size:.82rem;color:#6b7280;margin-top:3px;">Questions with the highest wrong-answer rate (min 3 attempts). Useful for identifying content gaps.</div>
        </div>
        <a href="?p=reports&amp;export=difficult" class="btn btn-primary">&#128229; Export CSV</a>
    </div>

    <?php if (empty($difficultData)): ?>
        <div style="background:#f9fafb;border-radius:8px;padding:20px;text-align:center;color:#9ca3af;font-size:.85rem;">
            Not enough quiz attempts yet to generate this report. Data appears here once learners complete pre/post tests or quizzes.
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="arise-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Module</th>
                    <th>Question</th>
                    <th>Correct Answer</th>
                    <th style="text-align:center;">Attempts</th>
                    <th style="text-align:center;">Wrong</th>
                    <th style="text-align:center;">Wrong %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($difficultData as $i => $row):
                    $wpct = (float)$row['wrong_pct'];
                    $col = $wpct >= 80 ? '#dc2626' : ($wpct >= 60 ? '#d97706' : '#6b7280');
                ?>
                <tr>
                    <td style="color:#9ca3af;font-size:.82rem;"><?= $i+1 ?></td>
                    <td style="font-size:.82rem;font-weight:600;"><?= e($row['module']) ?></td>
                    <td style="font-size:.82rem;max-width:320px;"><?= e(substr($row['question'],0,120)) ?><?= strlen($row['question'])>120?'&hellip;':'' ?></td>
                    <td style="font-size:.8rem;color:#166534;font-weight:600;"><?= e(strtoupper($row['correct_option']).'. '.($row['correct_text']??'')) ?></td>
                    <td style="text-align:center;"><?= (int)$row['attempts'] ?></td>
                    <td style="text-align:center;"><?= (int)$row['wrong'] ?></td>
                    <td style="text-align:center;">
                        <span style="font-weight:700;color:<?=$col?>;background:<?=$wpct>=80?'#fee2e2':($wpct>=60?'#fef3c7':'#f3f4f6')?>;padding:2px 8px;border-radius:10px;font-size:.82rem;">
                            <?= $wpct ?>%
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
