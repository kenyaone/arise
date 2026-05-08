<?php
/**
 * AJAX endpoint to save quiz results
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$moduleId = intval($_POST['module_id'] ?? 0);
$score = intval($_POST['score'] ?? 0);
$total = intval($_POST['total'] ?? 0);
$percentage = floatval($_POST['percentage'] ?? 0);

if ($moduleId > 0 && $total > 0) {
    $stmt = db()->prepare('INSERT INTO quiz_attempts (session_hash, student_id, module_id, score, total_questions, percentage) VALUES (:hash, :sid, :mod, :score, :total, :pct)');
    $stmt->bindValue(':hash', getSessionHash());
    $stmt->bindValue(':sid', getStudentId());
    $stmt->bindValue(':mod', $moduleId);
    $stmt->bindValue(':score', $score);
    $stmt->bindValue(':total', $total);
    $stmt->bindValue(':pct', $percentage);
    $stmt->execute();
    $attemptId = db()->lastInsertRowID();

    // Save per-question answers for review
    $answersJson = $_POST['answers_json'] ?? '[]';
    $answers = json_decode($answersJson, true) ?: [];
    foreach ($answers as $a) {
        $qid = intval($a['question_id'] ?? 0);
        $chosen = trim($a['chosen'] ?? '');
        $isCorrect = intval($a['is_correct'] ?? 0);
        $st2 = db()->prepare('INSERT INTO quiz_answers (attempt_id,question_id,chosen_option,is_correct) VALUES (:ai,:qi,:cho,:ic)');
        $st2->bindValue(':ai',$attemptId);$st2->bindValue(':qi',$qid);$st2->bindValue(':cho',$chosen);$st2->bindValue(':ic',$isCorrect);
        $st2->execute();
    }

    // Update retry log
    $hash = getSessionHash();
    $existing = db()->querySingle("SELECT id,attempt_count FROM quiz_retry_log WHERE session_hash='".SQLite3::escapeString($hash)."' AND module_id=$moduleId",true);
    if ($existing) {
        db()->exec("UPDATE quiz_retry_log SET attempt_count=attempt_count+1, last_attempt=CURRENT_TIMESTAMP WHERE id=".$existing['id']);
    } else {
        $st3=db()->prepare('INSERT INTO quiz_retry_log (session_hash,module_id,attempt_count) VALUES (:h,:m,1)');
        $st3->bindValue(':h',$hash);$st3->bindValue(':m',$moduleId);$st3->execute();
    }
    
    updateDailyStats();
    
    echo json_encode(['status' => 'ok', 'attempt_id' => $attemptId]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error']);
}
