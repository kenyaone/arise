// ARISE Service Worker — offline-first caching
const CACHE = 'arise-v2';
const STATIC = [
  '/arise/',
  '/arise/css/style.css',
  '/arise/js/app.js',
  '/arise/?p=modules',
  '/arise/?p=resources',
  '/arise/?p=forum',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(STATIC)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);

  // Never intercept admin, api, or non-GET
  if (e.request.method !== 'GET') return;
  if (url.pathname.startsWith('/arise/admin')) return;
  if (url.searchParams.get('p') === 'api_lesson') return;
  if (url.searchParams.get('p') === 'datapost') return;
  if (url.searchParams.get('api')) return;

  // Network-first for dynamic pages, cache-first for assets
  const isAsset = /\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ico)(\?|$)/.test(url.pathname);

  if (isAsset) {
    e.respondWith(
      caches.match(e.request).then(cached => {
        if (cached) return cached;
        return fetch(e.request).then(res => {
          if (res.ok) {
            const clone = res.clone();
            caches.open(CACHE).then(c => c.put(e.request, clone));
          }
          return res;
        }).catch(() => cached);
      })
    );
  } else {
    e.respondWith(
      fetch(e.request).then(res => {
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, clone));
        }
        return res;
      }).catch(() =>
        caches.match(e.request).then(cached =>
          cached || caches.match('/arise/')
        )
      )
    );
  }
});
