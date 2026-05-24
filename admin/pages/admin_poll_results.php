<?php
/**
 * Admin — Module Feedback Poll Results
 */
$filterMod = intval($_GET['module_id'] ?? 0);

// Aggregate per module
$modRows = db()->query("
    SELECT m.id, m.icon, m.title, m.slug,
        COUNT(mf.id) AS total_votes,
        ROUND(AVG(mf.rating),2) AS avg_rating,
        SUM(mf.would_recommend) AS recommends
    FROM modules m
    LEFT JOIN module_feedback mf ON mf.module_id = m.id
    GROUP BY m.id
    ORDER BY m.id
");
$mods = [];
while ($r = $modRows->fetchArray(SQLITE3_ASSOC)) $mods[] = $r;
?>

<div class="container" style="max-width:900px;">
    <h1 class="page-title">📊 Module Feedback Poll Results</h1>
    <p class="text-muted" style="margin-top:-16px;margin-bottom:24px;">Learner ratings and comments collected after each module.</p>

    <!-- Summary table -->
    <div class="dp-card" style="margin-bottom:28px;overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
            <thead>
                <tr style="border-bottom:2px solid var(--border);">
                    <th style="text-align:left;padding:8px 10px;">Module</th>
                    <th style="text-align:center;padding:8px 10px;">Responses</th>
                    <th style="text-align:center;padding:8px 10px;">Avg Rating</th>
                    <th style="text-align:center;padding:8px 10px;">★★★★★</th>
                    <th style="text-align:center;padding:8px 10px;">Would Recommend</th>
                    <th style="text-align:center;padding:8px 10px;">Details</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($mods as $m):
                $pctRec = $m['total_votes'] > 0 ? round($m['recommends'] / $m['total_votes'] * 100) : 0;
                $stars  = $m['avg_rating'] ? round($m['avg_rating']) : 0;
            ?>
            <tr style="border-bottom:1px solid var(--border);<?= $m['total_votes']?'':'opacity:.45;' ?>">
                <td style="padding:9px 10px;font-weight:600;"><?= $m['icon'] ?> <?= e($m['title']) ?></td>
                <td style="text-align:center;padding:9px 10px;"><?= $m['total_votes'] ?: '—' ?></td>
                <td style="text-align:center;padding:9px 10px;font-weight:700;color:<?= $m['avg_rating']>=4?'#16a34a':($m['avg_rating']>=3?'#d97706':'#dc2626') ?>;">
                    <?= $m['avg_rating'] ?: '—' ?>
                </td>
                <td style="text-align:center;padding:9px 10px;">
                    <?php if ($m['avg_rating']): ?>
                        <?= str_repeat('⭐', $stars) ?><?= str_repeat('☆', 5-$stars) ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td style="text-align:center;padding:9px 10px;">
                    <?= $m['total_votes'] ? $pctRec.'%' : '—' ?>
                </td>
                <td style="text-align:center;padding:9px 10px;">
                    <?php if ($m['total_votes']): ?>
                    <a href="?page=poll_results&module_id=<?= $m['id'] ?>" class="btn btn-secondary btn-sm">View</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Detail view for one module -->
    <?php if ($filterMod): ?>
    <?php
    $mod = db()->querySingle("SELECT * FROM modules WHERE id=$filterMod", true);
    if ($mod):
        $rows = db()->query("
            SELECT mf.*, s.full_name AS student_name
            FROM module_feedback mf
            LEFT JOIN students s ON s.id = mf.student_id
            WHERE mf.module_id = $filterMod
            ORDER BY mf.submitted_at DESC
        ");
        $responses = [];
        while ($r = $rows->fetchArray(SQLITE3_ASSOC)) $responses[] = $r;
        $total = count($responses);
        $avgR  = $total ? round(array_sum(array_column($responses,'rating')) / $total, 1) : 0;
        $pctRec = $total ? round(array_sum(array_column($responses,'would_recommend')) / $total * 100) : 0;
        $starCounts = array_fill(1, 5, 0);
        foreach ($responses as $r) $starCounts[$r['rating']]++;
    ?>
    <div class="dp-card" style="margin-bottom:24px;">
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:16px;"><?= $mod['icon'] ?> <?= e($mod['title']) ?> — <?= $total ?> Response<?= $total!=1?'s':'' ?></h2>

        <div style="display:flex;gap:28px;flex-wrap:wrap;margin-bottom:20px;">
            <div style="text-align:center;">
                <div style="font-size:2rem;font-weight:900;color:var(--primary);"><?= $avgR ?>★</div>
                <div style="font-size:.75rem;color:#6b7280;">Avg rating</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:2rem;font-weight:900;color:#10b981;"><?= $pctRec ?>%</div>
                <div style="font-size:.75rem;color:#6b7280;">Would recommend</div>
            </div>
            <div style="flex:1;min-width:200px;">
                <?php for($s=5;$s>=1;$s--):
                    $p = $total ? round($starCounts[$s]/$total*100) : 0;
                ?>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <span style="width:16px;font-size:.75rem;color:#6b7280;text-align:right;"><?= $s ?>★</span>
                    <div style="flex:1;background:#e5e7eb;border-radius:4px;height:10px;overflow:hidden;">
                        <div style="width:<?= $p ?>%;background:var(--primary);height:100%;border-radius:4px;"></div>
                    </div>
                    <span style="width:36px;font-size:.72rem;color:#6b7280;"><?= $starCounts[$s] ?> (<?= $p ?>%)</span>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Text responses -->
        <?php
        $useful  = array_filter(array_column($responses,'most_useful'));
        $unclear = array_filter(array_column($responses,'unclear'));
        ?>
        <?php if ($useful): ?>
        <div style="margin-bottom:16px;">
            <h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#374151;margin-bottom:10px;">💡 What learners found most useful</h3>
            <?php foreach ($useful as $u): ?>
            <div style="background:#f0fdf4;border-left:3px solid #16a34a;padding:8px 12px;border-radius:0 8px 8px 0;font-size:.84rem;margin-bottom:8px;"><?= e($u) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($unclear): ?>
        <div>
            <h3 style="font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#374151;margin-bottom:10px;">🔍 What was unclear or difficult</h3>
            <?php foreach ($unclear as $u): ?>
            <div style="background:#fff7ed;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:0 8px 8px 0;font-size:.84rem;margin-bottom:8px;"><?= e($u) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$useful && !$unclear): ?>
            <p class="text-muted">No text comments yet for this module.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>
