/* MustrHQ kiosk — service worker.
   Scope is /kiosk/. Network-first so the screen stays fresh online, with a
   cached shell so a brief Wi-Fi drop doesn't blank the tablet. POSTs (clock
   actions) are never intercepted — they always go to the server. */

const CACHE = 'mustrhq-v3';
const ASSETS = [
  '../assets/style.css',
  '../assets/kiosk.js',
  'manifest.php',
  'icons/icon-192.png',
  'icons/icon-512.png'
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      .then((c) => c.addAll(ASSETS))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.map((k) => (k === CACHE ? null : caches.delete(k)))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return; // let clock in/out POSTs hit the network untouched
  e.respondWith(
    fetch(req)
      .then((res) => {
        if (res && res.ok && req.url.indexOf(self.location.origin) === 0) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      })
      .catch(() => caches.match(req))
  );
});
