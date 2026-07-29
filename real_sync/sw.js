const CACHE_NAME = 'zgxn-pwa-shell-v2';
const SHELL = [
  '/mobile/mine.html',
  '/mobile/workload.html',
  '/mobile/workload-v2.html',
  '/mobile/drill.html',
  '/mobile/learning.html',
  '/manifest.webmanifest',
  '/assets/pwa/icon.svg',
  '/js/mobile-pwa.js',
  '/js/app-auth.js?v=3',
  '/js/api-client.js?v=2'
];

self.addEventListener('install', event => event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting())));
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('zgxn-pwa-shell-') && key !== CACHE_NAME).map(key => caches.delete(key)))).then(() => self.clients.claim())));
self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;
  event.respondWith(fetch(request).then(response => {
    const copy = response.clone();
    caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
    return response;
  }).catch(() => caches.match(request).then(cached => cached || caches.match('/mobile/mine.html'))));
});
self.addEventListener('message', event => { if (event.data === 'SKIP_WAITING') self.skipWaiting(); });
