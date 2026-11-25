// Pompes quotidiennes : carte de l'accueil (jauge, +X, jokers, encouragements) et calendrier du Suivi.
import { api } from './api.js';
import { enqueue, flush } from './queue.js';
import { html, uuid, longDate } from './ui.js';

let messages = null;      // data/encouragements.json
let current = null;       // dernier état connu
let note = '';            // encouragement affiché

const plural = (n, word) => `${n} ${word}${n > 1 ? 's' : ''}`;

/** Tire un message du moment, jamais le même deux fois de suite. */
function encourage(moment, st) {
  const list = messages?.[moment];
  if (!list?.length) return '';
  let last = null;
  try { last = localStorage.getItem('coach.lastMsg'); } catch { /* indisponible */ }
  const pool = list.length > 1 ? list.filter((m) => m !== last) : list;
  const pick = pool[Math.floor(Math.random() * pool.length)];
  try { localStorage.setItem('coach.lastMsg', pick); } catch { /* idem */ }
  const vars = {
    sets_done: st.sets_done, sets_target: st.sets_target, reps_per_set: st.reps_per_set, done: st.done, target: st.target,
    remaining_sets: st.sets_target - st.sets_done, streak: st.streak, record: st.record_streak, jokers: st.jokers_left,
  };
  return pick.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? '');
}

function momentFor(before, after) {
  if (after.status === 'reached' && before.status !== 'reached') {
    if (after.streak > before.record_streak && after.streak > 1) return 'new_record';
    if ([7, 14, 30, 60, 100, 200, 365].includes(after.streak)) return 'streak_milestone';
    return 'day_reached';
  }
  if (after.status === 'reached') return 'after_set';
  if (after.sets_target - after.sets_done === 1) return 'last_set';
  if (after.sets_done * 2 === after.sets_target) return 'halfway';
  return 'after_set';
}

/** Mise à jour locale quand le serveur est injoignable. */
function optimistic(st, count) {
  const done = st.done + count;
  const reached = done >= st.target;
  return {
    ...st,
    done,
    remaining: Math.max(0, st.target - done),
    sets_done: Math.min(st.sets_target, Math.floor(done / st.reps_per_set)),
    status: reached ? 'reached' : st.status,
    streak: reached && st.status === 'in_progress' ? st.streak + 1 : st.streak,
    logs: [{ id: 0, count, logged_at: new Date().toISOString() }, ...st.logs],
  };
}

export async function loadPushups() {
  messages ??= await api.encouragements().catch(() => null);
  current = await api.pushups().catch(() => current);
  return current;
}

