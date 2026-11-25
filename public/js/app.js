// Coach — application (lot 1 : connexion, accueil, programme, bibliothèque d'exercices).
import { api, ApiError } from './api.js';

const main = document.getElementById('main');
const tabbar = document.getElementById('tabbar');
const state = { me: null, program: null, exercises: null };

const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const BLOCK_TYPES = {
  warmup: 'Échauffement', circuit: 'Circuit', sets: 'Séries', interval: 'Fractionné', amrap: 'AMRAP', cooldown: 'Retour au calme',
};

// Échappement HTML : toute donnée insérée dans le DOM passe par cette balise de gabarit.
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
class Safe { constructor(value) { this.value = value; } }
const raw = (v) => new Safe(v);
const part = (v) => (v instanceof Safe ? v.value : esc(v));
// Gabarit : renvoie du HTML sûr ; les valeurs sont échappées sauf si elles viennent d'un autre gabarit.
const html = (strings, ...values) => new Safe(strings.reduce((out, s, i) => {
  const v = values[i - 1];
  return out + (Array.isArray(v) ? v.map(part).join('') : part(v)) + s;
}));

function render(content, { title, tab } = {}) {
  main.innerHTML = content.value;
  document.title = title ? `${title} · Coach` : 'Coach';
  tabbar.hidden = !state.me;
  for (const a of tabbar.querySelectorAll('a')) {
    if (a.dataset.tab === tab) a.setAttribute('aria-current', 'page');
    else a.removeAttribute('aria-current');
  }
  window.scrollTo(0, 0);
  main.focus({ preventScroll: true });
}

// --- Formatage -----------------------------------------------------------------

function formatSeconds(s) {
  if (s < 60) return `${s} s`;
  const m = Math.floor(s / 60);
  const r = s % 60;
  return r ? `${m} min ${r} s` : `${m} min`;
}

function formatTarget(item) {
  let t;
  if (item.target_reps != null) {
    t = item.target_reps_max ? `${item.target_reps} à ${item.target_reps_max} rép.` : `${item.target_reps} rép.`;
  } else {
    t = item.target_seconds_max ? `${item.target_seconds} à ${item.target_seconds_max} s` : formatSeconds(item.target_seconds);
  }
  if (item.per_side) t += ' par côté';
  return t;
}

function formatBlockMeta(block) {
  const parts = [];
  if (block.type === 'amrap' && block.duration_s) parts.push(`${formatSeconds(block.duration_s)}, max de tours`);
  else if (block.rounds > 1) parts.push(block.type === 'sets' || block.type === 'interval' ? `${block.rounds} fois` : `${block.rounds} tours`);
  else if (block.duration_s) parts.push(formatSeconds(block.duration_s));
  if (block.rest_between_rounds_s) parts.push(`repos ${formatSeconds(block.rest_between_rounds_s)}`);
  return parts.join(' · ');
}

function initials(name) {
  return name.split(/[\s-]+/).filter((w) => w.length > 2).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
}

// Prochain jour de séance d'après les réglages (rotation A/B/C calculée au lot 2).
function nextSessionDay(sessionDays) {
  const today = new Date();
  for (let i = 0; i < 7; i++) {
    const d = new Date(today);
    d.setDate(today.getDate() + i);
    const iso = d.getDay() === 0 ? 7 : d.getDay();
    if (sessionDays.includes(iso)) return { offset: i, name: DAYS[d.getDay()] };
  }
  return null;
}

// --- Écrans --------------------------------------------------------------------

function screenLogin(error = '') {
  render(html`
    <h1>Coach</h1>
    <p class="muted">Séances, pompes et protéines.</p>
    <form id="login" class="card" novalidate>
      <label for="email">E-mail</label>
      <input id="email" name="email" type="email" autocomplete="username" required>
      <label for="password">Mot de passe</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>
      <p style="margin-top:16px"><button class="btn block" type="submit">Se connecter</button></p>
      <p class="error" role="alert">${error}</p>
    </form>`, { title: 'Connexion' });

  document.getElementById('login').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = e.target.querySelector('button');
    btn.disabled = true;
    try {
      state.me = await api.login(e.target.email.value, e.target.password.value);
      location.hash = '#/';
      route();
    } catch (err) {
      screenLogin(err.message);
    }
  });
}

