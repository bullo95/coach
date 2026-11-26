// Rappels : abonnement de l'appareil aux notifications (Web Push) et réglages des rappels.
import { api } from './api.js';
import { html } from './ui.js';

const LATER_KEY = 'coach.push.later';
const TYPES = [
  ['session', 'Séance du jour', 'Les jours de séance, à l\'heure choisie'],
  ['session_missed', 'Séance pas faite', '2 h après, si elle n\'est toujours pas faite'],
  ['pushups', 'Séries de pompes', 'Un rappel par série, tant qu\'elle n\'est pas faite'],
  ['weight', 'Pesée', 'Le jour de pesée, à l\'heure choisie dans Suivi'],
];
const TYPE_NAMES = Object.fromEntries(TYPES.map(([k, name]) => [k, name]));

let server = null; // réponse de /api/push
let subscription = null; // abonnement de cet appareil, s'il existe
let notice = null;
let onChange = () => {};

const supported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
const isIos = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isInstalled = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

function keyBytes(base64url) {
  const b64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
}

/** Charge l'état des rappels ; si l'appareil est abonné, renvoie son abonnement au serveur (il peut l'avoir perdu). */
export async function loadPush() {
  server = await api.push().catch(() => null);
  subscription = null;
  if (supported()) {
    const reg = await navigator.serviceWorker.getRegistration().catch(() => null);
    subscription = reg ? await reg.pushManager.getSubscription().catch(() => null) : null;
    if (subscription && server?.configured) {
      server = await api.pushSubscribe(subscription.toJSON()).catch(() => server);
    }
  }
}

export function onPushChange(fn) {
  onChange = fn;
}

async function enable() {
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    notice = 'Notifications refusées : rien n\'a été activé.';
    return;
  }
  const reg = await navigator.serviceWorker.ready;
  const options = { userVisibleOnly: true, applicationServerKey: keyBytes(server.public_key) };
  try {
    subscription = await reg.pushManager.subscribe(options);
  } catch {
    // Abonnement fait avec d'anciennes clés : on le remplace.
    const old = await reg.pushManager.getSubscription();
    if (old) await old.unsubscribe();
    subscription = await reg.pushManager.subscribe(options);
  }
  server = await api.pushSubscribe(subscription.toJSON());
  notice = 'Rappels activés sur cet appareil.';
}

async function disable() {
  if (!subscription) return;
  const { endpoint } = subscription;
  await subscription.unsubscribe().catch(() => {});
  subscription = null;
  server = await api.pushUnsubscribe(endpoint);
  notice = 'Rappels coupés sur cet appareil.';
}

function deviceBlock() {
  if (!server) return html`<p class="muted">Hors ligne : réglage des rappels indisponible.</p>`;
  if (!supported()) {
    return isIos() && !isInstalled()
      ? html`<p><strong>Sur iPhone, ajoute d'abord l'app à l'écran d'accueil.</strong></p>
          <p class="muted">Dans Safari : bouton Partager, puis « Sur l'écran d'accueil ». Ouvre ensuite Coach depuis son icône et reviens ici.</p>`
      : html`<p class="muted">Ce navigateur ne gère pas les notifications.</p>`;
  }
  if (!server.configured) {
    return html`<p class="muted">Le serveur n'est pas encore réglé pour envoyer des notifications : ${server.missing}.</p>`;
  }
  if (Notification.permission === 'denied') {
    return html`<p><strong>Les notifications sont bloquées pour Coach sur cet appareil.</strong></p>
      <p class="muted">iPhone : Réglages → Notifications → Coach → Autoriser. Ordinateur : icône à gauche de l'adresse → Notifications → Autoriser. Puis reviens ici.</p>`;
  }
  if (subscription) {
    return html`<p class="ok-line">Rappels activés sur cet appareil</p>
      <div class="stack">
        <button class="btn secondary block" type="button" data-push="test">Envoyer une notification d'essai</button>
        <button class="btn secondary block" type="button" data-push="disable">Couper sur cet appareil</button>
      </div>`;
  }
  return html`<p>Les rappels arrivent même quand l'app est fermée.</p>
    <button class="btn block" type="button" data-push="enable">Activer les rappels</button>`;
}

/** Carte de l'accueil : propose d'activer les rappels tant que ce n'est ni fait ni écarté. */
export function pushPromptCard() {
  let later = false;
  try {
    later = localStorage.getItem(LATER_KEY) === '1';
  } catch { /* stockage indisponible */ }
  if (!server?.configured || !supported() || subscription || Notification.permission !== 'default' || later) return '';
  return html`<section class="card" aria-labelledby="h-push">
    <h2 id="h-push">Rappels</h2>
    <p>Séance, pompes et pesée : reçois un rappel au bon moment, même app fermée.</p>
    <div class="stack">
      <button class="btn block" type="button" data-push="enable">Activer les rappels</button>
      <button class="btn secondary block" type="button" data-push="later">Plus tard</button>
    </div>
  </section>`;
}

