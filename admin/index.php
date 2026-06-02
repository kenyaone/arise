<?php
session_start();
const CONFIG_PATH = '/home/cpmsfdav/cloud_db_config.php';
const ADMIN_PASSWORD = 'arise2026';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['arise_admin'] = true;
        header('Location: /admin/'); exit;
    }
    $loginError = 'Wrong password.';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: /admin/'); exit; }
if (!($_SESSION['arise_admin'] ?? false)) { showLogin($loginError ?? null); exit; }

// ── DB ────────────────────────────────────────────────────────────────────────
$cfg = require CONFIG_PATH;
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli($cfg['host']??'localhost',$cfg['user']??'',$cfg['pass']??'',$cfg['db']??'');
$m->set_charset('utf8mb4');

// ── Actions ───────────────────────────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $did = trim($_POST['device_id'] ?? '');
    $act = $_POST['action'] ?? '';

    if ($act === 'update' && $did) {
        $name    = trim($_POST['name'] ?? '');
        $county  = trim($_POST['county'] ?? '');
        $cluster = trim($_POST['cluster_name'] ?? '');
        $lat     = $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
        $lng     = $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
        $active  = isset($_POST['is_active']) ? 1 : 0;
        $stmt = $m->prepare('UPDATE schools SET name=?, county=?, cluster_name=?, lat=?, lng=?, is_active=? WHERE device_id=?');
        $stmt->bind_param('sssddis', $name, $county, $cluster, $lat, $lng, $active, $did);
        $stmt->execute();
        $msg = "✅ Updated $did";
    }
    if ($act === 'deactivate' && $did) {
        $m->query("UPDATE schools SET is_active=0 WHERE device_id='".$m->real_escape_string($did)."'");
        $msg = "⛔ Deactivated $did";
    }
    if ($act === 'activate' && $did) {
        $m->query("UPDATE schools SET is_active=1 WHERE device_id='".$m->real_escape_string($did)."'");
        $msg = "✅ Activated $did";
    }
}

