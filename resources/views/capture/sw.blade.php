@verbatim
// Job Capture service worker (§5). Keeps a copy of the app shell so the PWA launches offline;
// the offline UPLOAD queue is IndexedDB in the page, not here. API calls are never cached.
//
// The shell is NETWORK-FIRST: an online launch always fetches the current screen and refreshes the
// cached copy; the cache is only served when the network is unreachable. (A cache-first shell froze
// every phone on whatever screen it first installed — a deploy was invisible until site data was
// cleared by hand.) The cache name is versioned so an updated worker discards the old copy on activate.
const CACHE = 'job-capture-v3';
const SHELL = ['/capture', '/capture/manifest.webmanifest', '/capture-icon.svg'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// Fetch from the network, refresh the cached copy on success; fall back to the cache when offline.
function networkFirst(request, cacheKey) {
  return fetch(request)
    .then((response) => {
      if (response && response.ok) {
        const copy = response.clone();
        caches.open(CACHE).then((cache) => cache.put(cacheKey, copy)).catch(() => {});
      }
      return response;
    })
    .catch(() => caches.match(cacheKey).then((cached) => cached || Response.error()));
}

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Never cache the API — the page owns online/offline behaviour via its IndexedDB queue.
  if (url.pathname.startsWith('/capture/api/')) {
    return;
  }

  // App navigations: the live shell when online, the cached shell for a cold offline launch.
  if (event.request.mode === 'navigate' && url.pathname.startsWith('/capture')) {
    event.respondWith(networkFirst(event.request, '/capture'));
    return;
  }

  // Static shell assets: same network-first, cache-fallback.
  if (SHELL.includes(url.pathname)) {
    event.respondWith(networkFirst(event.request, url.pathname));
  }
});
@endverbatim
