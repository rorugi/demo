// Samskritam (Ruppel) service worker: keeps a copy of the app so it starts offline.
// The page comes from the network when online (so updates arrive by themselves), otherwise from the copy.
// Progress is never touched here: it lives in the browser's storage.
const CACHE = 'ruppel-v1';
const FONTS = 'ruppel-fonts';
const PAGE = './ruppel-sanskrit.html';
const CORE = [PAGE, './ruppel-sanskrit.webmanifest', './ruppel-icon-192.png', './ruppel-icon-512.png', './ruppel-icon-maskable-512.png', './ruppel-icon-180.png', './ruppel-icon-32.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(CORE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k.startsWith('ruppel-') && k !== CACHE && k !== FONTS).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  // Google Fonts: use the saved copy, refresh it in the background
  if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
    e.respondWith(caches.open(FONTS).then(async c => {
      const hit = await c.match(req);
      const net = fetch(req).then(res => { if (res.ok || res.type === 'opaque') c.put(req, res.clone()); return res; }).catch(() => hit);
      return hit || net;
    }));
    return;
  }
  if (url.origin !== location.origin) return;
  // "Check for updates" in About: always ask the server, keep what it sends as the new copy
  if (url.searchParams.has('check') && /\/ruppel-sanskrit(\.html)?$/.test(url.pathname)) {
    e.respondWith(fetch(new URL(PAGE, location).href, { cache: 'no-store' }).then(async res => {
      if (res.ok) (await caches.open(CACHE)).put(PAGE, res.clone());
      return res;
    }));
    return;
  }
  if (req.mode === 'navigate' || /\/ruppel-sanskrit(\.html)?$/.test(url.pathname)) {
    e.respondWith((async () => {
      const cache = await caches.open(CACHE);
      const net = fetch(new URL(PAGE, location).href, { cache: 'no-cache' })
        .then(res => { if (res.ok) cache.put(PAGE, res.clone()); return res; });
      try {
        // wait up to 3 s for the newest version, then use the copy (the download still updates it)
        const res = await Promise.race([net, new Promise((_, rej) => setTimeout(() => rej(new Error('slow')), 3000))]);
        if (res.ok) return res;
      } catch (_) {}
      net.catch(() => {});
      return (await cache.match(PAGE)) || fetch(req);
    })());
    return;
  }
  e.respondWith(caches.match(req).then(hit => hit || fetch(req)));
});
