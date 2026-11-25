// Mode séance guidé : déroulé pas à pas, chronos, repos, saisie des charges et des répétitions.
// L'état de la séance est gardé dans localStorage (reprise possible pendant 12 h) ; chaque saisie part
// dans la file d'attente IndexedDB, envoyée au serveur dès que le réseau le permet.
import { enqueue, flush } from './queue.js';
import { html, render, clock, exerciseVisual, formatSeconds, num, localDate, uuid } from './ui.js';

const KEY = 'coach.active';
export const RESUME_MS = 12 * 3600 * 1000;
const EFFORT = ['Facile', 'Modéré', 'Soutenu', 'Difficile', 'Épuisant'];
const PAIN_AREAS = [['epaule', 'Épaule'], ['coude', 'Coude'], ['dos', 'Dos'], ['genou', 'Genou'], ['autre', 'Autre']];

// --- État persistant -------------------------------------------------------------

export function loadActive() {
  try { return JSON.parse(localStorage.getItem(KEY)); } catch { return null; }
}
function save(state) {
  state.lastActive = Date.now();
  try { localStorage.setItem(KEY, JSON.stringify(state)); } catch { /* stockage indisponible */ }
}
function clearActive() {
  try { localStorage.removeItem(KEY); } catch { /* idem */ }
}

/** Séance commencée il y a plus de 12 h : on la clôt avec ce qui a été fait. */
export async function closeStale() {
  const s = loadActive();
  if (!s || Date.now() - s.startedAt < RESUME_MS) return false;
  await enqueue({
    type: 'session_end',
    client_uuid: uuid(),
    session_uuid: s.uuid,
    ended_at: new Date(s.endedAt ?? s.lastActive ?? s.startedAt).toISOString(),
    status: s.log.length ? 'done' : 'skipped',
    rounds_done: s.roundsDone ?? null,
  });
  clearActive();
  return true;
}

// --- Construction des étapes -------------------------------------------------------

/** Transforme une séance (blocs → exercices) en liste d'étapes : effort, repos, AMRAP. */
export function buildSteps(template, settings) {
  const transition = settings.circuit_transition_s ?? 15;
  const steps = [];
  template.blocks.forEach((block, bi) => {
    if (bi > 0 && transition > 0) steps.push({ kind: 'rest', label: 'Bloc suivant', seconds: transition, block });
    if (block.type === 'amrap') {
      steps.push({ kind: 'amrap', block, seconds: block.duration_s });
      return;
    }
    const rounds = block.rounds || 1;
    const continuous = ['warmup', 'cooldown', 'interval'].includes(block.type);
    for (let r = 1; r <= rounds; r++) {
      block.items.forEach((item, ii) => {
        steps.push(...workSteps(item, block, r, rounds));
        if (!continuous && ii < block.items.length - 1 && transition > 0) {
          steps.push({ kind: 'rest', label: 'Transition', seconds: transition, block, round: r, rounds });
        }
      });
      if (r < rounds && block.rest_between_rounds_s) {
        steps.push({ kind: 'rest', label: 'Repos entre les tours', seconds: block.rest_between_rounds_s, block, round: r, rounds });
      }
    }
  });
  steps.forEach((s, i) => {
    if (s.kind === 'rest') s.next = steps.slice(i + 1).find((x) => x.kind !== 'rest') ?? null;
  });
  return steps;
}

function workSteps(item, block, round, rounds) {
  const base = { kind: 'work', block, item, round, rounds };
  if (block.type === 'interval') {
    return [{ ...base, mode: 'time', seconds: item.target_seconds, intensity: /rapide/i.test(item.load_label ?? '') ? 'fast' : 'slow' }];
  }
  if (item.target_reps != null) return [{ ...base, mode: 'reps', reps: item.target_reps }];
  if (item.per_side) {
    return [
      { ...base, mode: 'time', seconds: item.target_seconds, side: 'côté gauche' },
      { ...base, mode: 'time', seconds: item.target_seconds, side: 'côté droit' },
    ];
  }
  return [{ ...base, mode: 'time', seconds: item.target_seconds }];
}

