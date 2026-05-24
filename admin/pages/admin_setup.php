<?php
$message = '';
$school = getSchoolConfig();

// Ensure webhook_url column exists (safe migration — runs once, ignored after)
try {
    db()->exec("ALTER TABLE datapost_config ADD COLUMN webhook_url TEXT DEFAULT ''");
} catch (Exception $e) { /* column already exists — fine */ }

// ── Email: Save Email Endpoint ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_email') {
    $email = trim($_POST['email_endpoint'] ?? '');
    if ($school) {
        $stmt = db()->prepare('UPDATE datapost_config SET email_endpoint=:email WHERE id=:id');
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':id', $school['id']);
        $stmt->execute();
        $message = '✅ Email endpoint saved!';
    } else {
        $message = '❌ Please save School Information first.';
    }
    $school = getSchoolConfig();
}

// ── Webhook: Save URL ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_webhook') {
    $webhookUrl = trim($_POST['webhook_url'] ?? '');
    if ($school) {
        $stmt = db()->prepare('UPDATE datapost_config SET webhook_url=:url WHERE id=:id');
        $stmt->bindValue(':url', $webhookUrl);
        $stmt->bindValue(':id', $school['id']);
        $stmt->execute();
        $message = '✅ Webhook URL saved!';
    } else {
        $message = '❌ Please save School Information first before setting a webhook URL.';
    }
    $school = getSchoolConfig();
}

