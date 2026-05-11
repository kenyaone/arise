<?php
/**
 * My Certificates page — shows all certificates earned by this student
 */
trackPageView('certificates');

$student = getStudentBySession();
$certs = [];
$refusalPassed = false;

if ($student) {
    $stmt = db()->prepare("SELECT c.*, m.icon FROM certificates c JOIN modules m ON c.module_id = m.id WHERE c.student_id = :sid ORDER BY c.issued_at DESC");
    $stmt->bindValue(':sid', $student['id']);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $certs[] = $row; }

    $refusalPassed = (bool) db()->querySingle(
        "SELECT id FROM quiz_attempts WHERE student_id=" . intval($student['id']) .
        " AND lesson_slug='refusal-skills-lesson' AND percentage>=60 LIMIT 1"
    );
}

$REFUSAL_GATED = [1, 4, 5, 6];
?>

<div class="container">
    <div class="breadcrumb">
        <a href="/">Home</a> <span class="sep">›</span> <span>My Certificates</span>
    </div>

    <h1 class="page-title" style="margin-bottom:5px;">🎓 My Certificates</h1>
    <p class="text-muted mb-2">Score 70% or higher on any module quiz to earn a certificate.</p>

    <?php if (!$student): ?>
        <div class="dp-card text-center">
            <div style="font-size:2.5rem;">📝</div>
            <p style="margin:10px 0;">Register first to start earning certificates!</p>
            <a href="?p=register" class="btn btn-primary">📝 Register Now</a>
        </div>

    <?php elseif (count($certs) === 0): ?>
        <div class="dp-card text-center">
            <div style="font-size:2.5rem;">📚</div>
            <p style="margin:10px 0 15px;">You haven't earned any certificates yet. Take a module quiz and score 70% or higher!</p>
            <a href="?p=modules" class="btn btn-primary">📚 Browse Modules</a>
        </div>

    <?php else: ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:15px;">
            <?php foreach ($certs as $c): ?>
            <?php
                $isGated = in_array($c['module_id'], $REFUSAL_GATED) && !$refusalPassed;
                $modSlug = db()->querySingle("SELECT slug FROM modules WHERE id={$c['module_id']}");
            ?>
            <div class="dp-card" style="border-top:4px solid <?= $isGated ? '#f59e0b' : 'var(--primary)' ?>; text-align:center; position:relative;">
                <?php if ($isGated): ?>
                <div style="position:absolute;top:10px;right:10px;background:#fef3c7;border:1px solid #f59e0b;border-radius:20px;padding:2px 8px;font-size:.7rem;font-weight:700;color:#92400e;">🔒 Locked</div>
                <?php endif; ?>
                <div style="font-size:2rem;"><?= $c['icon'] ?></div>
                <h3 style="margin:5px 0;"><?= e($c['module_title']) ?></h3>
                <div style="font-size:1.8rem; font-weight:700; color:var(--success); margin:8px 0;"><?= $c['percentage'] ?>%</div>
                <div class="text-muted text-small" style="margin-bottom:12px;">
                    Earned <?= date('M j, Y', strtotime($c['issued_at'])) ?><br>
                    <span style="font-family:monospace; font-size:0.75rem;"><?= e($c['cert_number']) ?></span>
                </div>
                <?php if ($isGated): ?>
                <a href="?p=lesson&slug=refusal-skills-lesson&required=1" class="btn btn-sm" style="background:#f59e0b;color:#fff;border:none;">
                    ⚠️ Complete Refusal Skills to unlock
                </a>
                <?php else: ?>
                <a href="?p=certificate&module=<?= urlencode($modSlug) ?>&score=<?= intval($c['percentage']) ?>"
                   class="btn btn-primary btn-sm" target="_blank">🖨️ View & Print</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="mt-2">
        <a href="?p=verify" class="btn btn-secondary">🔍 Verify a Certificate</a>
    </div>
</div>
