<?php
/**
 * ARISE DataPost — Courier Interface
 * Accessible at /datapost/
 */
require_once __DIR__ . '/../includes/config.php';

$schoolConfig = getSchoolConfig();
$action = $_GET['action'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARISE DataPost</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>

<nav class="navbar">
    <div class="navbar-inner">
        <a href="/datapost/" class="navbar-brand">
            <div class="brand-icon">📡</div>
            <div>
                <h1>ARISE DataPost</h1>
                <span>Offline Data Sync</span>
            </div>
        </a>
        <ul class="nav-links">
            <li><a href="/datapost/" class="<?= $action === 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a></li>
            <li><a href="/datapost/?action=pickup" class="<?= $action === 'pickup' ? 'active' : '' ?>">📥 Pickup</a></li>
            <li><a href="/datapost/?action=deliver" class="<?= $action === 'deliver' ? 'active' : '' ?>">📤 Deliver</a></li>
            <li><a href="/datapost/?action=log" class="<?= $action === 'log' ? 'active' : '' ?>">📋 Log</a></li>
        </ul>
    </div>
</nav>

<div class="container">

<?php if (!$schoolConfig): ?>
    <div class="alert alert-warning">
        ⚠️ <strong>School not configured.</strong> Please complete <a href="/admin/?p=setup">initial setup</a> first.
    </div>
<?php endif; ?>

<?php
switch ($action) {
    case 'pickup':
        include __DIR__ . '/pickup.php';
        break;
    case 'deliver':
        include __DIR__ . '/deliver.php';
        break;
    case 'download':
        include __DIR__ . '/download.php';
        break;
    case 'log':
        include __DIR__ . '/log.php';
        break;
    default:
        include __DIR__ . '/dashboard.php';
}
?>

</div>

<footer class="footer">
    <strong>ARISE DataPost</strong> — Offline Data Sync System<br>
    <small>v<?= ARISE_VERSION ?> • <?= $schoolConfig ? e($schoolConfig['school_name']) : 'Not Configured' ?></small>
</footer>

</body>
</html>
