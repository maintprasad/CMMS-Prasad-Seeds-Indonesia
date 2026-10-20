// Service worker CMMS: aplikasi bisa dipasang & cangkangnya terbuka cepat/offline.
// - /api/ TIDAK pernah di-cache (data selalu dari server).
// - HTML/JS/CSS: jaringan dulu (pembaruan langsung sampai), cadangan dari cache saat offline.
// - Gambar, font, ikon, pustaka (assets/): cache dulu, perbarui di latar belakang.
const VERSION = 'cmms-v1';
const SHELL = ['./', 'index.html', 'manifest.webmanifest', 'css/cmms.css', 'js/main.js', 'assets/icons/icon-192.png', 'assets/icons/icon-512.png', 'assets/icons/favicon-32.png',
  'assets/arclon/css/vendor.min.css', 'assets/arclon/css/app.min.css', 'assets/arclon/css/icons.min.css'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => Promise.allSettled(SHELL.map((u) => c.add(u)))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

const put = (req, res) => { if (res && res.ok && res.type === 'basic') { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(req, copy)).catch(() => {}); } return res; };

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin || url.pathname.includes('/api/')) return;

  if (url.pathname.includes('/assets/') && !url.pathname.endsWith('.css')) {
    e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => put(req, res))));
    return;
  }
  e.respondWith(fetch(req).then((res) => put(req, res)).catch(() => caches.match(req).then((hit) => hit || (req.mode === 'navigate' ? caches.match('index.html') : Response.error()))));
});
