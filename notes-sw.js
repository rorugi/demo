// RRnotes service worker: keeps a copy of the app so it always starts, also offline.
// The app page comes from the network when online (so updates arrive by themselves), otherwise from the copy.
const CACHE = 'rrnotes-v1';
const CORE = ['./notes.html', './notes.webmanifest', './notes-icon-192.png', './notes-icon-512.png', './notes-icon-maskable-512.png', './notes-icon-180.png', './notes-icon-32.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(CORE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k.startsWith('rrnotes-') && k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  // "Update" in About: always ask the server, and keep what it sends as the new copy
  if (url.searchParams.has('check') && /\/notes(\.html)?$/.test(url.pathname)) {
    e.respondWith(fetch(new URL('./notes.html', location).href, { cache: 'no-store' }).then(async res => {
      if (res.ok) (await caches.open(CACHE)).put('./notes.html', res.clone());
      return res;
    }));
    return;
  }
  if (req.mode === 'navigate' || /\/notes(\.html)?$/.test(url.pathname)) {
    e.respondWith((async () => {
      const cache = await caches.open(CACHE);
      const net = fetch(new URL('./notes.html', location).href, { cache: 'no-cache' })
        .then(res => { if (res.ok) cache.put('./notes.html', res.clone()); return res; });
      try {
        // wait up to 3 s for the newest version, then use the copy (the download still updates it)
        const res = await Promise.race([net, new Promise((_, rej) => setTimeout(() => rej(new Error('slow')), 3000))]);
        if (res.ok) return res;
      } catch (_) {}
      net.catch(() => {});
      return (await cache.match('./notes.html')) || fetch(req);
    })());
    return;
  }
  e.respondWith(caches.match(req).then(hit => hit || fetch(req)));
});
