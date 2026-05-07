const CACHE_VERSION = 'arise-pwa-v1';
const CACHE_FILES = [
  '/arise/pwa_datapost',
  '/arise/css/style.css'
];

// Install service worker
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => {
      return cache.addAll(CACHE_FILES).catch(() => {});
    })
  );
  self.skipWaiting();
});

// Activate service worker
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_VERSION) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  self.clients.claim();
});

// Fetch event - network first, cache fallback
self.addEventListener('fetch', (e) => {
  if (e.request.method === 'GET') {
    e.respondWith(
      fetch(e.request).then((response) => {
        if (response.ok) {
          const cache = caches.open(CACHE_VERSION);
          cache.then((c) => c.put(e.request, response.clone()));
        }
        return response;
      }).catch(() => {
        return caches.match(e.request).then((response) => {
          return response || new Response('Offline - page not cached', {
            status: 503,
            statusText: 'Service Unavailable'
          });
        });
      })
    );
  }
});
