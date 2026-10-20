// SVG charts: the main line/area chart and the world map. No dependencies.
import { html, useState, useEffect, useRef, useMemo, fmtShort, fmt, Icon } from './lib.js';

const parseLocal = (s) => new Date(s.replace(' ', 'T'));

/** Format a bucket label ("YYYY-MM-DD HH:MM:SS", site-local) for the axis or the tooltip. */
export function bucketText(label, interval, long = false) {
  const d = parseLocal(label);
  if (interval === 'minute') return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  if (interval === 'hour') return long ? d.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric' }) : d.toLocaleTimeString(undefined, { hour: 'numeric' });
  if (interval === 'month') return d.toLocaleDateString(undefined, { month: long ? 'long' : 'short', year: long ? 'numeric' : undefined });
  if (interval === 'week') return (long ? 'Week of ' : '') + d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  return d.toLocaleDateString(undefined, long ? { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' } : { month: 'short', day: 'numeric' });
}

/** Round the axis up to a "nice" step so ticks read 0, 50, 100… not 62.5. Returns [max, step]. */
function niceScale(v) {
  if (v <= 4) { const m = Math.max(1, Math.ceil(v)); return [m, 1]; }
  const raw = v / 4, p = Math.pow(10, Math.floor(Math.log10(raw)));
  const step = [1, 2, 2.5, 5, 10].map((k) => k * p).find((s) => s >= raw);
  return [Math.ceil(v / step) * step, step];
}

export function useWidth() {
  const ref = useRef();
  const [w, setW] = useState(0);
  useEffect(() => {
    if (!ref.current) return;
    setW(ref.current.clientWidth);
    const ro = new ResizeObserver(([e]) => setW(Math.round(e.contentRect.width)));
    ro.observe(ref.current);
    return () => ro.disconnect();
  }, []);
  return [ref, w];
}

/**
 * values/labels: current period. prev/prevLabels: optional comparison series (aligned by index).
 * format(v) renders numbers; interval controls label text.
 */
export function LineChart({ labels, values, prev, prevLabels, format = fmtShort, name = 'Visitors', interval = 'day', height = 300, live = false }) {
  const [ref, width] = useWidth();
  const [hover, setHover] = useState(null);
  const m = { t: 12, r: 12, b: 28, l: 44 };
  const W = Math.max(width, 120), H = height;
  const iw = W - m.l - m.r, ih = H - m.t - m.b;
  const n = labels.length;
  const [max, step] = useMemo(() => niceScale(Math.max(0, ...values, ...(prev || []))), [values, prev]);
  const x = (i) => m.l + (n <= 1 ? iw / 2 : (i / (n - 1)) * iw);
  const y = (v) => m.t + ih - (v / max) * ih;
  const line = (arr) => arr.map((v, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1)).join(' ');
  const ticks = Array.from({ length: Math.round(max / step) + 1 }, (_, i) => i * step);
  const every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(iw / 78))));

  const move = (e) => {
    const r = e.currentTarget.getBoundingClientRect();
    const px = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
    setHover(Math.max(0, Math.min(n - 1, Math.round(((px - m.l) / iw) * (n - 1)))));
  };
  const key = (e) => {
    if (e.key === 'ArrowLeft') { e.preventDefault(); setHover((h) => Math.max(0, (h ?? n) - 1)); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); setHover((h) => Math.min(n - 1, (h ?? -1) + 1)); }
    else if (e.key === 'Escape') setHover(null);
  };
  const tipLeft = hover === null ? 0 : Math.min(Math.max(x(hover), 90), W - 90);

  return html`<div class="chart" ref=${ref} style=${`height:${H}px`} tabindex="0" role="img" aria-label=${`${name} over time. Use the arrow keys to read each point.`} onKeyDown=${key} onBlur=${() => setHover(null)}>
    ${width > 0 && html`<svg width=${W} height=${H} onMouseMove=${move} onMouseLeave=${() => setHover(null)} onTouchMove=${move} onTouchEnd=${() => setHover(null)}>
      <defs><linearGradient id="area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--series-1)" stop-opacity="0.22" /><stop offset="1" stop-color="var(--series-1)" stop-opacity="0.02" /></linearGradient></defs>
      <g class="grid">${ticks.map((t) => html`<line x1=${m.l} x2=${W - m.r} y1=${y(t)} y2=${y(t)} />`)}</g>
      ${ticks.map((t) => html`<text x=${m.l - 8} y=${y(t) + 4} text-anchor="end">${format(Math.round(t * 100) / 100)}</text>`)}
      ${labels.map((l, i) => i % every === 0 && html`<text x=${x(i)} y=${H - 8} text-anchor=${i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}>${bucketText(l, interval)}</text>`)}
      ${prev && html`<path d=${line(prev.slice(0, n))} fill="none" stroke="var(--series-2)" stroke-width="2" stroke-dasharray="4 4" stroke-linejoin="round" opacity="0.9" />`}
      ${n > 1 && html`<path d=${line(values) + ` L${x(n - 1)} ${y(0)} L${x(0)} ${y(0)} Z`} fill="url(#area)" />`}
      <path d=${line(values)} fill="none" stroke="var(--series-1)" stroke-width="2.25" stroke-linejoin="round" stroke-linecap="round" />
      ${n === 1 && html`<circle cx=${x(0)} cy=${y(values[0])} r="4" fill="var(--series-1)" />`}
      ${hover !== null && html`<g><line class="cross" x1=${x(hover)} x2=${x(hover)} y1=${m.t} y2=${y(0)} />
        ${prev && prev[hover] !== undefined && html`<circle cx=${x(hover)} cy=${y(prev[hover])} r="4" fill="var(--surface)" stroke="var(--series-2)" stroke-width="2" />`}
        <circle cx=${x(hover)} cy=${y(values[hover])} r="4.5" fill="var(--surface)" stroke="var(--series-1)" stroke-width="2.25" /></g>`}
    </svg>`}
    ${hover !== null && html`<div class="tooltip" style=${`left:${tipLeft}px;top:${Math.max(0, Math.min(y(values[hover]), y(prev && prev[hover] !== undefined ? prev[hover] : values[hover])) - 12)}px`}>
      <div class="tt-h" style="margin:0 0 3px">${name}</div>
      <div class="tt-r"><i style="background:var(--series-1)"></i><span>${bucketText(labels[hover], interval, true)}</span><b>${format(values[hover])}</b></div>
      ${prev && prev[hover] !== undefined && html`<div class="tt-r"><i style="background:var(--series-2)"></i><span>${prevLabels ? bucketText(prevLabels[hover], interval, true) : 'Previous'}</span><b>${format(prev[hover])}</b></div>`}