/** Réglage de charge proposé pendant l'effort (barre : ±2,5 kg ; kettlebell : 10 ↔ 12 kg). */
export function loadControl(item) {
  const label = item.load_label ?? '';
  if (/barre|charge légère/i.test(label)) return { step: 2.5, min: 0, max: 150, start: 10 };
  if (/kettlebell/i.test(label)) return { step: 2, min: 8, max: 24, start: 12 };
  return null;
}

// --- Démarrage --------------------------------------------------------------------

export async function startWorkout(template, loads) {
  const state = {
    v: 1,
    uuid: uuid(),
    code: template.code,
    plannedDate: localDate(),
    startedAt: Date.now(),
    index: 0,
    deadline: null,
    paused: false,
    remaining: null,
    loads: {},
    reps: {},
    log: [],
    amrap: null,
    roundsDone: null,
  };
  for (const block of template.blocks) {
    for (const item of block.items) {
      const ctl = loadControl(item);
      const known = loads[item.id] ?? item.load_kg;
      state.loads[item.id] = known ?? (ctl ? ctl.start : null);
    }
  }
  unlockAudio();
  save(state);
  await enqueue({
    type: 'session_start',
    client_uuid: state.uuid,
    template_code: template.code,
    planned_date: state.plannedDate,
    started_at: new Date(state.startedAt).toISOString(),
  });
  location.hash = '#/seance-en-cours';
}

// --- Son, vibration, écran allumé ----------------------------------------------------

let audioCtx = null;
export function unlockAudio() {
  try {
    audioCtx ??= new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
  } catch { /* pas de son disponible */ }
}

let ctx = null; // séance affichée : { state, template, steps, settings, exercises, timer, wakeLock, beeped }

function beep(freq = 880, duration = 0.12) {
  if (!audioCtx || !ctx?.settings.sound_enabled) return;
  const t = audioCtx.currentTime;
  const osc = audioCtx.createOscillator();
  const gain = audioCtx.createGain();
  osc.type = 'sine';
  osc.frequency.value = freq;
  gain.gain.setValueAtTime(0.35, t);
  gain.gain.exponentialRampToValueAtTime(0.0001, t + duration);
  osc.connect(gain).connect(audioCtx.destination);
  osc.start(t);
  osc.stop(t + duration + 0.02);
}

function signalEnd() {
  beep(660, 0.45);
  if (ctx?.settings.vibration_enabled && navigator.vibrate) navigator.vibrate([200, 80, 200]);
  document.body.classList.add('flash');
  setTimeout(() => document.body.classList.remove('flash'), 350);
}

function signalCountdown() {
  beep(990, 0.1);
  if (ctx?.settings.vibration_enabled && navigator.vibrate) navigator.vibrate(60);
}

function signalPhase(intensity) {
  if (intensity === 'fast') {
    beep(1100, 0.12);
    setTimeout(() => beep(1100, 0.12), 180);
  } else {
    beep(520, 0.3);
  }
}

async function acquireWakeLock() {
  if (!ctx || !('wakeLock' in navigator) || ctx.wakeLock) return;
  try {
    ctx.wakeLock = await navigator.wakeLock.request('screen');
    ctx.wakeLock.addEventListener('release', () => { if (ctx) ctx.wakeLock = null; });
  } catch { /* refus ou non pris en charge : signalé à l'écran */ }
}

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && ctx) acquireWakeLock();
});

// --- Écran de séance ------------------------------------------------------------------

/**
 * @param data { program, exercises, me }
 */
