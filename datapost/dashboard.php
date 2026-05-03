<?php
/**
 * DataPost Local Dashboard — Shows school stats at a glance
 */

// Overall stats
$totalSessions = db()->querySingle("SELECT COUNT(*) FROM sessions") ?? 0;
$totalDevices = db()->querySingle("SELECT COUNT(DISTINCT device_hash) FROM sessions") ?? 0;
$totalPageViews = db()->querySingle("SELECT COUNT(*) FROM page_views") ?? 0;
$totalQuizzes = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts") ?? 0;
$totalQuestions = db()->querySingle("SELECT COUNT(*) FROM anonymous_questions") ?? 0;
$avgQuizScore = db()->querySingle("SELECT ROUND(AVG(percentage), 1) FROM quiz_attempts") ?? 0;

// Today's stats
$today = date('Y-m-d');
$todaySessions = db()->querySingle("SELECT COUNT(*) FROM sessions WHERE DATE(started_at) = '$today'") ?? 0;
$todayDevices = db()->querySingle("SELECT COUNT(DISTINCT device_hash) FROM sessions WHERE DATE(started_at) = '$today'") ?? 0;

// Last pickup
$lastPickup = db()->querySingle("SELECT pickup_time FROM datapost_pickups ORDER BY pickup_time DESC LIMIT 1");

// Topic distribution
$topicResult = db()->query("SELECT pv.page_slug, m.title, m.icon, COUNT(*) as views FROM page_views pv JOIN modules m ON pv.page_slug = m.slug WHERE pv.page_type = 'module' GROUP BY pv.page_slug ORDER BY views DESC LIMIT 5");
$topTopics = [];
while ($row = $topicResult->fetchArray(SQLITE3_ASSOC)) {
    $topTopics[] = $row;
}
?>

<h1 class="page-title">📊 DataPost Dashboard</h1>

<?php if ($schoolConfig): ?>
    <p class="text-muted mb-2">
        <strong><?= e($schoolConfig['school_name']) ?></strong> •
        <?= e($schoolConfig['county']) ?>, <?= e($schoolConfig['sub_county']) ?> •
        ID: <code><?= e($schoolConfig['school_id']) ?></code>
    </p>
<?php endif; ?>

<!-- Today -->
<h2 class="section-title">📅 Today (<?= date('M j, Y') ?>)</h2>
<div class="dp-stats-grid mb-2">
    <div class="dp-card dp-stat">
        <div class="stat-value"><?= $todayDevices ?></div>
        <div class="stat-label">Devices Today</div>
    </div>
    <div class="dp-card dp-stat">
        <div class="stat-value"><?= $todaySessions ?></div>
        <div class="stat-label">Sessions Today</div>
    </div>
</div>

<!-- All Time -->
<h2 class="section-title">📈 All Time</h2>
<div class="dp-stats-grid mb-2">
    <div class="dp-card dp-stat">
        <div class="stat-value"><?= $totalDevices ?></div>
        <div class="stat-label">Unique Devices</div>
    </div>
    <div class="dp-card dp-stat">
        <div class="stat-value"><?= $totalSessions ?></div>
        <div class="stat-label">Total Sessions</div>
    </div>
    <div class="dp-card dp-stat">
        <div class="stat-value"><?= $totalPageViews ?></div>
        <div class="stat-label">Page Views</div>
    </div>
    <div class="dp-card dp-stat">
        <div class="stat-value"><?= $totalQuizzes ?></div>
        <div class="stat-label">Quizzes Taken</div>
    </div>
    <div class="dp-card dp-stat">
        <div class="stat-value"><?= $avgQuizScore ?>%</div>
        <div class="stat-label">Avg Quiz Score</div>
    </div>
    <div class="dp-card dp-stat">
        <div class="stat-value"><?= $totalQuestions ?></div>
        <div class="stat-label">Questions Asked</div>
    </div>
</div>

<!-- Top Topics -->
<?php if (count($topTopics) > 0): ?>
<h2 class="section-title">🔥 Most Viewed Topics</h2>
<div class="dp-card mb-2">
    <?php foreach ($topTopics as $t): ?>
        <div class="dp-log-item">
            <span><?= $t['icon'] ?> <?= e($t['title']) ?></span>
            <strong><?= $t['views'] ?> views</strong>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Last Pickup -->
<div class="dp-card mb-2">
    <h3 class="section-title">📡 DataPost Status</h3>
    <p>Last data pickup: <strong><?= $lastPickup ? date('M j, Y g:i A', strtotime($lastPickup)) : 'Never — no data picked up yet' ?></strong></p>
    <div class="mt-1">
        <a href="/datapost/?action=pickup" class="dp-pickup-btn">📥 Ready for Pickup — Tap to Start</a>
    </div>
</div>
