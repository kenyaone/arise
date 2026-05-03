<?php /* PWA install banner — included snippet, not standalone */ ?>
<div id="pwa-install-bar" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#0ea271;color:#fff;padding:12px 16px;align-items:center;justify-content:space-between;gap:10px;box-shadow:0 -2px 8px rgba(0,0,0,.25);">
  <span style="flex:1;font-size:.9rem;font-weight:600;">&#128241; Add ARISE to your home screen for offline access</span>
  <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
    <button id="pwa-install-btn" style="background:#fff;color:#0ea271;border:none;border-radius:8px;padding:8px 16px;font-weight:700;font-size:.85rem;cursor:pointer;">Install</button>
    <button id="pwa-dismiss-btn" aria-label="Dismiss" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:8px;padding:8px 10px;font-size:1rem;cursor:pointer;line-height:1;">&#10005;</button>
  </div>
</div>
<script>
(function () {
  var bar = document.getElementById('pwa-install-bar');
  var installBtn = document.getElementById('pwa-install-btn');
  var dismissBtn = document.getElementById('pwa-dismiss-btn');
  var deferred = null;

  // Show the bar only when the browser fires beforeinstallprompt
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    bar.style.display = 'flex';
  });

  // Trigger the native install dialog on button click
  installBtn.addEventListener('click', function () {
    if (deferred) {
      deferred.prompt();
      deferred.userChoice.then(function () {
        deferred = null;
        bar.style.display = 'none';
      });
    }
  });

  // Dismiss without installing
  dismissBtn.addEventListener('click', function () {
    bar.style.display = 'none';
  });

  // Hide bar once the app is installed
  window.addEventListener('appinstalled', function () {
    bar.style.display = 'none';
  });
}());
</script>
