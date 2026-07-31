// ARISE Courier — Service Worker
// Cache-first for the app shell so the PWA opens even when the phone is offline.
// Network-only for pickup (LAN box) and delivery (ariseci.org) so we always
// hit the wire when trying to sync data.

const SHELL_CACHE = 'arise-courier-shell-v1';
const SHELL_URLS  = [
  '/arise/arise-courier.html',
  '/arise/courier_manifest.webmanifest',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) => cache.addAll(SHELL_URLS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((k) => k !== SHELL_CACHE).map((k) => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Never cache pickup or delivery — always hit the network.
  const isPickup   = url.pathname.endsWith('/arise-courier.html') === false
                     && url.searchParams.get('action') === 'courier_bundle';
  const isAck      = url.searchParams.get('action') === 'courier_ack';
  const isDelivery = url.hostname.endsWith('ariseci.org');
  if (isPickup || isAck || isDelivery) {
    event.respondWith(fetch(event.request));
    return;
  }

  // Shell files: cache-first, fall back to network.
  if (event.request.method === 'GET' && SHELL_URLS.some((u) => url.pathname === u || url.pathname.endsWith(u))) {
    event.respondWith(
      caches.match(event.request).then((cached) => cached || fetch(event.request).then((resp) => {
        const clone = resp.clone();
        caches.open(SHELL_CACHE).then((cache) => cache.put(event.request, clone));
        return resp;
      }))
    );
    return;
  }

  // Everything else: network with cache fallback for offline resilience.
  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request))
  );
});

// Background Sync — the PWA registers 'arise-flush' when a pickup lands while
// the phone is offline. Chrome fires this event once connectivity returns.
self.addEventListener('sync', (event) => {
  if (event.tag !== 'arise-flush') return;
  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
    for (const client of clients) {
      client.postMessage({ type: 'flush-pending' });
    }
  })());
});