function screenHome() {
  const s = state.me.settings;
  const next = nextSessionDay(s.session_days);
  const pushTarget = s.pushups_sets * s.pushups_reps_per_set;
  const sessionLine = !next ? 'Aucun jour de séance réglé.'
    : next.offset === 0 ? 'Aujourd\'hui est un jour de séance.'
      : `Prochaine séance : ${next.name}.`;

  render(html`
    <h1>Bonjour</h1>
    <section class="card" aria-labelledby="h-seance">
      <h2 id="h-seance">Séance</h2>
      <p>${sessionLine}</p>
      <p class="muted">Le mode séance guidé arrive au lot 2. En attendant, consulte le programme.</p>
      <a class="btn secondary" href="#/seances">Voir le programme</a>
    </section>
    <section class="card" aria-labelledby="h-pompes">
      <h2 id="h-pompes">Pompes du jour</h2>
      <p>Objectif : ${s.pushups_sets} séries de ${s.pushups_reps_per_set}, soit ${pushTarget} pompes.</p>
      <div class="gauge" role="img" aria-label="0 sur ${pushTarget} pompes"><span style="width:0%"></span></div>
      <p class="muted">0 / ${s.pushups_sets} séries · compteur, objectifs et jokers au lot 4.</p>
    </section>
    <section class="card" aria-labelledby="h-prot">
      <h2 id="h-prot">Protéines du jour</h2>
      <p class="muted">Calcul de l'objectif (${String(s.protein_coef).replace('.', ',')} g/kg) et suivi au lot 4.</p>
    </section>`, { title: 'Accueil', tab: 'accueil' });
}

async function screenSessions() {
  state.program ??= await api.program();
  render(html`
    <h1>Séances</h1>
    <ul class="list card">
      ${state.program.map((t) => html`<li><a href="#/seances/${t.code}">
        <span><strong>Séance ${t.code}</strong> — ${t.name}<br><span class="muted">${t.est_duration_min} min · ${t.blocks.length} blocs</span></span>
      </a></li>`)}
    </ul>
    <h2>Bibliothèque</h2>
    <ul class="list card"><li><a href="#/exercices"><span>Toutes les fiches d'exercices</span></a></li></ul>`,
  { title: 'Séances', tab: 'seances' });
}

async function screenTemplate(code) {
  state.program ??= await api.program();
  const t = state.program.find((x) => x.code === code);
  if (!t) return screenNotFound();
  render(html`
    <a class="back" href="#/seances">‹ Séances</a>
    <h1>Séance ${t.code} — ${t.name}</h1>
    <p class="muted">Durée prévue : ${t.est_duration_min} min</p>
    ${t.blocks.map((b) => html`
      <section class="card">
        <div class="block-head"><h2>${b.name}</h2><span class="tag">${BLOCK_TYPES[b.type] ?? b.type}</span></div>
        <p class="muted">${formatBlockMeta(b)}</p>
        <ul class="list">
          ${b.items.map((i) => html`<li><a href="#/exercices/${i.exercise.slug}">
            <span>${i.exercise.name}${i.load_label ? raw(`<br><span class="muted">${esc(i.load_label)}</span>`) : ''}</span>
            <span class="item-target">${formatTarget(i)}</span>
          </a></li>`)}
        </ul>
      </section>`)}
    <p><button class="btn block" type="button" disabled>Démarrer (lot 2)</button></p>`,
  { title: `Séance ${t.code}`, tab: 'seances' });
}

async function screenLibrary() {
  state.exercises ??= await api.exercises();
  render(html`
    <a class="back" href="#/seances">‹ Séances</a>
    <h1>Bibliothèque</h1>
    <ul class="list card">
      ${state.exercises.map((e) => html`<li><a href="#/exercices/${e.slug}">
        <span>${e.name_fr}<br><span class="muted">${e.muscles ?? ''}</span></span></a></li>`)}
    </ul>`, { title: 'Bibliothèque', tab: 'seances' });
}

