// The site dashboard: toolbar (site, filters, date), top stats + graph, then the report panels.
import { setBrand, html, useState, useEffect, useMemo, useRef, useApi, api, useLocation, setQuery, setFilters, parseFilters, navigate, Link, Icon, toast, PERIODS, FILTER_OPS, FILTER_DIMS, dimLabel, todayStr, shiftDate, rememberPeriod, fmt, fmtShort, moneyShort, dur, countryName, flag, addFilter, shareAuth, share, useDocTitle, useScope, BASE, UserCtx, useContext } from './lib.js';
import { Pop, Modal, Loading, Empty, Field, CodeBlock, useDebounced, Sparkline } from './ui.js';
import { scrollToSection } from './shell.js';
import { LineChart, ChartTable, bucketText } from './chart.js';
import { Sources, Pages, Locations, Devices, Behaviors } from './reports.js';

// ------------------------------------------------------------------------------------------ metrics
const METRICS = {
  visitors: ['Unique visitors', fmtShort], visits: ['Total visits', fmtShort], pageviews: ['Total pageviews', fmtShort],
  views_per_visit: ['Views per visit', (v) => fmt(v)], bounce_rate: ['Bounce rate', (v) => v + '%'], visit_duration: ['Visit duration', dur],
};
/** The top stats of a goal; a goal that reports revenue also gets its total and average, in the goal's currency. */
const goalMetrics = (currency) => ({
  unique_conversions: ['Unique conversions', fmtShort], total_conversions: ['Total conversions', fmtShort],
  ...(currency ? { total_revenue: ['Total revenue', (v) => moneyShort(v, currency)], average_revenue: ['Average revenue', (v) => moneyShort(v, currency)] } : {}),
  conversion_rate: ['Conversion rate', (v) => v + '%'],
});
const HELP = {
  visitors: 'People who visited, counted once per day', visits: 'Sessions: a visit ends after 30 minutes of inactivity', pageviews: 'Every page loaded or navigated to', views_per_visit: 'Average pageviews per visit',
  bounce_rate: 'Visits that left after a single page', visit_duration: 'Average time between the first and last activity of a visit',
  unique_conversions: 'Visitors who completed the goal', total_conversions: 'How many times the goal was completed', conversion_rate: 'Unique conversions as a share of visitors',
  total_revenue: 'Revenue from the goal\'s conversions', average_revenue: 'Revenue per conversion',
};
const LOWER_IS_BETTER = new Set(['bounce_rate']);

function Delta({ metric, change }) {
  if (change === null || change === undefined) return null;
  const flat = Math.abs(change) < 0.05;
  const good = LOWER_IS_BETTER.has(metric) ? change < 0 : change > 0;
  return html`<span class=${'delta ' + (flat ? 'flat' : good ? 'up' : 'down')}>${!flat && html`<${Icon} name=${change > 0 ? 'arrow-up' : 'arrow-down'} size=${11} />`}${Math.abs(change) >= 1000 ? '999%+' : Math.abs(change).toFixed(change % 1 === 0 ? 0 : 1) + '%'}</span>`;
}

/** "26 Sep - 23 Oct", or with the year when the window crosses years. */
function rangeLabel(p) {
  const a = p.from.slice(0, 10), b = p.to.slice(0, 10);
  const f = (d, y) => new Date(d + 'T12:00:00Z').toLocaleDateString(undefined, { timeZone: 'UTC', day: 'numeric', month: 'short', ...(y ? { year: 'numeric' } : {}) });
  const y = a.slice(0, 4) !== b.slice(0, 4) || a.slice(0, 4) !== new Date().getFullYear().toString();
  return a === b ? f(a, y) : `${f(a, y)} - ${f(b, y)}`;
}

