/**
 * Saee Life Herbal service worker.
 * - App-shell caching for fast repeat visits on slow connections.
 * - Network-first for HTML, stale-while-revalidate for static assets.
 * - NEVER caches admin, member portal, API responses or any authenticated/financial data.
 */
const VERSION = 'saee-v1.3.0';
const STATIC_CACHE = `${VERSION}-static`;
const PAGE_CACHE = `${VERSION}-pages`;

const PRECACHE = [
  './',
  './our-products',
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/vendor/bootstrap/bootstrap.min.css',
  './assets/vendor/bootstrap/bootstrap.bundle.min.js',
  './assets/vendor/fontawesome/css/all.min.css',
  './assets/fonts/poppins/poppins-latin-400-normal.woff2',
  './assets/fonts/poppins/poppins-latin-600-normal.woff2',
  './assets/fonts/roboto/roboto-latin-400-normal.woff2',
  './assets/images/logo.png',
  './assets/images/logo-square.png',
  './pwa/offline.php'
];

// Any URL containing these segments is treated as private and never cached.
const PRIVATE_SEGMENTS = ['/admin', '/member', '/api', '/checkout', '/my-account', '/my-orders', '/invoice', '/payment', '/cart'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE).catch(() => undefined)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => !key.startsWith(VERSION)).map((key) => caches.delete(key)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

function isPrivate(url) {
  return PRIVATE_SEGMENTS.some((segment) => url.pathname.indexOf(segment) === 0 || url.pathname.indexOf(segment + '/') !== -1);
}

function isStatic(url) {
  return /\.(css|js|woff2?|png|jpe?g|webp|gif|svg|ico)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') { return; }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) { return; }
  if (isPrivate(url)) { return; } // let the network handle private areas

  if (isStatic(url)) {
    event.respondWith(
      caches.open(STATIC_CACHE).then((cache) =>
        cache.match(request).then((cached) => {
          const network = fetch(request).then((response) => {
            if (response && response.ok) { cache.put(request, response.clone()); }
            return response;
          }).catch(() => cached);
          return cached || network;
        })
      )
    );
    return;
  }

  if (request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html')) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response && response.ok) {
            const copy = response.clone();
            caches.open(PAGE_CACHE).then((cache) => cache.put(request, copy)).catch(() => undefined);
          }
          return response;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match('./pwa/offline.php')))
    );
  }
});