// ── Webhook: Send Now ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_webhook') {
    $school = getSchoolConfig();
    $webhookUrl = trim($school['webhook_url'] ?? '');

    if (empty($webhookUrl)) {
        $message = '❌ No webhook URL configured. Please save a URL first.';
    } elseif (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
        $message = '❌ Webhook URL is not valid.';
    } else {
        // Build ping + summary payload (mirrors datapost.php)
        $summaryRows = [
            ['Total Learners (active)',    db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1 AND deleted_at IS NULL") ?? 0],
            ['Total Projects',             db()->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1") ?? 0],
            ['Total Clusters',             db()->querySingle("SELECT COUNT(*) FROM classes WHERE is_active=1") ?? 0],
            ['Total Modules',              db()->querySingle("SELECT COUNT(*) FROM modules WHERE is_active=1") ?? 0],
            ['Total Lessons',              db()->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1") ?? 0],
            ['Lessons Completed',          db()->querySingle("SELECT COUNT(*) FROM lesson_progress WHERE completed_at IS NOT NULL") ?? 0],
            ['Quiz Attempts',              db()->querySingle("SELECT COUNT(*) FROM quiz_attempts") ?? 0],
            ['Pre-Tests Completed',        db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='pre'") ?? 0],
            ['Post-Tests Completed',       db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='post'") ?? 0],
            ['Avg Quiz Score %',           db()->querySingle("SELECT ROUND(AVG(percentage),1) FROM quiz_attempts") ?? 0],
            ['Learners Passed >=60%',      db()->querySingle("SELECT COUNT(DISTINCT student_id) FROM quiz_attempts WHERE percentage>=60") ?? 0],
            ['Certificates Issued',        db()->querySingle("SELECT COUNT(*) FROM certificates") ?? 0],
            ['Forum Posts',                db()->querySingle("SELECT COUNT(*) FROM forum_posts WHERE is_hidden=0") ?? 0],
            ['Anonymous Questions',        db()->querySingle("SELECT COUNT(*) FROM anonymous_questions") ?? 0],
            ['Questions Answered',         db()->querySingle("SELECT COUNT(*) FROM anonymous_questions WHERE is_answered=1") ?? 0],
        ];
        // Learners per project
        $pq = db()->query("SELECT school_name, COUNT(*) AS cnt FROM students WHERE is_active=1 AND deleted_at IS NULL GROUP BY school_name ORDER BY cnt DESC");
        while ($pr = $pq->fetchArray(SQLITE3_ASSOC)) {
            $summaryRows[] = ['  Learners — ' . ($pr['school_name'] ?: 'No Project'), $pr['cnt']];
        }

        $payload = json_encode([
            'status'         => 'ok',
            'platform'       => 'ARISE',
            'school_id'      => $school['school_id']   ?? '',
            'school_name'    => $school['school_name'] ?? '',
            'county'         => $school['county']      ?? '',
            'sub_county'     => $school['sub_county']  ?? '',
            'learners'       => db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1") ?? 0,
            'projects'       => db()->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1") ?? 0,
            'clusters'       => db()->querySingle("SELECT COUNT(*) FROM classes WHERE is_active=1") ?? 0,
            'lessons'        => db()->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1") ?? 0,
            'quiz_attempts'  => db()->querySingle("SELECT COUNT(*) FROM quiz_attempts") ?? 0,
            'pretests_done'  => db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='pre'") ?? 0,
            'posttests_done' => db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='post'") ?? 0,
            'certs_issued'   => db()->querySingle("SELECT COUNT(*) FROM certificates") ?? 0,
            'forum_posts'    => db()->querySingle("SELECT COUNT(*) FROM forum_posts WHERE is_hidden=0") ?? 0,
            'time'           => date('Y-m-d H:i:s'),
            'table'          => 'summary',
            'headers'        => ['Metric', 'Value'],
            'count'          => count($summaryRows),
            'protected'      => 'Learner names omitted — ARISE Data Protection Policy',
            'data'           => $summaryRows,
        ]);

        // POST via cURL (preferred) or file_get_contents fallback
        $webhookSuccess = false;
        $webhookResponse = '';
        $webhookError = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'User-Agent: ARISE-DataPost/1.0'],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);
            $webhookResponse = curl_exec($ch);
            $httpCode        = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $webhookError    = curl_error($ch);
            curl_close($ch);

            if ($webhookResponse !== false && $httpCode >= 200 && $httpCode < 300) {
                $webhookSuccess = true;
            } elseif ($webhookError) {
                $webhookError = 'cURL error: ' . $webhookError;
            } else {
                $webhookError = "Server returned HTTP $httpCode";
            }
        } else {
            // Fallback: file_get_contents with stream context
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\nUser-Agent: ARISE-DataPost/1.0\r\n",
                    'content' => $payload,
                    'timeout' => 15,
                    'ignore_errors' => true,
                ],
            ]);
            $webhookResponse = @file_get_contents($webhookUrl, false, $ctx);
            if ($webhookResponse === false) {
                $webhookError = 'Could not reach the webhook URL. Check that the server has internet access and the URL is correct.';
            } else {
                $webhookSuccess = true;
            }
        }

        if ($webhookSuccess) {
            $preview = mb_substr((string)$webhookResponse, 0, 120);
            $message = '✅ Data sent successfully! Webhook responded: <code>' . htmlspecialchars($preview, ENT_QUOTES) . '</code>';
        } else {
            $message = '❌ Webhook delivery failed. ' . htmlspecialchars($webhookError ?: 'Unknown error.', ENT_QUOTES)
                . '<br><small>Tip: Make sure the server has outbound internet access and the URL is reachable.</small>';
        }
    }
}

// Handle logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['school_logo'])) {
    $file = $_FILES['school_logo'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (in_array($mime, $allowed) && $file['size'] <= 2 * 1024 * 1024) {
            $ext = match($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                default => 'png'
            };
            $logoDir = __DIR__ . '/../../data/uploads/logos/';
            if (!is_dir($logoDir)) mkdir($logoDir, 0775, true);

            // Remove old logos
            foreach (glob($logoDir . 'school_logo.*') as $old) unlink($old);

            $logoPath = $logoDir . 'school_logo.' . $ext;
            move_uploaded_file($file['tmp_name'], $logoPath);
            chmod($logoPath, 0664);
            $message = '✅ Logo uploaded successfully!';
        } else {
            $message = '❌ Invalid file. Use PNG, JPG, GIF, WebP or SVG (max 2MB).';
        }
    }
}

// Handle remove logo
if (isset($_GET['remove_logo'])) {
    $logoDir = __DIR__ . '/../../data/uploads/logos/';
    foreach (glob($logoDir . 'school_logo.*') as $old) unlink($old);
    $message = '✅ Logo removed.';
}

