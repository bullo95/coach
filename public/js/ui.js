// Utilitaires d'affichage partagés.

export class Safe { constructor(value) { this.value = value; } }
export const raw = (v) => new Safe(v);
export const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const part = (v) => (v instanceof Safe ? v.value : esc(v));

// Gabarit : renvoie du HTML sûr ; les valeurs sont échappées sauf si elles viennent d'un autre gabarit.
export const html = (strings, ...values) => new Safe(strings.reduce((out, s, i) => {
  const v = values[i - 1];
  return out + (Array.isArray(v) ? v.map(part).join('') : part(v)) + s;
}));

const main = document.getElementById('main');
const tabbar = document.getElementById('tabbar');

/** Affiche un écran. fullscreen : masque la barre de navigation (mode séance). */
export function render(content, { title, tab, showTabs = true, fullscreen = false } = {}) {
  main.innerHTML = content.value;
  document.title = title ? `${title} · Coach` : 'Coach';
  tabbar.hidden = !showTabs || fullscreen;
  document.body.classList.toggle('fullscreen', fullscreen);
  // Thème sombre forcé en séance (cahier des charges, ergonomie).
  if (fullscreen) document.documentElement.dataset.theme = 'dark';
  else delete document.documentElement.dataset.theme;
  if (!fullscreen) document.body.classList.remove('phase-fast', 'phase-slow', 'phase-rest');
  for (const a of tabbar.querySelectorAll('a')) {
    if (a.dataset.tab === tab) a.setAttribute('aria-current', 'page');
    else a.removeAttribute('aria-current');
  }
  window.scrollTo(0, 0);
  main.focus({ preventScroll: true });
}

export const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
export const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

export const num = (v) => String(v).replace('.', ',');

export function formatSeconds(s) {
  if (s < 60) return `${s} s`;
  const m = Math.floor(s / 60);
  const r = s % 60;
  return r ? `${m} min ${r} s` : `${m} min`;
}

/** Chrono « m:ss ». */
export function clock(ms) {
  const total = Math.max(0, Math.ceil(ms / 1000));
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

export function formatTarget(item) {
  let t;
  if (item.target_reps != null) {
    t = item.target_reps_max ? `${item.target_reps} à ${item.target_reps_max} rép.` : `${item.target_reps} rép.`;
  } else {
    t = item.target_seconds_max ? `${item.target_seconds} à ${item.target_seconds_max} s` : formatSeconds(item.target_seconds);
  }
  if (item.per_side) t += ' par côté';
  return t;
}

/** Date locale AAAA-MM-JJ. */
export function localDate(d = new Date()) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** « mercredi 26 novembre » à partir de AAAA-MM-JJ. */
export function longDate(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  const date = new Date(y, m - 1, d);
  return `${DAYS[date.getDay()]} ${d} ${MONTHS[m - 1]}`;
}

export function initials(name) {
  return name.split(/[\s-]+/).filter((w) => w.length > 2).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
}

export function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/**
 * Visuel d'un exercice : vidéo en boucle sans son, sinon deux images alternées toutes les 1,5 s
 * (position de départ / d'arrivée), sinon un cartouche avec les initiales.
 */
export function exerciseVisual(ex, name, cls = '') {
  const label = name ?? ex?.name_fr ?? '';
  const paths = ex?.media_paths ?? [];
  if (ex?.media_type === 'video' && paths.length) {
    return html`<video class="visual ${cls}" src="${paths[0]}" poster="${paths[1] ?? ''}" autoplay loop muted playsinline aria-label="${label}"></video>`;
  }
  if (ex?.media_type === 'images' && paths.length) {
    return html`<img class="visual ${cls}" src="${paths[0]}" data-frames="${paths.join('|')}" alt="${label} : position de départ puis d'arrivée" width="800" height="533" decoding="async">`;
  }
  return html`<div class="visual ${cls}" role="img" aria-label="Visuel à venir pour ${label}"><div><strong>${initials(label)}</strong>${cls ? '' : 'Visuel à venir'}</div></div>`;
}

// Alternance des images. Avec « réduire les animations », l'image ne change qu'au toucher.
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
function nextFrame(img) {
  const frames = img.dataset.frames.split('|');
  const i = (Number(img.dataset.frame ?? 0) + 1) % frames.length;
  img.dataset.frame = String(i);
  img.src = frames[i];
}
setInterval(() => {
  if (reduceMotion.matches || document.hidden) return;
  document.querySelectorAll('img[data-frames]').forEach(nextFrame);
}, 1500);
document.addEventListener('click', (e) => {
  const img = e.target.closest('img[data-frames]');
  if (img) nextFrame(img);
});

/** Met en cache les visuels pour le hors-ligne (le service worker garde /media/). */
export function preloadMedia(exercises) {
  for (const ex of exercises ?? []) {
    if (ex.media_type !== 'images' && ex.media_type !== 'video') continue;
    for (const p of ex.media_paths) fetch(p).catch(() => {});
  }
}
