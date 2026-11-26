// Suivi : graphes (séances par semaine, pompes par jour, poids) et pesée hebdomadaire.
import { api } from './api.js';
import { barChart, lineChart } from './charts.js';
import { html, longDate, num, DAYS } from './ui.js';

let stats = null;
let measures = null;
let refresh = () => {};

const SHORT_MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const shortDate = (iso) => `${Number(iso.slice(8))} ${SHORT_MONTHS[Number(iso.slice(5, 7)) - 1]}`;
const dayLetter = (iso) => {
  const [y, m, d] = iso.split('-').map(Number);
  return 'DLMMJVS'[new Date(y, m - 1, d).getDay()];
};
const signed = (v) => `${v > 0 ? '+' : v < 0 ? '−' : ''}${num(Math.abs(v))}`;
const ISO_DAYS = [1, 2, 3, 4, 5, 6, 7].map((n) => [n, DAYS[n % 7]]);

export async function loadTracking() {
  [stats, measures] = await Promise.all([api.stats().catch(() => stats), api.measures().catch(() => measures)]);
  return { stats, measures };
}

/** À appeler par l'écran qui affiche les cartes : dit comment se redessiner après une saisie. */
export function onTrackingChange(fn) {
  refresh = fn;
}

/** Rappel dans l'app : la pesée de la semaine est due. */
export function weighDueCard() {
  const w = measures?.weigh;
  if (!w?.due) return '';
  const why = w.reason === 'first' ? 'Première pesée : elle sert de point de départ et au calcul des protéines.'
    : w.reason === 'weigh_day' ? `C'est ${DAYS[w.day % 7]}, ton jour de pesée.`
      : `Dernière pesée il y a ${w.days_since} jours.`;
  return html`<section class="card weigh-due" aria-labelledby="h-pesee">
    <h2 id="h-pesee">Pesée de la semaine</h2>
    <p>${why}</p>
    ${weighForm()}
    <p class="muted small-note">Le matin, à jeun, après être passé aux toilettes, sans vêtements, toujours sur la même balance.</p>
  </section>`;
}

function weighForm() {
  const last = measures?.summary.current;
  return html`<form class="inline-form" data-weigh-form>
    <label class="sr-only" for="weigh-kg">Poids en kilos</label>
    <input id="weigh-kg" type="text" inputmode="decimal" autocomplete="off" placeholder="${last ? `${num(last)} kg` : 'Poids en kg'}" required>
    <button class="btn" type="submit">Enregistrer</button>
  </form>
  <p class="error" role="alert" data-weigh-error></p>`;
}

function sessionsChart() {
  const weeks = stats.weeks;
  const planned = weeks[weeks.length - 1].planned;
  return barChart(weeks.map((w, i) => ({
    value: w.done,
    axis: i === weeks.length - 1 ? 'cette sem.' : (i % 2 === (weeks.length - 1) % 2 ? shortDate(w.start) : ''),
    tip: `Semaine du ${shortDate(w.start)} : ${w.done} séance${w.done > 1 ? 's' : ''} sur ${w.planned}${w.jokers ? `, ${w.jokers} joker${w.jokers > 1 ? 's' : ''}` : ''}`,
  })), {
    target: planned, targetLabel: `${planned}`,
    caption: 'Séances réalisées par semaine, 8 dernières semaines',
    head: ['Semaine du', 'Séances', 'Prévues', 'Jokers'],
    rows: weeks.map((w) => [shortDate(w.start), w.done, w.planned, w.jokers]),
  });
}

function pushupsChart() {
  const days = stats.pushups;
  const target = [...days].reverse().find((d) => d.target != null)?.target ?? null;
  const label = { reached: 'atteint', joker: 'joker', missed: 'manqué', in_progress: 'en cours' };
  return barChart(days.map((d, i) => ({
    value: d.done,
    muted: d.status === 'joker' || d.status === 'missed',
    axis: i === days.length - 1 ? 'auj.' : dayLetter(d.day),
    tip: `${longDate(d.day)} : ${d.done} pompe${d.done > 1 ? 's' : ''}${d.target ? ` sur ${d.target}` : ''}${d.status ? ` (${label[d.status]})` : ''}`,
  })), {
    target, targetLabel: target ? `${target}` : '',
    caption: 'Pompes par jour, 14 derniers jours',
    head: ['Jour', 'Pompes', 'Objectif', 'État'],
    rows: days.map((d) => [shortDate(d.day), d.done, d.target ?? '—', d.status ? label[d.status] : '—']),
  });
}