/** Section de l'écran Réglages. */
export function pushSettings() {
  const r = server;
  return html`
    <section class="card" aria-labelledby="h-rappels">
      <h2 id="h-rappels">Rappels</h2>
      ${notice ? html`<p class="flash-msg" role="status">${notice}</p>` : ''}
      ${deviceBlock()}
      ${r && r.devices > 0 ? html`<p class="muted small-note">${r.devices} appareil${r.devices > 1 ? 's' : ''} abonné${r.devices > 1 ? 's' : ''}.</p>` : ''}
    </section>
    ${r ? html`<section class="card" aria-labelledby="h-rappels-quoi">
      <h2 id="h-rappels-quoi">Quels rappels</h2>
      <form data-reminders>
        ${TYPES.map(([key, name, hint]) => html`<label class="check"><input type="checkbox" name="${key}" ${r.types[key] ? 'checked' : ''}><span>${name}<br><span class="muted">${hint}</span></span></label>`)}
        <h3>Horaires</h3>
        <div class="schedule">
          <label for="r-session">Séance</label><input id="r-session" type="time" name="session_time" value="${r.session_time}" required>
          <label for="r-pstart">Pompes, de</label><input id="r-pstart" type="time" name="pushups_window_start" value="${r.pushups_window_start}" required>
          <label for="r-pend">Pompes, à</label><input id="r-pend" type="time" name="pushups_window_end" value="${r.pushups_window_end}" required>
          <label for="r-qstart">Silence, de</label><input id="r-qstart" type="time" name="quiet_start" value="${r.quiet_start}" required>
          <label for="r-qend">Silence, à</label><input id="r-qend" type="time" name="quiet_end" value="${r.quiet_end}" required>
        </div>
        <p class="muted small-note">Aucune notification pendant le silence. Un rappel du matin qui tombe dedans (la pesée de ${r.weigh_time}, par exemple) arrive à la fin du silence.</p>
        <p class="error" role="alert" data-reminders-error></p>
        <button class="btn secondary block" type="submit">Enregistrer</button>
      </form>
      <h3>Prévus aujourd'hui</h3>
      ${r.today.length
        ? html`<ul class="plain-list">${r.today.map((t) => html`<li><strong>${t.time}</strong> ${TYPE_NAMES[t.type]}</li>`)}</ul>`
        : html`<p class="muted">Aucun rappel à envoyer aujourd'hui.</p>`}
      <p class="muted small-note">Un rappel ne part que si la chose n'est pas déjà faite : la liste se vide au fil de la journée.</p>
    </section>` : ''}`;
}

// Écouteurs délégués, posés une fois.
const main = document.getElementById('main');

main.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-push]');
  if (!btn) return;
  btn.disabled = true;
  notice = null;
  try {
    const action = btn.dataset.push;
    if (action === 'enable') await enable();
    else if (action === 'disable') await disable();
    else if (action === 'later') localStorage.setItem(LATER_KEY, '1');
    else if (action === 'test') {
      const res = await api.pushTest();
      server = await api.push().catch(() => server);
      notice = res.sent > 0
        ? 'Notification envoyée : elle arrive dans quelques secondes.'
        : `L'envoi a échoué${res.errors[0] ? ` (${res.errors[0]})` : ''}.`;
    }
  } catch (err) {
    notice = err.message;
  }
  onChange();
});

main.addEventListener('submit', async (e) => {
  const form = e.target.closest('[data-reminders]');
  if (!form) return;
  e.preventDefault();
  const data = { types: {} };
  for (const [key] of TYPES) data.types[key] = form.elements[key].checked;
  for (const key of ['session_time', 'pushups_window_start', 'pushups_window_end', 'quiet_start', 'quiet_end']) data[key] = form.elements[key].value;
  try {
    server = await api.reminders(data);
    notice = 'Rappels enregistrés.';
    onChange();
  } catch (err) {
    form.querySelector('[data-reminders-error]').textContent = err.message;
  }
});

// Tap sur une notification alors que l'app est déjà ouverte : le service worker indique l'écran à afficher.
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', (e) => {
    if (e.data?.type !== 'open') return;
    const hash = new URL(e.data.url).hash || '#/';
    if (location.hash === hash) onChange();
    else location.hash = hash;
  });
}
