/* ELKA Pokladna – service worker (served from /pokladna/sw.js). */
const VERSION = '__VERSION__';
const ASSETS = __ASSETS__;
const SHELL = '__SHELL__';
const CACHE = 'elka-pos-' + VERSION;
const IMG_CACHE = 'elka-pos-img';
const IMG_MAX = 1500;

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    await Promise.all(ASSETS.map(async (url) => {
      try {
        const res = await fetch(url, { cache: 'reload', credentials: 'same-origin' });
        if (res.ok) await cache.put(url, res);
      } catch (e) { /* asset will be cached on first use */ }
    }));
    try {
      const shell = await fetch(SHELL, { cache: 'reload', credentials: 'same-origin' });
      if (shell.ok && !shell.redirected) await cache.put(SHELL, shell);
    } catch (e) { /* offline install */ }
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const key of await caches.keys()) {
      if (key.startsWith('elka-pos-') && key !== CACHE && key !== IMG_CACHE) await caches.delete(key);
    }
    await self.clients.claim();
  })());
});

async function trimImages() {
  const cache = await caches.open(IMG_CACHE);
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - IMG_MAX; i++) await cache.delete(keys[i]);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // REST + admin-ajax: always network (the app keeps its own data in IndexedDB).
  if (url.pathname.includes('/wp-json/') || url.searchParams.has('rest_route') || url.pathname.endsWith('admin-ajax.php')) return;

  // App shell: network first, cached copy offline.
  if (req.mode === 'navigate' && url.href.split('#')[0].split('?')[0] === SHELL) {
    event.respondWith((async () => {
      try {
        const res = await fetch(req);
        if (res.ok && !res.redirected) (await caches.open(CACHE)).put(SHELL, res.clone());
        return res;
      } catch (e) {
        const cached = await caches.match(SHELL);
        return cached || new Response('<h1>Pokladna je offline</h1><p>Při prvním spuštění je potřeba připojení k internetu.</p>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
      }
    })());
    return;
  }

  // Product images: cache first, refreshed in the background.
  if (req.destination === 'image' && /\/wp-content\/uploads\//.test(url.pathname)) {
    event.respondWith((async () => {
      const cache = await caches.open(IMG_CACHE);
      const hit = await cache.match(req);
      const net = fetch(req).then((res) => {
        if (res.ok) { cache.put(req, res.clone()); trimImages(); }
        return res;
      }).catch(() => null);
      return hit || (await net) || new Response('', { status: 504 });
    })());
    return;
  }

  // Plugin assets: cache first.
  if (url.pathname.includes('/elka-pos/assets/')) {
    event.respondWith((async () => {
      const hit = await caches.match(req, { ignoreSearch: false }) || await caches.match(req.url.split('?')[0]);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok) (await caches.open(CACHE)).put(req, res.clone());
      return res;
    })());
  }
});

self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') self.skipWaiting();
});
