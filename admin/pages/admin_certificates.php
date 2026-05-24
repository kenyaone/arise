<?php
/**
 * Admin — View all issued certificates
 */
$search = $_GET['search'] ?? '';
$where = '';
if ($search) {
    $esc = SQLite3::escapeString($search);
    $where = "WHERE c.student_name LIKE '%$esc%' OR c.cert_number LIKE '%$esc%' OR c.module_title LIKE '%$esc%'";
}

$totalCerts = db()->querySingle("SELECT COUNT(*) FROM certificates c $where") ?? 0;

$result = db()->query("SELECT c.*, m.icon, m.slug as module_slug FROM certificates c JOIN modules m ON c.module_id = m.id $where ORDER BY c.issued_at DESC LIMIT 100");
$certs = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $certs[] = $row; }

// Stats
$totalStudentsCerted = db()->querySingle("SELECT COUNT(DISTINCT student_id) FROM certificates WHERE student_id IS NOT NULL") ?? 0;
$totalModulesCerted = db()->querySingle("SELECT COUNT(DISTINCT module_id) FROM certificates") ?? 0;
$avgCertScore = db()->querySingle("SELECT ROUND(AVG(percentage), 1) FROM certificates") ?? 0;
?>

<h1 class="page-title">🎓 Certificates (<?= $totalCerts ?>)</h1>

<!-- Stats -->
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:12px; margin-bottom:20px;">
    <div class="stat-box"><div class="stat-num"><?= $totalCerts ?></div><div class="stat-label">Total Issued</div></div>
    <div class="stat-box"><div class="stat-num"><?= $totalStudentsCerted ?></div><div class="stat-label">Students Certified</div></div>
    <div class="stat-box"><div class="stat-num"><?= $totalModulesCerted ?></div><div class="stat-label">Modules</div></div>
    <div class="stat-box"><div class="stat-num"><?= $avgCertScore ?>%</div><div class="stat-label">Avg Score</div></div>
</div>

<!-- Search -->
<div class="dp-card" style="margin-bottom:15px;">
    <form method="GET" style="display:flex; gap:10px;">
        <input type="hidden" name="p" value="certificates">
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by name, cert number, or module..." style="flex:1; padding:10px; border:2px solid var(--border); border-radius:8px;">
        <button type="submit" class="btn btn-primary">🔍 Search</button>
        <?php if ($search): ?><a href="?p=certificates" class="btn btn-secondary">Clear</a><?php endif; ?>
    </form>
</div>

<!-- Certificate List -->
<div class="dp-card">
    <?php if (count($certs) === 0): ?>
        <div class="alert alert-info">No certificates issued yet. Students earn certificates by scoring 70%+ on module quizzes.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead>
                <tr style="background:var(--light); text-align:left;">
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Cert #</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Student</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Module</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Score</th>
                    <th style="padding:10px 12px; border-bottom:2px solid var(--border);">Issued</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($certs as $c): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:8px 12px; font-family:monospace; font-size:0.8rem;"><?= e($c['cert_number']) ?></td>
                    <td style="padding:8px 12px; font-weight:600;"><?= e($c['student_name']) ?></td>
                    <td style="padding:8px 12px;"><?= $c['icon'] ?> <?= e($c['module_title']) ?></td>
                    <td style="padding:8px 12px; font-weight:600; color:var(--success);"><?= $c['percentage'] ?>%</td>
                    <td style="padding:8px 12px; font-size:0.85rem;"><?= date('M j, Y', strtotime($c['issued_at'])) ?></td>
                    <td style="padding:8px 12px;">
                        <a href="/arise/?p=certificate&module=<?= e($c['module_slug'] ?? '') ?>&score=<?= intval($c['percentage']) ?>&name=<?= urlencode($c['student_name']) ?>&cert=<?= urlencode($c['cert_number']) ?>&school=<?= urlencode($c['student_name'] ?? 'ARISE') ?>&date=<?= urlencode($c['issued_at']) ?>"
                           target="_blank" class="btn btn-sm btn-primary" style="font-size:.75rem;">
                           &#128438; Print
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
