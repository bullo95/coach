// Service worker Coach (écrit à la main).
// Lot 1 : coquille en cache, lecture hors ligne du programme et des fiches.
// Lot 2 : séance entière hors ligne (les saisies passent par la file IndexedDB de js/queue.js).
// Lot 5 : push et clics sur notification.

// Incrémenter VERSION quand la liste SHELL change (les anciens caches sont alors supprimés).
const VERSION = 'coach-v4';
const SHELL = [
  '/',
  '/index.html',
  '/css/style.css',
  '/js/app.js',
  '/js/api.js',
  '/js/ui.js',
  '/js/queue.js',
  '/js/workout.js',
  '/js/pushups.js',
  '/manifest.webmanifest',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/apple-touch-icon.png',
];
// Réponses d'API lisibles hors ligne (dernière version connue).
const CACHED_API = ['/api/me', '/api/program', '/api/exercises', '/api/today', '/api/loads', '/api/sessions',
  '/api/pushups', '/api/pushups/history', '/api/pushups/plan/history', '/api/encouragements'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;

  // Navigation : réseau d'abord, coquille en cache si hors ligne.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(VERSION).then((c) => c.put('/index.html', copy));
          }
          return res;
        })
        .catch(() => caches.match('/index.html')),
    );
    return;
  }

  // API : réseau d'abord ; copie gardée pour les lectures utiles hors ligne.
  if (url.pathname.startsWith('/api/')) {
    const cacheable = CACHED_API.includes(url.pathname) || url.pathname.startsWith('/api/exercises/');
    if (!cacheable) return;
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(VERSION).then((c) => c.put(req, copy));
          }
          return res;
        })
        .catch(() => caches.match(req).then((hit) => hit || Response.json({ error: 'Hors ligne' }, { status: 503 }))),
    );
    return;
  }

  // Fichiers de l'app : réseau d'abord (une mise à jour se voit tout de suite), copie en cache pour le hors-ligne.
  // Visuels des exercices : cache d'abord (ils ne changent pas).
  if (url.pathname.startsWith('/media/')) {
    event.respondWith(mediaResponse(req));
    return;
  }
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(VERSION).then((c) => c.put(req, copy));
        }
        return res;
      })
      .catch(() => caches.match(req, { ignoreSearch: true })),
  );
});

// Visuels (images et vidéos) : copie complète gardée en cache. Les lecteurs vidéo demandent des morceaux
// (en-tête Range) : on les découpe dans la copie, ce qui permet la lecture hors ligne.
async function mediaResponse(req) {
  const cache = await caches.open(VERSION);
  const full = await cache.match(req.url);
  const range = req.headers.get('range');
  if (!full) {
    if (range) {
      fetch(req.url).then((r) => (r.status === 200 ? cache.put(req.url, r) : null)).catch(() => {});
      return fetch(req);
    }
    const res = await fetch(req);
    if (res.status === 200) cache.put(req.url, res.clone());
    return res;
  }
  if (!range) return full;
  const buf = await full.arrayBuffer();
  const m = /bytes=(\d*)-(\d*)/.exec(range) ?? [];
  let start = m[1] ? Number(m[1]) : 0;
  let end = m[2] ? Number(m[2]) : buf.byteLength - 1;
  if (!m[1] && m[2]) {
    start = Math.max(0, buf.byteLength - Number(m[2]));
    end = buf.byteLength - 1;
  }
  end = Math.min(end, buf.byteLength - 1);
  if (start > end) return new Response(null, { status: 416, headers: { 'Content-Range': `bytes */${buf.byteLength}` } });
  return new Response(buf.slice(start, end + 1), {
    status: 206,
    headers: {
      'Content-Type': full.headers.get('Content-Type') || 'application/octet-stream',
      'Content-Range': `bytes ${start}-${end}/${buf.byteLength}`,
      'Content-Length': String(end - start + 1),
      'Accept-Ranges': 'bytes',
    },
  });
}
