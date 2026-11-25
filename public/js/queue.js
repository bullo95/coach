// File d'attente des saisies (IndexedDB) : chaque saisie est gardée sur le téléphone jusqu'à ce que
// le serveur l'ait acceptée. Le client_uuid de chaque saisie évite les doublons en cas de renvoi.

const DB_NAME = 'coach';
const STORE = 'queue';
let dbPromise = null;
let flushing = null;
const listeners = new Set();

function open() {
  dbPromise ??= new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'seq', autoIncrement: true });
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
  return dbPromise;
}

async function tx(mode, fn) {
  const db = await open();
  return new Promise((resolve, reject) => {
    const t = db.transaction(STORE, mode);
    const result = fn(t.objectStore(STORE));
    t.oncomplete = () => resolve(result?.result ?? result);
    t.onerror = () => reject(t.error);
  });
}

export async function enqueue(event) {
  await tx('readwrite', (s) => s.add(event));
  notify();
  flush();
}

export async function pendingCount() {
  return tx('readonly', (s) => s.count());
}

async function all() {
  return tx('readonly', (s) => s.getAll());
}

async function remove(seqs) {
  await tx('readwrite', (s) => { for (const k of seqs) s.delete(k); });
}

/** Envoie les saisies en attente, par lots, dans l'ordre. Sans réseau : on réessaiera plus tard. */
export function flush() {
  flushing ??= (async () => {
    try {
      for (;;) {
        const items = (await all()).slice(0, 100);
        if (!items.length) return;
        let res;
        try {
          res = await fetch('/api/sync', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ events: items.map(({ seq, ...e }) => e) }),
          });
        } catch {
          return; // hors ligne
        }
        if (!res.ok) return; // non connecté ou erreur serveur : on garde tout
        const { accepted = [], rejected = [] } = await res.json();
        for (const r of rejected) console.warn('Saisie refusée par le serveur', r);
        const done = new Set([...accepted, ...rejected.map((r) => r.uuid)]);
        const seqs = items.filter((i) => done.has(i.client_uuid)).map((i) => i.seq);
        if (!seqs.length) return;
        await remove(seqs);
        notify();
      }
    } finally {
      flushing = null;
    }
  })();
  return flushing;
}

export function onChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

function notify() {
  pendingCount().then((n) => listeners.forEach((fn) => fn(n))).catch(() => {});
}

window.addEventListener('online', () => flush());
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') flush(); });
setInterval(() => { if (navigator.onLine) flush(); }, 60_000);