function weightChart() {
  const pts = measures.weights.slice(-26);
  return lineChart(pts.map((p) => ({ value: p.value, label: shortDate(p.date), tip: `${longDate(p.date)} : ${num(p.value)} kg` })), {
    unit: 'kg',
    caption: 'Poids, pesées hebdomadaires',
    head: ['Date', 'Poids'],
    rows: pts.map((p) => [shortDate(p.date), p.value]),
  });
}

/** Cartes de suivi. compact : version de l'accueil (sans réglages ni liste des pesées). */
export function trackingCards({ compact = false } = {}) {
  if (!stats || !measures) {
    return html`<section class="card"><h2>Mon suivi</h2><p class="muted">Hors ligne : graphes indisponibles pour l'instant.</p></section>`;
  }
  const s = measures.summary;
  const w = measures.weigh;
  const thisWeek = stats.weeks[stats.weeks.length - 1];
  const today = stats.pushups[stats.pushups.length - 1];

  return html`
    <section class="card" aria-labelledby="h-g-seances">
      <div class="block-head"><h2 id="h-g-seances">Séances par semaine</h2><span class="tag">8 semaines</span></div>
      <p class="figure"><strong>${thisWeek.done}</strong> / ${thisWeek.planned} cette semaine<span class="muted"> · ${stats.totals.sessions} au total</span></p>
      ${sessionsChart()}
    </section>
    <section class="card" aria-labelledby="h-g-pompes">
      <div class="block-head"><h2 id="h-g-pompes">Pompes par jour</h2><span class="tag">14 jours</span></div>
      <p class="figure"><strong>${today.done}</strong> aujourd'hui<span class="muted"> · ${stats.totals.pushups.toLocaleString('fr-FR')} au total</span></p>
      ${pushupsChart()}
    </section>
    <section class="card" aria-labelledby="h-g-poids">
      <div class="block-head"><h2 id="h-g-poids">Poids</h2><span class="tag">pesée du ${DAYS[w.day % 7]}</span></div>
      ${s.current != null
        ? html`<p class="figure"><strong>${num(s.current)}</strong> kg${s.since_first != null ? html`<span class="muted"> · ${signed(s.since_first)} kg depuis le ${shortDate(s.first_date)}</span>` : ''}</p>`
        : html`<p class="muted">Aucune pesée pour l'instant.</p>`}
      ${weightChart()}
      ${compact ? '' : html`
        ${w.due ? '' : html`<p class="muted">Prochaine pesée : ${longDate(w.next_date)}.</p><details class="cues-box"><summary>Ajouter ou corriger une pesée aujourd'hui</summary>${weighForm()}</details>`}
        <h3>Quand me peser</h3>
        <form class="schedule" data-weigh-schedule>
          <label for="weigh-day">Jour</label>
          <select id="weigh-day">${ISO_DAYS.map(([n, name]) => html`<option value="${n}" ${n === w.day ? 'selected' : ''}>${name}</option>`)}</select>
          <label for="weigh-time">Heure du rappel</label>
          <input id="weigh-time" type="time" value="${w.time}" required>
          <button class="btn secondary" type="submit">Enregistrer</button>
        </form>
        <p class="muted small-note">Une pesée par semaine suffit : le poids varie d'un jour à l'autre, c'est la tendance qui compte. Le matin, à jeun, sur la même balance. L'app l'affiche sur l'accueil le jour venu et envoie un rappel à cette heure si les rappels sont activés (Réglages).</p>
        <p class="flash-msg" role="status" data-schedule-ok hidden>Jour de pesée enregistré.</p>`}
    </section>`;
}

// Saisies (écouteurs délégués, posés une fois).
document.getElementById('main').addEventListener('submit', async (e) => {
  const weigh = e.target.closest('[data-weigh-form]');
  const schedule = e.target.closest('[data-weigh-schedule]');
  if (!weigh && !schedule) return;
  e.preventDefault();
  try {
    if (weigh) {
      measures = await api.addMeasure({ weight_kg: weigh.querySelector('input').value });
      await refresh();
    } else {
      measures = await api.weighSchedule(Number(document.getElementById('weigh-day').value), document.getElementById('weigh-time').value);
      await refresh();
      const ok = document.querySelector('[data-schedule-ok]');
      if (ok) ok.hidden = false;
    }
  } catch (err) {
    const el = (weigh ?? schedule).parentElement.querySelector('[data-weigh-error]') ?? document.querySelector('[data-weigh-error]');
    if (el) el.textContent = err.message;
  }
});
