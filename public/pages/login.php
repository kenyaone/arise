<?php
/**
 * Unified Login Page — Admin / Teacher / Student
 * Routes to correct dashboard based on role
 */
trackPageView('login');

$error = '';
$mode = $_GET['mode'] ?? 'select'; // select|student|admin|teacher

// Handle Admin Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $user = trim($_POST['admin_user'] ?? '');
    $pass = trim($_POST['admin_pass'] ?? '');
    if ($user && $pass) {
        $row = db()->querySingle("SELECT * FROM admin_users WHERE username='".SQLite3::escapeString($user)."' AND is_active=1", true);
        if ($row && password_verify($pass, $row['password_hash'])) {
            $_SESSION['arise_admin_id']   = $row['id'];
            $_SESSION['arise_admin_name'] = $row['full_name'] ?: $row['username'];
            $_SESSION['arise_admin_role'] = $row['role'];
            $perms = [];
            $pr = db()->query("SELECT permission FROM admin_permissions WHERE user_id=".$row['id']);
            while ($p = $pr->fetchArray(SQLITE3_ASSOC)) $perms[] = $p['permission'];
            $_SESSION['arise_permissions'] = $perms;
            header('Location: /arise/admin/dashboard');
            exit;
        } else {
            $error = 'Invalid admin credentials.';
            $mode = 'admin';
        }
    }
}

// Handle Teacher Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['teacher_login'])) {
    $user = trim($_POST['teacher_user'] ?? '');
    $pass = trim($_POST['teacher_pass'] ?? '');
    if ($user && $pass) {
        $teacher = db()->querySingle(
            "SELECT * FROM admin_users WHERE username='".SQLite3::escapeString($user)."' AND role='teacher' AND is_active=1",
            true
        );
        if ($teacher && password_verify($pass, $teacher['password_hash'])) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            // Set admin panel session
            $_SESSION['arise_admin_id']   = $teacher['id'];
            $_SESSION['arise_admin_name'] = $teacher['full_name'];
            $_SESSION['arise_admin_role'] = 'teacher';
            $_SESSION['arise_permissions'] = ['content_view', 'content_manage', 'students_view', 'dashboard'];
            header('Location: /arise/admin/dashboard');
            exit;
        } else {
            $error = 'Invalid teacher credentials.';
            $mode = 'teacher';
        }
    }
}

