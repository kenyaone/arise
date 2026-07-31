<?php
// ARISE — first-boot landing page for field admins.
// Shows: box identity, network state, quick links, last sync, update prompt.
// Reached at /arise/?p=welcome — no auth required.

require_once dirname(__DIR__, 2) . '/includes/config.php';

function getHotspot(): array {
    if (!function_exists('shell_exec')) return ['ssid'=>null, 'pass'=>null];
    $ssid = trim((string)@shell_exec("nmcli -t -f 802-11-wireless.ssid connection show ARISE-Hotspot 2>/dev/null | cut -d: -f2"));
    $pass = trim((string)@shell_exec("nmcli -t -s -f 802-11-wireless-security.psk connection show ARISE-Hotspot 2>/dev/null | cut -d: -f2"));
    return ['ssid' => $ssid ?: null, 'pass' => $pass ?: null];
}

function getBoxIP(): string {
    $ip = trim((string)@shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'"));
    return $ip ?: 'unknown';
}

function lastSync(): ?array {
    $log = '/home/arise/cloud_sync.log';
    if (!is_readable($log)) return null;
    $tail = @shell_exec("tail -n 60 " . escapeshellarg($log) . " 2>/dev/null");
    if (!$tail) return null;
    if (preg_match_all('/\[([^\]]+)\] (MySQL OK|MySQL FAIL).*/m', $tail, $m, PREG_SET_ORDER)) {
        $last = end($m);
        return ['when' => $last[1], 'status' => $last[2]];
    }
    return null;
}

$hotspot = getHotspot();
$ip      = getBoxIP();
$sync    = lastSync();
$hostname= trim((string)@shell_exec('hostname'));
$device  = is_readable('/etc/arise_device_id') ? trim((string)file_get_contents('/etc/arise_device_id')) : 'unknown';
$internet = false;
$ch = @curl_init('https://github.com');
if ($ch) {
    @curl_setopt_array($ch, [CURLOPT_NOBODY=>true, CURLOPT_TIMEOUT=>2, CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false]);
    @curl_exec($ch);
    $internet = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) > 0;
    @curl_close($ch);
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Welcome to ARISE</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Roboto,Arial,sans-serif;background:linear-gradient(135deg,#0a5e2a,#0ea271);min-height:100vh;color:#1f2937;padding:20px;}
.wrap{max-width:760px;margin:0 auto;}
.hero{background:#fff;border-radius:16px;padding:32px 24px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.15);margin-bottom:18px;}
.hero h1{font-size:1.8rem;color:#0a5e2a;margin-bottom:6px;}
.hero p{color:#6b7280;font-size:.95rem;}
.cards{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));}
.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 14px rgba(0,0,0,.08);}
.card h2{font-size:1rem;color:#0a5e2a;margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.kv{display:flex;justify-content:space-between;padding:6px 0;font-size:.92rem;border-bottom:1px solid #f3f4f6;}
.kv:last-child{border:none;}
.kv .k{color:#6b7280;}
.kv .v{font-weight:700;color:#111827;text-align:right;font-family:'Courier New',monospace;}
.btn-row{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-top:18px;}
.btn{background:#0a5e2a;color:#fff;padding:14px 18px;border-radius:10px;text-decoration:none;font-weight:700;text-align:center;font-size:.95rem;transition:.2s;}
.btn:hover{background:#0e7c38;}
.btn.secondary{background:#fff;color:#0a5e2a;border:2px solid #0a5e2a;}
.btn.secondary:hover{background:#dcfce7;}
.status-pill{display:inline-block;padding:4px 12px;border-radius:20px;font-size:.78rem;font-weight:700;}
.status-pill.ok{background:#dcfce7;color:#166534;}
.status-pill.off{background:#fee2e2;color:#991b1b;}
.status-pill.warn{background:#fef3c7;color:#92400e;}
footer{text-align:center;color:#fff;font-size:.78rem;margin-top:20px;opacity:.9;}
@media (max-width:600px){.hero h1{font-size:1.4rem;}}
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <h1>🌍 Welcome to ARISE</h1>
    <p>Adolescent Reproductive Health · Education Platform</p>
    <div style="margin-top:12px;">
      <span class="status-pill <?= $internet ? 'ok' : 'warn' ?>">
        <?= $internet ? '🌐 Online' : '📡 Offline' ?>
      </span>
    </div>
  </div>

  <div class="cards">
    <div class="card">
      <h2>📡 Connection</h2>
      <div class="kv"><span class="k">WiFi name</span><span class="v"><?= h($hotspot['ssid'] ?: '—') ?></span></div>
      <div class="kv"><span class="k">WiFi password</span><span class="v"><?= h($hotspot['pass'] ?: '—') ?></span></div>
      <div class="kv"><span class="k">Box IP</span><span class="v"><?= h($ip) ?></span></div>
      <div class="kv"><span class="k">Address to share</span><span class="v">http://<?= h($ip) ?>/arise/</span></div>
    </div>

    <div class="card">
      <h2>🆔 This box</h2>
      <div class="kv"><span class="k">Hostname</span><span class="v"><?= h($hostname) ?></span></div>
      <div class="kv"><span class="k">Device ID</span><span class="v"><?= h($device) ?></span></div>
      <?php if ($sync): ?>
      <div class="kv"><span class="k">Last cloud sync</span><span class="v" style="color:<?= $sync['status']==='MySQL OK'?'#166534':'#991b1b' ?>"><?= h($sync['when']) ?></span></div>
      <?php else: ?>
      <div class="kv"><span class="k">Last cloud sync</span><span class="v">—</span></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="margin-top:14px;">
    <h2>🚀 What to do next</h2>
    <p style="font-size:.92rem;color:#374151;margin-bottom:8px;">
      Students connect to the WiFi above, then open the browser and they land on the learner app.
      Admin tasks (adding projects, learners, content) happen here:
    </p>
    <div class="btn-row">
      <a href="/arise/admin/" class="btn">🔐 Admin Login</a>
      <a href="/arise/" class="btn secondary">📚 Student App</a>
      <?php if ($internet): ?>
        <a href="/arise/admin/?p=updates" class="btn secondary">⬆️ Check for Updates</a>
      <?php endif; ?>
    </div>
    <p style="font-size:.82rem;color:#6b7280;margin-top:14px;">
      <?php if ($internet): ?>
        🌐 You're online — click <strong>Check for Updates</strong> after logging in to install the latest features.
      <?php else: ?>
        📡 You're currently offline. Updates will work whenever you connect to WiFi or hotspot.
      <?php endif; ?>
    </p>
  </div>

  <div class="card" style="margin-top:14px;border:2px solid #A855F7;">
    <h2>📱 Sync this box to <code>ariseci.org</code> — from your phone</h2>
    <p style="font-size:.92rem;color:#374151;margin-bottom:10px;">
      Install the <strong>ARISE Courier</strong> app on any Android or iPhone.
      Tap Pickup while you're on this box's WiFi, walk out, and when the phone
      finds internet the data ships to
      <a href="https://ariseci.org/locations.php" target="_blank">ariseci.org/locations</a>
      automatically. No cables, no upload forms.
    </p>

    <div class="btn-row" style="margin-bottom:14px;">
      <a href="/arise/arise-courier.html" class="btn">📲 Open Courier App</a>
      <a href="/arise/?p=pwa_datapost#tab-courier" class="btn secondary">⚙️ Admin: Courier tab</a>
    </div>

    <div style="background:#faf5ff;border:1px solid #E9D5FF;border-radius:8px;padding:12px 14px;margin-bottom:12px;">
      <div style="font-weight:700;color:#6B21A8;font-size:.9rem;margin-bottom:8px;">How to install (once)</div>
      <ol style="margin:0 0 0 20px;color:#374151;font-size:.88rem;line-height:1.55;">
        <li>Connect your phone to the WiFi named <strong><?= h($hotspot['ssid'] ?: '(this box\'s WiFi)') ?></strong>.</li>
        <li>In the phone browser open <code>http://<?= h($ip) ?>/arise/arise-courier.html</code>.</li>
        <li><strong>Android (Chrome):</strong> tap the menu <code>⋮</code> → <em>Add to Home screen</em> → <em>Install</em>.</li>
        <li><strong>iPhone (Safari):</strong> tap the share icon <code>⎋</code> → <em>Add to Home Screen</em>.</li>
        <li>Launch <em>ARISE Courier</em> from your home screen. The Box URL and Pickup Secret should already be filled in.</li>
      </ol>
    </div>

    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;">
      <div style="font-size:.75rem;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">If you need to type it manually</div>
      <div style="font-size:.85rem;color:#111;line-height:1.7;">
        Box URL: <code>http://<?= h($ip) ?>/arise</code><br>
        Pickup Secret: <code id="wc-secret" data-full="<?= h(defined('CLOUD_SYNC_SECRET') ? CLOUD_SYNC_SECRET : '') ?>"><?php
          $s = defined('CLOUD_SYNC_SECRET') ? CLOUD_SYNC_SECRET : '';
          echo h(strlen($s) > 4 ? str_repeat('•', max(4, strlen($s) - 4)) . substr($s, -4) : '••••');
        ?></code>
        <button onclick="var c=document.getElementById('wc-secret');if(c.textContent===c.dataset.full){c.textContent=c.dataset.full.replace(/./g,'•').slice(0,-4)+c.dataset.full.slice(-4);this.textContent='Reveal';}else{c.textContent=c.dataset.full;this.textContent='Hide';}" style="margin-left:8px;padding:2px 10px;font-size:.7rem;border:1px solid #A855F7;color:#7E22CE;background:#fff;border-radius:12px;cursor:pointer;">Reveal</button>
      </div>
    </div>
  </div>

  <footer>ARISE Platform · Built for offline-first deployments</footer>
</div>
</body>
</html>
