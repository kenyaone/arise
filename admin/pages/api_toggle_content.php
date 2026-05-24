<?php
/**
 * API endpoint for publish/unpublish toggle
 * Returns JSON without HTML wrapper
 */

// Check if logged in as teacher/admin
$isTeacher = false;

if (isset($_SESSION['arise_admin_id']) && isset($_SESSION['arise_admin_role'])) {
    $isTeacher = in_array($_SESSION['arise_admin_role'], ['teacher', 'admin']);
}

if (!$isTeacher) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Handle toggle request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $type = $_POST['type'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    $status = intval($_POST['status'] ?? 0);

    if ($action === 'toggle' && $type && $id) {
        try {
            if ($type === 'lesson') {
                $st = db()->prepare("UPDATE lessons SET is_published = :s WHERE id = :id");
                $st->bindValue(':s', $status);
                $st->bindValue(':id', $id);
                $st->execute();
                $msg = 'Lesson ' . ($status ? 'published' : 'unpublished');
            } elseif ($type === 'quiz') {
                $st = db()->prepare("UPDATE quiz_questions SET is_published = :s WHERE id = :id");
                $st->bindValue(':s', $status);
                $st->bindValue(':id', $id);
                $st->execute();
                $msg = 'Quiz ' . ($status ? 'published' : 'unpublished');
            } else {
                throw new Exception('Invalid type');
            }

            http_response_code(200);
            echo json_encode(['status' => 'ok', 'message' => $msg]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
exit;
