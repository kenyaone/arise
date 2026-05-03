<?php
/**
 * Admin Backup Management — CSV downloads + auto-backup
 */
$msg = '';
$config = getSchoolConfig();
$backupDir = $config['auto_backup_path'] ?? DATAPOST_PATH . '../data/backups/';
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

// Handle CSV download — Students only
if (isset($_GET['action']) && $_GET['action'] === 'download_students') {
    $csv = generateStudentCSV();
    $filename = 'arise_students_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $csv;
    
    // Log backup
    $stmt = db()->prepare('INSERT INTO backup_log (backup_type, filename, file_size_kb, created_by) VALUES (:type, :file, :size, :by)');
    $stmt->bindValue(':type', 'students_csv');
    $stmt->bindValue(':file', $filename);
    $stmt->bindValue(':size', round(strlen($csv) / 1024, 2));
    $stmt->bindValue(':by', $_SESSION['arise_admin_id'] ?? 0);
    $stmt->execute();
    exit;
}

// Handle CSV download — Full backup
if (isset($_GET['action']) && $_GET['action'] === 'download_full') {
    $csv = generateFullBackupCSV();
    $filename = 'arise_full_backup_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $csv;
    
    $stmt = db()->prepare('INSERT INTO backup_log (backup_type, filename, file_size_kb, created_by) VALUES (:type, :file, :size, :by)');
    $stmt->bindValue(':type', 'full_csv');
    $stmt->bindValue(':file', $filename);
    $stmt->bindValue(':size', round(strlen($csv) / 1024, 2));
    $stmt->bindValue(':by', $_SESSION['arise_admin_id'] ?? 0);
    $stmt->execute();
    exit;
}

// Handle database download
if (isset($_GET['action']) && $_GET['action'] === 'download_db') {
    $dbPath = DB_PATH;
    if (file_exists($dbPath)) {
        $filename = 'arise_database_' . date('Y-m-d_His') . '.sqlite';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($dbPath));
        readfile($dbPath);
        
        $stmt = db()->prepare('INSERT INTO backup_log (backup_type, filename, file_size_kb, created_by) VALUES (:type, :file, :size, :by)');
        $stmt->bindValue(':type', 'database');
        $stmt->bindValue(':file', $filename);
        $stmt->bindValue(':size', round(filesize($dbPath) / 1024, 2));
        $stmt->bindValue(':by', $_SESSION['arise_admin_id'] ?? 0);
        $stmt->execute();
        exit;
    }
}

// Handle manual backup trigger
if (isset($_GET['action']) && $_GET['action'] === 'run_backup') {
    $csv = generateFullBackupCSV();
    $filename = 'arise_backup_' . date('Y-m-d_His') . '.csv';
    file_put_contents($backupDir . $filename, $csv);
    
    // Also copy database
    $dbFilename = 'arise_db_' . date('Y-m-d_His') . '.sqlite';
    copy(DB_PATH, $backupDir . $dbFilename);
    
    $stmt = db()->prepare('INSERT INTO backup_log (backup_type, filename, file_size_kb, created_by) VALUES (:type, :file, :size, :by)');
    $stmt->bindValue(':type', 'manual_backup');
    $stmt->bindValue(':file', $filename);
    $stmt->bindValue(':size', round(strlen($csv) / 1024, 2));
    $stmt->bindValue(':by', $_SESSION['arise_admin_id'] ?? 0);
    $stmt->execute();
    
    $msg = "✅ Backup saved to server: $filename + $dbFilename";
}

// Handle auto-backup toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_backup_settings'])) {
    $enabled = isset($_POST['auto_backup_enabled']) ? 1 : 0;
    $path = trim($_POST['backup_path'] ?? $backupDir);
    if ($config) {
        $stmt = db()->prepare('UPDATE datapost_config SET auto_backup_enabled = :en, auto_backup_path = :path WHERE id = :id');
        $stmt->bindValue(':en', $enabled);
        $stmt->bindValue(':path', $path);
        $stmt->bindValue(':id', $config['id']);
        $stmt->execute();
        $msg = '✅ Backup settings saved.';
        $config = getSchoolConfig();
    }
}

// Stats
$totalStudents = db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active = 1") ?? 0;
$totalQuizAttempts = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts") ?? 0;
$totalEssays = db()->querySingle("SELECT COUNT(*) FROM essay_responses") ?? 0;
$dbSize = file_exists(DB_PATH) ? round(filesize(DB_PATH) / 1024, 1) : 0;

// Backup history
$logResult = db()->query("SELECT bl.*, au.full_name AS admin_name FROM backup_log bl LEFT JOIN admin_users au ON bl.created_by = au.id ORDER BY bl.created_at DESC LIMIT 20");
$logs = [];
while ($row = $logResult->fetchArray(SQLITE3_ASSOC)) { $logs[] = $row; }

// Auto-backup files on disk
$autoBackups = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . 'arise_*');
    rsort($files);
    foreach (array_slice($files, 0, 20) as $f) {
        $autoBackups[] = [
            'name' => basename($f),
            'size' => round(filesize($f) / 1024, 1),
            'date' => date('Y-m-d H:i', filemtime($f))
        ];
    }
}
?>

<h1 class="page-title">💾 Backup & Recovery</h1>
<?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>

