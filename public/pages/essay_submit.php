<?php
/**
 * Save essay response to database
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$questionId = intval($_POST['question_id'] ?? 0);
$moduleId = intval($_POST['module_id'] ?? 0);
$response = trim($_POST['response'] ?? '');
$wordCount = intval($_POST['word_count'] ?? 0);

if ($questionId > 0 && $moduleId > 0 && !empty($response)) {
    $stmt = db()->prepare('INSERT INTO essay_responses (student_id, session_hash, question_id, module_id, response_text, word_count) VALUES (:sid, :hash, :qid, :mod, :resp, :wc)');
    $stmt->bindValue(':sid', getStudentId());
    $stmt->bindValue(':hash', getSessionHash());
    $stmt->bindValue(':qid', $questionId);
    $stmt->bindValue(':mod', $moduleId);
    $stmt->bindValue(':resp', $response);
    $stmt->bindValue(':wc', $wordCount);
    $stmt->execute();
    echo json_encode(['status' => 'ok']);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error']);
}