</div>`}
  </div>`;
}

/** Table fallback for the chart, for screen reader and keyboard users. */
export function ChartTable({ labels, values, prev, format, name, interval }) {
  return html`<div class="chart-table"><table class="t"><thead><tr><th>Date</th><th class="right">${name}</th>${prev && html`<th class="right">Previous</th>`}</tr></thead>
    <tbody>${labels.map((l, i) => html`<tr><td>${bucketText(l, interval, true)}</td><td class="right">${format(values[i])}</td>${prev && html`<td class="right">${format(prev[i])}</td>`}</tr>`)}</tbody></table></div>`;
}

let worldPromise;
const loadWorld = () => (worldPromise ||= fetch(`${window.SD.base}/assets/world.json`).then((r) => r.json()));

/** Choropleth world map. `rows` = [{name: 'DE', visitors}]; onPick(code) filters the dashboard. */
export function WorldMap({ rows, onPick, valueLabel = 'Visitors' }) {
  const [world, setWorld] = useState(null);
  const [tip, setTip] = useState(null);
  const [ref, width] = useWidth();
  useEffect(() => { loadWorld().then(setWorld).catch(() => {}); }, []);
  const byCode = useMemo(() => Object.fromEntries(rows.map((r) => [r.name, r.visitors])), [rows]);
  const max = Math.max(1, ...rows.map((r) => r.visitors));
  if (!world) return html`<div class="skeleton" style="height:230px"></div>`;
  const level = (v) => (v ? 0.18 + 0.82 * Math.sqrt(v / max) : 0);
  return html`<div class="worldmap" ref=${ref}>
    <svg viewBox=${`0 0 ${world.w} ${world.h}`} role="img" aria-label="Map of visitors by country" onMouseLeave=${() => setTip(null)}>
      ${Object.entries(world.c).map(([code, c]) => {
        const v = byCode[code];
        return html`<path d=${c.d} class=${'country' + (v ? ' has' : '')} style=${v ? `fill-opacity:${level(v)}` : ''} onMouseMove=${(e) => { const r = ref.current.getBoundingClientRect(); setTip({ x: e.clientX - r.left, y: e.clientY - r.top, name: c.n, v, code }); }} onClick=${() => v && onPick && onPick(code)} />`;
      })}
      ${tip && world.c[tip.code] && html`<path d=${world.c[tip.code].d} class="country-hot" />`}
    </svg>
    ${tip && html`<div class="tooltip" style=${`left:${Math.min(tip.x, Math.max(width - 90, 0))}px;top:${tip.y - 14}px`}><div class="tt-r"><span>${tip.name}</span><b>${tip.v ? fmt(tip.v) : '—'}</b></div><div class="tt-h" style="margin:0">${valueLabel}</div></div>`}
  </div>`;
}