export function screenActive(data) {
  const state = loadActive();
  if (!state || state.phase === 'end') {
    location.hash = state ? '#/fin-de-seance' : '#/';
    return;
  }
  const template = data.program.find((t) => t.code === state.code);
  if (!template) {
    clearActive();
    location.hash = '#/';
    return;
  }
  stopRunner();
  ctx = {
    state,
    template,
    steps: buildSteps(template, data.me.settings),
    settings: data.me.settings,
    exercises: Object.fromEntries(data.exercises.map((e) => [e.slug, e])),
    timer: null,
    wakeLock: null,
    beeped: new Set(),
  };
  // Réouverture (app fermée, rechargement) : on repart en pause pour ne rien sauter.
  if (!state.paused && state.deadline) pause();
  if (state.deadline == null && !state.paused) enterStep(state.index);
  else renderStep();
  acquireWakeLock();
  ctx.timer = setInterval(tick, 250);
}

export function stopRunner() {
  if (!ctx) return;
  clearInterval(ctx.timer);
  ctx.wakeLock?.release().catch(() => {});
  ctx = null;
  document.body.classList.remove('phase-fast', 'phase-slow', 'phase-rest');
}

function currentStep() {
  return ctx.steps[ctx.state.index];
}

function enterStep(index) {
  const s = ctx.state;
  s.index = index;
  ctx.beeped = new Set();
  if (index >= ctx.steps.length) {
    finish();
    return;
  }
  const step = currentStep();
  s.paused = false;
  s.remaining = null;
  s.deadline = step.kind === 'rest' || step.mode === 'time' || step.kind === 'amrap' ? Date.now() + step.seconds * 1000 : null;
  if (step.kind === 'amrap') {
    s.amrap = { round: 1, itemIndex: 0, itemDeadline: null, itemRemaining: null };
    startAmrapItem();
  }
  if (step.intensity) signalPhase(step.intensity);
  save(s);
  renderStep();
}

function startAmrapItem() {
  const step = currentStep();
  const a = ctx.state.amrap;
  const item = step.block.items[a.itemIndex];
  a.itemDeadline = item.target_reps == null ? Date.now() + item.target_seconds * 1000 : null;
  a.itemRemaining = null;
}

function tick() {
  if (!ctx) return;
  const s = ctx.state;
  if (s.paused || s.deadline == null) return;
  const step = currentStep();
  const now = Date.now();
  const remaining = s.deadline - now;
  setText('timer', clock(remaining));

  if (step.kind === 'amrap') {
    const a = s.amrap;
    if (a.itemDeadline != null) {
      const itemRemaining = a.itemDeadline - now;
      setText('item-timer', clock(itemRemaining));
      countdownBeeps('item', itemRemaining);
      if (itemRemaining <= 0) {
        signalEnd();
        amrapDone(step.block.items[a.itemIndex].target_seconds);
        return;
      }
    }
    if (remaining <= 0) {
      signalEnd();
      s.roundsDone = a.round - 1;
      enterStep(s.index + 1);
    }
    return;
  }

  countdownBeeps('step', remaining);
  if (remaining <= 0) {
    signalEnd();
    if (step.kind === 'work') record(step, { seconds: step.seconds });
    enterStep(s.index + 1);
  }
}

function countdownBeeps(scope, remaining) {
  const sec = Math.ceil(remaining / 1000);
  if (sec >= 1 && sec <= 3 && !ctx.beeped.has(scope + sec)) {
    ctx.beeped.add(scope + sec);
    signalCountdown();
  }
}

function setText(id, text) {
  const el = document.getElementById(id);
  if (el && el.textContent !== text) el.textContent = text;
}

// --- Saisies -------------------------------------------------------------------------