// Handle school config save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['school_id'])) {
    $schoolId = trim($_POST['school_id'] ?? '');
    $schoolName = trim($_POST['school_name'] ?? '');
    $county = trim($_POST['county'] ?? '');
    $subCounty = trim($_POST['sub_county'] ?? '');
    $contact = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['contact_phone'] ?? '');

    if ($school) {
        $stmt = db()->prepare('UPDATE datapost_config SET school_id=:sid, school_name=:name, county=:county, sub_county=:sub, contact_person=:contact, contact_phone=:phone WHERE id=:id');
        $stmt->bindValue(':id', $school['id']);
    } else {
        $stmt = db()->prepare('INSERT INTO datapost_config (school_id, school_name, county, sub_county, contact_person, contact_phone) VALUES (:sid, :name, :county, :sub, :contact, :phone)');
    }
    $stmt->bindValue(':sid', $schoolId);
    $stmt->bindValue(':name', $schoolName);
    $stmt->bindValue(':county', $county);
    $stmt->bindValue(':sub', $subCounty);
    $stmt->bindValue(':contact', $contact);
    $stmt->bindValue(':phone', $phone);
    $stmt->execute();
    $message = '✅ School configuration saved!';
    $school = getSchoolConfig();
}

// Handle password change
if (isset($_POST['change_password'])) {
    $newPass = $_POST['new_password'] ?? '';
    if (strlen($newPass) >= 6) {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE admin_users SET password_hash = :hash WHERE username = :user');
        $stmt->bindValue(':hash', $hash);
        $stmt->bindValue(':user', $_SESSION['arise_admin']);
        $stmt->execute();
        $message = '✅ Password changed!';
    } else {
        $message = '❌ Password must be at least 6 characters.';
    }
}

// Handle clean reset
if (isset($_POST['factory_reset']) && $_POST['confirm_reset'] === 'RESET') {
    // Reset database
    if (file_exists(DB_PATH)) {
        // Backup first
        $backupDir = dirname(DB_PATH) . '/backups/';
        if (!is_dir($backupDir)) mkdir($backupDir, 0775, true);
        copy(DB_PATH, $backupDir . 'pre_reset_' . date('Y-m-d_His') . '.db');
        unlink(DB_PATH);
    }
    initDatabase();
    $message = '✅ System reset to factory defaults. Please login again.';
    session_destroy();
    header('Location: /arise/login');
    exit;
}

$logoUrl = getLogoUrl();
?>

<h1 class="page-title">⚙️ School Setup</h1>
<?php if ($message): ?><div class="alert <?= str_starts_with($message, '✅') ? 'alert-success' : 'alert-danger' ?>"><?= $message ?></div><?php endif; ?>

<!-- Logo Upload -->
<div class="dp-card">
    <h2 class="section-title">🖼️ School Logo</h2>
    <p class="text-muted text-small mb-2">Upload your school or organization logo. It will appear on the site, admin panel, certificates, and DataPost pages.</p>

    <div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap;">
        <!-- Current logo preview -->
        <div style="width:150px; height:150px; border:2px dashed var(--border); border-radius:var(--radius); display:flex; align-items:center; justify-content:center; background:var(--light); overflow:hidden;">
            <?php if ($logoUrl): ?>
                <img src="<?= $logoUrl ?>" alt="School Logo" style="max-width:140px; max-height:140px; object-fit:contain;">
            <?php else: ?>
                <div style="text-align:center; color:var(--dark-soft);">
                    <div style="font-size:2.5rem;">🌟</div>
                    <div style="font-size:0.75rem;">No logo set</div>
                </div>
            <?php endif; ?>
        </div>

        <div style="flex:1; min-width:250px;">
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="school_logo" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" required
                    style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px; margin-bottom:10px; font-size:0.9rem;">
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary">📤 Upload Logo</button>
                    <?php if ($logoUrl): ?>
                        <a href="?p=setup&remove_logo=1" class="btn btn-secondary" onclick="return confirm('Remove the logo?')">🗑️ Remove</a>
                    <?php endif; ?>
                </div>
                <p class="text-muted text-small" style="margin-top:8px;">PNG, JPG, GIF, WebP or SVG • Max 2MB • Recommended: 300x300px or larger, square/transparent background</p>
            </form>
        </div>
    </div>
