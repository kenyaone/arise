<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?p=ask');
    exit;
}

$question = trim($_POST['question'] ?? '');
$moduleId = intval($_POST['module_id'] ?? 0) ?: null;

if (empty($question)) {
    header('Location: ?p=ask');
    exit;
}

$stmt = db()->prepare('INSERT INTO anonymous_questions (question, module_id) VALUES (:q, :mod)');
$stmt->bindValue(':q', $question);
$stmt->bindValue(':mod', $moduleId);
$stmt->execute();

trackPageView('question_box', 'submitted', $moduleId);
updateDailyStats();

header('Location: ?p=ask&sent=1');
exit;
