<?php
/**
 * ARISE Certificate — standalone printable page
 * Must be called via index.php (uses config.php functions)
 */

// 1. Get student from session BEFORE clearing buffer
$student     = getStudentBySession();
// Fall back to URL params (when admin prints on behalf of student)
$studentName = $student ? $student['full_name'] : (isset($_GET['name']) ? urldecode($_GET['name']) : 'Student');
$studentId   = $student ? $student['id'] : null;
$schoolName  = $student ? ($student['school_name'] ?? 'ARISE') : (isset($_GET['school']) ? urldecode($_GET['school']) : 'ARISE');

// 2. Clear any buffered navbar/header output from index.php
while (ob_get_level()) ob_end_clean();

// 3. Get module and score
$moduleSlug = $_GET['module'] ?? '';
$scorePct   = intval($_GET['score'] ?? 0);
$module     = getModule($moduleSlug);

if (!$module || $scorePct < 60) {
    header('Location: /arise/?p=modules');
    exit;
}

// 4. Get certificate record — use URL params if provided (admin print)
$urlCert = isset($_GET['cert']) ? urldecode($_GET['cert']) : null;
$urlDate = isset($_GET['date']) ? urldecode($_GET['date']) : null;
$certNumber    = $urlCert ?: 'ARISE-' . date('Y') . '-' . str_pad(mt_rand(10000,99999),5,'0',STR_PAD_LEFT);
$formattedDate = $urlDate ? date('F j, Y', strtotime($urlDate)) : date('F j, Y');

if ($studentId) {
    $stmt = db()->prepare('SELECT * FROM certificates WHERE student_id=:sid AND module_id=:mid ORDER BY id DESC LIMIT 1');
    $stmt->bindValue(':sid', $studentId);
    $stmt->bindValue(':mid', $module['id']);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($existing) {
        $certNumber    = $existing['cert_number'];
        $formattedDate = date('F j, Y', strtotime($existing['issued_at']));
    }
}