// Handle Student Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_login'])) {
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
            if (!empty($student['password_hash'])) {
                if (!password_verify($passwordInput, $student['password_hash'])) {
                    $error = 'Incorrect password. Please try again.';
                    $student = null;
                }
            }
        } else {
            $error = 'Name or cluster not found. Check spelling or register below.';
        }

        if ($student && !$error) {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['arise_student_id'] = $student['id'];
            setcookie('arise_uid', $student['id'], ['expires'=>time()+86400*30,'path'=>'/arise/','httponly'=>true,'samesite'=>'Lax']);
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
            awardXP($student['id'], 50, 'login', 'Returned to ARISE');
            header('Location: /arise/');
            exit;
        }
    } else {
        $error = 'Please enter your full name and cluster.';
        $mode = 'student';
    }
}
?>
<?php if ($mode === 'select'): ?>
<!-- LOGIN SELECTION PAGE -->
<div class="container">
    <div style="max-width:900px; margin:0 auto; padding:20px;">
        <div class="dp-card" style="text-align:center; padding:40px 32px; border-top:4px solid var(--pri); margin-bottom:24px;">
            <div style="font-size:3rem; margin-bottom:14px;">🌟</div>
            <h1 class="page-title" style="font-size:2rem; margin-bottom:8px;">Welcome to ARISE</h1>
            <p style="color:#6b7280; font-size:1rem; margin:0;">Choose how you'd like to sign in</p>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:20px; margin-bottom:20px;">
            <!-- ADMIN LOGIN -->
            <div class="dp-card" style="border-top:4px solid #3b82f6; padding:28px; cursor:pointer;" onclick="location.href='?p=login&mode=admin'">
                <div style="font-size:2.5rem; margin-bottom:12px;">👨‍💼</div>
                <h3 style="margin-bottom:6px; color:#1f2937; font-size:1.1rem;">Admin Login</h3>
                <p style="color:#6b7280; margin:0; font-size:0.9rem;">Access administration panel</p>
                <div style="margin-top:16px; padding-top:16px; border-top:1px solid #e5e7eb;">
                    <button type="button" class="btn btn-primary" style="width:100%; border-radius:8px; padding:10px;">Sign in →</button>
                </div>
            </div>

            <!-- TEACHER LOGIN -->
            <div class="dp-card" style="border-top:4px solid #0ea271; padding:28px; cursor:pointer;" onclick="location.href='?p=login&mode=teacher'">
                <div style="font-size:2.5rem; margin-bottom:12px;">👩‍🏫</div>
                <h3 style="margin-bottom:6px; color:#1f2937; font-size:1.1rem;">Teacher Login</h3>
                <p style="color:#6b7280; margin:0; font-size:0.9rem;">Access teacher dashboard</p>
                <div style="margin-top:16px; padding-top:16px; border-top:1px solid #e5e7eb;">
                    <button type="button" class="btn btn-success" style="width:100%; border-radius:8px; padding:10px;">Sign in →</button>
                </div>
            </div>

            <!-- STUDENT LOGIN -->
            <div class="dp-card" style="border-top:4px solid #f59e0b; padding:28px; cursor:pointer;" onclick="location.href='?p=login&mode=student'">
                <div style="font-size:2.5rem; margin-bottom:12px;">👨‍🎓</div>
                <h3 style="margin-bottom:6px; color:#1f2937; font-size:1.1rem;">Student Login</h3>
                <p style="color:#6b7280; margin:0; font-size:0.9rem;">Access your learning modules</p>
                <div style="margin-top:16px; padding-top:16px; border-top:1px solid #e5e7eb;">
                    <button type="button" class="btn" style="width:100%; background:#f59e0b; color:#fff; border:none; border-radius:8px; padding:10px; cursor:pointer; font-weight:600;">Sign in →</button>
                </div>
            </div>
        </div>

        <div class="dp-card" style="background:#f0fdf4; border-left:4px solid #10b981; padding:14px 18px;">
            <p style="margin:0; color:#166534; font-size:0.9rem;"><strong>New student?</strong> <a href="/arise/?p=register" style="color:#10b981; font-weight:700; text-decoration:none;">Register here →</a></p>
        </div>
    </div>
</div>

<?php elseif ($mode === 'admin'): ?>
<!-- ADMIN LOGIN FORM -->
<div class="container">
    <div style="max-width:460px; margin:0 auto;">
        <div class="dp-card" style="border-top:4px solid #3b82f6; padding:32px; margin-top:20px;">
            <div style="text-align:center; margin-bottom:24px;">
                <div style="font-size:2.2rem; margin-bottom:12px;">👨‍💼</div>
                <h1 class="page-title" style="margin-bottom:4px;">Admin Login</h1>
                <p style="color:#6b7280; margin:0; font-size:0.9rem;">Enter your admin credentials</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" style="background:#fef2f2; color:#991b1b; border-left:4px solid #dc2626; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="admin_login" value="1">
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:.75rem; font-weight:700; color:#374151; margin-bottom:6px; text-transform:uppercase;">
                        Username
                    </label>
                    <input type="text" name="admin_user" required autofocus placeholder="Enter admin username"
                           style="width:100%; padding:10px 14px; border:2px solid #e5e7eb; border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block; font-size:.75rem; font-weight:700; color:#374151; margin-bottom:6px; text-transform:uppercase;">
                        Password
                    </label>
                    <input type="password" name="admin_pass" required placeholder="Enter password"
                           style="width:100%; padding:10px 14px; border:2px solid #e5e7eb; border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; padding:12px; border-radius:8px; font-weight:700;">
                    Sign In →
                </button>
            </form>

            <div style="text-align:center; margin-top:16px;">
                <p style="color:#6b7280; font-size:0.85rem; margin:0;">
                    <a href="?p=login" style="font-weight:700; color:var(--pri); text-decoration:none;">← Back to login options</a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php elseif ($mode === 'teacher'): ?>