<!-- Quick Stats -->
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:12px; margin-bottom:20px;">
    <div class="stat-box"><div class="stat-num"><?= $totalStudents ?></div><div class="stat-label">Students</div></div>
    <div class="stat-box"><div class="stat-num"><?= $totalQuizAttempts ?></div><div class="stat-label">Quiz Attempts</div></div>
    <div class="stat-box"><div class="stat-num"><?= $totalEssays ?></div><div class="stat-label">Essays</div></div>
    <div class="stat-box"><div class="stat-num"><?= $dbSize ?> KB</div><div class="stat-label">Database Size</div></div>
</div>

<!-- Download Buttons -->
<div class="dp-card">
    <h2 class="section-title">📥 Download Backups</h2>
    <p class="text-muted text-small mb-2">Download data to your computer. Use a USB drive to keep copies safe.</p>
    
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="?p=backup&action=download_students" class="btn btn-primary" style="flex:1; min-width:180px; text-align:center;">
            👥 Students CSV<br><span style="font-size:0.75rem; font-weight:normal;">Names, schools, scores</span>
        </a>
        <a href="?p=backup&action=download_full" class="btn btn-secondary" style="flex:1; min-width:180px; text-align:center;">
            📋 Full Data CSV<br><span style="font-size:0.75rem; font-weight:normal;">Students + quizzes + essays</span>
        </a>
        <a href="?p=backup&action=download_db" class="btn btn-secondary" style="flex:1; min-width:180px; text-align:center;">
            🗄️ Database File<br><span style="font-size:0.75rem; font-weight:normal;">Complete SQLite backup</span>
        </a>
    </div>
    
    <div style="margin-top:15px; padding:12px; background:#FFF9E6; border-radius:8px; border-left:3px solid #FFB800;">
        <strong>💡 Tip:</strong> Download the <strong>Database File</strong> regularly to a USB drive. This contains ALL data and can fully restore the system if the server fails.
    </div>
</div>

<!-- Auto-Backup Settings -->
<div class="dp-card">
    <h2 class="section-title">⏰ Auto-Backup</h2>
    <p class="text-muted text-small mb-2">The system automatically saves a CSV + database copy daily. Backups older than 30 days are cleaned up.</p>
    
    <form method="POST">
        <input type="hidden" name="save_backup_settings" value="1">
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">
            <label style="display:flex; align-items:center; gap:8px; padding:10px; background:var(--light); border-radius:8px; cursor:pointer;">
                <input type="checkbox" name="auto_backup_enabled" <?= ($config['auto_backup_enabled'] ?? 1) ? 'checked' : '' ?>>
                <strong>Enable daily auto-backup</strong>
            </label>
            <div style="flex:1; min-width:250px;">
                <label class="text-small"><strong>Backup Path</strong></label>
                <input type="text" name="backup_path" value="<?= e($config['auto_backup_path'] ?? $backupDir) ?>" style="width:100%; padding:10px; border:2px solid var(--border); border-radius:8px;">
            </div>
            <button type="submit" class="btn btn-primary">💾 Save</button>
        </div>
    </form>
    
    <div style="margin-top:15px;">
        <a href="?p=backup&action=run_backup" class="btn btn-success" onclick="return confirm('Run a manual backup now?')">▶️ Run Backup Now</a>
    </div>
</div>

<!-- Auto-backup files on disk -->
<?php if (count($autoBackups) > 0): ?>
<div class="dp-card">
    <h2 class="section-title">📁 Server Backup Files (<?= count($autoBackups) ?>)</h2>
    <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:var(--light); text-align:left;">
                    <th style="padding:8px 10px; border-bottom:2px solid var(--border);">Filename</th>
                    <th style="padding:8px 10px; border-bottom:2px solid var(--border);">Size</th>
                    <th style="padding:8px 10px; border-bottom:2px solid var(--border);">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($autoBackups as $b): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:6px 10px; font-family:monospace; font-size:0.8rem;"><?= e($b['name']) ?></td>
                    <td style="padding:6px 10px;"><?= $b['size'] ?> KB</td>
                    <td style="padding:6px 10px;"><?= $b['date'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Backup Log -->
<?php if (count($logs) > 0): ?>
<div class="dp-card">
    <h2 class="section-title">📜 Download History</h2>
    <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:var(--light); text-align:left;">
                    <th style="padding:8px 10px; border-bottom:2px solid var(--border);">Type</th>
                    <th style="padding:8px 10px; border-bottom:2px solid var(--border);">File</th>
                    <th style="padding:8px 10px; border-bottom:2px solid var(--border);">Size</th>
                    <th style="padding:8px 10px; border-bottom:2px solid var(--border);">By</th>
                    <th style="padding:8px 10px; border-bottom:2px solid var(--border);">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $l): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:6px 10px;">
                        <?php
                        $badges = ['students_csv' => '👥', 'full_csv' => '📋', 'database' => '🗄️', 'manual_backup' => '▶️'];
                        echo ($badges[$l['backup_type']] ?? '📦') . ' ' . str_replace('_', ' ', ucfirst($l['backup_type']));
                        ?>
                    </td>
                    <td style="padding:6px 10px; font-family:monospace; font-size:0.8rem;"><?= e($l['filename']) ?></td>
                    <td style="padding:6px 10px;"><?= $l['file_size_kb'] ?> KB</td>
                    <td style="padding:6px 10px;"><?= e($l['admin_name'] ?? '—') ?></td>
                    <td style="padding:6px 10px;"><?= date('M j, g:i A', strtotime($l['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