$logoUrl = getLogoUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Certificate — <?= e($module['title']) ?> | ARISE</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#f0e8ff;font-family:'Georgia',serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:20px;}
.controls{display:flex;gap:12px;margin-bottom:24px;width:100%;max-width:880px;flex-wrap:wrap;}
.btn-print{background:linear-gradient(135deg,#7B2FC9,#5B1A9E);color:#fff;border:none;padding:12px 28px;border-radius:8px;font-size:.95rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(123,47,201,.35);}
.btn-print:hover{transform:translateY(-2px);}
.btn-back{background:#fff;color:#7B2FC9;border:2px solid #7B2FC9;padding:12px 24px;border-radius:8px;font-size:.95rem;font-weight:700;text-decoration:none;}
.cert{width:880px;background:#fff;border:3px solid #7B2FC9;border-radius:12px;position:relative;padding:50px 60px;box-shadow:0 20px 60px rgba(123,47,201,.2);text-align:center;overflow:hidden;}
.cert::before,.cert::after{content:'';position:absolute;width:120px;height:120px;border:8px solid #F5E642;opacity:.35;}
.cert::before{top:12px;left:12px;border-right:none;border-bottom:none;border-radius:8px 0 0 0;}
.cert::after{bottom:12px;right:12px;border-left:none;border-top:none;border-radius:0 0 8px 0;}
.wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:120px;opacity:.04;pointer-events:none;font-family:serif;color:#7B2FC9;font-weight:900;letter-spacing:10px;}
.cert-logo{width:72px;height:72px;object-fit:contain;border-radius:12px;border:2px solid #E9D5FF;margin-bottom:8px;}
.cert-logo-icon{font-size:3rem;margin-bottom:8px;display:block;}
.cert-org{font-size:1.1rem;font-weight:700;color:#7B2FC9;letter-spacing:3px;text-transform:uppercase;margin-bottom:2px;}
.cert-org-sub{font-size:.72rem;color:#9CA3AF;letter-spacing:1px;text-transform:uppercase;margin-bottom:24px;}
.div{width:60%;height:2px;margin:0 auto 24px;background:linear-gradient(90deg,transparent,#7B2FC9,#F5E642,#7B2FC9,transparent);}
.cert-title{font-size:2rem;font-weight:700;color:#2D0654;letter-spacing:2px;margin-bottom:4px;}
.cert-sub{font-size:.85rem;color:#9CA3AF;letter-spacing:2px;text-transform:uppercase;margin-bottom:28px;}
.cert-awarded{font-size:.9rem;color:#6B7280;margin-bottom:12px;}
.cert-name{font-size:2.2rem;font-weight:700;color:#7B2FC9;font-style:italic;border-bottom:2px solid #E9D5FF;padding-bottom:8px;margin-bottom:20px;display:inline-block;min-width:300px;}
.cert-body{font-size:.9rem;color:#6B7280;line-height:1.7;}
.cert-module{font-size:1.25rem;font-weight:700;color:#2D0654;margin:8px 0 4px;background:#F5EFFE;border-radius:8px;padding:10px 24px;display:inline-block;border:1px solid #E9D5FF;}
.cert-score{font-size:2.5rem;font-weight:900;color:#7B2FC9;margin:12px 0 28px;}
.cert-score span{font-size:.9rem;font-weight:400;color:#9CA3AF;}
.cert-footer{display:flex;justify-content:space-between;align-items:flex-end;border-top:1px solid #E9D5FF;padding-top:24px;margin-top:8px;}
.cert-fi{text-align:center;}
.cert-fv{font-size:.95rem;font-weight:700;color:#2D0654;}
.cert-fl{height:1px;background:#7B2FC9;margin:8px auto;width:120px;}
.cert-fl-label{font-size:.7rem;color:#9CA3AF;text-transform:uppercase;letter-spacing:1px;}
.cert-seal{width:70px;height:70px;border-radius:50%;border:3px solid #7B2FC9;background:#F5EFFE;display:flex;align-items:center;justify-content:center;font-size:2rem;margin:0 auto 4px;}
.cert-seal img{width:56px;height:56px;object-fit:contain;border-radius:50%;}
.cert-verify{font-size:.68rem;color:#9CA3AF;margin-top:20px;letter-spacing:.5px;}
@media print{
    body{background:white;padding:0;}
    .controls{display:none!important;}
    .cert{width:100%;box-shadow:none;border-radius:0;}
    @page{size:A4 landscape;margin:10mm;}
}
@media(max-width:920px){
    .cert{width:100%;padding:30px 20px;}
    .cert-name{font-size:1.5rem;min-width:auto;}
    .cert-title{font-size:1.5rem;}
    .cert-footer{flex-direction:column;gap:16px;align-items:center;}
}
</style>
</head>
<body>

<div class="controls">
    <button class="btn-print" onclick="window.print()">&#128438; Print / Save as PDF</button>
    <a href="/arise/?p=certificates" class="btn-back">&#8592; My Certificates</a>
    <a href="/arise/?p=modules" class="btn-back" style="border-color:#9CA3AF;color:#6B7280;">&#128218; Modules</a>
</div>

<div class="cert">
    <div class="wm">ARISE</div>

    <?php if ($logoUrl): ?>
        <img src="<?= e($logoUrl) ?>" alt="ARISE" class="cert-logo">
    <?php else: ?>
        <span class="cert-logo-icon">&#127775;</span>
    <?php endif; ?>

    <div class="cert-org">ARISE</div>
    <div class="cert-org-sub">Adolescent Reproductive Health Information Support &amp; Empowerment</div>
    <div class="div"></div>

    <div class="cert-title">Certificate of Completion</div>
    <div class="cert-sub">Module Achievement Award</div>

    <div class="cert-awarded">This is to certify that</div>
    <div class="cert-name"><?= e($studentName) ?></div>

    <div class="cert-body">has successfully completed the health education module</div>

    <div class="cert-module"><?= htmlspecialchars($module['icon']) ?> <?= e($module['title']) ?></div>

    <div class="cert-score"><?= $scorePct ?>% <span>achievement</span></div>

    <div class="cert-footer">
        <div class="cert-fi">
            <div class="cert-fv"><?= e($formattedDate) ?></div>
            <div class="cert-fl"></div>
            <div class="cert-fl-label">Date Issued</div>
        </div>
        <div class="cert-fi">
            <div class="cert-seal">
                <?php if ($logoUrl): ?>
                    <img src="<?= e($logoUrl) ?>" alt="Seal">
                <?php else: ?>&#127891;<?php endif; ?>
            </div>
            <div style="font-size:.65rem;color:#9CA3AF;margin-top:4px;">ARISE Platform</div>
        </div>
        <div class="cert-fi">
            <div class="cert-fv"><?= e($schoolName) ?></div>
            <div class="cert-fl"></div>
            <div class="cert-fl-label">Institution</div>
        </div>
    </div>

    <div class="cert-verify">
        Certificate No: <?= e($certNumber) ?> &bull;
        ARISE Health Education Platform &bull;
        <?= $_SERVER['HTTP_HOST'] ?? '192.168.0.118' ?>/arise
    </div>
</div>

</body>
</html>
<?php exit; ?>