<!-- TEACHER LOGIN FORM -->
<div class="container">
    <div style="max-width:460px; margin:0 auto;">
        <div class="dp-card" style="border-top:4px solid #0ea271; padding:32px; margin-top:20px;">
            <div style="text-align:center; margin-bottom:24px;">
                <div style="font-size:2.2rem; margin-bottom:12px;">👩‍🏫</div>
                <h1 class="page-title" style="margin-bottom:4px;">Teacher Login</h1>
                <p style="color:#6b7280; margin:0; font-size:0.9rem;">Access your teacher dashboard</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" style="background:#fef2f2; color:#991b1b; border-left:4px solid #dc2626; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="teacher_login" value="1">
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:.75rem; font-weight:700; color:#374151; margin-bottom:6px; text-transform:uppercase;">
                        Username
                    </label>
                    <input type="text" name="teacher_user" required autofocus placeholder="Enter teacher username"
                           style="width:100%; padding:10px 14px; border:2px solid #e5e7eb; border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block; font-size:.75rem; font-weight:700; color:#374151; margin-bottom:6px; text-transform:uppercase;">
                        Password
                    </label>
                    <input type="password" name="teacher_pass" required placeholder="Enter password"
                           style="width:100%; padding:10px 14px; border:2px solid #e5e7eb; border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <button type="submit" class="btn btn-success" style="width:100%; padding:12px; border-radius:8px; font-weight:700;">
                    Sign In →
                </button>
            </form>

            <div style="text-align:center; margin-top:16px;">
                <p style="color:#6b7280; font-size:0.85rem; margin:0;">
                    <a href="?p=login" style="font-weight:700; color:var(--pri); text-decoration:none;">← Back to login options</a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php elseif ($mode === 'student'): ?>
<!-- STUDENT LOGIN FORM -->
<div class="container">
    <div style="max-width:460px; margin:0 auto;">
        <div class="dp-card" style="border-top:4px solid #f59e0b; padding:32px; margin-top:20px;">
            <div style="text-align:center; margin-bottom:24px;">
                <div style="font-size:2.2rem; margin-bottom:12px;">👨‍🎓</div>
                <h1 class="page-title" style="margin-bottom:4px;">Welcome Back</h1>
                <p style="color:#6b7280; margin:0; font-size:0.9rem;">Enter your name and cluster to continue</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" style="background:#fef2f2; color:#991b1b; border-left:4px solid #dc2626; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="student_login" value="1">
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:.75rem; font-weight:700; color:#374151; margin-bottom:6px; text-transform:uppercase;">
                        Full Name *
                    </label>
                    <input type="text" name="full_name" required autofocus placeholder="e.g. Jane Wanjiku"
                           value="<?= e($_POST['full_name'] ?? '') ?>"
                           style="width:100%; padding:10px 14px; border:2px solid #e5e7eb; border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:.75rem; font-weight:700; color:#374151; margin-bottom:6px; text-transform:uppercase;">
                        Cluster *
                    </label>
                    <input type="text" name="class_name" required placeholder="e.g. Form 2A, Grade 9"
                           value="<?= e($_POST['class_name'] ?? '') ?>"
                           style="width:100%; padding:10px 14px; border:2px solid #e5e7eb; border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block; font-size:.75rem; font-weight:700; color:#374151; margin-bottom:6px; text-transform:uppercase;">
                        Password <span style="font-weight:400; font-style:italic; text-transform:none; font-size:0.72rem;">(only if you set one)</span>
                    </label>
                    <input type="password" name="password" placeholder="Leave blank if you have no password"
                           style="width:100%; padding:10px 14px; border:2px solid #e5e7eb; border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; padding:12px; border-radius:8px; font-weight:700;">
                    Sign In →
                </button>
            </form>

            <div style="text-align:center; margin-top:16px;">
                <p style="color:#6b7280; font-size:0.85rem; margin:0;">
                    New here? <a href="/arise/?p=register" style="color:var(--pri); font-weight:700; text-decoration:none;">Register free →</a>
                </p>
                <p style="color:#6b7280; font-size:0.85rem; margin-top:8px;">
                    <a href="?p=login" style="font-weight:700; color:var(--pri); text-decoration:none;">← Back to login options</a>
                </p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
