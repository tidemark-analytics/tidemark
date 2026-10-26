// "What changed": the rules behind the Insights card. Pure functions, no imports, deterministic (the same numbers always give the
// same findings, and nothing is sent anywhere): everything is computed in the browser from the breakdowns the dashboard already
// loads for the current period and for the comparison period.
//
// The rules, in plain words:
//  1. Entities. For pages, sources, countries and goals we look at every row that exists in either period.
//  2. Noise floor. A change only counts when it moved at least `floor` visitors, where floor = max(5, 2% of this period's visitors).
//     A page going from 1 to 4 visitors is not news; going from 40 to 160 is.
//  3. Risers and fallers. Beyond the floor, a row is a riser when it grew by at least 50% (ratio >= 1.5) and a faller when it lost
//     at least a third (ratio <= 0.67). A row with nothing before is "new" (a new referrer, a page that got its first visitors).
//     Its score is the size of the move relative to all visitors, so big movers on a busy site outrank small ones.
//  4. Unusual day. For daily charts, a day is unusually high/low when it sits at least 2 standard deviations from the average of
//     the other days and differs by at least the floor. Only the most extreme day is reported.
//  5. Ranking. All candidates are ordered by score; at most 2 per kind of thing (pages, sources, countries, goals, days) and 6
//     in total are kept, so the card stays varied instead of listing five pages.
//  6. Thin data. Fewer than MIN_VISITORS visitors in either period and nothing is reported at all (the card hides itself).

export const MIN_VISITORS = 30; // combined visitors of both periods below which the card hides
export const MAX_FINDINGS = 6;
export const PER_KIND = 2;
export const RISE_RATIO = 1.5;
export const FALL_RATIO = 0.67;
export const DAY_Z = 2;

/** The smallest move (in visitors) worth mentioning for a period with `total` visitors. */
export const noiseFloor = (total) => Math.max(5, Math.ceil(total * 0.02));

/** Is there enough data to say anything? */
export const hasEnoughData = (totalNow, totalBefore) => (totalNow || 0) + (totalBefore || 0) >= MIN_VISITORS && (totalNow || 0) > 0;

/** "4x more", "62% more", "35% fewer" for a change from `before` to `now` (before > 0). */
export function describeChange(now, before) {
  if (!before) return null;
  const ratio = now / before;
  if (ratio >= 2) return { text: `${ratio >= 10 ? Math.round(ratio) : Math.round(ratio * 10) / 10}x more`, pct: Math.round((ratio - 1) * 100) };
  if (ratio > 1) return { text: `${Math.round((ratio - 1) * 100)}% more`, pct: Math.round((ratio - 1) * 100) };
  return { text: `${Math.round((1 - ratio) * 100)}% fewer`, pct: -Math.round((1 - ratio) * 100) };
}

/**
 * Compare one dimension between two periods.
 * @param {Array<{name:string,visitors:number}>} now rows of the current period
 * @param {Array<{name:string,visitors:number}>} before rows of the comparison period
 * @param {number} totalNow visitors of the whole current period (for the noise floor and the score)
 * @param {{ignoreNew?: string[], newCounts?: boolean}} [opt] ignoreNew: names that are never "new" (like "Direct / None"); newCounts: whether a row with no history is reportable
 * @returns {Array<{name:string,now:number,before:number,delta:number,kind:'riser'|'faller'|'new',score:number}>}
 */
export function compareRows(now, before, totalNow, opt = {}) {
  const floor = noiseFloor(totalNow);
  const prev = new Map((before || []).map((r) => [r.name, r.visitors]));
  const seen = new Set();
  const out = [];
  const rows = [...(now || []), ...(before || []).filter((r) => !(now || []).some((x) => x.name === r.name)).map((r) => ({ name: r.name, visitors: 0 }))];
  for (const r of rows) {
    if (seen.has(r.name)) continue;
    seen.add(r.name);
    const cur = r.visitors, was = prev.get(r.name) || 0;
    const delta = cur - was;
    if (Math.abs(delta) < floor) continue;
    const score = Math.abs(delta) / Math.max(1, totalNow);
    if (was === 0) { if (opt.newCounts !== false && !(opt.ignoreNew || []).includes(r.name)) out.push({ name: r.name, now: cur, before: 0, delta, kind: 'new', score }); continue; }
    const ratio = cur / was;
    if (ratio >= RISE_RATIO) out.push({ name: r.name, now: cur, before: was, delta, kind: 'riser', score });
    else if (ratio <= FALL_RATIO) out.push({ name: r.name, now: cur, before: was, delta, kind: 'faller', score });
  }
  return out.sort((a, b) => b.score - a.score || a.name.localeCompare(b.name));
}

/**
 * The most unusual day of a series, or null. Mean and standard deviation come from the other days, so one huge spike can't hide itself.
 * @returns {{index:number,value:number,typical:number,direction:'high'|'low',z:number}|null}
 */
