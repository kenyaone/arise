/**
 * ARISE Offline-First Data Sync Manager
 * Stores pending syncs in IndexedDB, detects internet, auto-sends when online
 * Uses Background Sync API for reliability, falls back to 'online' event listener
 */

const DB_NAME = 'arise-db';
const DB_VERSION = 1;
const CLOUD_URL = 'https://ariseci.org/arise-sync.php';
const PING_URL = 'https://ariseci.org/';
const SYNC_TAG = 'arise-cloud-sync';

// ═══════════════════════════════════════════════════════════════════════════
// ─ IndexedDB Helpers ─────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════

function promisify(req) {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function openDB() {
  const req = indexedDB.open(DB_NAME, DB_VERSION);

  req.onupgradeneeded = (e) => {
    const db = e.target.result;
    const oldVersion = e.oldVersion;

    if (oldVersion < 1) {
      // pending_syncs store
      const pendingStore = db.createObjectStore('pending_syncs', {
        keyPath: 'id',
        autoIncrement: true
      });
      pendingStore.createIndex('by_status', 'status', { unique: false });
      pendingStore.createIndex('by_queued_at', 'queued_at', { unique: false });

      // sync_history store
      db.createObjectStore('sync_history', {
        keyPath: 'id',
        autoIncrement: true
      });

      // app_config store
      db.createObjectStore('app_config', { keyPath: 'key' });
    }
  };

  const db = await promisify(req);

  // Reset any stuck 'sending' records on open
  await resetStuckSending(db);

  return db;
}

async function resetStuckSending(db) {
  try {
    const tx = db.transaction('pending_syncs', 'readwrite');
    const store = tx.objectStore('pending_syncs');
    const index = store.index('by_status');
    const sending = await promisify(index.getAll('sending'));

    for (const record of sending) {
      record.status = 'pending';
      await promisify(store.put(record));
    }
  } catch (e) {
    console.warn('Error resetting stuck syncs:', e);
  }
}

async function getAllByStatus(db, status) {
  const tx = db.transaction('pending_syncs', 'readonly');
  const index = tx.objectStore('pending_syncs').index('by_status');
  return promisify(index.getAll(status));
}

async function putRecord(db, storeName, record) {
  const tx = db.transaction(storeName, 'readwrite');
  const store = tx.objectStore(storeName);
  return promisify(store.put(record));
}

async function getConfigValue(db, key) {
  const tx = db.transaction('app_config', 'readonly');
  const req = tx.objectStore('app_config').get(key);
  const result = await promisify(req);
  return result ? result.value : null;
}

async function setConfigValue(db, key, value) {
  const tx = db.transaction('app_config', 'readwrite');
  return promisify(tx.objectStore('app_config').put({ key, value }));
}

// ═══════════════════════════════════════════════════════════════════════════
// ─ Device Identity ──────────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════

async function getOrCreateDeviceId(db) {
  let deviceId = await getConfigValue(db, 'device_id');

  if (!deviceId) {
    // Fall back to localStorage
    deviceId = localStorage.getItem('arise_device_id');

    if (!deviceId) {
      // Generate new ID
      const uuid = crypto.randomUUID ? crypto.randomUUID() : 'arise-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
      deviceId = 'arise-' + uuid.substr(0, 20);
    }

    // Store in both IndexedDB and localStorage
    await setConfigValue(db, 'device_id', deviceId);
    localStorage.setItem('arise_device_id', deviceId);
  }

  return deviceId;
}

// ═══════════════════════════════════════════════════════════════════════════
// ─ Internet Detection ───────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════

async function hasInternet() {
  if (!navigator.onLine) return false;

  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 3000);

    const response = await fetch(PING_URL, {
      method: 'HEAD',
      mode: 'no-cors',
      signal: controller.signal,
      cache: 'no-store'
    });

    clearTimeout(timeout);
    return true;
  } catch (e) {
    return false;
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// ─ Queue Operations ─────────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════

async function queueSyncPayload(summary, schools) {
  const db = await openDB();
  const deviceId = await getOrCreateDeviceId(db);

  const record = {
    queued_at: new Date().toISOString(),
    device_id: deviceId,
    school_id: window.ARISE_SCHOOL_ID || deviceId,
    school_name: window.ARISE_SCHOOL_NAME || 'Unknown',
    summary: summary,
    schools: schools || [],
    status: 'pending',
    attempts: 0,
    last_attempt: null,
    sent_at: null,
    error: null
  };

  const tx = db.transaction('pending_syncs', 'readwrite');
  const id = await promisify(tx.objectStore('pending_syncs').add(record));

  await updateQueueSize(db);

  return id;
}

async function countByStatus(db, status) {
  const pending = await getAllByStatus(db, status);
  return pending.length;
}

async function updateQueueSize(db) {
  const pending = await countByStatus(db, 'pending');
  const failed = await countByStatus(db, 'failed');
  await setConfigValue(db, 'queue_size', pending + failed);
}

async function getQueueStats() {
  try {
    const db = await openDB();
    const pending = await countByStatus(db, 'pending');
    const failed = await countByStatus(db, 'failed');
    const lastSent = await getConfigValue(db, 'last_cloud_sync');

    return { pending, failed, lastSent };
  } catch (e) {
    console.error('Error getting queue stats:', e);
    return { pending: 0, failed: 0, lastSent: null };
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// ─ Cloud Send Operations ────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════

async function sendToCloud(db, record) {
  record.status = 'sending';
  record.attempts += 1;
  record.last_attempt = new Date().toISOString();
  await putRecord(db, 'pending_syncs', record);

  const payload = {
    api_key: 'ARISE_CLOUD_SYNC_2026_KEY',
    device_id: record.device_id,
    synced_at: record.queued_at,
    schools: record.schools
  };

  try {
    const response = await fetch(CLOUD_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const data = await response.json();

    if (response.ok && data.status === 'ok') {
      record.status = 'sent';
      record.sent_at = new Date().toISOString();
      record.error = null;
      await putRecord(db, 'pending_syncs', record);
      await appendHistory(db, record, true, data.message || 'Sent to cloud');
      await setConfigValue(db, 'last_cloud_sync', new Date().toISOString());
      return true;
    } else {
      throw new Error(data.message || `HTTP ${response.status}`);
    }
  } catch (err) {
    record.error = err.message;
    record.status = record.attempts >= 3 ? 'failed' : 'pending';
    await putRecord(db, 'pending_syncs', record);

    if (record.status === 'failed') {
      await appendHistory(db, record, false, `Failed after 3 attempts: ${err.message}`);
    }

    return false;
  }
}

async function processQueue() {
  if (!(await hasInternet())) {
    console.log('ARISE Sync: No internet, queue remains pending');
    return;
  }

  try {
    const db = await openDB();
    const pending = await getAllByStatus(db, 'pending');

    for (const record of pending) {
      await sendToCloud(db, record);
    }

    await updateQueueSize(db);
  } catch (e) {
    console.error('Error processing queue:', e);
  }
}

async function retryFailed() {
  try {
    const db = await openDB();
    const failed = await getAllByStatus(db, 'failed');

    for (const record of failed) {
      record.status = 'pending';
      record.attempts = 0;
      record.error = null;
      await putRecord(db, 'pending_syncs', record);
    }

    await updateQueueSize(db);
    await processQueue();
  } catch (e) {
    console.error('Error retrying failed syncs:', e);
  }
}

async function appendHistory(db, record, cloudOk, cloudMsg) {
  try {
    const historyRecord = {
      synced_at: record.queued_at,
      school_id: record.school_id,
      summary: record.summary,
      cloud_ok: cloudOk,
      cloud_msg: cloudMsg
    };

    const tx = db.transaction('sync_history', 'readwrite');
    await promisify(tx.objectStore('sync_history').add(historyRecord));
  } catch (e) {
    console.warn('Error appending to history:', e);
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// ─ Background Sync & Online Event ───────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════

async function registerBackgroundSync() {
  try {
    if (!('serviceWorker' in navigator) || !('SyncManager' in window)) {
      console.log('ARISE Sync: Background Sync not supported, using online event');
      return;
    }

    const reg = await navigator.serviceWorker.ready;
    await reg.sync.register(SYNC_TAG);
    console.log('ARISE Sync: Background Sync registered for tag:', SYNC_TAG);
  } catch (e) {
    console.warn('ARISE Sync: Background Sync registration failed:', e);
  }
}

window.addEventListener('online', async () => {
  console.log('ARISE Sync: Online event fired, waiting for stability...');
  await new Promise(r => setTimeout(r, 1500));

  if (await hasInternet()) {
    console.log('ARISE Sync: Internet confirmed, processing queue');
    await processQueue();

    if (window.AriseSyncManager && window.AriseSyncManager.refreshQueueUI) {
      window.AriseSyncManager.refreshQueueUI();
    }
  } else {
    console.log('ARISE Sync: Online event but no real internet yet');
  }
});

// ═══════════════════════════════════════════════════════════════════════════
// ─ Public API ───────────────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════

async function queueAndSend(summary, schools) {
  console.log('ARISE Sync: Queueing snapshot...');

  // Always queue first (IndexedDB write always works)
  const id = await queueSyncPayload(summary, schools);
  console.log('ARISE Sync: Queued snapshot #' + id);

  // Try cloud immediately if internet available
  if (await hasInternet()) {
    console.log('ARISE Sync: Internet available, sending to cloud now');
    await processQueue();
  } else {
    console.log('ARISE Sync: Offline mode - will retry when internet returns');
    await registerBackgroundSync();
  }

  return id;
}

window.AriseSyncManager = {
  queueAndSend,
  processQueue,
  processNow: () => processQueue(),
  retryFailed,
  getQueueStats,
  refreshQueueUI: null // Set by pwa_datapost.php after DOM ready
};

console.log('ARISE Sync: Manager initialized', window.AriseSyncManager);
