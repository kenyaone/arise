<?php
/**
 * Certificate Verification Page
 */
$certNum = trim($_GET['cert'] ?? $_POST['cert'] ?? '');
$verified = null;

if ($certNum) {
    $stmt = db()->prepare('SELECT c.*, m.icon FROM certificates c JOIN modules m ON c.module_id = m.id WHERE c.cert_number = :cert');
    $stmt->bindValue(':cert', $certNum);
    $result = $stmt->execute();
    $verified = $result->fetchArray(SQLITE3_ASSOC);
}
?>

<div class="container">
    <div class="breadcrumb">
        <a href="/">Home</a> <span class="sep">›</span> <span>Verify Certificate</span>
    </div>

    <div style="max-width:550px; margin:0 auto;">
        <div class="dp-card text-center">
            <div style="font-size:2.5rem;">🔍</div>
            <h1 class="page-title" style="margin-bottom:5px;">Verify Certificate</h1>
            <p class="text-muted mb-2">Enter a certificate number to verify its authenticity</p>

            <form method="GET" action="">
                <input type="hidden" name="p" value="verify">
                <input type="text" name="cert" value="<?= e($certNum) ?>" placeholder="e.g. ARISE-2026-12345" required
                    style="width:100%; padding:14px; border:2px solid var(--border); border-radius:8px; font-size:1rem; text-align:center; font-family:monospace; letter-spacing:2px; margin-bottom:12px;">
                <button type="submit" class="btn btn-primary btn-block">🔍 Verify</button>
            </form>
        </div>

        <?php if ($certNum && $verified): ?>
        <div class="dp-card" style="border: 2px solid var(--success); background: #F0FFF5;">
            <div class="text-center">
                <div style="font-size:3rem;">✅</div>
                <h2 style="color:var(--success); margin:8px 0;">Certificate Verified</h2>
                <p class="text-muted text-small">This is a valid ARISE certificate</p>
            </div>
            
            <div style="margin-top:20px; padding:15px; background:white; border-radius:var(--radius);">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="padding:8px 10px; color:var(--mid); font-size:0.9rem; width:40%;">Certificate No:</td>
                        <td style="padding:8px 10px; font-weight:600; font-family:monospace;"><?= e($verified['cert_number']) ?></td>
                    </tr>
                    <tr style="background:var(--light);">
                        <td style="padding:8px 10px; color:var(--mid); font-size:0.9rem;">Student Name:</td>
                        <td style="padding:8px 10px; font-weight:600;"><?= e($verified['student_name']) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:8px 10px; color:var(--mid); font-size:0.9rem;">Module:</td>
                        <td style="padding:8px 10px; font-weight:600;"><?= $verified['icon'] ?> <?= e($verified['module_title']) ?></td>
                    </tr>
                    <tr style="background:var(--light);">
                        <td style="padding:8px 10px; color:var(--mid); font-size:0.9rem;">Score:</td>
                        <td style="padding:8px 10px; font-weight:600; color:var(--success);"><?= $verified['percentage'] ?>%</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 10px; color:var(--mid); font-size:0.9rem;">Date Issued:</td>
                        <td style="padding:8px 10px; font-weight:600;"><?= date('jS F, Y', strtotime($verified['issued_at'])) ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <?php elseif ($certNum && !$verified): ?>
        <div class="dp-card" style="border:2px solid var(--danger); background:#FFF5F5;">
            <div class="text-center">
                <div style="font-size:3rem;">❌</div>
                <h2 style="color:var(--danger); margin:8px 0;">Not Found</h2>
                <p class="text-muted">No certificate found with number <strong><?= e($certNum) ?></strong>. Please check the number and try again.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
