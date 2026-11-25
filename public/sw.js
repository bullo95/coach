// Service worker Coach (écrit à la main).
// Lot 1 : coquille en cache, lecture hors ligne du programme et des fiches.
// Lot 2 : file d'attente des saisies. Lot 5 : push et clics sur notification.

// Incrémenter VERSION à chaque déploiement qui modifie un fichier de SHELL : les fichiers statiques sont servis depuis le cache.
const VERSION = 'coach-v1';
const SHELL = [
  '/',
  '/index.html',
  '/css/style.css',
  '/js/app.js',
  '/js/api.js',
  '/manifest.webmanifest',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/apple-touch-icon.png',
];
// Réponses d'API lisibles hors ligne (dernière version connue).
const CACHED_API = ['/api/me', '/api/program', '/api/exercises'];

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
    event.respondWith(fetch(req).catch(() => caches.match('/index.html')));
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

  // Fichiers statiques : cache d'abord, puis réseau (et mise en cache des visuels).
  event.respondWith(
    caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      if (res.ok && url.pathname.startsWith('/media/')) {
        const copy = res.clone();
        caches.open(VERSION).then((c) => c.put(req, copy));
      }
      return res;
    })),
  );
});