function record(step, { reps = null, seconds = null }, item = step.item, round = step.round) {
  const s = ctx.state;
  const load = s.loads[item.id] ?? null;
  const event = {
    type: 'set',
    client_uuid: uuid(),
    session_uuid: s.uuid,
    template_item_id: item.id,
    round: round ?? 1,
    reps_done: reps,
    seconds_done: seconds,
    load_kg: load,
    logged_at: new Date().toISOString(),
  };
  s.log.push({ itemId: item.id, slug: item.exercise.slug, name: item.exercise.name, round: event.round, reps, seconds, load });
  save(s);
  enqueue(event).catch((err) => console.error('Saisie non enregistrée', err));
}

function repsKey(item) {
  return String(item.id);
}

function amrapDone(seconds = null) {
  const s = ctx.state;
  const step = currentStep();
  const a = s.amrap;
  const item = step.block.items[a.itemIndex];
  const reps = item.target_reps != null ? (s.reps[repsKey(item)] ?? item.target_reps) : null;
  record(step, { reps, seconds }, item, a.round);
  delete s.reps[repsKey(item)];
  a.itemIndex += 1;
  if (a.itemIndex >= step.block.items.length) {
    a.itemIndex = 0;
    a.round += 1;
    beep(1320, 0.15);
  }
  startAmrapItem();
  ctx.beeped = new Set();
  save(s);
  renderStep();
}

function pause() {
  const s = ctx.state;
  if (s.paused) return;
  s.paused = true;
  s.remaining = s.deadline != null ? Math.max(0, s.deadline - Date.now()) : null;
  if (s.amrap?.itemDeadline != null) s.amrap.itemRemaining = Math.max(0, s.amrap.itemDeadline - Date.now());
  save(s);
  renderStep();
}

function resume() {
  const s = ctx.state;
  unlockAudio();
  acquireWakeLock();
  s.paused = false;
  if (s.remaining != null) s.deadline = Date.now() + s.remaining;
  if (s.amrap?.itemRemaining != null) s.amrap.itemDeadline = Date.now() + s.amrap.itemRemaining;
  s.remaining = null;
  if (s.amrap) s.amrap.itemRemaining = null;
  save(s);
  renderStep();
}

function finish() {
  const s = ctx.state;
  s.phase = 'end';
  s.endedAt = Date.now();
  s.deadline = null;
  save(s);
  stopRunner();
  location.hash = '#/fin-de-seance';
}

// --- Rendu ---------------------------------------------------------------------------

function cuesBlock(slug) {
  const ex = ctx.exercises[slug];
  if (!ex) return '';
  return html`<details class="cues-box"><summary>Consignes</summary>
    <ol class="cues">${ex.cues.map((c) => html`<li>${c}</li>`)}</ol>
    <p class="muted">À éviter : ${ex.mistake ?? '—'}</p></details>`;
}

function loadBlock(item) {
  const ctl = loadControl(item);
  const load = ctx.state.loads[item.id];
  if (ctl) {
    return html`<div class="stepper" role="group" aria-label="Charge">
      <button class="btn secondary round" type="button" data-action="load-minus" aria-label="Moins de charge">−</button>
      <span class="stepper-value"><strong id="load-value">${num(load)}</strong> kg</span>
      <button class="btn secondary round" type="button" data-action="load-plus" aria-label="Plus de charge">+</button>
    </div>`;
  }
  return item.load_label ? html`<p class="load-label">${item.load_label}</p>` : '';
}

function repsBlock(item, target) {
  const reps = ctx.state.reps[repsKey(item)] ?? target;
  return html`<div class="stepper" role="group" aria-label="Répétitions faites">
    <button class="btn secondary round" type="button" data-action="reps-minus" aria-label="Une répétition de moins">−</button>
    <span class="stepper-value"><strong id="reps-value">${reps}</strong> rép.${item.per_side ? ' par côté' : ''}</span>
    <button class="btn secondary round" type="button" data-action="reps-plus" aria-label="Une répétition de plus">+</button>
  </div>`;
}

function visual(slug, name) {
  return exerciseVisual(ctx.exercises[slug], name, 'small');
}

