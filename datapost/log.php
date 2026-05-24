<?php
/**
 * DataPost Log — Pickup and delivery history
 */

$pickups = db()->query('SELECT * FROM datapost_pickups ORDER BY pickup_time DESC LIMIT 20');
$pickupList = [];
while ($row = $pickups->fetchArray(SQLITE3_ASSOC)) {
    $pickupList[] = $row;
}

$deliveries = db()->query('SELECT * FROM datapost_deliveries ORDER BY delivery_time DESC LIMIT 20');
$deliveryList = [];
while ($row = $deliveries->fetchArray(SQLITE3_ASSOC)) {
    $deliveryList[] = $row;
}
?>

<h1 class="page-title">📋 DataPost Activity Log</h1>

<!-- Pickups -->
<h2 class="section-title">📥 Data Pickups</h2>
<?php if (count($pickupList) > 0): ?>
    <div class="dp-card mb-2">
        <?php foreach ($pickupList as $p): ?>
            <div class="dp-log-item">
                <div>
                    <strong><?= e($p['courier_name']) ?></strong>
                    <span class="text-muted text-small">(<?= e($p['courier_email']) ?>)</span><br>
                    <span class="text-small text-muted">
                        Data: <?= date('M j', strtotime($p['data_from'])) ?> — <?= date('M j, Y', strtotime($p['data_to'])) ?> •
                        <?= $p['bundle_size_kb'] ?> KB
                    </span>
                </div>
                <span class="text-small text-muted"><?= date('M j, Y g:i A', strtotime($p['pickup_time'])) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="alert alert-info mb-2">No pickups recorded yet.</div>
<?php endif; ?>

<!-- Deliveries -->
<h2 class="section-title">📤 Content Deliveries</h2>
<?php if (count($deliveryList) > 0): ?>
    <div class="dp-card">
        <?php foreach ($deliveryList as $d): ?>
            <div class="dp-log-item">
                <div>
                    <strong><?= e($d['courier_name']) ?></strong>
                    <span class="text-muted text-small">(<?= e($d['courier_email']) ?>)</span><br>
                    <span class="text-small text-muted">
                        📦 <?= e($d['package_name']) ?> • <?= $d['package_size_kb'] ?> KB
                        <?php if ($d['notes']): ?><br>💬 <?= e($d['notes']) ?><?php endif; ?>
                    </span>
                </div>
                <span class="text-small text-muted"><?= date('M j, Y g:i A', strtotime($d['delivery_time'])) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="alert alert-info">No deliveries recorded yet.</div>
<?php endif; ?>
