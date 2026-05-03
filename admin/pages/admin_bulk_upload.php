<?php
// Admin: Bulk ZIP Upload for Arise lessons
if (!defined('ARISE_VERSION')) { http_response_code(403); exit; }

$msg = '';
$uploadDir = '/var/www/arise/data/uploads/';
$allowedTypes = ['html', 'mp4', 'webm', 'pdf'];

// ── Bulk Learner Import (CSV) ─────────────────────────────────────────────────
$learnerImportMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_learners') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $learnerImportMsg = '<div class="alert alert-error">&#10060; CSV upload failed (error code: ' . ($_FILES['csv_file']['error'] ?? 'none') . ').</div>';
    } else {
        $csvPath    = $_FILES['csv_file']['tmp_name'];
        $csvContent = file_get_contents($csvPath);
        if ($csvContent === false) {
            $learnerImportMsg = '<div class="alert alert-error">&#10060; Could not read uploaded file.</div>';
        } else {
            // Normalise line endings, drop blank lines
            $lines = array_values(array_filter(
                explode("\n", str_replace(["\r\n", "\r"], "\n", $csvContent)),
                'strlen'
            ));

            $imported = 0;
            $skipped  = 0;
            $rowErrors = [];

            // Skip header row (index 0)
            for ($i = 1; $i < count($lines); $i++) {
                $row = str_getcsv($lines[$i]);

                $fullName   = isset($row[0]) ? trim($row[0]) : '';
                $className  = isset($row[1]) ? trim($row[1]) : '';
                $schoolName = isset($row[2]) ? trim($row[2]) : '';
                $pin        = isset($row[3]) ? trim($row[3]) : '';

                // Validate required field
                if ($fullName === '') {
                    $rowErrors[] = "Row " . ($i + 1) . ": full_name is empty — skipped.";
                    $skipped++;
                    continue;
                }

                // Validate PIN format if provided
                if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) {
                    $rowErrors[] = "Row " . ($i + 1) . ": PIN must be 4–6 digits — skipped.";
                    $skipped++;
                    continue;
                }

                // Generate random 4-digit PIN if not supplied
                if ($pin === '') {
                    $pin = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                }

                $pinHash = password_hash($pin, PASSWORD_DEFAULT);

                // INSERT OR IGNORE — duplicate = same full_name + class_name
                $stmt = db()->prepare(
                    "INSERT OR IGNORE INTO students
                        (full_name, class_name, school_name, password_hash, is_active)
                     VALUES
                        (:fn, :cn, :sn, :ph, 1)"
                );
                $stmt->bindValue(':fn', $fullName,   SQLITE3_TEXT);
                $stmt->bindValue(':cn', $className,  SQLITE3_TEXT);
                $stmt->bindValue(':sn', $schoolName, SQLITE3_TEXT);
                $stmt->bindValue(':ph', $pinHash,    SQLITE3_TEXT);
                $stmt->execute();

                if (db()->changes() > 0) {
                    $imported++;
                } else {
                    $skipped++;
                    $rowErrors[] = "Row " . ($i + 1) . ": &quot;" . htmlspecialchars($fullName) . "&quot; in class &quot;" . htmlspecialchars($className) . "&quot; already exists — skipped.";
                }
            }

            $errHtml = '';
            if ($rowErrors) {
                $errHtml = '<ul style="margin:8px 0 0 18px;font-size:.85rem;">'
                    . implode('', array_map(fn($e) => "<li>$e</li>", $rowErrors))
                    . '</ul>';
            }
            $learnerImportMsg = "<div class='alert alert-success'>&#9989; Learner import complete: <strong>$imported</strong> imported, <strong>$skipped</strong> skipped.$errHtml</div>";
        }
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// Handle POST upload (existing ZIP feature)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['zip_file'])) {
    $moduleId = intval($_POST['module_id'] ?? 0);
    $lessonType = $_POST['lesson_type'] ?? 'interactive';

    if ($moduleId <= 0) {
        $msg = '<div class="alert alert-error">❌ Please select a module.</div>';
    } elseif ($_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = '<div class="alert alert-error">❌ Upload error: ' . $_FILES['zip_file']['error'] . '</div>';
    } else {
        $tmpZip = $_FILES['zip_file']['tmp_name'];
        $origName = $_FILES['zip_file']['name'];

        if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'zip') {
            $msg = '<div class="alert alert-error">❌ Only ZIP files are accepted.</div>';
        } else {
            $zip = new ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                $msg = '<div class="alert alert-error">❌ Could not open ZIP file.</div>';
            } else {
                $inserted = 0; $skipped = 0; $errors = [];
                $module = db()->querySingle("SELECT title,slug FROM modules WHERE id=$moduleId", true);

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedTypes)) continue;
                    if (substr($entry, -1) === '/') continue; // skip dirs

                    $baseName = basename($entry);
                    $title = pathinfo($baseName, PATHINFO_FILENAME);
                    $title = preg_replace('/[_-]+/', ' ', $title);
                    $title = trim($title);

                    // Check for duplicate
                    $titleSafe = db()->escapeString($title);
                    $exists = db()->querySingle("SELECT id FROM lessons WHERE module_id=$moduleId AND title='$titleSafe' AND lesson_type='$lessonType'");
                    if ($exists) { $skipped++; continue; }

                    // Extract file
                    $ts = time() + $i;
                    $destName = $ts . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $baseName);
                    $destPath = $uploadDir . $destName;

                    $contents = $zip->getFromIndex($i);
                    if ($contents === false) { $errors[] = "Could not read: $baseName"; continue; }

                    // For HTML: inject ARISE vars
                    if ($ext === 'html') {
                        // Get max sort_order
                        $maxSort = db()->querySingle("SELECT COALESCE(MAX(sort_order),0) FROM lessons WHERE module_id=$moduleId") ?? 0;
                        $stmt = db()->prepare("INSERT INTO lessons (module_id,title,lesson_type,file_path,sort_order,is_active,created_at) VALUES (:mid,:t,:lt,:fp,:so,1,CURRENT_TIMESTAMP)");
                        $stmt->bindValue(':mid', $moduleId);
                        $stmt->bindValue(':t', $title);
                        $stmt->bindValue(':lt', $lessonType);
                        $stmt->bindValue(':fp', $destName);
                        $stmt->bindValue(':so', $maxSort + 1);
                        $stmt->execute();
                        file_put_contents($destPath, $contents);
                        $inserted++;
                    } elseif ($ext === 'mp4' || $ext === 'webm') {
                        $maxSort = db()->querySingle("SELECT COALESCE(MAX(sort_order),0) FROM lessons WHERE module_id=$moduleId") ?? 0;
                        $stmt = db()->prepare("INSERT INTO lessons (module_id,title,lesson_type,file_path,sort_order,is_active,created_at) VALUES (:mid,:t,'video',:fp,:so,1,CURRENT_TIMESTAMP)");
                        $stmt->bindValue(':mid', $moduleId);
                        $stmt->bindValue(':t', $title);
                        $stmt->bindValue(':fp', $destName);
                        $stmt->bindValue(':so', $maxSort + 1);
                        $stmt->execute();
                        file_put_contents($destPath, $contents);
                        $inserted++;
                    } elseif ($ext === 'pdf') {
                        $maxSort = db()->querySingle("SELECT COALESCE(MAX(sort_order),0) FROM lessons WHERE module_id=$moduleId") ?? 0;
                        $stmt = db()->prepare("INSERT INTO lessons (module_id,title,lesson_type,file_path,sort_order,is_active,created_at) VALUES (:mid,:t,'pdf',:fp,:so,1,CURRENT_TIMESTAMP)");
                        $stmt->bindValue(':mid', $moduleId);
                        $stmt->bindValue(':t', $title);
                        $stmt->bindValue(':fp', $destName);
                        $stmt->bindValue(':so', $maxSort + 1);
                        $stmt->execute();
                        file_put_contents($destPath, $contents);
                        $inserted++;
                    }
                }
                $zip->close();

                ariseAuditLog('bulk_upload', 'module', $moduleId, "ZIP: $origName, inserted: $inserted, skipped: $skipped");

                $errHtml = $errors ? '<br>Errors: ' . implode(', ', array_map('htmlspecialchars', $errors)) : '';
                $modTitle = htmlspecialchars($module['title'] ?? 'Module');
                $msg = "<div class='alert alert-success'>✅ Uploaded to <strong>$modTitle</strong>: <strong>$inserted</strong> lessons added, <strong>$skipped</strong> skipped (already exist).$errHtml</div>";
            }
        }
    }
}