function renderStep() {
  const s = ctx.state;
  const step = currentStep();
  if (!step) return;
  const total = ctx.steps.length;
  const pct = Math.round((s.index / total) * 100);
  const remainingMs = s.paused ? (s.remaining ?? 0) : (s.deadline ? s.deadline - Date.now() : 0);

  document.body.classList.toggle('phase-rest', step.kind === 'rest');
  document.body.classList.toggle('phase-fast', step.intensity === 'fast');
  document.body.classList.toggle('phase-slow', step.intensity === 'slow');

  const top = html`
    <div class="run-top">
      <span>Séance ${s.code} · ${step.block.name}${step.rounds > 1 ? ` · tour ${step.round}/${step.rounds}` : ''}</span>
      <span class="run-actions">
        ${s.paused
          ? html`<button class="btn small" type="button" data-action="resume">Reprendre</button>`
          : html`<button class="btn secondary small" type="button" data-action="pause">Pause</button>`}
        <button class="btn secondary small" type="button" data-action="stop">Arrêter</button>
      </span>
    </div>
    <div class="gauge thin" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}" aria-label="Avancement de la séance"><span style="width:${pct}%"></span></div>
    ${!ctx.wakeLock && !('wakeLock' in navigator) ? html`<p class="muted small-note">Écran : ce téléphone ne peut pas le garder allumé, pense à désactiver la mise en veille.</p>` : ''}`;

  let body;
  if (step.kind === 'rest') {
    const n = step.next;
    body = html`
      <p class="phase-label">Repos</p>
      <p class="big-timer" id="timer" role="timer">${clock(remainingMs)}</p>
      <p class="muted">${step.label}</p>
      ${n ? html`<div class="card next"><p class="muted">Ensuite</p>
        <p class="next-name">${n.kind === 'amrap' ? n.block.name : n.item.exercise.name}${n.side ? ` (${n.side})` : ''}</p>
        <p>${n.kind === 'amrap' ? `${formatSeconds(n.seconds)}, max de tours` : n.mode === 'reps' ? `${n.reps} rép.${n.item.per_side ? ' par côté' : ''}` : formatSeconds(n.seconds)}
          ${n.item && ctx.state.loads[n.item.id] != null && loadControl(n.item) ? ` · ${num(ctx.state.loads[n.item.id])} kg` : ''}</p></div>` : ''}
      <button class="btn block huge" type="button" data-action="skip">Passer le repos</button>`;
  } else if (step.kind === 'amrap') {
    const a = s.amrap;
    const item = step.block.items[a.itemIndex];
    const itemMs = s.paused ? (a.itemRemaining ?? 0) : (a.itemDeadline ? a.itemDeadline - Date.now() : 0);
    body = html`
      <div class="amrap-head">
        <div><p class="muted">Temps restant</p><p class="mid-timer" id="timer" role="timer">${clock(remainingMs)}</p></div>
        <div><p class="muted">Tours complets</p><p class="mid-timer">${a.round - 1}</p></div>
      </div>
      <h1 class="ex-name">${item.exercise.name}</h1>
      ${item.target_reps != null
        ? html`<p class="target">Objectif : ${item.target_reps} rép.${item.per_side ? ' par côté' : ''}</p>${loadBlock(item)}${repsBlock(item, item.target_reps)}`
        : html`<p class="big-timer" id="item-timer" role="timer">${clock(itemMs)}</p>`}
      ${cuesBlock(item.exercise.slug)}
      <ol class="amrap-list">${step.block.items.map((it, i) => html`<li class="${i === a.itemIndex ? 'current' : ''}">${it.exercise.name}</li>`)}</ol>
      <button class="btn block huge" type="button" data-action="amrap-done">${item.target_reps != null ? 'Fait' : 'Passer'}</button>`;
  } else {
    const item = step.item;
    const intensity = step.intensity === 'fast'
      ? html`<p class="phase-label">RAPIDE · ${num(ctx.settings.interval_fast_kmh)} km/h</p>`
      : step.intensity === 'slow' ? html`<p class="phase-label">LENTE · ${num(ctx.settings.interval_slow_kmh)} km/h</p>` : '';
    body = html`
      ${intensity}
      ${step.intensity ? '' : visual(item.exercise.slug, item.exercise.name)}
      <h1 class="ex-name">${item.exercise.name}${step.side ? html` <span class="muted">(${step.side})</span>` : ''}</h1>
      ${step.mode === 'reps'
        ? html`<p class="target">Objectif : ${step.reps} rép.${item.per_side ? ' par côté' : ''}</p>${loadBlock(item)}${repsBlock(item, step.reps)}`
        : html`<p class="big-timer" id="timer" role="timer">${clock(remainingMs)}</p>${step.intensity ? '' : loadBlock(item)}`}
      ${step.intensity ? html`<p class="muted">Pente fixe 10 à 15 %, seule la vitesse change.</p>` : cuesBlock(item.exercise.slug)}
      <button class="btn block huge" type="button" data-action="${step.mode === 'reps' ? 'done' : 'skip'}">${step.mode === 'reps' ? 'Fait' : 'Passer'}</button>`;
  }

  render(html`<div class="run">${top}<div class="run-body">${body}</div>
    <p class="muted step-count">Étape ${s.index + 1} / ${total}</p></div>`, { title: `Séance ${s.code}`, fullscreen: true });
}