</div>

<!-- School Information -->
<div class="dp-card mt-2">
    <h2 class="section-title">🏫 School Information</h2>
    <p class="text-muted mb-2">This information appears on certificates and DataPost data bundles.</p>
    <form method="POST">
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:12px;">
            <div>
                <label class="text-small"><strong>School ID *</strong></label>
                <input type="text" name="school_id" value="<?= e($school['school_id'] ?? '') ?>" placeholder="ARISE-COUNTY-001" required
                    style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <div>
                <label class="text-small"><strong>School Name *</strong></label>
                <input type="text" name="school_name" value="<?= e($school['school_name'] ?? '') ?>" placeholder="e.g. Moi Girls Eldoret" required
                    style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <div>
                <label class="text-small"><strong>County</strong></label>
                <input type="text" name="county" value="<?= e($school['county'] ?? '') ?>" placeholder="e.g. Uasin Gishu"
                    style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <div>
                <label class="text-small"><strong>Sub-County</strong></label>
                <input type="text" name="sub_county" value="<?= e($school['sub_county'] ?? '') ?>" placeholder="e.g. Eldoret East"
                    style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <div>
                <label class="text-small"><strong>Contact Person</strong></label>
                <input type="text" name="contact_person" value="<?= e($school['contact_person'] ?? '') ?>" placeholder="Coordinator name"
                    style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <div>
                <label class="text-small"><strong>Contact Phone</strong></label>
                <input type="text" name="contact_phone" value="<?= e($school['contact_phone'] ?? '') ?>" placeholder="0712345678"
                    style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px;">
            </div>
        </div>
        <div style="margin-top:15px;">
            <button type="submit" class="btn btn-primary btn-block">💾 Save School Configuration</button>
        </div>
    </form>
</div>

<!-- DataPost Email Endpoint -->
<div class="dp-card mt-2">
    <h2 class="section-title">📧 DataPost Email Endpoint</h2>
    <p class="text-muted mb-2">Configure the email address where DataPost reports will be sent. When users click <strong>POST</strong> in DataPost (when online), synced data will be emailed to this address.</p>

    <form method="POST" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:12px;">
        <input type="hidden" name="action" value="save_email">
        <div style="flex:1; min-width:260px;">
            <label class="text-small"><strong>Email Address</strong></label>
            <input type="email" name="email_endpoint"
                value="<?= e($school['email_endpoint'] ?? '') ?>"
                placeholder="admin@school.edu"
                style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-top:4px;">
        </div>
        <button type="submit" class="btn btn-secondary">💾 Save Email</button>
    </form>

    <?php if (!empty($school['email_endpoint'] ?? '')): ?>
        <p class="text-muted text-small">✅ Configured: <strong><?= e($school['email_endpoint']) ?></strong></p>
    <?php else: ?>
        <p class="text-muted text-small">⚠️ Not yet configured. Users won't be able to POST data via email.</p>
    <?php endif; ?>
</div>

<!-- DataPost Webhook -->
<div class="dp-card mt-2">
    <h2 class="section-title">🔗 DataPost Webhook</h2>
    <p class="text-muted mb-2">Configure an external URL to receive the DataPost summary. Click <strong>Send Data Now</strong> to POST the full summary JSON to your webhook endpoint (e.g. a Google Apps Script, Make.com, or your own server).</p>

    <!-- Save webhook URL -->
    <form method="POST" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:12px;">
        <input type="hidden" name="action" value="save_webhook">
        <div style="flex:1; min-width:260px;">
            <label class="text-small"><strong>Webhook URL</strong></label>
            <input type="url" name="webhook_url"
                value="<?= e($school['webhook_url'] ?? '') ?>"
                placeholder="https://example.com/arise-webhook"
                style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px; margin-top:4px;">
        </div>
        <button type="submit" class="btn btn-secondary">💾 Save URL</button>
    </form>

    <!-- Send data now -->
    <form method="POST" onsubmit="return confirm('POST the DataPost summary to the configured webhook now?');">
        <input type="hidden" name="action" value="send_webhook">
        <button type="submit" class="btn btn-primary"
            <?= empty($school['webhook_url'] ?? '') ? 'disabled title="Save a webhook URL first"' : '' ?>>
            📡 Send Data Now
        </button>
        <?php if (!empty($school['webhook_url'] ?? '')): ?>
            <span class="text-muted text-small" style="margin-left:10px;">→ <code><?= e($school['webhook_url']) ?></code></span>
        <?php endif; ?>
    </form>
    <p class="text-muted text-small" style="margin-top:8px;">The payload is the same JSON as <code>?p=datapost&amp;action=pickup&amp;table=summary</code> plus the ping fields. Requires outbound internet access from this server.</p>
