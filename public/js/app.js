// Coach — application : connexion, accueil, programme, bibliothèque, mode séance, historique.
import { api, ApiError } from './api.js';
import { flush, pendingCount } from './queue.js';
import { html, render as baseRender, exerciseVisual, formatSeconds, formatTarget, longDate, num, preloadMedia } from './ui.js';
import { loadPushups, pushupsCard, pushupsPlanPage, pushupsTracking } from './pushups.js';
import { closeStale, loadActive, screenActive, screenEnd, startWorkout, stopRunner } from './workout.js';

const main = document.getElementById('main');
const state = { me: null, program: null, exercises: null };

const BLOCK_TYPES = {
  warmup: 'Échauffement', circuit: 'Circuit', sets: 'Séries', interval: 'Fractionné', amrap: 'AMRAP', cooldown: 'Retour au calme',
};
const EFFORT = ['Facile', 'Modéré', 'Soutenu', 'Difficile', 'Épuisant'];

const render = (content, opts = {}) => baseRender(content, { showTabs: !!state.me, ...opts });

function formatBlockMeta(block) {
  const parts = [];
  if (block.type === 'amrap' && block.duration_s) parts.push(`${formatSeconds(block.duration_s)}, max de tours`);
  else if (block.rounds > 1) parts.push(block.type === 'sets' || block.type === 'interval' ? `${block.rounds} fois` : `${block.rounds} tours`);
  else if (block.duration_s) parts.push(formatSeconds(block.duration_s));
  if (block.rest_between_rounds_s) parts.push(`repos ${formatSeconds(block.rest_between_rounds_s)}`);
  return parts.join(' · ');
}

/** Charge tout ce qu'une séance demande ; le service worker en garde une copie pour le hors-ligne. */
async function loadWorkoutData() {
  const [program, exercises] = await Promise.all([
    state.program ?? api.program(),
    state.exercises ?? api.exercises(),
  ]);
  if (!state.exercises) preloadMedia(exercises);
  state.program = program;
  state.exercises = exercises;
  return { program, exercises, me: state.me };
}

async function begin(code) {
  const { program } = await loadWorkoutData();
  const template = program.find((t) => t.code === code);
  const loads = await api.loads().catch(() => ({}));
  await startWorkout(template, loads);
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
    e.target.querySelector('button').disabled = true;
    try {
      state.me = await api.login(e.target.email.value, e.target.password.value);
      flush();
      location.hash = '#/';
      route();
    } catch (err) {
      screenLogin(err.message);
    }
  });
}

