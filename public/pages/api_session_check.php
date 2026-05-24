<?php
require_once __DIR__ . '/../../includes/config.php';
header('Content-Type: application/json');

$student = getStudentBySession();
if ($student) {
    echo json_encode(['status' => 'ok', 'role' => 'student', 'user' => [
        'id'        => $student['id'],
        'full_name' => $student['full_name'],
    ]]);
    exit;
}

if (!empty($_SESSION['arise_admin_id'])) {
    $stmt = db()->prepare('SELECT id, full_name, role FROM admin_users WHERE id = :id AND is_active = 1');
    $stmt->bindValue(':id', $_SESSION['arise_admin_id']);
    $admin = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($admin) {
        echo json_encode(['status' => 'ok', 'role' => $admin['role'], 'user' => $admin]);
        exit;
    }
}

echo json_encode(['status' => 'not_logged_in']);