async function screenExercise(slug) {
  let e;
  try {
    e = await api.exercise(slug);
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) return screenNotFound();
    throw err;
  }
  const visual = e.media_type === 'images' && e.media_paths.length
    ? html`<img class="visual" src="${e.media_paths[0]}" alt="${e.name_fr}, position de départ">`
    : html`<div class="visual" role="img" aria-label="Visuel à venir pour ${e.name_fr}"><div><strong>${initials(e.name_fr)}</strong>Visuel à venir (lot 3)</div></div>`;
  const variant = e.easier_variant
    ? html`<a href="#/exercices/${e.easier_variant.slug}">${e.easier_variant.name}</a>`
    : html`${e.easier_variant_text ?? '—'}`;

  render(html`
    <a class="back" href="#/exercices" data-back>‹ Retour</a>
    <h1>${e.name_fr}</h1>
    ${visual}
    <p class="muted">${e.muscles ?? ''}</p>
    <section class="card">
      <h2>Consignes clés</h2>
      <ol class="cues">${e.cues.map((c) => html`<li>${c}</li>`)}</ol>
      <h3>Erreur à éviter</h3>
      <p>${e.mistake ?? '—'}</p>
      <h3>Variante plus facile</h3>
      <p>${variant}</p>
    </section>
    ${e.attribution ? html`<p class="muted">Visuel : ${e.attribution} (${e.license ?? ''})</p>` : ''}`,
  { title: e.name_fr, tab: 'seances' });
}

function screenPlaceholder(title, tab, lot) {
  render(html`<h1>${title}</h1><section class="card"><p class="muted">Cet écran arrive au lot ${lot}.</p></section>`, { title, tab });
}

function screenSettings() {
  const u = state.me.user;
  render(html`
    <h1>Réglages</h1>
    <section class="card">
      <h2>Compte</h2>
      <p>${u.email}<br><span class="muted">Fuseau : ${u.timezone}</span></p>
      <button class="btn secondary" type="button" id="logout">Se déconnecter</button>
    </section>
    <section class="card"><p class="muted">Jours, rappels, pompes et protéines : réglables aux lots suivants.</p></section>`,
  { title: 'Réglages', tab: 'reglages' });
  document.getElementById('logout').addEventListener('click', async () => {
    await api.logout().catch(() => {});
    // Retire du cache hors ligne les réponses liées au compte.
    if ('caches' in window) {
      for (const key of await caches.keys()) {
        const cache = await caches.open(key);
        for (const req of await cache.keys()) {
          if (new URL(req.url).pathname.startsWith('/api/')) await cache.delete(req);
        }
      }
    }
    state.me = null;
    state.program = null;
    state.exercises = null;
    location.hash = '#/';
    route();
  });
}

function screenNotFound() {
  render(html`<h1>Page introuvable</h1><p><a href="#/">Retour à l'accueil</a></p>`, { title: 'Introuvable' });
}

function screenError(err) {
  render(html`<h1>Oups</h1><section class="card"><p>${err.message}</p>
    <button class="btn" type="button" data-reload>Réessayer</button></section>`, { title: 'Erreur' });
}

// --- Routage -------------------------------------------------------------------

const routes = [
  [/^\/$/, screenHome],
  [/^\/seances$/, screenSessions],
  [/^\/seances\/([A-Z])$/, screenTemplate],
  [/^\/exercices$/, screenLibrary],
  [/^\/exercices\/([\w-]+)$/, screenExercise],
  [/^\/proteines$/, () => screenPlaceholder('Protéines', 'proteines', 4)],
  [/^\/suivi$/, () => screenPlaceholder('Suivi', 'suivi', '2 et 4')],
  [/^\/reglages$/, screenSettings],
];

async function route() {
  const path = location.hash.replace(/^#/, '') || '/';
  try {
    if (!state.me) {
      try {
        state.me = await api.me();
      } catch (err) {
        if (err instanceof ApiError && err.status === 401) return screenLogin();
        throw err;
      }
    }
    for (const [re, screen] of routes) {
      const m = path.match(re);
      if (m) return await screen(...m.slice(1));
    }
    screenNotFound();
  } catch (err) {
    screenError(err);
  }
}

// Actions génériques sans JavaScript inline (compatible avec la politique de sécurité du serveur).
main.addEventListener('click', (e) => {
  if (e.target.closest('[data-back]') && history.length > 1) {
    e.preventDefault();
    history.back();
  } else if (e.target.closest('[data-reload]')) {
    location.reload();
  }
});

window.addEventListener('hashchange', route);
route();

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch((err) => console.warn('Service worker non enregistré', err));
}