async function screenHome() {
  await closeStale();
  const s = state.me.settings;
  const [today, pending] = await Promise.all([
    api.today().catch(() => null),
    pendingCount().catch(() => 0),
    loadWorkoutData().catch(() => null), // prépare le cache hors ligne de la séance
    loadPushups(),
  ]);
  api.loads().catch(() => {});
  const active = loadActive();
  const flash = sessionStorage.getItem('coach.flash');
  sessionStorage.removeItem('coach.flash');
  const tpl = (code) => state.program?.find((t) => t.code === code);

  let session;
  if (active) {
    session = html`<p><strong>Séance ${active.code} en cours.</strong></p>
      <a class="btn block" href="${active.phase === 'end' ? '#/fin-de-seance' : '#/seance-en-cours'}">${active.phase === 'end' ? 'Terminer le bilan' : 'Reprendre la séance'}</a>`;
  } else if (!today) {
    session = html`<p class="muted">Hors ligne : planning indisponible. Tu peux lancer une séance depuis l'onglet Séances.</p>`;
  } else if (today.done_today) {
    session = html`<p><strong>Séance faite aujourd'hui.</strong></p>
      <p class="muted">Prochaine : ${today.next_session_date ? longDate(today.next_session_date) : '—'}, séance ${today.next_template}.</p>`;
  } else if (today.proposed_today) {
    const t = tpl(today.next_template);
    session = html`<p><strong>Aujourd'hui : séance ${today.next_template}</strong>${t ? ` — ${t.name}` : ''}</p>
      <p class="muted">${t ? `${t.est_duration_min} min` : ''}</p>
      <p><button class="btn block" type="button" data-start="${today.next_template}">Démarrer la séance</button></p>
      <div class="stack">
        <a class="btn secondary block" href="#/seances/${today.next_template}">Voir l'aperçu</a>
        ${today.can_joker ? html`<button class="btn secondary block" type="button" data-session-joker="use">Sauter avec un joker (${today.jokers_left} restant${today.jokers_left > 1 ? 's' : ''})</button>` : ''}
      </div>`;
  } else if (today.skipped_today) {
    session = html`<p><strong>Séance sautée avec un joker.</strong></p>
      <p class="muted">Elle reste la prochaine : ${today.next_session_date ? longDate(today.next_session_date) : '—'}, séance ${today.next_template}.</p>
      <button class="btn secondary block" type="button" data-session-joker="cancel">Annuler le joker</button>`;
  } else {
    session = html`<p><strong>Repos aujourd'hui.</strong>${today.rest_reason === 'seance_hier' ? ' Séance faite hier : pas deux jours de suite.' : ''}</p>
      <p class="muted">Prochaine séance : ${today.next_session_date ? longDate(today.next_session_date) : '—'}, séance ${today.next_template}.</p>`;
  }

  render(html`
    <h1>Bonjour</h1>
    ${flash ? html`<p class="flash-msg" role="status">${flash}</p>` : ''}
    <section class="card" aria-labelledby="h-seance">
      <h2 id="h-seance">Séance</h2>
      ${session}
      ${today ? html`<p class="week">Cette semaine : <strong>${today.week.done} / ${today.week.planned}</strong> séances${today.week.jokers ? ` · ${today.week.jokers} joker${today.week.jokers > 1 ? 's' : ''}` : ''}</p>` : ''}
    </section>
    ${pending ? html`<p class="muted" role="status">${pending} saisie${pending > 1 ? 's' : ''} en attente d'envoi : elles partiront au retour du réseau.</p>` : ''}
    ${pushupsCard()}
    <section class="card" aria-labelledby="h-prot">
      <h2 id="h-prot">Protéines du jour</h2>
      <p class="muted">Calcul de l'objectif (${num(s.protein_coef)} g/kg) et suivi au lot 4.</p>
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
  const loads = await api.loads().catch(() => ({}));
  const active = loadActive();
  const hasInterval = t.blocks.some((b) => b.type === 'interval');
  const loadText = (i) => {
    const kg = loads[i.id];
    if (kg != null && /barre|charge légère/i.test(i.load_label ?? '')) return `Barre : ${num(kg)} kg (dernière séance)`;
    if (kg != null && i.load_kg != null && kg !== i.load_kg) return `${num(kg)} kg (dernière séance)`;
    return i.load_label;
  };
  render(html`
    <a class="back" href="#/seances">‹ Séances</a>
    <h1>Séance ${t.code} — ${t.name}</h1>
    <p class="muted">Durée prévue : ${t.est_duration_min} min</p>
    ${hasInterval ? html`<p class="notice">Avant de commencer le fractionné, demande l'avis de ton médecin si ce n'est pas déjà fait.</p>` : ''}
    ${active
      ? html`<p><a class="btn block" href="#/seance-en-cours">Reprendre la séance ${active.code} en cours</a></p>`
      : html`<p><button class="btn block" type="button" data-start="${t.code}">Démarrer</button></p>`}
    ${t.blocks.map((b) => html`
      <section class="card">
        <div class="block-head"><h2>${b.name}</h2><span class="tag">${BLOCK_TYPES[b.type] ?? b.type}</span></div>
        <p class="muted">${formatBlockMeta(b)}</p>
        <ul class="list">
          ${b.items.map((i) => html`<li><a href="#/exercices/${i.exercise.slug}">
            <span>${i.exercise.name}${loadText(i) ? html`<br><span class="muted">${loadText(i)}</span>` : ''}</span>
            <span class="item-target">${formatTarget(i)}</span>
          </a></li>`)}
        </ul>
      </section>`)}`,
  { title: `Séance ${t.code}`, tab: 'seances' });
}

async function screenLibrary() {
  state.exercises ??= await api.exercises();
  render(html`
    <a class="back" href="#/seances">‹ Séances</a>
    <h1>Bibliothèque</h1>
    <ul class="list card">
      ${state.exercises.map((e) => html`<li><a href="#/exercices/${e.slug}">
        ${e.media_paths.length && e.media_type !== 'placeholder' ? html`<img class="thumb" src="${e.media_type === 'video' ? e.media_paths[1] : e.media_paths[0]}" alt="" loading="lazy" width="64" height="48">` : html`<span class="thumb" aria-hidden="true"></span>`}
        <span class="grow">${e.name_fr}<br><span class="muted">${e.muscles ?? ''}</span></span></a></li>`)}
    </ul>`, { title: 'Bibliothèque', tab: 'seances' });
}

async function screenExercise(slug) {
  state.exercises ??= await api.exercises();
  const e = state.exercises.find((x) => x.slug === slug);
  if (!e) return screenNotFound();
  const visual = exerciseVisual(e);
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
    ${e.attribution ? html`<p class="muted">Visuel : ${e.attribution}${e.license ? ` (${e.license})` : ''}</p>` : ''}`,
  { title: e.name_fr, tab: 'seances' });
}

async function screenTracking() {
  const pushups = await pushupsTracking();
  let sessions;
  try {
    sessions = await api.sessions();
  } catch (err) {
    if (err instanceof ApiError && err.status !== 0 && err.status !== 503) throw err;
    sessions = null;
  }
  render(html`
    <h1>Suivi</h1>
    <h2>Historique des séances</h2>
    ${sessions === null ? html`<p class="muted">Hors ligne : historique indisponible.</p>`
      : !sessions.length ? html`<section class="card"><p class="muted">Aucune séance pour l'instant.</p></section>`
        : html`<ul class="list card">${sessions.map((x) => html`<li><a href="#/suivi/seances/${x.id}">
          <span><strong>Séance ${x.code}</strong> — ${longDate(x.planned_date)}<br>
          <span class="muted">${x.joker ? 'Sautée avec un joker' : `${x.status === 'in_progress' ? 'En cours' : `${x.duration_min ?? '—'} min`} · ${x.sets_count} séries${x.pain_flag ? ' · douleur signalée' : ''}`}</span></span>
        </a></li>`)}</ul>`}
    ${pushups}
    <section class="card"><p class="muted">Poids, tour de taille et protéines : au lot 4.</p></section>`,
  { title: 'Suivi', tab: 'suivi' });
}

async function screenSessionDetail(id) {
  let x;
  try {
    x = await api.session(id);
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) return screenNotFound();
    throw err;
  }
  const setText = (set, perSide) => {
    const what = set.reps != null ? `${set.reps} rép.${perSide ? ' par côté' : ''}` : formatSeconds(set.seconds ?? 0);
    return set.load_kg != null ? `${what} · ${num(set.load_kg)} kg` : what;
  };
  render(html`
    <a class="back" href="#/suivi">‹ Suivi</a>
    <h1>Séance ${x.code} — ${longDate(x.planned_date)}</h1>
    <section class="card">
      <p>${x.name} · ${x.joker ? 'sautée avec un joker' : x.status === 'in_progress' ? 'en cours' : `${x.duration_min ?? '—'} min`}${x.rounds_done != null ? ` · ${x.rounds_done} tours en AMRAP` : ''}</p>
      <p>Ressenti : ${x.effort_1_5 ? `${x.effort_1_5} / 5 (${EFFORT[x.effort_1_5 - 1]})` : 'non renseigné'}${x.pain_flag ? ' · douleur signalée' : ''}</p>
      ${x.note ? html`<p class="muted">« ${x.note} »</p>` : ''}
    </section>
    ${x.exercises.map((ex) => html`<section class="card">
      <h2>${ex.exercise}</h2>
      <ul class="list">${ex.sets.map((set) => html`<li><span class="row"><span class="muted">Tour ${set.round}</span><span>${setText(set, ex.per_side)}</span></span></li>`)}</ul>
    </section>`)}
    ${!x.exercises.length ? html`<p class="muted">Aucune série enregistrée.</p>` : ''}`,
  { title: `Séance ${x.code}`, tab: 'suivi' });
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
    <ul class="list card"><li><a href="#/pompes"><span>Mon programme de pompes<br><span class="muted">Pompes par série, séries, objectif, conseils du coach</span></span></a></li></ul>
    <section class="card"><p class="muted">Jours de séance, rappels et protéines : réglables aux lots suivants.</p></section>`,
  { title: 'Réglages', tab: 'reglages' });
  document.getElementById('logout').addEventListener('click', async () => {
    await flush();
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
  [/^\/seance-en-cours$/, async () => screenActive(await loadWorkoutData())],
  [/^\/fin-de-seance$/, screenEnd],
  [/^\/exercices$/, screenLibrary],
  [/^\/exercices\/([\w-]+)$/, screenExercise],
  [/^\/pompes$/, async () => render(await pushupsPlanPage(), { title: 'Mon programme de pompes', tab: 'accueil' })],
  [/^\/proteines$/, () => screenPlaceholder('Protéines', 'proteines', 4)],
  [/^\/suivi$/, screenTracking],
  [/^\/suivi\/seances\/(\d+)$/, screenSessionDetail],
  [/^\/reglages$/, screenSettings],
];

async function route() {
  const path = location.hash.replace(/^#/, '') || '/';
  if (path !== '/seance-en-cours') stopRunner();
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
main.addEventListener('click', async (e) => {
  const start = e.target.closest('[data-start]');
  const sessionJoker = e.target.closest('[data-session-joker]');
  if (sessionJoker) {
    const use = sessionJoker.dataset.sessionJoker === 'use';
    if (use && !confirm('Sauter la séance d\'aujourd\'hui avec un joker ? Le quota du mois est commun avec les pompes.')) return;
    try {
      await (use ? api.sessionJoker() : api.sessionJokerCancel());
      await route();
    } catch (err) {
      screenError(err);
    }
  } else if (start) {
    start.disabled = true;
    try {
      await begin(start.dataset.start);
    } catch (err) {
      screenError(err);
    }
  } else if (e.target.closest('[data-back]') && history.length > 1) {
    e.preventDefault();
    history.back();
  } else if (e.target.closest('[data-reload]')) {
    location.reload();
  }
});

window.addEventListener('hashchange', route);
route();
flush();

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch((err) => console.warn('Service worker non enregistré', err));
}
