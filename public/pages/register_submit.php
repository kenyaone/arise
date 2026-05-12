<?php
/**
 * Student Registration Submit Handler (patched)
 * Validates password, hashes it, and stores in password_hash column.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /arise/?p=register');
    exit;
}

$name    = trim($_POST['full_name']   ?? '');
$school  = trim($_POST['school_name'] ?? '');
$class   = trim($_POST['class_name']  ?? '');
$password        = $_POST['password']         ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';

// ============================================================
// Basic field validation
// ============================================================
if (empty($name) || empty($school)) {
    header('Location: /arise/?p=register&error=1');
    exit;
}

// ============================================================
// Password validation
// ============================================================
if (empty($password)) {
    header('Location: /arise/?p=register&password_error=' . urlencode('Password is required.'));
    exit;
}
if (strlen($password) < 6) {
    header('Location: /arise/?p=register&password_error=' . urlencode('Password must be at least 6 characters.'));
    exit;
}
if ($password !== $passwordConfirm) {
    header('Location: /arise/?p=register&password_error=' . urlencode('Passwords do not match.'));
    exit;
}

// ============================================================
// Hash the password
// ============================================================
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// ============================================================
// Register student with password_hash
// ============================================================
$hash = getSessionHash();

// Check if student already exists (same name + class)
$existingStmt = db()->prepare(
    "SELECT id FROM students
     WHERE LOWER(full_name)=LOWER(:name) AND LOWER(class_name)=LOWER(:class)
     LIMIT 1"
);
$existingStmt->bindValue(':name', $name);
$existingStmt->bindValue(':class', $class);
$existing = $existingStmt->execute()->fetchArray(SQLITE3_ASSOC);

if ($existing) {
    // Student already exists — just log them in (update password if they're re-registering)
    $updateStmt = db()->prepare(
        'UPDATE students SET password_hash=:ph, session_hash=:h, is_active=1, deleted_at=NULL WHERE id=:id'
    );
    $updateStmt->bindValue(':ph', $passwordHash);
    $updateStmt->bindValue(':h',  $hash);
    $updateStmt->bindValue(':id', $existing['id']);
    $updateStmt->execute();

    // Link session
    $stmt = db()->prepare('UPDATE sessions SET student_id=:sid WHERE session_hash=:h');
    $stmt->bindValue(':sid', $existing['id']);
    $stmt->bindValue(':h',   $hash);
    $stmt->execute();

    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['arise_student_id']   = $existing['id'];
    $_SESSION['arise_student_name'] = $name;
    setcookie('arise_uid', $existing['id'], ['expires'=>time()+86400*30,'path'=>'/arise/','httponly'=>true,'samesite'=>'Lax']);

    header('Location: /arise/?p=modules&exists=1');
    exit;
}

// Insert new student with password_hash
$stmt = db()->prepare(
    'INSERT INTO students (full_name, school_name, class_name, session_hash, password_hash)
     VALUES (:name, :school, :class, :hash, :ph)'
);
$stmt->bindValue(':name',   $name);
$stmt->bindValue(':school', $school);
$stmt->bindValue(':class',  $class);
$stmt->bindValue(':hash',   $hash);
$stmt->bindValue(':ph',     $passwordHash);
$stmt->execute();
$studentId = db()->lastInsertRowID();

// Link session to student
$linkStmt = db()->prepare('UPDATE sessions SET student_id=:sid WHERE session_hash=:h');
$linkStmt->bindValue(':sid', $studentId);
$linkStmt->bindValue(':h',   $hash);
$linkStmt->execute();

// Set session
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['arise_student_id']   = $studentId;
$_SESSION['arise_student_name'] = $name;
setcookie('arise_uid', $studentId, ['expires'=>time()+86400*30,'path'=>'/arise/','httponly'=>true,'samesite'=>'Lax']);

header('Location: /arise/?p=modules');
exit;
