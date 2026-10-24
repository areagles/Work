// portal/sw.js
const CACHE_NAME = 'arab-eagles-v4';
const urlsToCache = [
  'login.html',
  'register.html',
  'dashboard.html',
  'orders.html',
  'quotes.html',
  'invoices.html',
  'profile.html',
  'new_order.html',
  'css/admin-identity.css',
  'css/style.css',
  'js/core.js',
  'js/layout.js',
  'assets/images/logo.webp',
  'assets/images/icon.png'
];

self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(urlsToCache))
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') {
    return;
  }

  const reqUrl = new URL(event.request.url);
  const isSameOrigin = reqUrl.origin === self.location.origin;

  if (!isSameOrigin) {
    return;
  }

  const path = reqUrl.pathname || '';
  if (path.includes('/client_portal/api/')) {
    return;
  }

  if (event.request.headers.get('cache-control') === 'no-store') {
    return;
  }

  event.respondWith(
    fetch(event.request)
      .then(response => {
        if (!response || !response.ok || response.type !== 'basic') {
          return response;
        }
        const responseUrl = new URL(response.url || event.request.url);
        if (responseUrl.pathname.includes('/client_portal/api/')) {
          return response;
        }
        const responseCacheControl = String(response.headers.get('Cache-Control') || '').toLowerCase();
        if (responseCacheControl.includes('no-store') || responseCacheControl.includes('private')) {
          return response;
        }
        const clone = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});