export function pushupsCard(st = current) {
  if (!st) {
    return html`<section class="card" id="pushups"><h2>Pompes du jour</h2><p class="muted">Hors ligne : compteur indisponible pour l'instant.</p></section>`;
  }
  const pct = Math.min(100, Math.round((st.done / st.target) * 100));
  const reached = st.status === 'reached';
  const seen = `coach.jokerSeen.${st.yesterday?.day}`;
  let autoJoker = false;
  try {
    autoJoker = st.yesterday?.status === 'joker' && st.yesterday.joker_mode === 'auto' && !localStorage.getItem(seen);
    if (autoJoker) localStorage.setItem(seen, '1');
  } catch { /* indisponible */ }

  return html`<section class="card" id="pushups" aria-labelledby="h-pompes">
    <div class="block-head"><h2 id="h-pompes">Pompes du jour</h2>${st.light_day ? html`<span class="tag">Journée allégée</span>` : ''}</div>
    <p class="push-figures"><strong>${st.sets_done}</strong> / ${st.sets_target} séries<span class="muted"> · ${st.done} / ${st.target} pompes</span></p>
    <div class="gauge ${reached ? 'ok' : ''}" role="progressbar" aria-valuemin="0" aria-valuemax="${st.target}" aria-valuenow="${Math.min(st.done, st.target)}" aria-label="Pompes du jour"><span style="width:${pct}%"></span></div>
    ${reached ? html`<p class="ok-line">Journée bouclée</p>` : ''}
    ${st.status === 'joker' ? html`<p class="notice">Joker posé aujourd'hui : la série de jours continue. Si tu atteins l'objectif, il te sera rendu.</p>` : ''}
    ${autoJoker ? html`<p class="notice">${encourage('joker_used', st) || 'Un joker a couvert la journée d\'hier.'}</p>` : ''}
    ${note ? html`<p class="encourage" role="status">${note}</p>` : ''}
    ${!st.plan.defined ? html`<p class="notice">C'est toi qui fixes ton programme : combien de pompes par série, combien de séries. <a href="#/pompes">Régler mon programme</a></p>` : ''}
    ${feedbackBlock(st)}
    ${coachBlock(st)}
    <p><button class="btn block big" type="button" data-push="${st.reps_per_set}">+${st.reps_per_set}<span>une série</span></button></p>
    <details class="cues-box">
      <summary>Autre nombre</summary>
      <form class="inline-form" id="push-free">
        <label for="push-count" class="sr-only">Nombre de pompes</label>
        <input id="push-count" type="number" inputmode="numeric" min="1" max="1000" placeholder="Nombre de pompes" required>
        <button class="btn secondary" type="submit">Ajouter</button>
      </form>
    </details>
    <dl class="stats">
      <div><dt>Série</dt><dd>${plural(st.streak, 'jour')}</dd></div>
      <div><dt>Record</dt><dd>${plural(st.record_streak, 'jour')}</dd></div>
      <div><dt>Jokers</dt><dd>${st.jokers_left} / ${st.jokers_per_month}</dd></div>
    </dl>
    <div class="stack">
      ${st.can_joker_yesterday ? html`<button class="btn secondary block" type="button" data-joker="yesterday">Rattraper hier avec un joker</button>` : ''}
      ${st.can_joker_today ? html`<button class="btn secondary block" type="button" data-joker="today">Poser un joker pour aujourd'hui</button>` : ''}
      ${st.logs.length ? html`<button class="btn secondary block" type="button" data-push-undo>Annuler la dernière série (${st.logs[0].count})</button>` : ''}
    </div>
    <p class="error" role="alert" id="push-error"></p>
    <p class="card-link"><a href="#/pompes">Mon programme et les conseils du coach</a></p>
  </section>`;
}

const EFFORT = [[1, 'Facile', 'j\'en avais sous le coude'], [2, 'Correct', 'ça travaillait'], [3, 'Difficile', 'j\'ai fini juste']];
const PAINS = [['', 'Aucune'], ['epaule', 'Épaule'], ['coude', 'Coude'], ['poignet', 'Poignet'], ['dos', 'Dos'], ['autre', 'Autre']];
let draft = { effort: null, pain: '' }; // réponses en cours de saisie

/** Questions posées quand les séries du jour sont terminées. */
function feedbackBlock(st) {
  if (!st.feedback_needed) {
    if (!st.feedback) return '';
    const label = EFFORT.find((e) => e[0] === st.feedback.effort)?.[1];
    const pain = st.feedback.pain_area ? PAINS.find((p) => p[0] === st.feedback.pain_area)?.[1].toLowerCase() : null;
    return html`<p class="muted">Ressenti du jour : ${label}${pain ? ` · douleur : ${pain}` : ' · aucune douleur'}.</p>`;
  }
  return html`<div class="ask" id="push-feedback">
    <h3 id="fb-effort">Tes séries sont finies. C'était comment ?</h3>
    <div class="choices three" role="group" aria-labelledby="fb-effort">
      ${EFFORT.map(([v, label, hint]) => html`<button class="btn secondary choice" type="button" data-fb-effort="${v}" aria-pressed="${draft.effort === v}">${label}<span>${hint}</span></button>`)}
    </div>
    <h3 id="fb-pain">Une douleur quelque part ?</h3>
    <div class="choices" role="group" aria-labelledby="fb-pain">
      ${PAINS.map(([v, label]) => html`<button class="btn secondary choice" type="button" data-fb-pain="${v}" aria-pressed="${draft.pain === v}">${label}</button>`)}
    </div>
    <p><button class="btn block" type="button" data-fb-send ${draft.effort ? '' : 'disabled'}>Envoyer au coach</button></p>
  </div>`;
}

/** Conseil du coach, avec ses raisons. Les boutons n'apparaissent que s'il propose un changement. */
export function coachBlock(st, { always = false } = {}) {
  const c = st.coach;
  if (!c || (!c.proposal && !always && !st.feedback)) return '';
  const p = c.proposal;
  return html`<div class="coach">
    <p class="coach-title"><span class="tag">Coach</span> ${c.title}</p>
    <ul>${c.reasons.map((r) => html`<li>${r}</li>`)}</ul>
    ${p ? html`<p class="coach-change">${st.plan.sets} × ${st.plan.reps_per_set} → <strong>${p.sets} × ${p.reps_per_set}</strong>
        <span class="muted">(${st.plan.sets * st.plan.reps_per_set} → ${p.sets * p.reps_per_set} pompes par jour)</span></p>
      <div class="stack">
        <button class="btn block" type="button" data-coach="accept">Passer à ${p.sets} séries de ${p.reps_per_set}</button>
        <button class="btn secondary block" type="button" data-coach="decline">Garder ${st.plan.sets} séries de ${st.plan.reps_per_set}</button>
      </div>
      <p class="muted small-note">Le changement s'applique à partir de demain. <a href="#/pompes">Plus de pompes ou plus de séries : la différence</a></p>` : ''}
  </div>`;
}

function redraw() {
  const el = document.getElementById('pushups');
  if (el) el.outerHTML = pushupsCard().value;
}

async function add(count) {
  if (!current) return;
  const before = current;
  await enqueue({ type: 'pushup', client_uuid: uuid(), count, logged_at: new Date().toISOString() });
  current = optimistic(before, count);
  note = encourage(momentFor(before, current), current);
  redraw();
  // Le serveur fait foi dès qu'il répond (série de jours, jokers).
  await Promise.race([flush(), new Promise((r) => setTimeout(r, 3000))]);
  const fresh = await api.pushups().catch(() => null);
  if (fresh && fresh.done >= current.done) {
    current = fresh;
    redraw();
  }
}

function fail(err) {
  const el = document.getElementById('push-error');
  if (el) el.textContent = err.message;
}

// Actions de la carte (écouteur délégué, posé une fois).
const main = document.getElementById('main');
main.addEventListener('click', async (e) => {
  const push = e.target.closest('[data-push]');
  const joker = e.target.closest('[data-joker]');
  const undo = e.target.closest('[data-push-undo]');
  try {
    if (push) {
      await add(Number(push.dataset.push));
    } else if (joker) {
      const which = joker.dataset.joker;
      const text = which === 'yesterday' ? 'Utiliser un joker pour la journée d\'hier ?' : 'Poser un joker pour aujourd\'hui ? La série de jours continue même sans atteindre l\'objectif.';
      if (!confirm(text)) return;
      await flush();
      current = await api.pushupJoker(which);
      note = '';
      redraw();
    } else if (undo) {
      await flush();
      current = await api.pushupUndo();
      note = '';
      redraw();
    } else if (e.target.closest('[data-fb-effort]')) {
      draft.effort = Number(e.target.closest('[data-fb-effort]').dataset.fbEffort);
      redraw();
    } else if (e.target.closest('[data-fb-pain]')) {
      draft.pain = e.target.closest('[data-fb-pain]').dataset.fbPain;
      redraw();
    } else if (e.target.closest('[data-fb-send]') && draft.effort) {
      await flush();
      current = await api.pushupFeedback(draft.effort, draft.pain || null);
      draft = { effort: null, pain: '' };
      note = '';
      redraw();
    } else if (e.target.closest('[data-coach]')) {
      current = await api.pushupCoach(e.target.closest('[data-coach]').dataset.coach);
      note = '';
      if (document.getElementById('plan-page')) location.reload();
      else redraw();
    }
  } catch (err) {
    fail(err);
  }
});
main.addEventListener('submit', async (e) => {
  if (e.target.id !== 'push-free') return;
  e.preventDefault();
  const n = Number(document.getElementById('push-count').value);
  if (Number.isInteger(n) && n >= 1 && n <= 1000) await add(n).catch(fail);
});

// --- Suivi : calendrier des 5 dernières semaines -----------------------------------

const MARK = { reached: ['✓', 'Atteint'], joker: ['J', 'Joker'], missed: ['✗', 'Manqué'], in_progress: ['…', 'En cours'] };

export async function pushupsTracking() {
  const [st, days] = await Promise.all([loadPushups(), api.pushupHistory().catch(() => null)]);
  if (!st || !days) return html`<section class="card"><h2>Pompes</h2><p class="muted">Hors ligne : suivi indisponible.</p></section>`;
  const ordered = [...days].reverse();
  return html`<section class="card">
    <h2>Pompes</h2>
    <dl class="stats pairs">
      <div><dt>Ce mois-ci</dt><dd>${st.month.reached} / ${plural(st.month.days, 'jour')}</dd></div>
      <div><dt>Série de jours</dt><dd>${plural(st.streak, 'jour')}</dd></div>
      <div><dt>Record</dt><dd>${plural(st.record_streak, 'jour')}</dd></div>
      <div><dt>Jokers restants</dt><dd>${st.jokers_left} / ${st.jokers_per_month}</dd></div>
    </dl>
    <ol class="cal" aria-label="Journées de pompes">
      ${ordered.map((d) => html`<li class="cal-${d.status}" title="${longDate(d.day)} : ${MARK[d.status][1]}, ${d.done} / ${d.target}">
        <span class="cal-day">${Number(d.day.slice(8))}</span>
        <span class="cal-mark" aria-hidden="true">${MARK[d.status][0]}</span>
        <span class="sr-only">${longDate(d.day)} : ${MARK[d.status][1]}, ${d.done} sur ${d.target}</span>
      </li>`)}
    </ol>
    <p class="muted legend">✓ atteint · J joker · ✗ manqué · … en cours</p>
  </section>`;
}

// --- Page « Mon programme de pompes » ---------------------------------------------------

const GOALS = [
  ['endurance', 'Enchaîner plus de pompes d\'affilée', 'Le coach allonge les séries.'],
  ['volume', 'Faire plus de pompes dans la journée', 'Le coach ajoute des séries.'],
  ['equilibre', 'Les deux, en alternance', 'Une fois les séries plus longues, une fois une série de plus.'],
];
const KINDS = { manual: 'Réglé par toi', coach_reps: 'Conseil du coach : séries plus longues', coach_sets: 'Conseil du coach : une série de plus', coach_decrease: 'Conseil du coach : allègement' };

export async function pushupsPlanPage() {
  const [st, history] = await Promise.all([loadPushups(), api.pushupPlanHistory().catch(() => [])]);
  if (!st) return html`<h1>Mon programme de pompes</h1><section class="card"><p class="muted">Hors ligne : réglage indisponible.</p></section>`;
  const p = st.plan;
  return html`<div id="plan-page">
    <a class="back" href="#/">‹ Accueil</a>
    <h1>Mon programme de pompes</h1>
    <form class="card" id="plan-form">
      <h2>Ce que je fais chaque jour</h2>
      ${stepper('reps', 'Pompes par série', p.reps_per_set, 1, 200)}
      ${stepper('sets', 'Séries par jour', p.sets, 1, 30)}
      <p class="plan-total">Soit <strong id="plan-total">${p.reps_per_set * p.sets}</strong> pompes par jour.</p>
      <label class="check"><input type="checkbox" id="plan-light" ${p.light_day_enabled ? 'checked' : ''}> Journée allégée les jours de séance (moitié des séries)</label>
      <h2 id="goal-h">Mon objectif</h2>
      <div class="goals" role="radiogroup" aria-labelledby="goal-h">
        ${GOALS.map(([v, label, hint]) => html`<label class="goal"><input type="radio" name="goal" value="${v}" ${st.goal === v ? 'checked' : ''}><span><strong>${label}</strong><br><span class="muted">${hint}</span></span></label>`)}
      </div>
      <p><button class="btn block" type="submit">Enregistrer mon programme</button></p>
      <p class="muted small-note">Si tu as déjà fait des séries aujourd'hui, le changement s'applique à partir de demain.</p>
      <p class="error" role="alert" id="plan-error"></p>
      <p class="flash-msg" role="status" id="plan-ok" hidden>Programme enregistré.</p>
    </form>
    <section class="card" id="plan-coach">
      <h2>Le conseil du coach</h2>
      ${coachBlock(st, { always: true })}
      <p class="muted small-note">Le coach te demande ton ressenti chaque fois que tu termines tes séries du jour. Il lui faut 3 journées pour se prononcer, et il ne change jamais rien sans ton accord.</p>
    </section>
    <section class="card">
      <h2>Plus de pompes par série, ou plus de séries ?</h2>
      <h3>Plus de pompes par série</h3>
      <ul>
        <li>Chaque série va plus près de la fatigue : c'est ce qui fait progresser le nombre de pompes que tu peux enchaîner d'un coup.</li>
        <li>Plus exigeant pour les épaules, les coudes et les poignets, et la technique se dégrade plus vite en fin de série.</li>
        <li>Ne prend pas plus de moments dans la journée.</li>
      </ul>
      <h3>Plus de séries</h3>
      <ul>
        <li>Chaque série reste aussi facile qu'avant : tu ajoutes du volume (plus de pompes au total, plus de dépense) sans durcir l'effort.</li>
        <li>Plus doux pour les articulations, et la technique reste propre.</li>
        <li>Demande un moment de plus dans la journée.</li>
      </ul>
      <h3>Comment le coach choisit</h3>
      <ul>
        <li>3 journées faciles de suite et sans douleur : il propose d'augmenter, selon ton objectif.</li>
        <li>Une journée difficile : il attend. Trois de suite : il propose de baisser d'un cran.</li>
        <li>Une douleur : aucune augmentation. Deux douleurs rapprochées : il allège les séries.</li>
        <li>Deux journées manquées dans la semaine : il propose une série de moins, la régularité d'abord.</li>
      </ul>
    </section>
    ${history.length ? html`<section class="card"><h2>Historique du programme</h2>
      <ul class="list">${history.map((h) => html`<li><span class="row"><span>${longDate(h.day)}<br><span class="muted">${KINDS[h.kind] ?? h.kind}</span></span><span>${h.sets} × ${h.reps_per_set}</span></span></li>`)}</ul></section>` : ''}
  </div>`;
}

function stepper(id, label, value, min, max) {
  return html`<div class="field-stepper">
    <span id="plan-${id}-l">${label}</span>
    <div class="stepper" role="group" aria-labelledby="plan-${id}-l">
      <button class="btn secondary round" type="button" data-plan-step="${id}" data-delta="-1" aria-label="${label} : moins">−</button>
      <input class="stepper-input" id="plan-${id}" type="number" inputmode="numeric" min="${min}" max="${max}" value="${value}" aria-labelledby="plan-${id}-l" required>
      <button class="btn secondary round" type="button" data-plan-step="${id}" data-delta="1" aria-label="${label} : plus">+</button>
    </div>
  </div>`;
}

function planTotal() {
  const el = document.getElementById('plan-total');
  if (el) el.textContent = String((Number(document.getElementById('plan-reps').value) || 0) * (Number(document.getElementById('plan-sets').value) || 0));
}

main.addEventListener('click', (e) => {
  const step = e.target.closest('[data-plan-step]');
  if (!step) return;
  const input = document.getElementById(`plan-${step.dataset.planStep}`);
  const next = (Number(input.value) || 0) + Number(step.dataset.delta);
  input.value = String(Math.min(Number(input.max), Math.max(Number(input.min), next)));
  planTotal();
});
main.addEventListener('input', (e) => { if (e.target.closest('#plan-form')) planTotal(); });
main.addEventListener('submit', async (e) => {
  if (e.target.id !== 'plan-form') return;
  e.preventDefault();
  const err = document.getElementById('plan-error');
  err.textContent = '';
  try {
    current = await api.pushupPlan({
      reps_per_set: Number(document.getElementById('plan-reps').value),
      sets: Number(document.getElementById('plan-sets').value),
      light_day_enabled: document.getElementById('plan-light').checked,
      goal: e.target.querySelector('input[name=goal]:checked')?.value,
    });
    document.getElementById('plan-ok').hidden = false;
  } catch (ex) {
    err.textContent = ex.message;
  }
});
