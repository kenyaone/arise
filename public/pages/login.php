<?php
/**
 * Student Login — name + cluster, with optional password support
 * "School" → "Project", "Class" → "Cluster" in UI
 * If student has password_hash set, password verification is required.
 */
trackPageView('login');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['full_name'] ?? '');
    $class = trim($_POST['class_name'] ?? '');
    $passwordInput = $_POST['password'] ?? '';

    if ($name && $class) {
        $stmt = db()->prepare(
            "SELECT * FROM students
             WHERE LOWER(full_name)=LOWER(:name)
               AND LOWER(class_name)=LOWER(:class)
               AND is_active=1
               AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->bindValue(':name',  $name);
        $stmt->bindValue(':class', $class);
        $student = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($student) {
            // If the student has a password set, verify it
            if (!empty($student['password_hash'])) {
                if (!password_verify($passwordInput, $student['password_hash'])) {
                    $error = 'Incorrect password. Please try again.';
                    $student = null; // block login
                }
            }
            // Password is NULL — allow login by name+cluster (no password needed)
        } else {
            $error = 'Name or cluster not found. Check spelling or register below.';
        }

        if ($student && !$error) {
            // Set session
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['arise_student_id'] = $student['id'];
            // Update session hash to link this student
            $hash = getSessionHash();
            if ($hash) {
                $stmt2 = db()->prepare('UPDATE students SET session_hash=:h WHERE id=:id');
                $stmt2->bindValue(':h', $hash);
                $stmt2->bindValue(':id', $student['id']);
                $stmt2->execute();
                $stmt3 = db()->prepare('UPDATE sessions SET student_id=:id WHERE session_hash=:h');
                $stmt3->bindValue(':id', $student['id']);
                $stmt3->bindValue(':h', $hash);
                $stmt3->execute();
            }
            // Award login XP
            awardXP($student['id'], 50, 'login', 'Returned to ARISE');
            header('Location: /arise/');
            exit;
        }
    } else {
        $error = 'Please enter your full name and cluster.';
    }
}

// Determine if we should show a password field.
// We show it always so users with passwords can enter them.
// Users without a password can leave it blank.
$showPasswordNote = true;
?>
<div class="container">
    <div style="max-width:460px; margin:0 auto;">
        <div class="dp-card" style="border-top:4px solid var(--pri); padding:32px; margin-top:20px;">

            <div style="text-align:center; margin-bottom:20px;">
                <div style="width:64px; height:64px; background:linear-gradient(135deg,var(--pri),var(--rose)); border-radius:18px; display:inline-flex; align-items:center; justify-content:center; font-size:1.8rem; margin-bottom:14px;">💜</div>
                <h1 class="page-title" style="margin-bottom:4px;">Welcome Back</h1>
                <p style="color:#6b7280; margin:0; font-size:0.9rem;">Enter your name and cluster to continue</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" style="background:#fef2f2; color:#991b1b; border-left:4px solid #dc2626; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <!-- Full Name -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" style="display:block; font-size:.75rem; font-weight:700; color:var(--mid); margin-bottom:6px; text-transform:uppercase;">
                        Full Name *
                    </label>
                    <input type="text"
                           name="full_name"
                           class="form-control"
                           placeholder="e.g. Jane Wanjiku"
                           required
                           autofocus
                           value="<?= e($_POST['full_name'] ?? '') ?>"
                           style="width:100%; padding:10px 14px; border:2px solid var(--border); border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <!-- Cluster (was: Class / Form) -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label" style="display:block; font-size:.75rem; font-weight:700; color:var(--mid); margin-bottom:6px; text-transform:uppercase;">
                        Cluster *
                    </label>
                    <input type="text"
                           name="class_name"
                           class="form-control"
                           placeholder="e.g. Form 2A, Grade 9"
                           value="<?= e($_POST['class_name'] ?? '') ?>"
                           style="width:100%; padding:10px 14px; border:2px solid var(--border); border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <!-- Password (optional — only required if account has one set) -->
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" style="display:block; font-size:.75rem; font-weight:700; color:var(--mid); margin-bottom:6px; text-transform:uppercase;">
                        Password <span style="font-weight:400; font-style:italic; text-transform:none; font-size:0.72rem;">(only if you set one)</span>
                    </label>
                    <input type="password"
                           name="password"
                           class="form-control"
                           placeholder="Leave blank if you have no password"
                           style="width:100%; padding:10px 14px; border:2px solid var(--border); border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <button type="submit" class="btn btn-primary"
                        style="width:100%; border-radius:12px; padding:14px; font-size:1rem;">
                    Sign In &#8594;
                </button>
            </form>

            <div style="text-align:center; margin-top:16px;">
                <p style="color:#6b7280; font-size:0.85rem; margin:0;">
                    New here? <a href="/arise/?p=register" style="font-weight:700; color:var(--pri);">Register free &rarr;</a>
                </p>
            </div>
        </div>
    </div>
</div>
