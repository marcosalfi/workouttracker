/* Workout Tracker - Service Worker (PWA)
 * NOTE:
 * - Cache static assets for faster loading.
 * - For API calls to auth.php/api.php we use network-first (fallback to cache if needed).
 * - First install requires HTTPS (or localhost).
 */

const CACHE_NAME = 'workout-tracker-v1';
const STATIC_ASSETS = [
  './',
  'index.html',
  'workoutList.html',
  'currentworkout.html',
  'workoutView.html',
  'esecizio.html',
  'timer.html',
  'manifest.json'
];

// External CDN assets used by your pages.
// If the CDN is unreachable offline, cache may still help after first visit.
const CDN_ASSETS = [
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
  'https://code.jquery.com/jquery-3.7.1.min.js',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css'
];

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    await cache.addAll(STATIC_ASSETS);
    // Best effort for CDN resources
    try { await cache.addAll(CDN_ASSETS); } catch (e) { /* ignore */ }
    self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)));
    self.clients.claim();
  })());
});

function isApiRequest(url) {
  return url.pathname.endsWith('/api.php') || url.pathname.endsWith('/auth.php')
      || url.pathname.includes('/api.php') || url.pathname.includes('/auth.php');
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  const isSameOrigin = url.origin === self.location.origin;
  const isKnownCdn = CDN_ASSETS.some(a => req.url.startsWith(a));
  if (!isSameOrigin && !isKnownCdn) return;

  // API: network-first
  if (isSameOrigin && isApiRequest(url)) {
    event.respondWith((async () => {
      try {
        const fresh = await fetch(req);
        const cache = await caches.open(CACHE_NAME);
        cache.put(req, fresh.clone());
        return fresh;
      } catch (e) {
        const cached = await caches.match(req);
        return cached || new Response('', { status: 503, statusText: 'Offline' });
      }
    })());
    return;
  }

  // Static: cache-first
  event.respondWith((async () => {
    const cached = await caches.match(req);
    if (cached) return cached;

    try {
      const fresh = await fetch(req);
      const cache = await caches.open(CACHE_NAME);
      cache.put(req, fresh.clone());
      return fresh;
    } catch (e) {
      if (req.mode === 'navigate') {
        const fallback = await caches.match('index.html');
        return fallback || new Response('Offline', { status: 503 });
      }
      return new Response('', { status: 503, statusText: 'Offline' });
    }
  })());
});
