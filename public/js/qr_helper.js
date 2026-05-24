/**
 * ARISE — Access Helper (qr_helper.js)
 * Offline-compatible URL display modal for classroom projection.
 *
 * Usage (from admin dashboard or any page):
 *   showAccessHelper('http://arise.local/arise/');
 *
 * Creates a full-screen overlay showing the URL in very large text,
 * plus the server's IP addresses (detected via WebRTC or fallback),
 * suitable for projection on a classroom screen.
 *
 * No external dependencies. Pure vanilla JS.
 */

(function (global) {
    'use strict';

    // ── Constants ─────────────────────────────────────────────────────────────
    var MODAL_ID  = 'arise-access-helper';
    var STYLE_ID  = 'arise-access-helper-styles';

    // ── Inject CSS (once) ─────────────────────────────────────────────────────
    function injectStyles() {
        if (document.getElementById(STYLE_ID)) return;
        var css = [
            '#' + MODAL_ID + '-overlay {',
            '  position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;',
            '  background:rgba(5,46,22,.97);',
            '  display:flex;align-items:center;justify-content:center;',
            '  padding:24px;',
            '  animation:ariseHelperFadeIn .25s ease;',
            '}',
            '@keyframes ariseHelperFadeIn {',
            '  from{opacity:0;transform:scale(.97)}',
            '  to  {opacity:1;transform:scale(1)}',
            '}',
            '#' + MODAL_ID + '-box {',
            '  background:#fff;',
            '  border-radius:24px;',
            '  padding:40px 48px 36px;',
            '  max-width:860px;',
            '  width:100%;',
            '  text-align:center;',
            '  box-shadow:0 32px 80px rgba(0,0,0,.4);',
            '  position:relative;',
            '}',
            '#' + MODAL_ID + '-close {',
            '  position:absolute;top:16px;right:18px;',
            '  background:rgba(0,0,0,.07);border:none;',
            '  width:36px;height:36px;border-radius:50%;',
            '  font-size:1.1rem;cursor:pointer;',
            '  display:flex;align-items:center;justify-content:center;',
            '  color:#374151;transition:background .15s;',
            '}',
            '#' + MODAL_ID + '-close:hover{background:rgba(0,0,0,.15);}',
            '#' + MODAL_ID + '-logo {',
            '  font-size:.72rem;font-weight:800;letter-spacing:2px;',
            '  text-transform:uppercase;color:#9ca3af;margin-bottom:18px;',
            '}',
            '#' + MODAL_ID + '-prompt {',
            '  font-size:1rem;font-weight:600;color:#6b7280;margin-bottom:10px;',
            '}',
            '#' + MODAL_ID + '-url {',
            '  font-size:clamp(1.6rem,5vw,3rem);',
            '  font-weight:900;',
            '  color:#064e3b;',
            '  letter-spacing:-.5px;',
            '  line-height:1.15;',
            '  word-break:break-all;',
            '  background:#f0fdf4;',
            '  border:3px solid #86efac;',
            '  border-radius:16px;',
            '  padding:22px 28px;',
            '  margin:0 0 24px;',
            '  cursor:pointer;',
            '  transition:background .15s;',
            '  user-select:all;',
            '}',
            '#' + MODAL_ID + '-url:hover{background:#dcfce7;}',
            '#' + MODAL_ID + '-ip-section {',
            '  margin-bottom:24px;',
            '}',
            '#' + MODAL_ID + '-ip-label {',
            '  font-size:.78rem;font-weight:700;color:#9ca3af;',
            '  text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;',
            '}',
            '#' + MODAL_ID + '-ip-chips {',
            '  display:flex;flex-wrap:wrap;gap:8px;justify-content:center;',
            '}',
            '.' + MODAL_ID + '-chip {',
            '  background:#eff6ff;border:1px solid #bfdbfe;',
            '  color:#1e40af;border-radius:10px;',
            '  padding:7px 16px;font-size:.95rem;font-weight:700;',
            '  font-family:monospace;letter-spacing:.5px;',
            '}',
            '#' + MODAL_ID + '-instructions {',
            '  background:#fffbeb;border:1px solid #fde68a;border-radius:12px;',
            '  padding:14px 18px;margin-bottom:20px;',
            '  font-size:.88rem;color:#92400e;',
            '  display:flex;align-items:flex-start;gap:10px;text-align:left;',
            '}',
            '#' + MODAL_ID + '-copy-btn {',
            '  background:linear-gradient(135deg,#0ea271,#059669);',
            '  color:#fff;border:none;border-radius:12px;',
            '  padding:13px 28px;font-size:.95rem;font-weight:700;',
            '  cursor:pointer;font-family:inherit;',
            '  transition:transform .15s,box-shadow .15s;',
            '  margin-right:8px;',
            '}',
            '#' + MODAL_ID + '-copy-btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(14,162,113,.3);}',
            '#' + MODAL_ID + '-dismiss-btn {',
            '  background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;',
            '  border-radius:12px;padding:13px 24px;font-size:.9rem;',
            '  font-weight:600;cursor:pointer;font-family:inherit;',
            '}',
            '#' + MODAL_ID + '-dismiss-btn:hover{background:#e5e7eb;}',
            '#' + MODAL_ID + '-copied {',
            '  display:none;color:#166534;font-size:.82rem;',
            '  font-weight:600;margin-top:8px;',
            '}',
        ].join('\n');

        var el = document.createElement('style');
        el.id  = STYLE_ID;
        el.textContent = css;
        document.head.appendChild(el);
    }

    // ── Copy to clipboard ─────────────────────────────────────────────────────
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        // Fallback
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity  = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        return Promise.resolve();
    }

    // ── Detect local IPs via WebRTC (best-effort, offline safe) ──────────────
    function detectLocalIPs(callback) {
        var ips = [];
        try {
            var RTCPeerConnection =
                global.RTCPeerConnection ||
                global.webkitRTCPeerConnection ||
                global.mozRTCPeerConnection;

            if (!RTCPeerConnection) { callback(ips); return; }

            var pc = new RTCPeerConnection({ iceServers: [] });
            pc.createDataChannel('');
            pc.createOffer().then(function (offer) {
                return pc.setLocalDescription(offer);
            }).catch(function () { callback(ips); });

            var seen = {};
            pc.onicecandidate = function (e) {
                if (!e || !e.candidate || !e.candidate.candidate) {
                    if (!seen._done) { seen._done = true; pc.close(); callback(ips); }
                    return;
                }
                var m = e.candidate.candidate.match(
                    /(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/g
                );
                if (m) {
                    m.forEach(function (ip) {
                        if (!seen[ip] && ip !== '0.0.0.0') {
                            seen[ip] = true;
                            // Only include private/LAN IPs
                            if (/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|127\.)/.test(ip)) {
                                ips.push(ip);
                            }
                        }
                    });
                }
            };
            // Timeout after 2 s
            setTimeout(function () {
                if (!seen._done) { seen._done = true; try { pc.close(); } catch (e) {} callback(ips); }
            }, 2000);
        } catch (err) {
            callback(ips);
        }
    }

    // ── Build alternative URLs from detected IPs ──────────────────────────────
    function buildIpUrls(originalUrl, ips) {
        try {
            var parsed = new URL(originalUrl);
            return ips.map(function (ip) {
                return parsed.protocol + '//' + ip +
                    (parsed.port && parsed.port !== '80' && parsed.port !== '443'
                        ? ':' + parsed.port : '') +
                    parsed.pathname + parsed.search;
            });
        } catch (e) {
            return ips.map(function (ip) { return 'http://' + ip + '/arise/'; });
        }
    }

    // ── Main function ─────────────────────────────────────────────────────────
    function showAccessHelper(url) {
        injectStyles();

        // Remove existing if present
        var existing = document.getElementById(MODAL_ID + '-overlay');
        if (existing) { existing.parentNode.removeChild(existing); }

        // Display URL (use provided URL, or fall back to current page origin)
        var displayUrl = url || (global.location.origin + '/arise/');

        // ── Build overlay ──────────────────────────────────────────────────────
        var overlay = document.createElement('div');
        overlay.id  = MODAL_ID + '-overlay';

        overlay.innerHTML = [
            '<div id="' + MODAL_ID + '-box">',

              '<button id="' + MODAL_ID + '-close" title="Close" aria-label="Close">&times;</button>',

              '<div id="' + MODAL_ID + '-logo">&#127775; ARISE Health Education Platform</div>',

              '<div id="' + MODAL_ID + '-prompt">',
                'Share this URL with learners &mdash; open on any device connected to this WiFi',
              '</div>',

              '<div id="' + MODAL_ID + '-url" title="Click to copy" tabindex="0">',
                escapeHtml(displayUrl),
              '</div>',

              '<div id="' + MODAL_ID + '-ip-section">',
                '<div id="' + MODAL_ID + '-ip-label">&#128246; Alternative access via IP address</div>',
                '<div id="' + MODAL_ID + '-ip-chips">',
                  '<span class="' + MODAL_ID + '-chip" style="color:#9ca3af;">Detecting...</span>',
                '</div>',
              '</div>',

              '<div id="' + MODAL_ID + '-instructions">',
                '<span style="font-size:1.1rem;flex-shrink:0;">&#128241;</span>',
                '<div>',
                  '<strong>How to connect:</strong> Learners open a browser on their device and type ',
                  'the URL above &mdash; or use one of the IP addresses shown. ',
                  'All devices must be on the <strong>same WiFi network</strong>.',
                '</div>',
              '</div>',

              '<div>',
                '<button id="' + MODAL_ID + '-copy-btn">&#128203; Copy URL</button>',
                '<button id="' + MODAL_ID + '-dismiss-btn">&#10005; Close</button>',
              '</div>',
              '<div id="' + MODAL_ID + '-copied">&#10003; Copied to clipboard!</div>',

            '</div>',
        ].join('');

        document.body.appendChild(overlay);

        // ── Wire up close handlers ─────────────────────────────────────────────
        function close() {
            var o = document.getElementById(MODAL_ID + '-overlay');
            if (o) o.parentNode.removeChild(o);
        }

        document.getElementById(MODAL_ID + '-close').addEventListener('click', close);
        document.getElementById(MODAL_ID + '-dismiss-btn').addEventListener('click', close);

        // Close on overlay click (outside box)
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });

        // ESC key
        function onKey(e) {
            if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
        }
        document.addEventListener('keydown', onKey);

        // ── Copy URL ───────────────────────────────────────────────────────────
        function doCopy() {
            copyText(displayUrl).then(function () {
                var el = document.getElementById(MODAL_ID + '-copied');
                var btn = document.getElementById(MODAL_ID + '-copy-btn');
                if (el)  { el.style.display  = 'block'; setTimeout(function(){ el.style.display = 'none'; }, 2500); }
                if (btn) { btn.textContent = '&#10003; Copied!'; setTimeout(function(){ btn.innerHTML = '&#128203; Copy URL'; }, 2500); }
            });
        }

        document.getElementById(MODAL_ID + '-copy-btn').addEventListener('click', doCopy);
        document.getElementById(MODAL_ID + '-url').addEventListener('click',      doCopy);
        document.getElementById(MODAL_ID + '-url').addEventListener('keydown',    function(e){ if (e.key==='Enter'||e.key===' ') doCopy(); });

        // ── Detect IPs async ───────────────────────────────────────────────────
        detectLocalIPs(function (ips) {
            var chipsEl = document.getElementById(MODAL_ID + '-ip-chips');
            if (!chipsEl) return;

            if (!ips || ips.length === 0) {
                chipsEl.innerHTML =
                    '<span class="' + MODAL_ID + '-chip" style="background:#f9fafb;color:#9ca3af;border-color:#e5e7eb;">' +
                    '&#9432; Type the URL above directly' +
                    '</span>';
                return;
            }

            var ipUrls = buildIpUrls(displayUrl, ips);
            var html = '';
            ips.forEach(function (ip, i) {
                html += '<span class="' + MODAL_ID + '-chip" title="' + escapeHtml(ipUrls[i]) + '" ' +
                        'style="cursor:pointer;" onclick="(function(){' +
                        'var ta=document.createElement(\'textarea\');' +
                        'ta.value=\'' + escapeJs(ipUrls[i]) + '\';' +
                        'document.body.appendChild(ta);ta.select();' +
                        'try{document.execCommand(\'copy\');}catch(e){}' +
                        'document.body.removeChild(ta);' +
                        'this.textContent=\'✓ Copied\';' +
                        'var self=this;setTimeout(function(){self.textContent=\'' + escapeJs(ip) + '\';},2000);' +
                        '}).call(this)">' +
                        escapeHtml(ip) +
                        '</span>';
            });
            chipsEl.innerHTML = html;
        });
    }

    // ── Small escape helpers ──────────────────────────────────────────────────
    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeJs(s) {
        return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }

    // ── Export ────────────────────────────────────────────────────────────────
    global.showAccessHelper = showAccessHelper;

}(typeof window !== 'undefined' ? window : this));
