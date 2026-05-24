<?php
// Handle answering a question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['answer_id'])) {
    $stmt = db()->prepare('UPDATE anonymous_questions SET answer = :ans, is_answered = 1, answered_at = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->bindValue(':ans', trim($_POST['answer']));
    $stmt->bindValue(':id', intval($_POST['answer_id']));
    $stmt->execute();
}

// Handle deleting
if (isset($_GET['delete'])) {
    $stmt = db()->prepare('DELETE FROM anonymous_questions WHERE id = :id');
    $stmt->bindValue(':id', intval($_GET['delete']));
    $stmt->execute();
    header('Location: ?p=questions');
    exit;
}

$unanswered = db()->query("SELECT aq.*, m.title AS module_title, m.icon FROM anonymous_questions aq LEFT JOIN modules m ON aq.module_id = m.id WHERE aq.is_answered = 0 ORDER BY aq.submitted_at DESC");
$unansweredList = [];
while ($row = $unanswered->fetchArray(SQLITE3_ASSOC)) { $unansweredList[] = $row; }

$answered = db()->query("SELECT aq.*, m.title AS module_title, m.icon FROM anonymous_questions aq LEFT JOIN modules m ON aq.module_id = m.id WHERE aq.is_answered = 1 ORDER BY aq.answered_at DESC LIMIT 20");
$answeredList = [];
while ($row = $answered->fetchArray(SQLITE3_ASSOC)) { $answeredList[] = $row; }
?>

<h1 class="page-title">❓ Anonymous Questions</h1>

<!-- Unanswered -->
<h2 class="section-title">🔴 Unanswered (<?= count($unansweredList) ?>)</h2>
<?php if (count($unansweredList) === 0): ?>
    <div class="alert alert-success mb-2">All questions have been answered! 🎉</div>
<?php endif; ?>

<?php foreach ($unansweredList as $q): ?>
<div class="dp-card">
    <?php if ($q['module_title']): ?>
        <span class="text-small text-muted"><?= $q['icon'] ?> <?= e($q['module_title']) ?></span>
    <?php endif; ?>
    <p style="font-weight:600; margin:8px 0; font-size:1.05rem;">Q: <?= e($q['question']) ?></p>
    <small class="text-muted">Submitted: <?= date('M j, Y g:i A', strtotime($q['submitted_at'])) ?></small>

    <form method="POST" style="margin-top:12px;">
        <input type="hidden" name="answer_id" value="<?= $q['id'] ?>">
        <textarea name="answer" placeholder="Type your answer here..." required
            style="width:100%; min-height:80px; padding:12px; border:2px solid var(--border); border-radius:8px; font-size:0.95rem; font-family:inherit;"></textarea>
        <div style="display:flex; gap:10px; margin-top:10px;">
            <button type="submit" class="btn btn-primary">✅ Submit Answer</button>
            <a href="?p=questions&delete=<?= $q['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this question?')">🗑️ Delete</a>
        </div>
    </form>
</div>
<?php endforeach; ?>

<!-- Answered -->
<h2 class="section-title mt-3">✅ Recently Answered (<?= count($answeredList) ?>)</h2>
<?php foreach ($answeredList as $q): ?>
<div class="dp-card">
    <?php if ($q['module_title']): ?>
        <span class="text-small text-muted"><?= $q['icon'] ?> <?= e($q['module_title']) ?></span>
    <?php endif; ?>
    <p style="font-weight:600; margin:8px 0;">Q: <?= e($q['question']) ?></p>
    <div style="background:var(--light); padding:12px; border-radius:8px; border-left:3px solid var(--success);">
        <strong>A:</strong> <?= nl2br(e($q['answer'])) ?>
    </div>
    <small class="text-muted">Answered: <?= date('M j, Y', strtotime($q['answered_at'])) ?></small>
</div>
<?php endforeach; ?>
