<?php
/**
 * DataPost Deliver — Upload content updates to this ARISE server
 */

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courierName = trim($_POST['courier_name'] ?? '');
    $courierEmail = trim($_POST['courier_email'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!empty($_FILES['package']['name']) && $_FILES['package']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = DATAPOST_PATH . 'deliveries/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = date('Y-m-d_His') . '_' . basename($_FILES['package']['name']);
        $filepath = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['package']['tmp_name'], $filepath)) {
            // Log the delivery
            $stmt = db()->prepare('INSERT INTO datapost_deliveries (courier_email, courier_name, package_name, package_size_kb, notes) VALUES (:email, :name, :pkg, :size, :notes)');
            $stmt->bindValue(':email', $courierEmail);
            $stmt->bindValue(':name', $courierName);
            $stmt->bindValue(':pkg', $filename);
            $stmt->bindValue(':size', round(filesize($filepath) / 1024, 1));
            $stmt->bindValue(':notes', $notes);
            $stmt->execute();

            // If it's a ZIP, try to extract
            if (pathinfo($filename, PATHINFO_EXTENSION) === 'zip') {
                $zip = new ZipArchive;
                if ($zip->open($filepath) === TRUE) {
                    $extractDir = CONTENT_PATH . 'updates/' . date('Y-m-d_His') . '/';
                    mkdir($extractDir, 0755, true);
                    $zip->extractTo($extractDir);
                    $zip->close();
                    $message = "✅ Content package delivered and extracted successfully! ({$filename})";
                } else {
                    $message = "✅ Package uploaded but could not be extracted. ({$filename})";
                }
            } else {
                $message = "✅ File delivered successfully! ({$filename})";
            }
            $messageType = 'success';
        } else {
            $message = "❌ Failed to upload file.";
            $messageType = 'danger';
        }
    } else {
        $message = "❌ No file selected or upload error.";
        $messageType = 'danger';
    }
}
?>

<h1 class="page-title">📤 Content Delivery</h1>
<p class="text-muted mb-2">Upload content updates, new quiz questions, or system patches to this ARISE server.</p>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
<?php endif; ?>

<div class="dp-card">
    <form method="POST" enctype="multipart/form-data">
        <label class="section-title" for="courier_name">Your Name</label>
        <input type="text" name="courier_name" id="courier_name" placeholder="e.g. John Doe" required
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:var(--radius); font-size:0.95rem; margin-bottom:12px;">

        <label class="section-title" for="courier_email">Your Email</label>
        <input type="email" name="courier_email" id="courier_email" placeholder="e.g. john@worldpossiblekenya.org" required
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:var(--radius); font-size:0.95rem; margin-bottom:12px;">

        <label class="section-title" for="package">Content Package (.zip, .json, .sql)</label>
        <input type="file" name="package" id="package" accept=".zip,.json,.sql,.csv" required
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:var(--radius); font-size:0.95rem; margin-bottom:12px;">

        <label class="section-title" for="notes">Notes (optional)</label>
        <textarea name="notes" id="notes" placeholder="e.g. Updated quiz questions for Module 7, new Kiswahili content..."
            style="width:100%; min-height:80px; padding:12px; border:2px solid var(--border); border-radius:var(--radius); font-size:0.95rem; margin-bottom:20px;"></textarea>

        <button type="submit" class="btn btn-primary btn-block btn-lg">📤 Upload Content Package</button>
    </form>
</div>
