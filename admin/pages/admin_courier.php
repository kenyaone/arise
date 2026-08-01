<?php
// Admin: Courier PWA share page.
// Purpose: give admins a single place to hand out the courier install link.
// Facilitators/pastors should never need to type or remember the URL —
// the admin shows them the QR code from this page and they scan it once.

if (!function_exists('db')) require_once dirname(__DIR__) . '/../includes/config.php';

// Build the courier URL from the current request so it works whether the box
// is on 192.168.0.100 or a renamed IP. Always HTTP on the LAN.
$host       = $_SERVER['HTTP_HOST'] ?? 'this-box';
$courierUrl = "http://$host/arise/arise-courier.html";

// Also expose the raw box URL for the courier's "Box URL" setting field —
// helps if the admin ever needs to enter it manually.
$boxUrl = "http://$host/arise";
?>
<h1 class="page-title">📱 Courier App</h1>
<p class="text-muted" style="margin-top:-16px;margin-bottom:20px;">
  Facilitators install the ARISE Courier once on their phone. Then they open it near this box (on the box's WiFi) and it automatically picks up data and sends it to <strong>ariseci.org</strong> — no URLs to type, no Send button to press.
</p>

<div class="dp-card">
  <h2 class="section-title">🔗 Direct link</h2>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="text" id="urlBox" readonly value="<?= htmlspecialchars($courierUrl) ?>"
           style="flex:1;min-width:280px;font-family:monospace;font-size:.95rem;padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
    <button type="button" onclick="copyUrl()" class="btn btn-primary" id="copyBtn">📋 Copy</button>
    <a href="<?= htmlspecialchars($courierUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm" style="background:#f3f4f6;color:#374151;">Open →</a>
  </div>
  <p style="font-size:.8rem;color:#6b7280;margin-top:10px;">
    This URL only works on devices connected to <strong>this box's WiFi</strong> — off-network devices can't reach it. That's the whole point: the courier picks up data from the box locally, then relays to the cloud when it has mobile data.
  </p>
</div>

<div class="dp-card">
  <h2 class="section-title">📷 Scan with a phone</h2>
  <p style="color:#6b7280;font-size:.9rem;margin-bottom:12px;">
    Show this QR to the facilitator. They open the phone camera, point at the code, tap the link — the courier loads. Then they install it once (steps below), and it's a home-screen app forever after.
  </p>
  <div id="qr" style="text-align:center;margin:8px 0;"></div>
  <p style="font-size:.8rem;color:#6b7280;text-align:center;">
    ↑ Points at <code style="background:#f3f4f6;padding:1px 4px;border-radius:3px;"><?= htmlspecialchars($courierUrl) ?></code>
  </p>
</div>

<div class="dp-card">
  <h2 class="section-title">📲 Install steps (share these with facilitators)</h2>

  <details open style="border-bottom:1px solid #f3f4f6;padding:10px 0;">
    <summary style="cursor:pointer;font-weight:600;">🤖 Android — Chrome, Samsung Internet, Edge</summary>
    <ol style="margin:12px 0 6px 22px;line-height:1.7;">
      <li>Open the courier link on the phone (scan the QR, or paste the URL)</li>
      <li>Tap the browser's ⋮ menu (top right)</li>
      <li>Tap <strong>"Add to Home screen"</strong> — or <strong>"Install app"</strong> if it appears</li>
      <li>Confirm. An ARISE Courier icon lands on the home screen.</li>
    </ol>
    <p style="font-size:.8rem;color:#6b7280;margin-top:6px;">
      Note: because this box serves HTTP (not HTTPS), the fancier "Install app" prompt won't auto-appear — but <em>Add to Home screen</em> works the same in practice.
    </p>
  </details>

  <details style="border-bottom:1px solid #f3f4f6;padding:10px 0;">
    <summary style="cursor:pointer;font-weight:600;">🍎 iPhone / iPad — Safari</summary>
    <ol style="margin:12px 0 6px 22px;line-height:1.7;">
      <li>Open the courier link in <strong>Safari</strong> — not Chrome. iOS PWA install only works from Safari.</li>
      <li>Tap the Share button (square with an up-arrow) at the bottom</li>
      <li>Scroll down and tap <strong>"Add to Home Screen"</strong></li>
      <li>Tap Add. An ARISE Courier icon lands on the home screen.</li>
    </ol>
  </details>

  <details style="padding:10px 0;">
    <summary style="cursor:pointer;font-weight:600;">⚙️ First-use configuration</summary>
    <p style="margin:12px 0 6px;line-height:1.6;">
      The courier is preconfigured — open it and it just works. If the settings ever get cleared, tap the ⚙️ Settings button in the app and paste these:
    </p>
    <table style="width:100%;font-size:.9rem;border-collapse:collapse;">
      <tr><td style="padding:6px 0;color:#6b7280;">Box URL</td><td><code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;"><?= htmlspecialchars($boxUrl) ?></code></td></tr>
      <tr><td style="padding:6px 0;color:#6b7280;">Cloud URL</td><td><code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;">https://ariseci.org/arise_cron_receiver.php</code></td></tr>
      <tr><td style="padding:6px 0;color:#6b7280;">Pickup Secret</td><td><code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;">arise_sync_k3nya_2026</code></td></tr>
    </table>
  </details>
</div>

<script src="/arise/js/qrcode.min.js"></script>
<script>
function copyUrl() {
  const box = document.getElementById('urlBox');
  box.select();
  box.setSelectionRange(0, 999);
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(box.value);
    } else {
      document.execCommand('copy');
    }
    const btn = document.getElementById('copyBtn');
    const orig = btn.textContent;
    btn.textContent = '✓ Copied';
    setTimeout(() => btn.textContent = orig, 1500);
  } catch (e) {
    alert('Copy failed — please select and copy manually.');
  }
}

// QR — auto-size so the code fits the URL length; error-correction M for
// a good balance of size vs scan reliability under bad lighting.
(function renderQR() {
  const url = <?= json_encode($courierUrl) ?>;
  const qr  = qrcode(0, 'M');
  qr.addData(url);
  qr.make();
  const container = document.getElementById('qr');
  container.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 4, scalable: true });
  const svg = container.querySelector('svg');
  if (svg) {
    svg.style.background   = '#fff';
    svg.style.border       = '2px solid #e5e7eb';
    svg.style.borderRadius = '12px';
    svg.style.padding      = '8px';
    svg.style.width        = '260px';
    svg.style.height       = '260px';
    svg.style.maxWidth     = '90vw';
  }
})();
</script>
