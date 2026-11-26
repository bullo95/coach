// Graphes en SVG, sans bibliothèque. Une seule série par graphe (le titre la nomme, pas de légende),
// barres fines à bout arrondi, grille discrète, valeur au survol ou au toucher, tableau des chiffres en dessous.
import { html, raw, esc } from './ui.js';

const W = 320;
const PAD = { top: 14, right: 34, bottom: 20, left: 6 };

function table(caption, head, rows) {
  return html`<details class="chart-table"><summary>Voir les chiffres</summary>
    <table><caption class="sr-only">${caption}</caption>
      <thead><tr>${head.map((h) => html`<th scope="col">${h}</th>`)}</tr></thead>
      <tbody>${rows.map((r) => html`<tr>${r.map((c, i) => (i === 0 ? html`<th scope="row">${c}</th>` : html`<td>${c}</td>`))}</tr>`)}</tbody>
    </table></details>`;
}

/**
 * Barres. items : [{ label, value, tip, axis }] ; axis = libellé sous la barre (facultatif).
 * target : valeur repère tracée en ligne fine avec son libellé.
 */
export function barChart(items, { target = null, targetLabel = '', height = 132, caption, head, rows }) {
  const innerW = W - PAD.left - PAD.right;
  const innerH = height - PAD.top - PAD.bottom;
  const max = Math.max(target ?? 0, ...items.map((i) => i.value), 1);
  const band = innerW / items.length;
  const bw = Math.min(24, Math.max(4, band - 4));
  const y = (v) => PAD.top + innerH - (v / max) * innerH;
  const base = PAD.top + innerH;

  const bars = items.map((it, i) => {
    const x = PAD.left + i * band + (band - bw) / 2;
    const top = y(it.value);
    const h = base - top;
    const r = Math.min(4, h, bw / 2);
    const bar = it.value > 0
      ? `<path class="bar${it.muted ? ' muted' : ''}" d="M${x},${base}V${top + r}Q${x},${top} ${x + r},${top}H${x + bw - r}Q${x + bw},${top} ${x + bw},${top + r}V${base}Z"/>`
      : `<rect class="bar zero" x="${x}" y="${base - 1.5}" width="${bw}" height="1.5"/>`;
    const axis = it.axis ? `<text class="axis" x="${x + bw / 2}" y="${height - 5}" text-anchor="middle">${esc(it.axis)}</text>` : '';
    return `${bar}${axis}<rect class="hit" x="${PAD.left + i * band}" y="${PAD.top}" width="${band}" height="${innerH + PAD.bottom}" tabindex="0" role="img" aria-label="${esc(it.tip)}" data-tip="${esc(it.tip)}"/>`;
  }).join('');

  const targetLine = target != null
    ? `<line class="target" x1="${PAD.left}" x2="${W - PAD.right}" y1="${y(target)}" y2="${y(target)}"/>
       <text class="axis" x="${W - PAD.right + 4}" y="${y(target) + 3}">${esc(targetLabel)}</text>`
    : '';

  return html`<div class="chart">
    <svg viewBox="0 0 ${W} ${height}" role="group" aria-label="${caption}">
      <line class="grid" x1="${PAD.left}" x2="${W - PAD.right}" y1="${base}" y2="${base}"/>
      ${raw(targetLine)}${raw(bars)}
    </svg>
    <div class="chart-tip" role="status" hidden></div>
    ${table(caption, head, rows)}
  </div>`;
}

