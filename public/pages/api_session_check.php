<?php
/**
 * API endpoint to check if session is still valid
 * Returns JSON with user info or error if not logged in
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

// Check student session
if (!empty($_SESSION['arise_student_id'])) {
    $stmt = db()->prepare('SELECT id, full_name, school_name, class_name FROM students WHERE id = :id AND is_active = 1');
    $stmt->bindValue(':id', $_SESSION['arise_student_id']);
    $result = $stmt->execute();
    $student = $result->fetchArray(SQLITE3_ASSOC);

    if ($student) {
        echo json_encode(['status' => 'ok', 'role' => 'student', 'user' => $student]);
        exit;
    }
}

// Check admin/teacher session
if (!empty($_SESSION['arise_admin_id'])) {
    $stmt = db()->prepare('SELECT id, full_name, role FROM admin_users WHERE id = :id AND is_active = 1');
    $stmt->bindValue(':id', $_SESSION['arise_admin_id']);
    $result = $stmt->execute();
    $admin = $result->fetchArray(SQLITE3_ASSOC);

    if ($admin) {
        echo json_encode(['status' => 'ok', 'role' => $admin['role'], 'user' => $admin]);
        exit;
    }
}

// Not logged in
echo json_encode(['status' => 'not_logged_in']);
exit;
