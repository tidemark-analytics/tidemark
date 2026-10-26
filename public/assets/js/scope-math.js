// Pure date arithmetic for the scope bar: stepping to the previous/next period and brush-to-zoom.
// No imports, so it can be exercised from node. All dates are 'YYYY-MM-DD' strings in the site's timezone.

const DAY = 86400000;
const toMs = (d) => Date.parse(d + 'T12:00:00Z');
const fromMs = (ms) => new Date(ms).toISOString().slice(0, 10);

export const addDays = (d, n) => fromMs(toMs(d) + n * DAY);
export const daysBetween = (a, b) => Math.round((toMs(b) - toMs(a)) / DAY);

/** The number of days a rolling period covers (the period ends today). */
const ROLLING_DAYS = { '7d': 7, '28d': 28, '30d': 30, '91d': 91, '12mo': 365, '6mo': 182 };

/**
 * Where "previous period" / "next period" goes from the current scope.
 * `query` is the URL query ({ period, date, from, to }), `today` the site's current date.
 * Returns the URL patch to apply, or null when there is nowhere to go (realtime, all time, already at today).
 *  - day, month, year step by one unit via `date`.
 *  - rolling windows (28d ...) and custom ranges become a custom range of the same length, moved by that length.
 */
export function stepPeriod(period, query, today, dir) {
  const date = query.date || today;
  if (period === 'day' || period === 'month' || period === 'year') {
    const d = new Date(date + 'T12:00:00Z');
    if (period === 'day') d.setUTCDate(d.getUTCDate() + dir);
    else if (period === 'month') d.setUTCMonth(d.getUTCMonth() + dir, 1);
    else d.setUTCFullYear(d.getUTCFullYear() + dir, 0, 1);
    const next = d.toISOString().slice(0, 10);
    if (next > today) return null;
    return { date: next === today ? null : next };
  }
  let from, to;
  if (period === 'custom' && query.from && query.to) { from = query.from; to = query.to; }
  else if (ROLLING_DAYS[period]) { to = today; from = addDays(today, -(ROLLING_DAYS[period] - 1)); }
  else return null;
  const len = daysBetween(from, to) + 1;
  const nf = addDays(from, dir * len), nt = addDays(to, dir * len);
  if (nf > today) return null;
  return { period: 'custom', from: nf, to: nt > today ? today : nt, date: null, interval: null };
}

/** Can stepPeriod go in this direction? Used to disable the arrows. */
export const canStep = (period, query, today, dir) => stepPeriod(period, query, today, dir) !== null;

/**
 * Brush-to-zoom: the URL patch for a selection of chart buckets [i, j] (inclusive). `labels` are bucket labels
 * ('YYYY-MM-DD HH:MM:SS' in site time). A selection inside one day opens that day (hourly); otherwise a custom range.
 * `original` is what to restore on Reset, kept in the URL as `zoom` so the state is shareable.
 */
export function zoomPatch(labels, i, j, original) {
  const a = Math.max(0, Math.min(i, j)), b = Math.min(labels.length - 1, Math.max(i, j));
  const from = labels[a].slice(0, 10), to = labels[b].slice(0, 10);
  const zoom = original || null;
  if (from === to) return { period: 'day', date: from, from: null, to: null, interval: null, zoom };
  return { period: 'custom', from, to, date: null, interval: null, zoom };
}

/** `zoom` is "28d", "month@2026-09-01" or "custom@2026-09-01..2026-09-30": what the scope was before zooming. */
export const encodeZoom = (query) => (query.period === 'custom' ? `custom@${query.from}..${query.to}` : query.date ? `${query.period}@${query.date}` : query.period || '');
export function decodeZoom(zoom) {
  if (!zoom) return null;
  const [period, rest] = zoom.split('@');
  if (!period) return null;
  const patch = { period, date: null, from: null, to: null, interval: null, zoom: null };
  if (period === 'custom' && rest && rest.includes('..')) [patch.from, patch.to] = rest.split('..');
  else if (rest) patch.date = rest;
  return patch;
}