/** Courbe. points : [{ label, value, tip }], du plus ancien au plus récent. */
export function lineChart(points, { height = 150, unit = '', caption, head, rows }) {
  if (!points.length) return html`<p class="muted">Pas encore de mesure.</p>`;
  const innerW = W - PAD.left - PAD.right - 10;
  const innerH = height - PAD.top - PAD.bottom;
  const values = points.map((p) => p.value);
  let lo = Math.min(...values);
  let hi = Math.max(...values);
  if (hi - lo < 1) {
    lo -= 0.5;
    hi += 0.5;
  }
  const margin = (hi - lo) * 0.15;
  lo -= margin;
  hi += margin;
  const x = (i) => PAD.left + 6 + (points.length === 1 ? innerW / 2 : (i / (points.length - 1)) * innerW);
  const y = (v) => PAD.top + innerH - ((v - lo) / (hi - lo)) * innerH;
  const fmt = (v) => String(Math.round(v * 10) / 10).replace('.', ',');

  const path = points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join('');
  const dots = points.map((p, i) => `<circle class="dot" cx="${x(i).toFixed(1)}" cy="${y(p.value).toFixed(1)}" r="4"/>`).join('');
  const band = points.length === 1 ? innerW : innerW / (points.length - 1);
  const hits = points.map((p, i) => `<rect class="hit" x="${(x(i) - band / 2).toFixed(1)}" y="${PAD.top}" width="${band.toFixed(1)}" height="${innerH + PAD.bottom}" tabindex="0" role="img" aria-label="${esc(p.tip)}" data-tip="${esc(p.tip)}"/>`).join('');
  const last = points[points.length - 1];
  const gridVals = [Math.max(...values), Math.min(...values)].filter((v, i, a) => a.indexOf(v) === i);
  const grid = gridVals.map((v) => `<line class="grid" x1="${PAD.left}" x2="${W - PAD.right}" y1="${y(v).toFixed(1)}" y2="${y(v).toFixed(1)}"/>
    <text class="axis" x="${W - PAD.right + 4}" y="${(y(v) + 3).toFixed(1)}">${fmt(v)}</text>`).join('');
  const ends = points.length > 1
    ? `<text class="axis" x="${x(0)}" y="${height - 5}" text-anchor="start">${esc(points[0].label)}</text>
       <text class="axis" x="${x(points.length - 1)}" y="${height - 5}" text-anchor="end">${esc(last.label)}</text>`
    : `<text class="axis" x="${x(0)}" y="${height - 5}" text-anchor="middle">${esc(last.label)}</text>`;

  return html`<div class="chart">
    <svg viewBox="0 0 ${W} ${height}" role="group" aria-label="${caption}">
      ${raw(grid)}
      ${points.length > 1 ? raw(`<path class="line" d="${path}"/>`) : ''}
      ${raw(dots)}${raw(ends)}${raw(hits)}
    </svg>
    <div class="chart-tip" role="status" hidden></div>
    ${table(caption, head, rows.map((r) => r.map((c) => (typeof c === 'number' ? `${fmt(c)} ${unit}`.trim() : c))))}
  </div>`;
}

// Valeur au survol, au toucher ou au clavier : une bulle par graphe.
function showTip(target) {
  const chart = target.closest('.chart');
  const tip = chart?.querySelector('.chart-tip');
  if (!tip) return;
  chart.querySelectorAll('.hit.on').forEach((h) => h.classList.remove('on'));
  target.classList.add('on');
  tip.textContent = target.dataset.tip;
  tip.hidden = false;
  const box = chart.getBoundingClientRect();
  const r = target.getBoundingClientRect();
  const center = r.left + r.width / 2 - box.left;
  tip.style.left = `${Math.min(Math.max(center, tip.offsetWidth / 2 + 4), box.width - tip.offsetWidth / 2 - 4)}px`;
}
for (const type of ['pointerover', 'focusin', 'click']) {
  document.addEventListener(type, (e) => {
    const hit = e.target.closest?.('.chart .hit');
    if (hit) showTip(hit);
  });
}
document.addEventListener('pointerout', (e) => {
  const chart = e.target.closest?.('.chart');
  if (chart && e.pointerType === 'mouse' && !chart.contains(e.relatedTarget)) {
    chart.querySelector('.chart-tip').hidden = true;
    chart.querySelectorAll('.hit.on').forEach((h) => h.classList.remove('on'));
  }
});
