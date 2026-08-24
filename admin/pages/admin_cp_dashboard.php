<?php
/**
 * ARISE Admin — Child Protection Dashboard
 * Facilitator-led vs. solo-track usage and completion, per module.
 */
$cpModuleIds = range(23, 29);
$idList = implode(',', $cpModuleIds);

$cpLessons = [];
$stmt = db()->query("
    SELECT l.id AS lesson_id, l.module_id, l.title, l.slug, l.sort_order,
           m.title AS module_title, m.icon AS module_icon, m.sort_order AS mod_sort,
           CASE WHEN l.slug LIKE '%-solo' THEN 'solo' ELSE 'facilitator' END AS track,
           (SELECT COUNT(*) FROM page_views pv WHERE pv.page_type='lesson' AND pv.page_slug=l.slug) AS views,
           (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.lesson_slug=l.slug) AS attempts,
           (SELECT ROUND(AVG(qa.percentage),1) FROM quiz_attempts qa WHERE qa.lesson_slug=l.slug) AS avg_score,
           (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.lesson_id=l.id) AS starts
    FROM lessons l
    JOIN modules m ON m.id=l.module_id
    WHERE l.module_id IN ($idList) AND l.is_active=1
    ORDER BY m.sort_order, l.sort_order
");
while ($r = $stmt->fetchArray(SQLITE3_ASSOC)) $cpLessons[] = $r;

$byModule = [];
foreach ($cpLessons as $l) {
    $mid = $l['module_id'];
    if (!isset($byModule[$mid])) {
        $byModule[$mid] = ['title' => $l['module_title'], 'icon' => $l['module_icon'], 'sort' => $l['mod_sort'], 'tracks' => []];
    }
    $byModule[$mid]['tracks'][$l['track']] = $l;
}
uasort($byModule, fn($a, $b) => $a['sort'] <=> $b['sort']);

$certsByModule = [];
$cstmt = db()->query("SELECT module_id, COUNT(*) AS n FROM certificates WHERE module_id IN ($idList) GROUP BY module_id");
while ($r = $cstmt->fetchArray(SQLITE3_ASSOC)) $certsByModule[$r['module_id']] = (int)$r['n'];

$totalFacViews = $totalSoloViews = $totalFacAttempts = $totalSoloAttempts = 0;
foreach ($byModule as $m) {
    $totalFacViews     += $m['tracks']['facilitator']['views']    ?? 0;
    $totalSoloViews    += $m['tracks']['solo']['views']           ?? 0;
    $totalFacAttempts  += $m['tracks']['facilitator']['attempts'] ?? 0;
    $totalSoloAttempts += $m['tracks']['solo']['attempts']        ?? 0;
}
$totalCerts = array_sum($certsByModule);
$totalViews = $totalFacViews + $totalSoloViews;
$soloSharePct = $totalViews > 0 ? round($totalSoloViews * 100 / $totalViews) : 0;

// ── 14-day activity trend (quiz attempts per day, both tracks combined) ──
$trendRaw = [];
$tstmt = db()->query("
    SELECT DATE(completed_at) AS d, COUNT(*) AS n
    FROM quiz_attempts
    WHERE module_id IN ($idList) AND completed_at >= DATE('now','-13 days')
    GROUP BY d
");
while ($r = $tstmt->fetchArray(SQLITE3_ASSOC)) $trendRaw[$r['d']] = (int)$r['n'];
$trend = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trend[] = ['date' => $d, 'label' => date('j M', strtotime($d)), 'n' => $trendRaw[$d] ?? 0];
}
$trendMax = max(1, max(array_column($trend, 'n')));

// ── Per-module max (views), for a shared bar scale across all modules ──
$moduleMaxViews = 1;
foreach ($byModule as $m) {
    $moduleMaxViews = max($moduleMaxViews, ($m['tracks']['facilitator']['views'] ?? 0), ($m['tracks']['solo']['views'] ?? 0));
}
?>

<div class="page-header" style="margin-bottom:20px;">
    <h1 class="page-title">🛡️ Child Protection Curriculum</h1>
    <p style="color:var(--mid);font-size:.9rem;margin-top:4px;">
        Usage and completion across the 7 Child Protection modules, split by delivery track — the original
        facilitator-led session versus the self-paced "On My Own" track.
    </p>
</div>

<!-- ── SUMMARY CARDS ── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
    <div class="dp-card" style="border:1px solid #e5e7eb;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:18px;">
        <div style="font-size:.72rem;color:var(--mid);text-transform:uppercase;letter-spacing:.4px;font-weight:700;margin-bottom:6px;">🎓 Facilitator Views</div>
        <div style="font-size:1.7rem;font-weight:900;color:#3b82f6;"><?= number_format($totalFacViews) ?></div>
    </div>
    <div class="dp-card" style="border:1px solid #e5e7eb;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:18px;">
        <div style="font-size:.72rem;color:var(--mid);text-transform:uppercase;letter-spacing:.4px;font-weight:700;margin-bottom:6px;">📱 Solo Views</div>
        <div style="font-size:1.7rem;font-weight:900;color:#0ea271;"><?= number_format($totalSoloViews) ?></div>
    </div>
    <div class="dp-card" style="border:1px solid #e5e7eb;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:18px;">
        <div style="font-size:.72rem;color:var(--mid);text-transform:uppercase;letter-spacing:.4px;font-weight:700;margin-bottom:6px;">✅ Quiz Attempts (both tracks)</div>
        <div style="font-size:1.7rem;font-weight:900;color:#7c3aed;"><?= number_format($totalFacAttempts + $totalSoloAttempts) ?></div>
    </div>
    <div class="dp-card" style="border:1px solid #e5e7eb;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:18px;">
        <div style="font-size:.72rem;color:var(--mid);text-transform:uppercase;letter-spacing:.4px;font-weight:700;margin-bottom:6px;">🎖️ Certificates Issued</div>
        <div style="font-size:1.7rem;font-weight:900;color:#f59e0b;"><?= number_format($totalCerts) ?></div>
    </div>
</div>

<?php if ($totalViews > 0): ?>
<div class="dp-card" style="border:1px solid #e5e7eb;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:16px 18px;margin-bottom:24px;">
    <div style="display:flex;justify-content:space-between;font-size:.78rem;color:var(--mid);margin-bottom:6px;">
        <span>🎓 Facilitator-led</span><span>📱 On My Own — <?= $soloSharePct ?>% of all views</span>
    </div>
    <div style="height:14px;border-radius:7px;overflow:hidden;background:#3b82f6;display:flex;">
        <div style="width:<?= 100 - $soloSharePct ?>%;background:#3b82f6;"></div>
        <div style="width:<?= $soloSharePct ?>%;background:#0ea271;"></div>
    </div>
</div>
<?php endif; ?>

<!-- ── 14-DAY ACTIVITY TREND ── -->
<div class="dp-card" style="border:1px solid #e5e7eb;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:20px;margin-bottom:24px;">
    <div class="section-header" style="margin-bottom:14px;">
        <h2>📈 Quiz Activity — Last 14 Days</h2>
        <span class="chip" style="background:#f3e8ff;color:#7c3aed;font-weight:700;"><?= array_sum(array_column($trend, 'n')) ?> attempts</span>
    </div>
    <div style="display:flex;align-items:flex-end;gap:5px;height:120px;padding:0 2px;">
        <?php foreach ($trend as $t): $h = $t['n'] > 0 ? max(6, round($t['n'] / $trendMax * 100)) : 3; ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;" title="<?= e($t['label']) ?>: <?= $t['n'] ?> attempts">
            <div style="font-size:.68rem;font-weight:700;color:#7c3aed;margin-bottom:3px;<?= $t['n']===0?'visibility:hidden;':'' ?>"><?= $t['n'] ?></div>
            <div style="width:100%;max-width:22px;height:<?= $h ?>%;border-radius:5px 5px 2px 2px;background:<?= $t['n']>0 ? 'linear-gradient(180deg,#a78bfa,#7c3aed)' : '#e5e7eb' ?>;"></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:5px;padding:0 2px;margin-top:6px;">
        <?php foreach ($trend as $i => $t): ?>
        <div style="flex:1;text-align:center;font-size:.62rem;color:var(--mid);white-space:nowrap;overflow:hidden;"><?= $i % 2 === 0 ? e($t['label']) : '' ?></div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── PER-MODULE BREAKDOWN ── -->
<div class="dp-card" style="border:1px solid #e5e7eb;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:20px;">
    <div class="section-header">
        <h2>📚 Per-Module Breakdown</h2>
        <span class="chip" style="background:#e0e7ff;color:#4f46e5;font-weight:700;"><?= count($byModule) ?> modules</span>
    </div>

    <?php if (count($byModule) === 0): ?>
        <p class="text-muted text-small">No Child Protection modules found.</p>
    <?php else: foreach ($byModule as $mid => $m):
        $fac  = $m['tracks']['facilitator'] ?? null;
        $solo = $m['tracks']['solo'] ?? null;
        $certs = $certsByModule[$mid] ?? 0;
    ?>
    <div style="border-top:1px solid #e5e7eb;padding:16px 0;">
        <div style="font-weight:700;font-size:.95rem;margin-bottom:10px;"><?= htmlspecialchars($m['icon']) ?> <?= e($m['title']) ?></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <!-- Facilitator track -->
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px;">
                <div style="font-size:.72rem;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px;">🎓 Facilitator-Led</div>
                <?php if ($fac): ?>
                <div style="height:8px;border-radius:4px;background:#dbeafe;overflow:hidden;margin-bottom:8px;">
                    <div style="height:100%;width:<?= max(3, round((int)$fac['views'] / $moduleMaxViews * 100)) ?>%;background:#3b82f6;border-radius:4px;"></div>
                </div>
                <div style="font-size:.82rem;color:#1e3a5f;line-height:1.9;">
                    <?= (int)$fac['views'] ?> views &middot; <?= (int)$fac['attempts'] ?> quiz attempts
                    <?php if ($fac['avg_score'] !== null): ?><br>Avg score: <strong><?= $fac['avg_score'] ?>%</strong><?php endif; ?>
                </div>
                <?php else: ?>
                <p style="font-size:.78rem;color:var(--mid);">No facilitator lesson registered.</p>
                <?php endif; ?>
            </div>
            <!-- Solo track -->
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:12px 14px;">
                <div style="font-size:.72rem;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px;">📱 On My Own</div>
                <?php if ($solo): ?>
                <div style="height:8px;border-radius:4px;background:#d1fae5;overflow:hidden;margin-bottom:8px;">
                    <div style="height:100%;width:<?= max(3, round((int)$solo['views'] / $moduleMaxViews * 100)) ?>%;background:#0ea271;border-radius:4px;"></div>
                </div>
                <div style="font-size:.82rem;color:#064e3b;line-height:1.9;">
                    <?= (int)$solo['views'] ?> views &middot; <?= (int)$solo['attempts'] ?> quiz attempts
                    <?php if ($solo['avg_score'] !== null): ?><br>Avg score: <strong><?= $solo['avg_score'] ?>%</strong><?php endif; ?>
                </div>
                <?php else: ?>
                <p style="font-size:.78rem;color:var(--mid);">No solo lesson registered.</p>
                <?php endif; ?>
            </div>
        </div>
        <div style="font-size:.75rem;color:var(--mid);margin-top:8px;">🎖️ <?= $certs ?> certificate<?= $certs === 1 ? '' : 's' ?> issued for this module (either track — certificates aren't split by track)</div>
    </div>
    <?php endforeach; endif; ?>
</div>

<div style="font-size:.76rem;color:var(--mid);margin-top:16px;line-height:1.6;">
    ℹ️ Quiz attempts and views are the completion signal shown here. Learners' private in-lesson reflections
    (e.g. "who are my trusted adults") are intentionally saved only on the learner's own device and are never
    sent to ARISE — by design, for privacy — so they don't appear in this dashboard.
    <br>📊 Some of the activity shown includes sample data seeded to preview these charts ahead of full rollout —
    tagged internally so it can be identified and cleared once real usage is established.
</div>
