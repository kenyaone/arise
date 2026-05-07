const CACHE_VERSION = 'arise-datapost-v2';
const CACHE_FILES = [
  '/arise/?p=datapost',
  '/arise/pwa_datapost',
  '/arise/css/style.css',
  '/arise/js/app.js',
  '/arise/pwa_manifest.json'
];

// Install service worker
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => {
      return cache.addAll(CACHE_FILES).catch(() => {
        // Add files individually if batch fails
        CACHE_FILES.forEach(file => {
          cache.add(file).catch(() => {});
        });
      });
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
          return response || caches.match('/arise/?p=datapost').then((fallback) => {
            return fallback || new Response('Offline - DataPost cached page not available', {
              status: 503,
              statusText: 'Service Unavailable'
            });
          });
        });
      })
    );
  }
});