</div>

<!-- Change Password -->
<div class="dp-card mt-2">
    <h2 class="section-title">🔐 Change Admin Password</h2>
    <form method="POST" style="display:flex; gap:10px; align-items:end;">
        <div style="flex:1;">
            <input type="password" name="new_password" placeholder="New password (min 6 characters)" required minlength="6"
                style="width:100%; padding:12px; border:2px solid var(--border); border-radius:8px;">
        </div>
        <button type="submit" name="change_password" value="1" class="btn btn-secondary">🔑 Change Password</button>
    </form>
</div>

<!-- Server Info -->
<div class="dp-card mt-2">
    <h2 class="section-title">🖥️ Server Information</h2>
    <div class="dp-log">
        <div class="dp-log-item"><span>ARISE Version</span><strong>v<?= ARISE_VERSION ?></strong></div>
        <div class="dp-log-item"><span>PHP Version</span><strong><?= phpversion() ?></strong></div>
        <div class="dp-log-item"><span>SQLite Version</span><strong><?= SQLite3::version()['versionString'] ?></strong></div>
        <div class="dp-log-item"><span>Server IP</span><strong><?= $_SERVER['SERVER_ADDR'] ?? 'N/A' ?></strong></div>
        <div class="dp-log-item"><span>Database Size</span><strong><?= file_exists(DB_PATH) ? round(filesize(DB_PATH) / 1024, 1) . ' KB' : 'Not created' ?></strong></div>
        <div class="dp-log-item"><span>Disk Free</span><strong><?= round(disk_free_space('/') / 1073741824, 1) ?> GB</strong></div>
        <div class="dp-log-item"><span>Logo</span><strong><?= $logoUrl ? '✅ Uploaded' : '❌ Not set' ?></strong></div>
        <div class="dp-log-item"><span>DataPost Email</span><strong><?= !empty($school['email_endpoint'] ?? '') ? '✅ Configured' : '❌ Not set' ?></strong></div>
        <div class="dp-log-item"><span>DataPost Webhook</span><strong><?= !empty($school['webhook_url'] ?? '') ? '✅ Configured' : '❌ Not set' ?></strong></div>
    </div>
</div>

<!-- Factory Reset -->
<div class="dp-card mt-2" style="border:2px solid var(--danger);">
    <h2 class="section-title" style="color:var(--danger);">🚨 Factory Reset</h2>
    <p class="text-muted text-small mb-2">This will delete ALL data (students, quizzes, essays, certificates) and reset the system to a fresh install. A backup of the database will be saved automatically.</p>
    <form method="POST" onsubmit="return document.getElementById('reset-confirm').value === 'RESET';">
        <input type="hidden" name="factory_reset" value="1">
        <div style="display:flex; gap:10px; align-items:end;">
            <div style="flex:1;">
                <label class="text-small"><strong>Type RESET to confirm</strong></label>
                <input type="text" name="confirm_reset" id="reset-confirm" placeholder="Type RESET"
                    style="width:100%; padding:12px; border:2px solid var(--danger); border-radius:8px; color:var(--danger);">
            </div>
            <button type="submit" class="btn btn-secondary" style="background:var(--danger); color:white; border-color:var(--danger);">🗑️ Factory Reset</button>
        </div>
    </form>
</div>
