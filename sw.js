/*
 * Goglobia — Service Worker (offline-shell strategy)
 * ---------------------------------------------------
 * Registered by app.js with an explicit scope equal to the app's base path
 * (the `root` constant, which may be a subpath like /goglobia/). All URLs here
 * are RELATIVE so they resolve against that scope — never hardcode the domain
 * or a subpath, so this works identically on localhost/<app> and at the root
 * of the production domain.
 *
 * Booking platform safety: HTML is network-first (live prices/availability are
 * never served stale); only the static shell (CSS/JS/fonts/icons) and a branded
 * offline page are cached. API, booking, payment and any non-GET request always
 * go straight to the network and are never cached.
 */

// Bump this string to force clients onto a fresh cache (e.g. after asset changes).
const CACHE_VERSION = 'goglobia-v1';
const OFFLINE_URL = 'offline.html';

// Minimal precache: the offline fallback + core icons. Relative to SW scope.
const PRECACHE_URLS = [
  OFFLINE_URL,
  'assets/pwa/icon-192.png',
  'assets/pwa/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Paths that must ALWAYS hit the network and never be cached.
function isNeverCache(url) {
  const p = url.pathname;
  return (
    p.includes('/api/') ||
    p.includes('/modules/') ||
    p.includes('/admin') ||
    p.includes('/checkout') ||
    p.includes('/payment') ||
    p.includes('/booking') ||
    p.includes('/login') ||
    p.includes('/logout') ||
    p.includes('/dashboard') ||
    p.includes('/partials/') ||
    p.includes('/cron')
  );
}

// Treat these as cacheable static assets (cache-first).
function isStaticAsset(url) {
  return /\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot)$/i.test(url.pathname) ||
         url.pathname.includes('/assets/');
}

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Only handle same-origin GET. Everything else (POST, cross-origin CDNs) goes
  // straight to the network untouched.
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (isNeverCache(url)) return; // network only, no SW involvement

  // Static assets: cache-first, then network (and populate cache on success).
  if (isStaticAsset(url)) {
    event.respondWith(
      caches.match(req).then((cached) => {
        if (cached) return cached;
        return fetch(req).then((resp) => {
          if (resp && resp.status === 200 && resp.type === 'basic') {
            const copy = resp.clone();
            caches.open(CACHE_VERSION).then((cache) => cache.put(req, copy));
          }
          return resp;
        }).catch(() => cached); // if offline and not cached, undefined → browser error
      })
    );
    return;
  }

  // Navigations / HTML: network-first, fall back to the offline page.
  if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
    event.respondWith(
      fetch(req).catch(() =>
        caches.match(OFFLINE_URL, { ignoreSearch: true }).then((page) =>
          page || new Response('You are offline.', { status: 503, headers: { 'Content-Type': 'text/plain' } })
        )
      )
    );
    return;
  }

  // Default: try network, fall back to any cached copy.
  event.respondWith(fetch(req).catch(() => caches.match(req)));
});
