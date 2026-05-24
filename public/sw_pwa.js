const CACHE_VERSION = 'arise-datapost-v2';

// Install service worker - cache on first load
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => {
      // Try to cache main pages
      Promise.all([
        cache.add('/arise/?p=datapost').catch(() => {}),
        cache.add('/arise/pwa_datapost').catch(() => {}),
        cache.add('/arise/css/style.css').catch(() => {}),
        cache.add('/arise/js/app.js').catch(() => {}),
      ]);
      return cache;
    })
  );
  self.skipWaiting();
});

// Activate - clean old caches
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

// Fetch - CACHE FIRST for DataPost, network-first for others
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  
  // For DataPost pages - use CACHE FIRST strategy
  if (url.pathname === '/arise/' && url.searchParams.get('p') === 'datapost') {
    e.respondWith(
      caches.match(e.request).then((cached) => {
        if (cached) return cached;
        
        return fetch(e.request).then((response) => {
          if (response.ok) {
            caches.open(CACHE_VERSION).then((cache) => {
              cache.put(e.request, response.clone());
            });
          }
          return response;
        }).catch((err) => {
          return caches.match('/arise/?p=datapost') || 
                 new Response('Offline - page not cached', { status: 503 });
        });
      })
    );
    return;
  }
  
  // For other GET requests - network first
  if (e.request.method === 'GET') {
    e.respondWith(
      fetch(e.request).then((response) => {
        if (response.ok) {
          caches.open(CACHE_VERSION).then((cache) => {
            cache.put(e.request, response.clone());
          });
        }
        return response;
      }).catch(() => {
        return caches.match(e.request).catch(() => {
          return new Response('Offline', { status: 503 });
        });
      })
    );
  }
});