// Load modules for dropdown
$modules = [];
$res = db()->query("SELECT id, title, slug FROM modules WHERE is_active=1 ORDER BY sort_order, title");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $modules[] = $r;
?>
<h1 class="page-title">📦 Bulk Lesson Upload</h1>

<?= $msg ?>

<div class="dp-card" style="max-width:640px;">
    <h3 style="margin-bottom:16px;">Upload a ZIP of Lesson Files</h3>
    <p style="color:#6b7280;font-size:.9rem;margin-bottom:18px;">
        ZIP may contain <strong>.html</strong> (interactive), <strong>.mp4/.webm</strong> (video), or <strong>.pdf</strong> files.
        Each file becomes one lesson. Files already in the module are skipped.
    </p>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label class="form-label">Target Module</label>
            <select name="module_id" class="form-control" required>
                <option value="">— Select a module —</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Default Lesson Type (for HTML files)</label>
            <select name="lesson_type" class="form-control">
                <option value="interactive">Interactive (Articulate/H5P HTML)</option>
                <option value="reading">Reading (plain HTML)</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">ZIP File</label>
            <input type="file" name="zip_file" accept=".zip" class="form-control" required style="padding:8px;">
        </div>
        <button type="submit" class="btn btn-primary">📦 Upload &amp; Import</button>
    </form>
