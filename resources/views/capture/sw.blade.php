@verbatim
// Job Capture service worker (§5). Precaches the app shell so the PWA launches offline;
// the offline UPLOAD queue is IndexedDB in the page, not here. API calls are never cached.
const CACHE = 'job-capture-v2';
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

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Never cache the API — the page owns online/offline behaviour via its IndexedDB queue.
  if (url.pathname.startsWith('/capture/api/')) {
    return;
  }

  // App navigations: serve the cached shell first so a cold, offline launch still works.
  if (event.request.mode === 'navigate' && url.pathname.startsWith('/capture')) {
    event.respondWith(
      caches.match('/capture').then((cached) => cached || fetch(event.request))
    );
    return;
  }

  // Static shell assets: cache-first, fall back to network.
  if (SHELL.includes(url.pathname)) {
    event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request)));
  }
});
@endverbatim
