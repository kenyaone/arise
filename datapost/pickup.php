<?php
/**
 * DataPost Pickup — Courier downloads usage data bundle
 */
?>

<h1 class="page-title">📥 Data Pickup</h1>
<p class="text-muted mb-2">Enter your details to download the usage data bundle from this ARISE server. This will be uploaded to the central dashboard when you have internet access.</p>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">✅ Data bundle downloaded successfully! Upload it at your central dashboard when you get internet access.</div>
<?php endif; ?>

<div class="dp-card">
    <form method="POST" action="/datapost/?action=download">
        <label class="section-title" for="courier_name">Your Name</label>
        <input type="text" name="courier_name" id="courier_name" placeholder="e.g. John Doe" required
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:var(--radius); font-size:0.95rem; margin-bottom:12px;">

        <label class="section-title" for="courier_email">Your Email</label>
        <input type="email" name="courier_email" id="courier_email" placeholder="e.g. john@worldpossiblekenya.org" required
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:var(--radius); font-size:0.95rem; margin-bottom:12px;">

        <label class="section-title" for="date_from">Data From</label>
        <input type="date" name="date_from" id="date_from" value="<?= date('Y-m-01') ?>" required
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:var(--radius); font-size:0.95rem; margin-bottom:12px;">

        <label class="section-title" for="date_to">Data To</label>
        <input type="date" name="date_to" id="date_to" value="<?= date('Y-m-d') ?>" required
            style="width:100%; padding:12px; border:2px solid var(--border); border-radius:var(--radius); font-size:0.95rem; margin-bottom:20px;">

        <button type="submit" class="dp-pickup-btn">📥 Generate & Download Data Bundle</button>
    </form>
</div>

<!-- Quick Stats Preview -->
<div class="dp-card mt-2">
    <h3 class="section-title">📊 Data Available for Pickup</h3>
    <?php
    $from = date('Y-m-01');
    $to = date('Y-m-d');
    $sessions = db()->querySingle("SELECT COUNT(*) FROM sessions WHERE DATE(started_at) BETWEEN '$from' AND '$to'") ?? 0;
    $devices = db()->querySingle("SELECT COUNT(DISTINCT device_hash) FROM sessions WHERE DATE(started_at) BETWEEN '$from' AND '$to'") ?? 0;
    $quizzes = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts WHERE DATE(completed_at) BETWEEN '$from' AND '$to'") ?? 0;
    $views = db()->querySingle("SELECT COUNT(*) FROM page_views WHERE DATE(viewed_at) BETWEEN '$from' AND '$to'") ?? 0;
    ?>
    <div class="dp-stats-grid">
        <div class="dp-stat">
            <div class="stat-value"><?= $devices ?></div>
            <div class="stat-label">Devices</div>
        </div>
        <div class="dp-stat">
            <div class="stat-value"><?= $sessions ?></div>
            <div class="stat-label">Sessions</div>
        </div>
        <div class="dp-stat">
            <div class="stat-value"><?= $views ?></div>
            <div class="stat-label">Page Views</div>
        </div>
        <div class="dp-stat">
            <div class="stat-value"><?= $quizzes ?></div>
            <div class="stat-label">Quizzes</div>
        </div>
    </div>
</div>
