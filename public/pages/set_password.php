<?php
/**
 * Set (or reset) learner password.
 * Reachable only when login.php has determined the learner has a NULL
 * password_hash — either freshly reset by an admin, or never set.
 * The pending student's id + name are handed to us via $_SESSION.
 */
trackPageView('set_password');

if (session_status() === PHP_SESSION_NONE) session_start();

$pendingId   = $_SESSION['arise_pending_pw_student_id']   ?? null;
$pendingName = $_SESSION['arise_pending_pw_student_name'] ?? '';

if (!$pendingId) {
    // Direct hit / stale session — bounce back to login.
    header('Location: /arise/?p=login&mode=student');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = $_POST['password']         ?? '';
    $pw2 = $_POST['password_confirm'] ?? '';

    if (strlen($pw) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($pw !== $pw2) {
        $error = 'The two passwords do not match.';
    } else {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE students SET password_hash=:h WHERE id=:id');
        $stmt->bindValue(':h',  $hash);
        $stmt->bindValue(':id', $pendingId, SQLITE3_INTEGER);
        $stmt->execute();

        // Complete the login the same way login.php would have.
        $_SESSION['arise_student_id'] = $pendingId;
        unset(
            $_SESSION['arise_pending_pw_student_id'],
            $_SESSION['arise_pending_pw_student_name']
        );
        setcookie('arise_uid', $pendingId, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/arise/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $sessHash = function_exists('getSessionHash') ? getSessionHash() : null;
        if ($sessHash) {
            $u = db()->prepare('UPDATE students SET session_hash=:h WHERE id=:id');
            $u->bindValue(':h',  $sessHash);
            $u->bindValue(':id', $pendingId, SQLITE3_INTEGER);
            $u->execute();
        }

        header('Location: /arise/');
        exit;
    }
}
?>
<div class="container">
    <div style="max-width:460px; margin:0 auto;">
        <div class="dp-card" style="border-top:4px solid #f59e0b; padding:32px; margin-top:20px;">
            <div style="text-align:center; margin-bottom:24px;">
                <div style="font-size:2.2rem; margin-bottom:12px;">🔐</div>
                <h1 class="page-title" style="margin-bottom:4px;">Set a new password</h1>
                <p style="color:#6b7280; margin:0; font-size:0.9rem;">
                    Welcome, <strong><?= htmlspecialchars($pendingName) ?></strong>.
                    Your password has been reset — please choose a new one to continue.
                </p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" style="background:#fef2f2; color:#991b1b; border-left:4px solid #dc2626; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:.75rem; font-weight:700; color:#374151; margin-bottom:6px; text-transform:uppercase;">
                        New password <span style="font-weight:400; font-style:italic; text-transform:none; font-size:0.72rem;">(min 6 characters)</span>
                    </label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="ariseNewPw1" required minlength="6" autofocus
                               style="width:100%; padding:10px 40px 10px 14px; border:2px solid #e5e7eb; border-radius:8px; font-size:1rem; box-sizing:border-box;">
                        <button type="button" onclick="arisePwToggle('ariseNewPw1', this)" aria-label="Show password"
                                style="position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:0; cursor:pointer; padding:6px; font-size:1rem; color:#6b7280;">👁</button>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block; font-size:.75rem; font-weight:700; color:#374151; margin-bottom:6px; text-transform:uppercase;">
                        Confirm new password
                    </label>
                    <div style="position:relative;">
                        <input type="password" name="password_confirm" id="ariseNewPw2" required minlength="6"
                               style="width:100%; padding:10px 40px 10px 14px; border:2px solid #e5e7eb; border-radius:8px; font-size:1rem; box-sizing:border-box;">
                        <button type="button" onclick="arisePwToggle('ariseNewPw2', this)" aria-label="Show password"
                                style="position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:0; cursor:pointer; padding:6px; font-size:1rem; color:#6b7280;">👁</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; padding:12px; border-radius:8px; font-weight:700;">
                    Set password &amp; continue →
                </button>
            </form>
        </div>
    </div>
</div>

<script>
window.arisePwToggle = window.arisePwToggle || function(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    var showing = el.type === 'password';
    el.type = showing ? 'text' : 'password';
    btn.textContent = showing ? '🙈' : '👁';
    btn.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
};
</script>