// Actions (un seul écouteur, délégué).
document.getElementById('main').addEventListener('click', (e) => {
  const btn = e.target.closest('[data-action]');
  if (!btn || !ctx) return;
  const s = ctx.state;
  const step = currentStep();
  const action = btn.dataset.action;
  const item = step?.kind === 'amrap' ? step.block.items[s.amrap.itemIndex] : step?.item;

  switch (action) {
    case 'pause': pause(); break;
    case 'resume': resume(); break;
    case 'stop':
      if (confirm('Arrêter la séance ? Ce qui est fait reste enregistré.')) finish();
      break;
    case 'done': {
      unlockAudio();
      record(step, { reps: s.reps[repsKey(item)] ?? step.reps });
      delete s.reps[repsKey(item)];
      enterStep(s.index + 1);
      break;
    }
    case 'skip': {
      if (step.kind === 'work') {
        const left = s.paused ? (s.remaining ?? 0) : Math.max(0, s.deadline - Date.now());
        record(step, { seconds: Math.max(0, Math.round(step.seconds - left / 1000)) });
      }
      enterStep(s.index + 1);
      break;
    }
    case 'amrap-done': {
      if (s.paused) resume();
      const left = s.amrap.itemDeadline != null ? Math.max(0, s.amrap.itemDeadline - Date.now()) : 0;
      amrapDone(item.target_reps == null ? Math.round(item.target_seconds - left / 1000) : null);
      break;
    }
    case 'reps-minus':
    case 'reps-plus': {
      const target = step.kind === 'amrap' ? item.target_reps : step.reps;
      const cur = s.reps[repsKey(item)] ?? target;
      s.reps[repsKey(item)] = Math.max(0, cur + (action === 'reps-plus' ? 1 : -1));
      setText('reps-value', String(s.reps[repsKey(item)]));
      save(s);
      break;
    }
    case 'load-minus':
    case 'load-plus': {
      const ctl = loadControl(item);
      const cur = s.loads[item.id] ?? ctl.start;
      const next = Math.min(ctl.max, Math.max(ctl.min, cur + (action === 'load-plus' ? ctl.step : -ctl.step)));
      s.loads[item.id] = Math.round(next * 100) / 100;
      setText('load-value', num(s.loads[item.id]));
      save(s);
      break;
    }
    default:
  }
});

// --- Fin de séance -------------------------------------------------------------------