export function unusualDay(values, floor) {
  if (!values || values.length < 7) return null;
  let best = null;
  for (let i = 0; i < values.length; i++) {
    const rest = values.filter((_, j) => j !== i);
    const mean = rest.reduce((a, b) => a + b, 0) / rest.length;
    const sd = Math.sqrt(rest.reduce((a, b) => a + (b - mean) ** 2, 0) / rest.length);
    const diff = values[i] - mean;
    if (Math.abs(diff) < floor) continue;
    const z = sd === 0 ? (diff === 0 ? 0 : Infinity) : diff / sd;
    if (Math.abs(z) >= DAY_Z && (!best || Math.abs(z) > Math.abs(best.z))) best = { index: i, value: values[i], typical: Math.round(mean), direction: diff > 0 ? 'high' : 'low', z };
  }
  return best;
}

const KIND_LABEL = { page: 'pages', source: 'sources', country: 'countries', goal: 'goals', day: 'days' };

/**
 * Turn the numbers into ranked, worded findings.
 * @param {object} d
 * @param {number} d.totalNow visitors in the current period
 * @param {number} d.totalBefore visitors in the comparison period
 * @param {{page?:Array,source?:Array,country?:Array}} d.now breakdown rows now
 * @param {{page?:Array,source?:Array,country?:Array}} d.before breakdown rows before
 * @param {Array<{name:string,visitors:number}>} [d.goalsNow]
 * @param {Array<{name:string,visitors:number}>} [d.goalsBefore]
 * @param {{labels:string[],values:number[],interval:string}|null} [d.series] visitors per bucket, current period
 * @param {(country:string)=>string} [d.country] display name for a country code
 * @param {(label:string,interval:string)=>string} [d.dayText] display text for a bucket label
 * @returns {Array<object>} findings: { id, kind, group, tone, text, delta, filter, ... }, best first; [] when the data is thin
 */
export function computeInsights(d) {
  if (!hasEnoughData(d.totalNow, d.totalBefore)) return [];
  const country = d.country || ((c) => c);
  const cands = [];
  const push = (c) => cands.push({ id: `${c.group}:${c.name || c.index}:${c.kind}`, ...c });
  const verb = (kind, group, name, row) => {
    const ch = describeChange(row.now, row.before);
    const who = group === 'goal' ? `“${name}” conversions` : group === 'country' ? `Visitors from ${country(name)}` : name;
    if (kind === 'new') return group === 'source' ? `${name} is a new source: ${row.now} ${row.now === 1 ? 'visitor' : 'visitors'} came from it` : group === 'goal' ? `“${name}” was completed for the first time: ${row.now} ${row.now === 1 ? 'visitor' : 'visitors'}` : `${who} is new this period with ${row.now} ${row.now === 1 ? 'visitor' : 'visitors'}`;
    if (group === 'country' || group === 'goal') return `${who} ${kind === 'riser' ? 'rose' : 'fell'} from ${row.before} to ${row.now}`;
    return `${who} got ${ch.text} visitors`;
  };
  for (const group of ['page', 'source', 'country']) {
    const rows = compareRows((d.now || {})[group], (d.before || {})[group], d.totalNow, { ignoreNew: ['Direct / None', 'Direct', 'None'], newCounts: true });
    for (const r of rows) push({ group, kind: r.kind, name: r.name, now: r.now, before: r.before, delta: r.delta, score: r.score, tone: r.kind === 'faller' ? 'down' : 'up', text: verb(r.kind, group, r.name, r), change: r.before ? describeChange(r.now, r.before).pct : null, filter: [group, r.name] });
  }
  for (const r of compareRows(d.goalsNow, d.goalsBefore, d.totalNow, { newCounts: true })) {
    push({ group: 'goal', kind: r.kind, name: r.name, now: r.now, before: r.before, delta: r.delta, score: r.score, tone: r.kind === 'faller' ? 'down' : 'up', text: verb(r.kind, 'goal', r.name, r), change: r.before ? describeChange(r.now, r.before).pct : null, filter: ['goal', r.name] });
  }
  if (d.series && d.series.interval === 'day') {
    const u = unusualDay(d.series.values, noiseFloor(d.totalNow) * 2);
    if (u) {
      const label = (d.dayText || ((l) => l.slice(0, 10)))(d.series.labels[u.index], d.series.interval);
      push({ group: 'day', kind: u.direction === 'high' ? 'high_day' : 'low_day', index: u.index, name: label, now: u.value, before: u.typical, delta: u.value - u.typical, score: Math.min(1, Math.abs(u.value - u.typical) / Math.max(1, d.totalNow)) * 1.5, tone: u.direction === 'high' ? 'up' : 'down',
        text: `${label} was unusually ${u.direction}: ${u.value} ${u.value === 1 ? 'visitor' : 'visitors'} against a typical ${u.typical}`, change: u.typical ? describeChange(u.value, u.typical).pct : null, filter: null, date: d.series.labels[u.index].slice(0, 10) });
    }
  }
  cands.sort((a, b) => b.score - a.score || a.id.localeCompare(b.id));
  const perGroup = {};
  const picked = [];
  for (const c of cands) {
    if (picked.length >= MAX_FINDINGS) break;
    if ((perGroup[c.group] || 0) >= PER_KIND) continue;
    perGroup[c.group] = (perGroup[c.group] || 0) + 1;
    picked.push(c);
  }
  return picked;
}

export const groupLabel = (g) => KIND_LABEL[g] || g;