function TopGraph({ site, scope, live, onIntervals }) {
  const [metric, setMetric] = useState('visitors');
  const [asTable, setAsTable] = useState(false);
  const base = `/sites/${site.domain}/stats`;
  const poll = live ? 5000 : 0;
  const top = useApi(`${base}/top`, scope.params, [], { poll });
  const graph = useApi(`${base}/graph`, scope.params, [], { poll });
  const defs = scope.goal ? goalMetrics(top.data && top.data.currency) : METRICS;
  const current = defs[metric] ? metric : Object.keys(defs)[0];
  const [name, format] = defs[current];
  // while the filter changes, the previous response may use the other set of metrics; treat that as still loading
  const g = graph.data && graph.data.plot[current] ? graph.data : null;
  useEffect(() => { if (g) onIntervals({ list: g.intervals, current: g.interval }); }, [g && g.interval, g && g.intervals.join()]);
  const stats = top.data && top.data.stats;
  const comparing = top.data && top.data.comparing;
  const busy = top.loading || graph.loading;
  const kpi = (k, [label, fmtv]) => {
    const s = stats && stats[k];
    const series = g && g.plot[k];
    return html`<button role="tab" aria-selected=${k === current} title=${HELP[k]} class=${'kpi ' + (k === current ? 'on' : '')} onClick=${() => setMetric(k)}>
      <span class="k">${label}</span>
      <span class="vd"><span class="v">${s ? fmtv(s.value) : html`<span class="skeleton" style="display:inline-block;width:64px;height:28px"></span>`}</span>
        ${s && comparing && !(s.value === 0 && !s.previous) ? html`<span class="d" title=${s.previous !== null && s.previous !== undefined ? 'Previous: ' + fmtv(s.previous) : ''}><${Delta} metric=${k} change=${s.change} /></span>` : ''}</span>
      ${s && comparing && scope.params.comparison && top.data.comparison_period ? html`<span class="cmp"><span class="range">${rangeLabel(top.data.period)}</span><span class="pv">${s.previous === null || s.previous === undefined ? '–' : fmtv(s.previous)}</span><span class="range">${rangeLabel(top.data.comparison_period)}</span></span>` : ''}
      ${series && series.length > 1 ? html`<span class="kpi-spark"><${Sparkline} data=${series} height=${30} /></span>` : ''}</button>`;
  };
  return html`<${Pulse} site=${site} scope=${scope} stats=${stats} comparing=${comparing} top=${top.data} graph=${g} />
    <div class="kpis" role="tablist" aria-label="Top stats">${Object.entries(defs).map(([k, d]) => kpi(k, d))}</div>
    <section class="card graph-card" aria-label="Graph">
      <div class="card-h"><h3>${name} over time</h3><div class="row"><span class="legend"><span><i style="background:var(--series-1)"></i>${name}</span>${g && g.comparison && html`<span><i style="background:var(--series-2)"></i>Comparison</span>`}</span>
        <button class="more" onClick=${() => setAsTable(!asTable)}>${asTable ? 'View as chart' : 'View as table'}</button></div></div>
      <div class=${'graph-b ' + (busy && g ? 'stale' : '')}>
        ${graph.error && !g ? html`<div class="panel-empty" style="min-height:300px"><b>Couldn't load the graph</b><p class="muted">${graph.error.message}</p><button class="btn sm" onClick=${() => { graph.reload(); top.reload(); }}>Try again</button></div>` : !g ? html`<div class="skeleton" style="height:300px"></div>` : asTable
          ? html`<${ChartTable} labels=${g.labels} values=${g.plot[current]} prev=${(g.comparison && g.comparison.plot[current]) || null} format=${format} name=${name} interval=${g.interval} />`
          : html`<${LineChart} labels=${g.labels} values=${g.plot[current]} prev=${g.comparison && g.comparison.plot[current] ? g.comparison.plot[current] : null} prevLabels=${g.comparison && g.comparison.labels} format=${format} name=${name} interval=${g.interval} live=${live} />`}
      </div>
    </section>`;
}

const WHEN = { Today: 'today', Yesterday: 'yesterday', 'Month to date': 'this month', 'Year to date': 'this year', 'Last month': 'last month', 'All time': 'across all time', Realtime: 'in the last 30 minutes' };
const whenPhrase = (scope, site) => { const l = periodLabel(scope, site); return WHEN[l] || (/^Last /.test(l) ? 'in the ' + l.toLowerCase() : 'in ' + l); };

/** The answer first: one plain sentence about the period, plus the few facts people look for next (click one to filter by it). */
function Pulse({ site, scope, stats, comparing, top, graph }) {
  const goal = !!scope.goal;
  const base = `/sites/${site.domain}/stats/breakdown`;
  const p = { ...scope.params, limit: 1 };
  const ch = useApi(goal ? null : `${base}/channel`, p, [], { enabled: !goal });
  const pg = useApi(goal ? null : `${base}/page`, p, [], { enabled: !goal });
  const co = useApi(goal ? null : `${base}/country`, p, [], { enabled: !goal });
  if (goal || !stats) return null;
  const total = stats.visitors.value;
  const first = (r) => (r.data && r.data.results && r.data.results[0]) || null;
  const share = (row) => (row && total ? Math.min(100, Math.round((row.visitors / total) * 100)) + '%' : '');
  const chips = [];
  const c1 = first(ch), c2 = first(pg), c3 = first(co);
  if (c1) chips.push(['Top channel', c1.name, share(c1), () => addFilter('channel', c1.name)]);
  if (c2) chips.push(['Top page', c2.name, share(c2), () => addFilter('page', c2.name)]);
  if (c3) chips.push(['Top country', flag(c3.name) + ' ' + countryName(c3.name), share(c3), () => addFilter('country', c3.name)]);
  const v = graph && graph.plot.visitors;
  if (v && v.length > 2) {
    const i = v.indexOf(Math.max(...v));
    if (v[i] > 0) chips.push(['Busiest ' + graph.interval, bucketText(graph.labels[i], graph.interval), fmtShort(v[i]), null]);
  }
  const change = comparing ? stats.visitors.change : null;
  const when = whenPhrase(scope, site);
  return html`<section class="card pulse" aria-label="Summary">
    <div class="pulse-t"><${Icon} name="sparkles" size=${15} /> <span>At a glance</span></div>
    <p class="pulse-s"><b>${fmt(total)}</b> ${total === 1 ? 'visitor' : 'visitors'} made <b>${fmt(stats.pageviews.value)}</b> ${stats.pageviews.value === 1 ? 'pageview' : 'pageviews'} ${when}${stats.visit_duration && stats.visit_duration.value ? html`, staying <b>${dur(stats.visit_duration.value)}</b> on average` : ''}.
      ${change !== null && change !== undefined && Math.abs(change) >= 0.5 && html` That's <b class=${change > 0 ? 'up' : 'down'}>${Math.abs(change) >= 1000 ? '999%+' : Math.abs(change).toFixed(change % 1 === 0 ? 0 : 1) + '%'} ${change > 0 ? 'more' : 'fewer'}</b> visitors than the comparison period.`}</p>
    ${chips.length > 0 && html`<div class="facts">${chips.map(([k, val, sh, fn]) => html`<${fn ? 'button' : 'div'} class=${'fact' + (fn ? ' click' : '')} onClick=${fn} title=${fn ? 'Filter by ' + val : ''}><span class="fk">${k}</span><span class="fv">${val}</span>${sh && html`<span class="fs">${sh}</span>`}<//>`)}</div>`}
  </section>`;
}

// ---------------------------------------------------------------------------------------------- filters
function FilterValueLabel({ dim, v }) { return dim === 'country' ? countryName(v) : v; }
const opLabel = (op) => (FILTER_OPS.find((o) => o[0] === op) || [0, op])[1];

let countryIdx;
/** "Germany" or "de" → "DE" so typed country names work as filter values. */
function countryCode(text) {
  const t = text.trim();
  if (/^[a-z]{2}$/i.test(t)) return t.toUpperCase();
  if (!countryIdx) {
    countryIdx = new Map();
    for (let a = 65; a < 91; a++) for (let b = 65; b < 91; b++) { const c = String.fromCharCode(a, b); const n = countryName(c); if (n && n !== c) countryIdx.set(n.toLowerCase(), c); }
  }
  return countryIdx.get(t.toLowerCase()) || t;
}

const DIM_ICON = { page: 'file-text', entry_page: 'log-in', exit_page: 'log-out', hostname: 'server', channel: 'compass', source: 'external-link', referrer: 'link', country: 'globe', region: 'map-pin', city: 'map-pin', browser: 'monitor', os: 'laptop', device: 'smartphone', goal: 'target' };
const dimIcon = (d) => DIM_ICON[d] || 'tag';

function FilterMenu({ site, scope, initial, onClose, segs, onSave, onManage }) {
  const editing = initial && initial.index !== undefined;
  const [dim, setDim] = useState(initial ? initial.rule[1] : null);
  const [op, setOp] = useState(initial ? initial.rule[0] : 'is');
  const [values, setValues] = useState(initial ? initial.rule[2] : []);
  const [text, setText] = useState('');
  const [find, setFind] = useState('');
  const [hi, setHi] = useState(-1);
  const search = useDebounced(text, 200);
  const props = useApi(`/sites/${site.domain}/stats/props`, { period: 'all' }, []);
  const sugg = useApi(dim ? (dim === 'goal' ? `/sites/${site.domain}/stats/goals` : dim.startsWith('prop:') ? `/sites/${site.domain}/stats/props/${encodeURIComponent(dim.slice(5))}` : `/sites/${site.domain}/stats/breakdown/${dim}`) : null,
    { period: 'all', limit: dim === 'country' ? 250 : 8, search: dim === 'country' ? undefined : search || undefined }, [dim, search], { enabled: !!dim });
  const options = ((sugg.data && (sugg.data.results || [])) || []).filter((r) => !search || String(r.name).toLowerCase().includes(search.toLowerCase()) || (dim === 'country' && countryName(r.name).toLowerCase().includes(search.toLowerCase()))).filter((r) => !values.includes(r.name)).slice(0, 8);
  useEffect(() => setHi(-1), [search, dim]);
  const groups = [...FILTER_DIMS, ['Properties', ((props.data && props.data.results) || []).map((p) => ['prop:' + p.name, p.name])]].filter(([, l]) => l.length);
  const norm = (v) => (dim === 'country' ? countryCode(v) : String(v).trim());
  const add = (v) => { v = norm(v); if (v && !values.includes(v)) setValues([...values, v]); setText(''); };
  const apply = () => {
    const all = text.trim() ? [...values, norm(text)] : values;
    if (!all.length) return;
    const list = parseFilters(scope.query);
    const rule = [op, dim, all];
    if (editing) list[initial.index] = rule; else list.push(rule);
    setFilters(list.filter((r, i, a) => a.findIndex((x) => x[1] === r[1] && x[0] === r[0] && JSON.stringify(x[2]) === JSON.stringify(r[2])) === i));
    onClose();
  };
  const multi = dim !== 'goal';
  const OPS = FILTER_OPS.filter(([o]) => multi || o === 'is' || o === 'is_not');
  const SHORT = { is: 'is', is_not: 'is not', contains: 'contains', contains_not: 'excludes' };
  const pick = (n) => (multi ? add(n) : setValues([n]));
  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setHi((i) => Math.min(options.length - 1, i + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setHi((i) => Math.max(-1, i - 1)); }
    else if (e.key === 'Enter') { e.preventDefault(); if (hi >= 0 && options[hi]) pick(options[hi].name); else if (text.trim()) pick(text); else apply(); }
  };
  const segmentItems = segs && segs.allowed ? segs.list.filter((x) => x.name.toLowerCase().includes(find.toLowerCase())) : [];
  return html`<div class="pop filter-pop left" role="dialog" aria-label="Filter">
    <div class="fp-head">${dim && !editing ? html`<button class="btn ghost icon sm" aria-label="Back" onClick=${() => { setDim(null); setValues([]); setText(''); }}><${Icon} name="chevron-left" size=${15} /></button>` : null}${dim && html`<${Icon} name=${dimIcon(dim)} size=${15} class="fp-ico" />`}<b>${dim ? (editing ? 'Edit filter: ' + dimLabel(dim) : dimLabel(dim)) : 'Filter by'}</b></div>
    ${!dim ? html`<div class="searchbox"><${Icon} name="search" size=${14} /><input placeholder="Search filters" aria-label="Search filters" value=${find} onInput=${(e) => setFind(e.target.value)} autofocus /></div>
      <div class="filter-groups">${segs && segs.allowed && segmentItems.length > 0 && html`<div><div class="grp">Segments</div>
        ${segmentItems.map((x) => html`<button class="item" onClick=${() => { setFilters([...parseFilters(scope.query).filter((r) => r[1] !== 'segment'), ['is', 'segment', [x.id]]]); onClose(); }}><span class="item-l"><${Icon} name="bookmark" size=${14} />${x.name}</span>${x.type === 'site' && html`<span class="muted">Shared</span>`}</button>`)}</div>`}
      ${groups.map(([g, list]) => {
        const shown = list.filter(([, l]) => l.toLowerCase().includes(find.toLowerCase()));
        return shown.length ? html`<div><div class="grp">${g}</div>${shown.map(([k, l]) => html`<button class="item" onClick=${() => { setDim(k); setOp('is'); }}><span class="item-l"><${Icon} name=${dimIcon(k)} size=${14} />${l}</span></button>`)}</div>` : null;
      })}</div>
      ${segs && segs.allowed && site.role && html`<div class="fp-links">
        ${scope.filters.some((r) => r[1] !== 'segment') && html`<button class="item" onClick=${() => { onClose(); onSave(); }}><span class="item-l"><${Icon} name="plus" size=${14} />Save current filters as segment</span></button>`}
        <button class="item" onClick=${() => { onClose(); onManage(); }}><span class="item-l"><${Icon} name="settings" size=${14} />Manage segments</span></button></div>`}`
      : html`<div class="form fp-form">
        <div class="seg" role="radiogroup" aria-label="Operator">${OPS.map(([o]) => html`<button type="button" role="radio" aria-checked=${op === o} class=${op === o ? 'on' : ''} onClick=${() => setOp(o)}>${SHORT[o] || o}</button>`)}</div>
        ${values.length > 0 && html`<div class="chips">${values.map((v) => html`<span class="chip"><b><${FilterValueLabel} dim=${dim} v=${v} /></b><button aria-label=${'Remove ' + v} onClick=${() => setValues(values.filter((x) => x !== v))}><${Icon} name="x" size=${12} /></button></span>`)}</div>`}
        <div class="searchbox"><${Icon} name="search" size=${14} /><input value=${text} autofocus onInput=${(e) => setText(e.target.value)} onKeyDown=${onKey} placeholder=${'Type or pick a ' + dimLabel(dim).toLowerCase()} aria-label="Value" /></div>
        ${op.startsWith('contains') || dim === 'page' ? html`<span class="help">Use * as a wildcard, e.g. /blog/*</span>` : null}
        ${options.length > 0 && html`${dim !== 'country' && !search && html`<span class="help sugg-cap">Most common values, by visitors over all time</span>`}<div class="sugg" role="listbox" aria-label="Suggestions">${options.map((r, n) => html`<button role="option" aria-selected=${n === hi} class=${n === hi ? 'on' : ''} onMouseMove=${() => setHi(n)} onClick=${() => pick(r.name)}><span class="trunc"><${FilterValueLabel} dim=${dim} v=${r.name} /></span>${r.visitors !== undefined && html`<em>${fmtShort(r.visitors)}</em>`}</button>`)}</div>`}
      </div>`}
    ${dim && html`<div class="fp-foot"><button class="btn" onClick=${onClose}>Cancel</button><button class="btn primary" onClick=${apply} disabled=${!values.length && !text.trim()}>Apply filter</button></div>`}
  </div>`;
}

function FilterButton({ site, scope, filter, setFilter, segs, onSave, onManage }) {
  const ref = useRef();
  useEffect(() => {
    if (!filter) return;
    const out = (e) => ref.current && !ref.current.contains(e.target) && setFilter(null);
    const esc = (e) => e.key === 'Escape' && setFilter(null);
    document.addEventListener('mousedown', out);
    document.addEventListener('keydown', esc);
    return () => { document.removeEventListener('mousedown', out); document.removeEventListener('keydown', esc); };
  }, [!!filter]);
  return html`<div class="pop-wrap" ref=${ref}>
    <button class="btn" aria-haspopup="dialog" aria-expanded=${!!filter} onClick=${() => setFilter(filter ? null : {})}><${Icon} name="funnel" size=${14} /> Filter${scope.filters.length > 0 && html`<span class="count-badge" aria-label=${scope.filters.length + ' active'}>${scope.filters.length}</span>`}</button>
    ${filter && html`<${FilterMenu} key=${filter.index ?? 'new'} site=${site} scope=${scope} initial=${filter.rule ? filter : null} onClose=${() => setFilter(null)} segs=${segs} onSave=${onSave} onManage=${onManage} />`}
  </div>`;
}

function FilterChips({ scope, edit, segs, onSave, site }) {
  const list = parseFilters(scope.query);
  if (!list.length) return null;
  const valueLabel = (dim, v) => (dim === 'country' ? countryName(v) : dim === 'segment' ? ((segs && segs.list.find((x) => x.id === v)) || { name: 'Unknown segment' }).name : v);
  return html`<div class="chips filter-chips" aria-label="Active filters">${list.map(([op, dim, vals], i) => html`<span class="chip">
    <button class="chip-edit" onClick=${() => dim !== 'segment' && edit({ index: i, rule: [op, dim, vals] })} title=${dim === 'segment' ? 'Segment' : 'Edit filter'}><${Icon} name=${dim === 'segment' ? 'bookmark' : dimIcon(dim)} size=${13} class="chip-ico" /><span>${dimLabel(dim)}</span><em>${opLabel(op)}</em><b>${vals.map((v) => valueLabel(dim, v)).join(' or ')}</b></button>
    <button aria-label=${'Remove filter ' + dimLabel(dim)} onClick=${() => setFilters(list.filter((_, j) => j !== i))}><${Icon} name="x" size=${12} /></button></span>`)}
    ${list.length > 1 && html`<button class="more" onClick=${() => setFilters([])}>Clear all</button>`}
    ${segs && segs.allowed && site.role && list.some((r) => r[1] !== 'segment') && html`<button class="more" onClick=${onSave}>Save as segment</button>`}</div>`;
}

/** Name the current filters and keep them, for yourself or (owners and admins) for everyone who can see the site. */
function SaveSegment({ site, scope, segs, onClose }) {
  const [name, setName] = useState('');
  const [type, setType] = useState('personal');
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true); setErr(null);
    try {
      const r = await api(`/sites/${site.domain}/segments`, { method: 'POST', body: { name, type, filters: parseFilters(scope.query).filter((x) => x[1] !== 'segment') } });
      segs.reload(); setFilters([['is', 'segment', [r.data.id]]]); toast('Segment saved'); onClose();
    } catch (x) { setErr((x.body && x.body.errors && Object.values(x.body.errors)[0][0]) || x.message); setBusy(false); }
  };
  return html`<${Modal} title="Save as segment" onClose=${onClose} footer=${html`<button class="btn" onClick=${onClose}>Cancel</button><button class="btn primary" disabled=${!name.trim() || busy} onClick=${save}>Save segment</button>`}>
    <div class="form">${err && html`<div class="alert">${err}</div>`}
      <div class="seg-sum"><span class="lbl">Filters saved</span>
        <div class="chips">${parseFilters(scope.query).filter((r) => r[1] !== 'segment').map(([op, dim, vals]) => html`<span class="chip static"><${Icon} name=${dimIcon(dim)} size=${13} class="chip-ico" /><span>${dimLabel(dim)}</span><em>${opLabel(op)}</em><b>${vals.map((v) => (dim === 'country' ? countryName(v) : v)).join(' or ')}</b></span>`)}</div></div>
      <${Field} label="Name"><input autofocus maxlength="100" value=${name} onInput=${(e) => setName(e.target.value)} placeholder="Visitors from Germany" /><//>
      <div class="bf-field"><span class="lbl">Who can use it</span>
        <div class="seg" role="radiogroup" aria-label="Who can use it">${[['personal', 'Only me', 'user'], ...(site.can_manage ? [['site', 'Everyone who can see this site', 'users']] : [])].map(([k, l, i]) => html`<button type="button" role="radio" aria-checked=${type === k} class=${type === k ? 'on' : ''} onClick=${() => setType(k)}><${Icon} name=${i} size=${14} />${l}</button>`)}</div></div>
      <p class="muted small">Pick it from the Filter menu any time.</p></div><//>`;
}

