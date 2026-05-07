<?php
/**
 * DataPost Sync PWA
 * Offline-first mobile app that syncs data when internet available
 * Uses existing DataPost configuration
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0ea271">
    <meta name="description" content="ARISE DataPost Sync - Offline data collection and cloud sync">
    <title>DataPost Sync</title>
    <link rel="manifest" href="/arise/pwa_manifest.json">
    <link rel="icon" type="image/png" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 180 180'><rect fill='%230ea271' width='180' height='180'/><text x='50%' y='50%' font-size='90' fill='white' text-anchor='middle' dominant-baseline='middle' font-weight='bold'>D</text></svg>">
    <link rel="apple-touch-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 180 180'><rect fill='%230ea271' width='180' height='180'/><text x='50%' y='50%' font-size='90' fill='white' text-anchor='middle' dominant-baseline='middle' font-weight='bold'>D</text></svg>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f1f5f2;
            color: #111;
            padding-bottom: 80px;
        }
        .header {
            background: linear-gradient(135deg, #0ea271, #059669);
            color: white;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
        }
        .header h1 { font-size: 1.4rem; margin-bottom: 4px; }
        .header p { font-size: .9rem; opacity: .9; }
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: .8rem;
            font-weight: 600;
            margin-top: 8px;
        }
        .status-online { background: #10b981; color: white; }
        .status-offline { background: #ef4444; color: white; }
        .status-syncing { background: #f59e0b; color: white; }
        .status-synced { background: #3b82f6; color: white; }

        .container { max-width: 500px; margin: 0 auto; padding: 16px; }

        .card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        .card h3 { font-size: 1rem; margin-bottom: 12px; color: #111; }

        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .stat-row:last-child { border-bottom: none; }
        .stat-label { font-size: .9rem; color: #6b7280; }
        .stat-value { font-weight: 700; color: #111; font-size: 1.1rem; }

        .button-group {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }
        .btn {
            flex: 1;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: .9rem;
            cursor: pointer;
            transition: .2s;
            font-family: inherit;
        }
        .btn-primary {
            background: #0ea271;
            color: white;
        }
        .btn-primary:active { opacity: .9; transform: scale(.98); }
        .btn-primary:disabled { opacity: .5; cursor: not-allowed; }

        .btn-secondary {
            background: #e5e7eb;
            color: #111;
        }
        .btn-secondary:active { opacity: .9; }

        .info-box {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 12px;
            font-size: .85rem;
            color: #166534;
        }

        .sync-log {
            background: #f9fafb;
            border-radius: 8px;
            padding: 12px;
            font-size: .8rem;
            font-family: monospace;
            color: #6b7280;
            max-height: 150px;
            overflow-y: auto;
            margin-top: 8px;
        }

        .sync-log .success { color: #10b981; }
        .sync-log .error { color: #ef4444; }
        .sync-log .info { color: #3b82f6; }

        .timer { font-size: .75rem; color: #9ca3af; margin-top: 6px; }

        .loading { animation: spin 1s linear infinite; display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 0;
            padding: 8px;
        }
        .nav-item {
            flex: 1;
            padding: 8px;
            text-align: center;
            text-decoration: none;
            color: #6b7280;
            font-size: .75rem;
            border-radius: 8px;
            transition: .2s;
        }
        .nav-item.active { background: #f0fdf4; color: #0ea271; font-weight: 600; }
        .nav-item:active { opacity: .7; }

        @media (display-mode: standalone) {
            body { padding-bottom: 0; }
            .bottom-nav { display: none; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 DataPost Sync</h1>
        <p>Offline data collection & cloud sync</p>
        <div class="status-badge" id="statusBadge">Loading...</div>
    </div>

    <div class="container">
        <!-- STATUS SECTION -->
        <div class="card" id="statusCard">
            <h3>📡 Connection Status</h3>
            <div class="stat-row">
                <span class="stat-label">Network</span>
                <span class="stat-value" id="networkStatus">Checking...</span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Last Sync</span>
                <span class="stat-value" id="lastSync">Never</span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Stored Data</span>
                <span class="stat-value" id="storedSize">Loading...</span>
            </div>
            <div class="button-group">
                <button class="btn btn-primary" id="syncBtn" onclick="manualSync()">🔄 Sync Now</button>
                <button class="btn btn-secondary" id="settingsBtn" onclick="toggleSettings()">⚙️ Settings</button>
            </div>
        </div>

        <!-- DATA SUMMARY -->
        <div class="card" id="dataCard" style="display:none;">
            <h3>📦 Data Summary</h3>
            <div class="stat-row">
                <span class="stat-label">Students</span>
                <span class="stat-value" id="studentCount">-</span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Modules</span>
                <span class="stat-value" id="moduleCount">-</span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Test Attempts</span>
                <span class="stat-value" id="testCount">-</span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Certificates</span>
                <span class="stat-value" id="certCount">-</span>
            </div>
        </div>

        <!-- SETTINGS -->
        <div class="card" id="settingsCard" style="display:none;">
            <h3>⚙️ Settings</h3>
            <div class="stat-row">
                <span class="stat-label">Server URL</span>
            </div>
            <input type="text" id="serverUrl" placeholder="http://192.168.0.10/arise" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;font-size:.9rem;margin-bottom:12px;">

            <div class="stat-row">
                <span class="stat-label">Auto-sync on Internet</span>
            </div>
            <input type="checkbox" id="autoSync" checked style="width:18px;height:18px;cursor:pointer;margin-bottom:12px;">

            <div class="stat-row">
                <span class="stat-label">Sync Interval</span>
            </div>
            <select id="syncInterval" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:12px;">
                <option value="5">Every 5 min</option>
                <option value="15" selected>Every 15 min</option>
                <option value="30">Every 30 min</option>
                <option value="60">Every hour</option>
            </select>

            <div class="button-group">
                <button class="btn btn-primary" onclick="saveSettings()">💾 Save</button>
                <button class="btn btn-secondary" onclick="clearData()">🗑️ Clear Data</button>
            </div>
        </div>

        <!-- SYNC LOG -->
        <div class="card">
            <h3>📋 Sync Log</h3>
            <div class="sync-log" id="syncLog">
                <div class="info">Initializing...</div>
            </div>
            <div class="timer">Last checked: <span id="lastChecked">never</span></div>
        </div>

        <div class="info-box">
            💡 <strong>Tip:</strong> Open on WiFi to sync data from ARISE server. When you go online, data automatically uploads to cloud.
        </div>
    </div>

    <div class="bottom-nav">
        <a href="#" class="nav-item active" onclick="showTab('status')">📡 Status</a>
        <a href="#" class="nav-item" onclick="showTab('data')">📦 Data</a>
        <a href="#" class="nav-item" onclick="showTab('logs')">📋 Logs</a>
    </div>

    <script>
        const DB_NAME = 'AriseDataPost';
        const DB_VERSION = 1;
        let db;
        let syncInterval;

        // Initialize IndexedDB
        async function initDB() {
            return new Promise((resolve, reject) => {
                const req = indexedDB.open(DB_NAME, DB_VERSION);
                req.onerror = () => reject(req.error);
                req.onsuccess = () => {
                    db = req.result;
                    resolve(db);
                };
                req.onupgradeneeded = (e) => {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains('data')) {
                        db.createObjectStore('data');
                    }
                    if (!db.objectStoreNames.contains('metadata')) {
                        db.createObjectStore('metadata');
                    }
                };
            });
        }

        // Load settings
        function loadSettings() {
            const serverUrl = localStorage.getItem('serverUrl') || 'http://192.168.0.10/arise';
            const autoSync = localStorage.getItem('autoSync') !== 'false';
            const syncInterval = localStorage.getItem('syncInterval') || '15';

            document.getElementById('serverUrl').value = serverUrl;
            document.getElementById('autoSync').checked = autoSync;
            document.getElementById('syncInterval').value = syncInterval;
        }

        // Save settings
        function saveSettings() {
            const serverUrl = document.getElementById('serverUrl').value;
            const autoSync = document.getElementById('autoSync').checked;
            const syncInterval = document.getElementById('syncInterval').value;

            localStorage.setItem('serverUrl', serverUrl);
            localStorage.setItem('autoSync', autoSync);
            localStorage.setItem('syncInterval', syncInterval);

            log('✅ Settings saved', 'success');
            setupAutoSync();
        }

        // Check connection status
        function updateStatus() {
            const online = navigator.onLine;
            const networkEl = document.getElementById('networkStatus');
            const badgeEl = document.getElementById('statusBadge');

            if (online) {
                networkEl.textContent = '🟢 Online';
                badgeEl.textContent = 'Connected to Internet';
                badgeEl.className = 'status-badge status-online';
            } else {
                networkEl.textContent = '🔴 Offline';
                badgeEl.textContent = 'Offline (cached data)';
                badgeEl.className = 'status-badge status-offline';
            }
        }

        // Fetch data from server
        async function fetchData() {
            const serverUrl = localStorage.getItem('serverUrl') || 'http://192.168.0.10/arise';
            try {
                log('⬇️ Fetching data from server...', 'info');
                const response = await fetch(`${serverUrl}/?p=datapost`);
                if (!response.ok) throw new Error('Server error');
                const data = await response.text();

                // Store in IndexedDB
                const tx = db.transaction(['data'], 'readwrite');
                await new Promise((resolve, reject) => {
                    const req = tx.objectStore('data').put(data, 'latest');
                    req.onerror = () => reject(req.error);
                    req.onsuccess = () => resolve();
                });

                log('✅ Data fetched & stored', 'success');
                updateDataSummary(data);
                return true;
            } catch (e) {
                log(`❌ Fetch failed: ${e.message}`, 'error');
                return false;
            }
        }

        // Manual sync
        async function manualSync() {
            const btn = document.getElementById('syncBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="loading">⏳</span> Syncing...';

            const success = await fetchData();

            if (success && navigator.onLine) {
                await uploadData();
            }

            btn.disabled = false;
            btn.innerHTML = '🔄 Sync Now';
            updateLastSync();
        }

        // Upload data when online
        async function uploadData() {
            try {
                const tx = db.transaction(['data'], 'readonly');
                const data = await new Promise((resolve, reject) => {
                    const req = tx.objectStore('data').get('latest');
                    req.onerror = () => reject(req.error);
                    req.onsuccess = () => resolve(req.result);
                });

                if (!data) return;

                log('📤 Uploading to cloud...', 'info');
                const response = await fetch('/arise/?p=datapost_upload', {
                    method: 'POST',
                    headers: { 'Content-Type': 'text/csv' },
                    body: data
                });

                if (response.ok) {
                    log('✅ Data uploaded to cloud', 'success');
                } else {
                    log('⚠️ Upload status: ' + response.status, 'error');
                }
            } catch (e) {
                log(`⚠️ Upload error: ${e.message}`, 'error');
            }
        }

        // Update last sync time
        async function updateLastSync() {
            const tx = db.transaction(['metadata'], 'readwrite');
            await new Promise((resolve) => {
                tx.objectStore('metadata').put(new Date().toISOString(), 'lastSync');
                tx.oncomplete = resolve;
            });

            const tx2 = db.transaction(['metadata'], 'readonly');
            const lastSync = await new Promise((resolve) => {
                const req = tx2.objectStore('metadata').get('lastSync');
                req.onsuccess = () => resolve(req.result);
            });

            if (lastSync) {
                document.getElementById('lastSync').textContent = new Date(lastSync).toLocaleString();
            }
        }

        // Update data summary
        function updateDataSummary(csvData) {
            const lines = csvData.split('\n').length;
            document.getElementById('studentCount').textContent = Math.max(0, lines - 5) || '-';
            document.getElementById('dataCard').style.display = 'block';
        }

        // Logging
        function log(msg, type = 'info') {
            const logEl = document.getElementById('syncLog');
            const logEntry = document.createElement('div');
            logEntry.className = type;
            logEntry.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
            logEl.insertBefore(logEntry, logEl.firstChild);

            if (logEl.children.length > 10) {
                logEl.removeChild(logEl.lastChild);
            }

            document.getElementById('lastChecked').textContent = new Date().toLocaleTimeString();
        }

        // Toggle settings
        function toggleSettings() {
            const card = document.getElementById('settingsCard');
            card.style.display = card.style.display === 'none' ? 'block' : 'none';
        }

        // Clear data
        function clearData() {
            if (confirm('Clear all stored data?')) {
                indexedDB.deleteDatabase(DB_NAME);
                localStorage.clear();
                location.reload();
            }
        }

        // Setup auto-sync
        function setupAutoSync() {
            clearInterval(syncInterval);
            const autoSync = localStorage.getItem('autoSync') !== 'false';
            const interval = parseInt(localStorage.getItem('syncInterval') || '15') * 60 * 1000;

            if (autoSync && navigator.onLine) {
                syncInterval = setInterval(manualSync, interval);
                log('✅ Auto-sync enabled', 'success');
            }
        }

        // Show tab
        function showTab(tab) {
            document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
            event.target.classList.add('active');
        }

        // Online/offline events
        window.addEventListener('online', () => {
            log('🟢 Internet connected', 'success');
            updateStatus();
            uploadData();
            setupAutoSync();
        });
        window.addEventListener('offline', () => {
            log('🔴 Internet disconnected', 'error');
            updateStatus();
        });

        // Initialize
        async function init() {
            try {
                await initDB();
                loadSettings();
                updateStatus();
                await updateLastSync();

                // Try to fetch on load if online
                if (navigator.onLine) {
                    await manualSync();
                }

                setupAutoSync();
                log('✅ DataPost Sync ready', 'success');

                // Register service worker for offline support
                if ('serviceWorker' in navigator) {
                    navigator.serviceWorker.register('/arise/sw_pwa.js').catch(() => {});
                }
            } catch (e) {
                log(`Error: ${e.message}`, 'error');
            }
        }

        init();
    </script>
</body>
</html>
