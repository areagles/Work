// service-worker.js - (Royal ERP V23.0 - Smart Push Engine)
const CACHE_NAME = 'royal-erp-v23-core';
const urlsToCache = [
    './',
    'manifest.php',
    'assets/img/icon-192x192.png',
    'assets/img/icon-512x512.png'
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(urlsToCache)));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                    return Promise.resolve();
                })
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // استثناء التحديثات الحية من الكاش لضمان دقة البيانات
    if (url.searchParams.get('live_updates') === '1') return;

    event.respondWith(
        fetch(request).catch(async () => {
            const cached = await caches.match(request);
            if (cached) return cached;
            if (request.mode === 'navigate') {
                return caches.match('./');
            }
            return Response.error();
        })
    );
});

// معالج النقر على الإشعارات (يفتح التطبيق على العملية المحددة)
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const jobId = event.notification.data.job_id;
    const targetUrl = jobId ? `job_details.php?id=${jobId}` : 'dashboard.php';

    event.waitUntil(
        clients.matchAll({type: 'window', includeUncontrolled: true}).then(function(clientList) {
            // إذا كانت الصفحة مفتوحة، قم بالتركيز عليها
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if (client.url.includes('dashboard.php') || client.url.includes('job_details.php')) {
                    return client.focus().then(c => c.navigate(targetUrl));
                }
            }
            // إذا كانت مغلقة، افتح نافذة جديدة
            if (clients.openWindow) return clients.openWindow(targetUrl);
        })
    );
});