export function screenEnd() {
  stopRunner();
  const s = loadActive();
  if (!s) {
    location.hash = '#/';
    return;
  }
  const minutes = Math.max(1, Math.round(((s.endedAt ?? Date.now()) - s.startedAt) / 60000));
  const loads = new Map();
  for (const l of s.log) if (l.load != null) loads.set(l.name, l.load);
  const answer = { effort: null, pain: false, area: null };

  render(html`
    <h1>Séance ${s.code} terminée</h1>
    <section class="card">
      <h2>Résumé</h2>
      <p>Durée : ${minutes} min · ${s.log.length} séries enregistrées${s.roundsDone != null ? ` · ${s.roundsDone} tours en AMRAP` : ''}</p>
      ${loads.size ? html`<ul class="list">${[...loads].map(([n, kg]) => html`<li><span class="row"><span>${n}</span><span>${num(kg)} kg</span></span></li>`)}</ul>` : ''}
    </section>
    <section class="card">
      <h2 id="effort-h">Ressenti</h2>
      <div class="choices" role="group" aria-labelledby="effort-h">
        ${EFFORT.map((label, i) => html`<button class="btn secondary choice" type="button" data-effort="${i + 1}" aria-pressed="false">${i + 1}<span>${label}</span></button>`)}
      </div>
    </section>
    <section class="card">
      <h2 id="pain-h">Douleur</h2>
      <div class="choices" role="group" aria-labelledby="pain-h">
        <button class="btn secondary choice" type="button" data-pain="0" aria-pressed="true">Aucune</button>
        ${PAIN_AREAS.map(([v, label]) => html`<button class="btn secondary choice" type="button" data-pain="${v}" aria-pressed="false">${label}</button>`)}
      </div>
      <p class="muted">Une douleur bloque l'augmentation de charge à la séance suivante.</p>
    </section>
    <section class="card">
      <label for="note">Note (facultatif)</label>
      <textarea id="note" rows="3" maxlength="2000"></textarea>
    </section>
    <p><button class="btn block huge" type="button" id="save-end">Enregistrer</button></p>
    <p class="error" role="alert" id="end-error"></p>`, { title: 'Fin de séance', showTabs: false });

  const main = document.getElementById('main');
  const press = (attr, value) => {
    for (const b of main.querySelectorAll(`[data-${attr}]`)) b.setAttribute('aria-pressed', String(b.dataset[attr] === value));
  };
  main.querySelectorAll('[data-effort]').forEach((b) => b.addEventListener('click', () => {
    answer.effort = Number(b.dataset.effort);
    press('effort', b.dataset.effort);
  }));
  main.querySelectorAll('[data-pain]').forEach((b) => b.addEventListener('click', () => {
    answer.pain = b.dataset.pain !== '0';
    answer.area = answer.pain ? b.dataset.pain : null;
    press('pain', b.dataset.pain);
  }));
  document.getElementById('save-end').addEventListener('click', async (e) => {
    e.currentTarget.disabled = true;
    try {
      await enqueue({
        type: 'session_end',
        client_uuid: uuid(),
        session_uuid: s.uuid,
        ended_at: new Date(s.endedAt ?? Date.now()).toISOString(),
        status: s.log.length ? 'done' : 'skipped',
        rounds_done: s.roundsDone ?? null,
        effort_1_5: answer.effort,
        pain_flag: answer.pain,
        pain_area: answer.area,
        note: document.getElementById('note').value,
      });
      clearActive();
      // On attend l'envoi (4 s au plus) pour que l'accueil soit à jour ; hors ligne, la file s'en chargera plus tard.
      await Promise.race([flush(), new Promise((r) => setTimeout(r, 4000))]);
      sessionStorage.setItem('coach.flash', 'Séance enregistrée.');
      location.hash = '#/';
    } catch (err) {
      e.currentTarget.disabled = false;
      document.getElementById('end-error').textContent = `Enregistrement impossible : ${err.message}`;
    }
  });
}