function ManageSegments({ site, scope, segs, onClose }) {
  const [err, setErr] = useState(null);
  const [names, setNames] = useState({});
  const current = parseFilters(scope.query).filter((x) => x[1] !== 'segment');
  const run = async (fn) => { setErr(null); try { await fn(); segs.reload(); } catch (x) { setErr(x.message); } };
  const can = (x) => x.type === 'site' ? site.can_manage : x.mine;
  return html`<${Modal} title="Segments" onClose=${onClose} wide footer=${html`<button class="btn" onClick=${onClose}>Done</button>`}>
    ${err && html`<div class="alert">${err}</div>`}
    ${!segs.list.length && html`<p class="muted">No segments yet. Add some filters, then choose Save as segment.</p>`}
    ${segs.list.length > 0 && html`<ul class="rows" aria-label="Segments">${segs.list.map((x) => html`<li class="rowitem">
      <span class="ri-ico" aria-hidden="true"><${Icon} name="bookmark" size=${16} /></span>
      <div class="grow ri-main"><input class="seg-name" aria-label=${'Name of ' + x.name} value=${names[x.id] ?? x.name} disabled=${!can(x)} maxlength="100" onInput=${(e) => setNames({ ...names, [x.id]: e.target.value })} onBlur=${() => names[x.id] && names[x.id] !== x.name && run(() => api(`/sites/${site.domain}/segments/${x.id}`, { method: 'PATCH', body: { name: names[x.id] } }))} /><span class="muted small">${x.type === 'site' ? 'Shared with everyone who can see this site' : 'Only you'}</span></div>
      ${can(x) && html`<button class="btn sm ghost" disabled=${!current.length} title=${current.length ? 'Replace the saved filters with the ones on screen' : 'Apply some filters first'} onClick=${() => run(() => api(`/sites/${site.domain}/segments/${x.id}`, { method: 'PATCH', body: { filters: current } }).then(() => toast('Segment updated')))}>Use current filters</button>
        <button class="btn ghost icon sm danger" aria-label=${'Delete ' + x.name} title="Delete segment" onClick=${() => run(() => api(`/sites/${site.domain}/segments/${x.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button>`}</li>`)}</ul>`}<//>`;
}

// ------------------------------------------------------------------------------------------- date picker
function periodLabel(scope, site) {
  const { query, period } = scope;
  const today = todayStr(site.timezone);
  const date = query.date || today;
  const fmtDay = (d, o) => new Date(d + 'T12:00:00Z').toLocaleDateString(undefined, { timeZone: 'UTC', ...o });
  if (period === 'day') return date === today ? 'Today' : date === shiftDate(today, 'day', -1) ? 'Yesterday' : fmtDay(date, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  if (period === 'month') return date.slice(0, 7) === today.slice(0, 7) ? 'Month to date' : date.slice(0, 7) === shiftDate(today.slice(0, 8) + '01', 'month', -1).slice(0, 7) ? 'Last month' : fmtDay(date, { month: 'long', year: 'numeric' });
  if (period === 'year') return date.slice(0, 4) === today.slice(0, 4) ? 'Year to date' : date.slice(0, 4);
  if (period === 'custom') return query.from && query.to ? `${fmtDay(query.from, { month: 'short', day: 'numeric' })} – ${fmtDay(query.to, { month: 'short', day: 'numeric', year: 'numeric' })}` : 'Custom range';
  return (PERIODS.find((p) => p[0] === period) || [0, { '30d': 'Last 30 days', '6mo': 'Last 6 months' }[period] || 'Last 28 days'])[1];
}

function DatePicker({ site, scope }) {
  const [open, setOpen] = useState(false);
  const [custom, setCustom] = useState(false);
  const { query, period } = scope;
  const [from, setFrom] = useState(query.from || '');
  const [to, setTo] = useState(query.to || '');
  const navigable = ['day', 'month', 'year'].includes(period);
  const today = todayStr(site.timezone);
  const date = query.date || today;
  const shift = (dir) => { const d = shiftDate(date, period, dir); if (d > today) return; setQuery({ date: d === today ? null : d }); };
  const yesterday = shiftDate(today, 'day', -1);
  const lastMonth = shiftDate(today.slice(0, 8) + '01', 'month', -1);
  const choose = (p) => {
    const q = { period: p, date: null, from: null, to: null, interval: null };
    if (p === 'yesterday') Object.assign(q, { period: 'day', date: yesterday });
    else if (p === 'last_month') Object.assign(q, { period: 'month', date: lastMonth });
    if (!['yesterday', 'last_month', 'realtime'].includes(p)) rememberPeriod(site, p); // a past day or month is not worth remembering
    setQuery(q); setOpen(false);
  };
  const isOn = (k) => (k === 'day' ? period === 'day' && date === today : k === 'yesterday' ? period === 'day' && date === yesterday : k === 'month' ? period === 'month' && date.slice(0, 7) === today.slice(0, 7) : k === 'last_month' ? period === 'month' && date.slice(0, 7) === lastMonth.slice(0, 7) : k === period);
  const comparison = query.comparison || '';
  const canCompare = !['realtime', 'all'].includes(period);
  useEffect(() => {
    const k = (e) => {
      if (e.metaKey || e.ctrlKey || e.altKey || /input|textarea|select/i.test(e.target.tagName || '') || e.target.isContentEditable || document.querySelector('.overlay')) return;
      const key = e.key.toUpperCase();
      const hit = PERIODS.find((p) => p[2] === key);
      if (hit) { e.preventDefault(); if (hit[0] === 'custom') { setOpen(true); setCustom(true); } else choose(hit[0]); }
      else if (key === 'X' && canCompare) setQuery({ comparison: comparison ? null : 'previous_period' });
      else if (navigable && e.key === 'ArrowLeft') shift(-1);
      else if (navigable && e.key === 'ArrowRight') shift(1);
    };
    document.addEventListener('keydown', k);
    return () => document.removeEventListener('keydown', k);
  });
  useEffect(() => {
    const h = (e) => {
      const c = e.detail || {};
      if (c.type === 'period') { if (c.key === 'custom') { setOpen(true); setCustom(true); } else choose(c.key); }
      else if (c.type === 'compare' && canCompare) setQuery({ comparison: comparison ? null : 'previous_period' });
    };
    window.addEventListener('tdm:cmd', h);
    return () => window.removeEventListener('tdm:cmd', h);
  });
  return html`<div class="row" style="gap:4px">
    ${navigable && html`<button class="btn icon" aria-label="Previous" onClick=${() => shift(-1)}><${Icon} name="chevron-left" size=${15} /></button>`}
    <${Pop} open=${open} setOpen=${setOpen} align="right" ariaLabel="Date range" btnClass="btn" label=${html`<${Icon} name="calendar" size=${14} /><span>${periodLabel(scope, site)}</span><${Icon} name="chevron-down" size=${13} class="muted" />`}>
      ${() => html`<div style="min-width:240px">
        ${PERIODS.filter((p) => p[0] !== 'custom').map(([k, l, key]) => html`<button class=${'item ' + (isOn(k) ? 'on' : '')} onClick=${() => choose(k)}><span>${l}</span>${key ? html`<span class="kbd">${key}</span>` : null}</button>`)}
        ${!custom ? html`<button class=${'item ' + (period === 'custom' ? 'on' : '')} onClick=${() => setCustom(true)}><span>Custom range</span><span class="kbd">C</span></button>`
          : html`<div class="form" style="width:240px"><label>From<input type="date" max=${today} value=${from} onInput=${(e) => setFrom(e.target.value)} /></label><label>To<input type="date" max=${today} value=${to} onInput=${(e) => setTo(e.target.value)} /></label>
            <button class="btn primary" disabled=${!from || !to || from > to} onClick=${() => { setQuery({ period: 'custom', from, to, date: null, interval: null }); setOpen(false); }}>Apply</button></div>`}
        ${canCompare && html`<hr /><button class="item" onClick=${() => { setQuery({ comparison: comparison ? null : 'previous_period' }); setOpen(false); }}><span>${comparison ? 'Disable comparison' : 'Compare'}</span><span class="kbd">X</span></button>`}
      </div>`}
    <//>
    ${comparison && canCompare && html`<span class="muted vs">vs</span><${Pop} align="right" ariaLabel="Comparison period" btnClass="btn" label=${html`<${Icon} name="calendar" size=${14} /><span>${comparison === 'year_over_year' ? 'Year over year' : 'Previous period'}</span><${Icon} name="chevron-down" size=${13} class="muted" />`}>
      ${(close) => html`<div style="min-width:200px"><button class=${'item ' + (comparison === 'previous_period' ? 'on' : '')} onClick=${() => { setQuery({ comparison: 'previous_period' }); close(); }}>Previous period</button>
        <button class=${'item ' + (comparison === 'year_over_year' ? 'on' : '')} onClick=${() => { setQuery({ comparison: 'year_over_year' }); close(); }}>Year over year</button></div>`}<//>`}
    ${navigable && html`<button class="btn icon" aria-label="Next" disabled=${date >= today} onClick=${() => shift(1)}><${Icon} name="chevron-right" size=${15} /></button>`}
  </div>`;
}

function MoreMenu({ site, scope, intervals }) {
  const exportUrl = () => {
    const q = new URLSearchParams(scope.params);
    const a = shareAuth(); if (a) q.set('auth', a);
    return `${BASE}/api/sites/${site.domain}/stats/export?${q}`;
  };
  return html`<${Pop} ariaLabel="More" btnClass="btn icon" label=${html`<${Icon} name="ellipsis-vertical" size=${16} />`}>
    ${(close) => html`<div style="min-width:200px">
      ${intervals && intervals.list.length > 1 && html`<div class="grp">Interval</div>${intervals.list.map((i) => html`<button class=${'item ' + (i === intervals.current ? 'on' : '')} onClick=${() => { setQuery({ interval: i }); close(); }}><span>${i[0].toUpperCase() + i.slice(1)}s</span>${i === intervals.current && html`<${Icon} name="check" size=${14} class="check" />`}</button>`)}<hr />`}
      <a class="item" href=${exportUrl()} download onClick=${close}><span>Export as CSV</span><${Icon} name="download" size=${14} /></a>
      ${site.can_manage && html`<${Link} to=${`/${site.domain}/settings`} class="item" onClick=${close}><span>Site settings</span><${Icon} name="settings" size=${14} /><//>`}
    </div>`}<//>`;
}

// ---------------------------------------------------------------------------------------- site switcher
function SiteSwitcher({ site, linkable }) {
  const sites = useApi(linkable ? '/sites' : null, null, [], { enabled: linkable });
  if (!linkable) return html`<div class="siteswitch static"><${Icon} name=${site.rollup ? 'layers' : 'globe'} size=${16} /><b>${site.domain}</b></div>`;
  return html`<${Pop} align="left" ariaLabel="Switch site" btnClass="siteswitch" label=${html`<${Icon} name=${site.rollup ? 'layers' : 'globe'} size=${16} /><b>${site.domain}</b><${Icon} name="chevron-down" size=${13} class="muted" />`}>
    ${(close) => html`<div style="min-width:240px">
      ${[...((sites.data && sites.data.data) || [])].sort((a, b) => (a.rollup !== b.rollup ? (a.rollup ? -1 : 1) : a.domain.localeCompare(b.domain))).map((s) => html`<${Link} to=${'/' + s.domain} class=${'item ' + (s.domain === site.domain ? 'on' : '')} onClick=${close}><span>${s.domain}</span>${s.rollup && html`<span class="muted">Rollup</span>`}<//>`)}
      <hr /><${Link} to="/sites/new" class="item" onClick=${close}><span>Add a site</span><${Icon} name="plus" size=${14} /><//>
      <${Link} to="/sites" class="item" onClick=${close}><span>All sites</span><//></div>`}<//>`;
}

function CurrentVisitors({ site, scope }) {
  const res = useApi(`/sites/${site.domain}/stats/current-visitors`, scope.params.filters ? { filters: scope.params.filters } : {}, [], { poll: 30000 });
  const n = res.data ? res.data.visitors : null;
  return html`<button class="live" onClick=${() => setQuery({ period: 'realtime', date: null, from: null, to: null, comparison: null })} title="See realtime activity"><span class=${'dot ' + (n ? '' : 'off')}></span>${n === null ? '…' : fmt(n)} <span class="muted">current visitor${n === 1 ? '' : 's'}</span></button>`;
}

// -------------------------------------------------------------------------------------------- onboarding
export function Snippet({ site }) {
  const base = (window.__cfg && window.__cfg.script_base) || window.SD.origin + '/js';
  const tag = `<script defer data-domain="${site.domain}" src="${base}/${site.script}"></script>`;
  return html`<${CodeBlock} text=${tag} />`;
}

function Waiting({ site, onData }) {
  useEffect(() => { const id = setInterval(() => api(`/sites/${site.domain}`).then((r) => r.data.has_data && onData()).catch(() => {}), 5000); return () => clearInterval(id); }, [site.domain]);
  if (site.rollup) return html`<section class="card waiting"><div class="onboard-ico"><span class="dot"></span></div><div class="grow"><h2>No visits yet</h2><p class="muted">${site.rollup.count === 0 ? 'This rollup has no sites yet.' : `The ${site.rollup.count} ${site.rollup.count === 1 ? 'site' : 'sites'} in this rollup haven't recorded a visit.`} ${site.can_manage ? html`<${Link} to=${`/${site.domain}/settings/sites`}>Choose its sites<//>` : ''}</p></div></section>`;
  return html`<section class="card waiting"><div class="onboard-ico"><span class="dot"></span></div>
    <div class="grow"><h2>Waiting for your first pageview</h2><p class="muted">Paste this snippet in the <code>${"<head>"}</code> of every page on <b>${site.domain}</b>. This page updates as soon as the first visit arrives.</p><${Snippet} site=${site} /></div></section>`;
}

// ----------------------------------------------------------------------------------------------- page
function SharePassword({ domain, onDone }) {
  const [pw, setPw] = useState('');
  const [err, setErr] = useState(false);
  const submit = async (e) => {
    e.preventDefault(); share.password = pw;
    try { await api(`/sites/${domain}`); onDone(); } catch (x) { setErr(true); }
  };
  return html`<div class="auth-body"><form class="auth-card stack" onSubmit=${submit}><div><h1>Password required</h1><p class="muted" style="margin-top:4px">${domain} is shared with a password.</p></div>
    ${err && html`<div class="alert">That password isn't right.</div>`}<${Field} label="Password"><input type="password" autofocus required value=${pw} onInput=${(e) => setPw(e.target.value)} /><//><button class="btn primary" style="height:38px">View dashboard</button></form></div>`;
}

const SecHead = ({ title, sub }) => html`<header class="sec-head"><h2>${title}</h2><span class="muted">${sub}</span></header>`;

export function Dashboard({ domain, shared }) {
  const { user } = useContext(UserCtx) || {};
  const site = useApi(`/sites/${domain}`, null, [domain]);
  const [rev, setRev] = useState(0);
  const [intervals, setIntervals] = useState(null);
  const [filter, setFilter] = useState(null);
  const [segmentDialog, setSegmentDialog] = useState(null);
  const s = site.data && site.data.data;
  const scope = useScope(s);
  const segmentsOn = !!(s && s.features && s.features.segments);
  const segData = useApi(segmentsOn ? `/sites/${domain}/segments` : null, null, [domain, segmentsOn], { enabled: segmentsOn });
  const segs = segmentsOn ? { allowed: !segData.data || segData.data.allowed !== false, list: (segData.data && segData.data.data) || [], reload: segData.reload } : null;
  useDocTitle(domain);
  // visitors to a public or shared dashboard see the site's own branding; signed-in members keep the normal look
  const brand = !user && s ? s.brand : null;
  useEffect(() => { setBrand(brand); return () => setBrand(null); }, [brand && brand.color, brand && brand.name, brand && brand.has_logo, brand && brand.logo && brand.logo.length]);
  useEffect(() => {
    // arriving from another page of the site via a sidebar section link
    if (!s || !window.__tdmJump) return;
    const id = window.__tdmJump;
    window.__tdmJump = null;
    setTimeout(() => scrollToSection(id), 250);
  }, [s && s.domain]);
  useEffect(() => {
    const h = (e) => {
      const c = e.detail || {};
      if (c.type === 'filter') setFilter({});
      else if (c.type === 'export' && s) { const q = new URLSearchParams(scope.params); const a = shareAuth(); if (a) q.set('auth', a); window.location.assign(`${BASE}/api/sites/${s.domain}/stats/export?${q}`); }
    };
    window.addEventListener('tdm:cmd', h);
    return () => window.removeEventListener('tdm:cmd', h);
  });
  useEffect(() => {
    const onScroll = () => { const t = document.querySelector('.toolbar.sticky'); if (t) t.classList.toggle('raised', window.scrollY > 8); };
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);
  if (site.error && site.error.body && site.error.body.error === 'password_required') return html`<${SharePassword} domain=${domain} onDone=${() => site.reload()} />`;
  if (site.error) return html`<main class="main"><${Empty} title=${site.error.status === 404 ? 'Site not found' : 'No access'} icon="lock">${site.error.status === 404 ? 'There is no site with this address.' : "You don't have access to this dashboard."}<${Link} to="/sites" class="btn" style="margin-top:8px">Back to your sites<//><//></main>`;
  if (!s) return html`<main class="main"><${Loading} rows=${8} /></main>`;
  const live = scope.period === 'realtime';
  const poll = live ? 10000 : 0;
  return html`<main class="main dash" id="main" key=${rev}>
    <div class="toolbar sticky">
      <h1 class="dash-title"><${Icon} name=${s.rollup ? 'layers' : 'globe'} size=${18} />${s.domain}</h1>
      <${SiteSwitcher} site=${s} linkable=${!shared && !!user} />
      <${CurrentVisitors} site=${s} scope=${scope} />
      <span class="grow"></span>
      <${FilterButton} site=${s} scope=${scope} filter=${filter} setFilter=${setFilter} segs=${segs} onSave=${() => setSegmentDialog('save')} onManage=${() => setSegmentDialog('manage')} />
      <${DatePicker} site=${s} scope=${scope} />
      <${MoreMenu} site=${s} scope=${scope} intervals=${intervals} />
    </div>
    <${FilterChips} scope=${scope} edit=${(f) => setFilter(f)} segs=${segs} site=${s} onSave=${() => setSegmentDialog('save')} />
    ${segmentDialog === 'save' && html`<${SaveSegment} site=${s} scope=${scope} segs=${segs} onClose=${() => setSegmentDialog(null)} />`}
    ${segmentDialog === 'manage' && html`<${ManageSegments} site=${s} scope=${scope} segs=${segs} onClose=${() => setSegmentDialog(null)} />`}
    ${s.imported && scope.filters.length > 0 && html`<p class="muted small imported-note">Data imported from Plausible (${s.imported.start} to ${s.imported.end}) is left out while a filter is applied.</p>`}
    ${!s.has_data && !live && html`<${Waiting} site=${s} onData=${() => { site.reload(); setRev((r) => r + 1); }} />`}
    <div id="sec-overview" class="dsec first"><${TopGraph} site=${s} scope=${scope} live=${live} onIntervals=${setIntervals} /></div>
    <section id="sec-traffic" class="dsec"><${SecHead} title="Traffic" sub="Where visitors come from and what they read" />
      <div class="grid2 panels"><${Sources} site=${s} scope=${scope} poll=${poll} /><${Pages} site=${s} scope=${scope} poll=${poll} /></div></section>
    <section id="sec-audience" class="dsec"><${SecHead} title="Audience" sub="Who they are, where they are and what they use" />
      <div class="grid2 panels"><${Locations} site=${s} scope=${scope} poll=${poll} /><${Devices} site=${s} scope=${scope} poll=${poll} /></div></section>
    <section id="sec-conversions" class="dsec"><${SecHead} title="Conversions" sub="Goals, funnels, properties and journeys" />
      <${Behaviors} site=${s} scope=${scope} poll=${poll} /></section>
  </main>`;
}