// ── Fetch devices ─────────────────────────────────────────────────────────────
$now = time();
$devices = [];
$r = $m->query("SELECT * FROM schools ORDER BY is_active DESC, last_sync_at DESC");
while ($row = $r->fetch_assoc()) {
    $syncTs = $row['last_sync_at'] ? strtotime($row['last_sync_at']) : null;
    $ageSec = $syncTs ? max(0, $now - $syncTs) : null;
    $row['_age']    = $ageSec;
    $row['_online'] = $ageSec !== null && $ageSec <= 7200;
    $row['_never']  = $ageSec === null;
    $devices[] = $row;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ago(?int $s): string {
    if ($s === null) return 'Never';
    if ($s < 120) return 'Just now';
    if ($s < 3600) return floor($s/60).'m ago';
    if ($s < 86400) return floor($s/3600).'h ago';
    return floor($s/86400).'d ago';
}

function showLogin(?string $err): void { ?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ARISE Admin — Login</title>
<style>*{box-sizing:border-box;margin:0;padding:0;}body{background:linear-gradient(135deg,#0a5e2a,#0ea271);min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',Arial,sans-serif;}.card{background:#fff;border-radius:16px;padding:40px;width:340px;box-shadow:0 8px 32px rgba(0,0,0,.15);}.card h1{font-size:1.4rem;color:#0a5e2a;margin-bottom:6px;}p{font-size:.85rem;color:#6b7280;margin-bottom:24px;}input{width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:.95rem;margin-bottom:14px;}button{width:100%;padding:11px;background:#0a5e2a;color:#fff;border:none;border-radius:8px;font-size:1rem;cursor:pointer;font-weight:600;}.err{color:#dc2626;font-size:.85rem;margin-top:8px;}</style>
</head><body><div class="card"><h1>🛡 ARISE Admin</h1><p>Cloud device management</p>
<form method="post"><input type="password" name="password" placeholder="Admin password" autofocus>
<button>Sign In</button><?php if($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
</form></div></body></html><?php }

?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ARISE Admin — Devices</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Roboto,Arial,sans-serif;background:#f5f7fa;color:#1f2937;}
header{background:linear-gradient(135deg,#0a5e2a,#0ea271);color:#fff;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;}
header h1{font-size:1.3rem;}
header a{color:#fff;font-size:.85rem;opacity:.85;text-decoration:none;}
.wrap{max-width:1100px;margin:24px auto;padding:0 16px;}
.msg{background:#dcfce7;color:#166534;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;}
.devices{display:grid;gap:16px;}
.device{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.07);overflow:hidden;}
.device-head{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid #f3f4f6;}
.dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.dot.on{background:#16a34a;}.dot.off{background:#dc2626;}.dot.never{background:#9ca3af;}
.device-head h2{font-size:1rem;color:#111827;flex:1;}
.device-head .mac{font-family:monospace;font-size:.78rem;color:#6b7280;}
.device-head .badge{font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:10px;}
.badge.on{background:#dcfce7;color:#166534;}.badge.off{background:#fee2e2;color:#dc2626;}.badge.never{background:#f3f4f6;color:#6b7280;}
.device-body{padding:20px;}
.stats{display:flex;gap:24px;margin-bottom:16px;flex-wrap:wrap;}
.stat .lbl{font-size:.72rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.stat .val{font-size:1.1rem;font-weight:700;color:#0a5e2a;}
form.edit{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
form.edit .full{grid-column:1/-1;}
label{font-size:.78rem;color:#6b7280;display:block;margin-bottom:3px;}
input[type=text],input[type=number]{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.9rem;}
.actions{display:flex;gap:8px;margin-top:12px;grid-column:1/-1;flex-wrap:wrap;}
button{padding:8px 16px;border:none;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer;}
.btn-save{background:#0a5e2a;color:#fff;}
.btn-deactivate{background:#fee2e2;color:#dc2626;}
.btn-activate{background:#dcfce7;color:#166534;}
.inactive{opacity:.6;}
@media(max-width:600px){form.edit{grid-template-columns:1fr;}.stats{gap:14px;}}
</style>
</head>
<body>
<header>
  <h1>🛡 ARISE Admin — Devices</h1>
  <a href="?logout=1">Sign out</a>
</header>
<div class="wrap">
<?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>

<div class="devices">
<?php foreach ($devices as $d):
    $status = $d['_never'] ? 'never' : ($d['_online'] ? 'on' : 'off');
    $label  = $d['_never'] ? 'Never synced' : ($d['_online'] ? 'Online' : 'Offline '.ago($d['_age']));
    $syncInterval = $d['avg_sync_interval_secs'] ? round($d['avg_sync_interval_secs']/60,1).' min' : '—';
    $delta = ($d['learner_count_prev'] !== null) ? ((int)$d['learner_count']-(int)$d['learner_count_prev']) : null;
?>
<div class="device <?= $d['is_active'] ? '' : 'inactive' ?>">
  <div class="device-head">
    <div class="dot <?= $status ?>"></div>
    <div>
      <h2><?= h($d['name']) ?></h2>
      <div class="mac"><?= h($d['device_id']) ?></div>
    </div>
    <span class="badge <?= $status ?>"><?= h($label) ?></span>
    <?php if (!$d['is_active']): ?><span class="badge never">Inactive</span><?php endif; ?>
  </div>
  <div class="device-body">
    <div class="stats">
      <div class="stat"><div class="lbl">Learners</div><div class="val"><?= (int)$d['learner_count'] ?><?= $delta > 0 ? " <span style='color:#16a34a;font-size:.8rem'>+$delta</span>" : ($delta < 0 ? " <span style='color:#dc2626;font-size:.8rem'>$delta</span>" : '') ?></div></div>
      <div class="stat"><div class="lbl">Sync rate</div><div class="val" style="font-size:.95rem"><?= h($syncInterval) ?></div></div>
      <div class="stat"><div class="lbl">Last sync</div><div class="val" style="font-size:.95rem"><?= ago($d['_age']) ?></div></div>
      <div class="stat"><div class="lbl">Cluster</div><div class="val" style="font-size:.85rem"><?= h($d['cluster_name'] ?: '—') ?></div></div>
      <div class="stat"><div class="lbl">County</div><div class="val" style="font-size:.85rem"><?= h($d['county'] ?: '—') ?></div></div>
    </div>

    <form method="post" class="edit">
      <input type="hidden" name="device_id" value="<?= h($d['device_id']) ?>">
      <input type="hidden" name="action" value="update">
      <div class="full">
        <label>Project name</label>
        <input type="text" name="name" value="<?= h($d['name']) ?>">
      </div>
      <div>
        <label>County</label>
        <input type="text" name="county" value="<?= h($d['county']) ?>">
      </div>
      <div>
        <label>Cluster</label>
        <input type="text" name="cluster_name" value="<?= h($d['cluster_name']) ?>">
      </div>
      <div>
        <label>Latitude</label>
        <input type="number" name="lat" step="0.000001" value="<?= h($d['lat']) ?>">
      </div>
      <div>
        <label>Longitude</label>
        <input type="number" name="lng" step="0.000001" value="<?= h($d['lng']) ?>">
      </div>
      <div class="full" style="display:flex;align-items:center;gap:8px;margin-top:4px;">
        <input type="checkbox" name="is_active" id="ia_<?= h($d['device_id']) ?>" <?= $d['is_active'] ? 'checked' : '' ?> style="width:auto;">
        <label for="ia_<?= h($d['device_id']) ?>" style="margin:0;font-size:.88rem;color:#374151;">Active (shows on map)</label>
      </div>
      <div class="actions">
        <button type="submit" class="btn-save">💾 Save changes</button>
        <?php if ($d['is_active']): ?>
        <button type="submit" name="action" value="deactivate" class="btn-deactivate" onclick="return confirm('Deactivate this device?')">⛔ Deactivate</button>
        <?php else: ?>
        <button type="submit" name="action" value="activate" class="btn-activate">✅ Activate</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>
</div>
</div>
</body></html>
