const APP_VERSION = '20';
const CACHE_PREFIX = 'zgxn-pwa-shell-';
const CACHE_NAME = 'zgxn-pwa-shell-v20';
const OFFLINE_URL = '/mobile/offline.html';
const SHELL = [
  '/mobile/',
  '/mobile/index.html',
  '/mobile/mine.html',
  '/mobile/workload.html',
  '/mobile/workload-v2.html',
  '/mobile/drill.html',
  '/mobile/learning.html',
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/assets/pwa/icon.svg',
  '/js/mobile-pwa.js',
  '/js/mobile-entry.js',
  '/css/mobile-shell.css',
  '/js/app-auth.js?v=20260806-login-final1',
  '/js/api-client.js?v=4',
  '/js/draft-store.js'
];
const APPROVED_PATHS = new Set(SHELL.map(path => new URL(path, self.location.origin).pathname));
const SENSITIVE_PREFIXES = ['/api/', '/admin/', '/uploads/', '/private/', '/files/'];

function isApprovedRequest(request) {
  if (request.method !== 'GET') return false;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return false;
  if (url.pathname.startsWith('/api/')) return false;
  if (SENSITIVE_PREFIXES.some(prefix => url.pathname.startsWith(prefix))) return false;
  return APPROVED_PATHS.has(url.pathname);
}

function cacheResponse(request, response) {
  if (!response || !response.ok || response.type === 'opaque') return Promise.resolve();
  return caches.open(CACHE_NAME).then(cache => cache.put(request, response.clone()));
}

function networkFirstNavigation(request) {
  return fetch(request).then(response => {
    return cacheResponse(request, response).then(() => response);
  }).catch(() => caches.match(request).then(cached => cached || caches.match(OFFLINE_URL)));
}

function cacheFirstAsset(request) {
  return caches.match(request).then(cached => {
    if (cached) return cached;
    return fetch(request).then(response => {
      return cacheResponse(request, response).then(() => response);
    });
  });
}

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(SHELL)));
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys
        .filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
        .map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (!isApprovedRequest(request)) return;
  event.respondWith(request.mode === 'navigate' ? networkFirstNavigation(request) : cacheFirstAsset(request));
});

self.addEventListener('message', event => {
  if (event.data === 'SKIP_WAITING' || (event.data && event.data.type === 'SKIP_WAITING')) {
    self.skipWaiting();
    return;
  }
  if (event.data && event.data.type === 'GET_VERSION' && event.ports && event.ports[0]) {
    event.ports[0].postMessage({ type: 'VERSION', version: APP_VERSION });
  }
});