</div>

<?php
// Show recent uploads
$recent = [];
$res = db()->query("SELECT l.title, l.lesson_type, l.created_at, m.title AS mod_title FROM lessons l JOIN modules m ON l.module_id=m.id ORDER BY l.created_at DESC LIMIT 20");
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $recent[] = $r;
if ($recent):
?>
<div class="dp-card" style="margin-top:20px;">
    <h3 style="margin-bottom:14px;">Recent Lessons (last 20)</h3>
    <table class="arise-table">
        <thead><tr><th>Title</th><th>Type</th><th>Module</th><th>Added</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['title']) ?></td>
                <td><span class="badge badge-green"><?= htmlspecialchars($r['lesson_type']) ?></span></td>
                <td><?= htmlspecialchars($r['mod_title']) ?></td>
                <td style="font-size:.8rem;color:#6b7280;"><?= date('M j, H:i', strtotime($r['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
/* ═══════════════════════════════════════════════════════════════════════════
   BULK IMPORT LEARNERS  (CSV)
   ═══════════════════════════════════════════════════════════════════════════ */

// Build downloadable CSV template as a data: URI
$templateRows = [
    ['full_name', 'class_name', 'school_name', 'pin'],
    ['Alice Mwangi',  'Form 3 East', 'Sunrise Primary',  '1234'],
    ['Brian Otieno',  'Form 2 West', 'Hilltop Academy',  ''],
    ['Carol Njeri',   'Form 1 North','Green Valley School','5678'],
];
$templateCsv = implode("\r\n", array_map(fn($r) => implode(',', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $r)), $templateRows));
$templateDataUri = 'data:text/csv;charset=utf-8,' . rawurlencode($templateCsv);
?>

<div class="dp-card" style="margin-top:32px; border-left:4px solid #16a34a; max-width:680px;">
    <h3 style="margin-bottom:6px;">&#128100; Bulk Import Learners (CSV)</h3>
    <p style="color:#6b7280;font-size:.9rem;margin-bottom:16px;">
        Upload a CSV file to create multiple learner accounts at once.
        Rows with a duplicate <em>full_name + class_name</em> combination are skipped.
        If no PIN is supplied, a random 4-digit PIN is generated automatically.
    </p>

    <?= $learnerImportMsg ?>

    <!-- Template download -->
    <p style="margin-bottom:16px;">
        <a href="<?= $templateDataUri ?>" download="learners_template.csv"
           style="display:inline-flex;align-items:center;gap:6px;color:#16a34a;font-weight:600;text-decoration:none;">
            &#11015; Download CSV Template
        </a>
        &nbsp;<span style="color:#9ca3af;font-size:.85rem;">(full_name, class_name, school_name, pin)</span>
    </p>

    <!-- Upload form -->
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="import_learners">
        <div class="form-group">
            <label class="form-label">CSV File</label>
            <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required style="padding:8px;">
        </div>
        <button type="submit" class="btn btn-primary">&#128100; Import Learners</button>
    </form>

    <!-- Column reference -->
    <div style="margin-top:20px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:14px;">
        <strong style="font-size:.85rem;color:#15803d;">CSV Column Reference</strong>
        <table style="width:100%;margin-top:8px;font-size:.82rem;border-collapse:collapse;">
            <thead>
                <tr style="color:#374151;">
                    <th style="text-align:left;padding:4px 8px;border-bottom:1px solid #d1fae5;">Column</th>
                    <th style="text-align:left;padding:4px 8px;border-bottom:1px solid #d1fae5;">Description</th>
                    <th style="text-align:left;padding:4px 8px;border-bottom:1px solid #d1fae5;">Required?</th>
                </tr>
            </thead>
            <tbody style="color:#6b7280;">
                <tr><td style="padding:4px 8px;"><code>full_name</code></td>   <td style="padding:4px 8px;">Learner's full name</td>                       <td style="padding:4px 8px;">Yes</td></tr>
                <tr><td style="padding:4px 8px;"><code>class_name</code></td>  <td style="padding:4px 8px;">Cluster / class (e.g. "Form 2 East")</td>       <td style="padding:4px 8px;">No</td></tr>
                <tr><td style="padding:4px 8px;"><code>school_name</code></td> <td style="padding:4px 8px;">Project / school (e.g. "Sunrise Primary")</td>  <td style="padding:4px 8px;">No</td></tr>
                <tr><td style="padding:4px 8px;"><code>pin</code></td>         <td style="padding:4px 8px;">4–6 digit login PIN (auto-generated if blank)</td><td style="padding:4px 8px;">No</td></tr>
            </tbody>
        </table>
    </div>
</div>
