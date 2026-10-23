// The site dashboard: toolbar (site, filters, date), top stats + graph, then the report panels.
import { setBrand, html, useState, useEffect, useMemo, useRef, useApi, api, useLocation, setQuery, setFilters, parseFilters, navigate, Link, Icon, toast, PERIODS, FILTER_OPS, FILTER_DIMS, dimLabel, todayStr, shiftDate, fmt, fmtShort, dur, countryName, shareAuth, share, useDocTitle, useScope, BASE, UserCtx, useContext } from './lib.js';
import { Pop, Modal, Loading, Empty, Field, CodeBlock, useDebounced } from './ui.js';
import { LineChart, ChartTable, bucketText } from './chart.js';
import { Sources, Pages, Locations, Devices, Behaviors } from './reports.js';

// ------------------------------------------------------------------------------------------ metrics
const METRICS = {
  visitors: ['Unique visitors', fmtShort], visits: ['Total visits', fmtShort], pageviews: ['Total pageviews', fmtShort],
  views_per_visit: ['Views per visit', (v) => fmt(v)], bounce_rate: ['Bounce rate', (v) => v + '%'], visit_duration: ['Visit duration', dur],
};
const GOAL_METRICS = { unique_conversions: ['Unique conversions', fmtShort], total_conversions: ['Total conversions', fmtShort], conversion_rate: ['Conversion rate', (v) => v + '%'] };
const HELP = {
  visitors: 'People who visited, counted once per day', visits: 'Sessions: a visit ends after 30 minutes of inactivity', pageviews: 'Every page loaded or navigated to', views_per_visit: 'Average pageviews per visit',
  bounce_rate: 'Visits that left after a single page', visit_duration: 'Average time between the first and last activity of a visit',
  unique_conversions: 'Visitors who completed the goal', total_conversions: 'How many times the goal was completed', conversion_rate: 'Unique conversions as a share of visitors',
};
const LOWER_IS_BETTER = new Set(['bounce_rate']);

function Delta({ metric, change }) {
  if (change === null || change === undefined) return null;
  const flat = Math.abs(change) < 0.05;
  const good = LOWER_IS_BETTER.has(metric) ? change < 0 : change > 0;
  return html`<span class=${'delta ' + (flat ? 'flat' : good ? 'up' : 'down')}>${!flat && html`<${Icon} name=${change > 0 ? 'arrow-up' : 'arrow-down'} size=${11} />`}${Math.abs(change).toFixed(change % 1 === 0 ? 0 : 1)}%</span>`;
}

function TopGraph({ site, scope, live, onIntervals }) {
  const [metric, setMetric] = useState('visitors');
  const [asTable, setAsTable] = useState(false);
  const base = `/sites/${site.domain}/stats`;
  const poll = live ? 5000 : 0;
  const top = useApi(`${base}/top`, scope.params, [], { poll });
  const graph = useApi(`${base}/graph`, scope.params, [], { poll });
  const defs = scope.goal ? GOAL_METRICS : METRICS;
  const current = defs[metric] ? metric : Object.keys(defs)[0];
  const [name, format] = defs[current];
  // while the filter changes, the previous response may use the other set of metrics; treat that as still loading
  const g = graph.data && graph.data.plot[current] ? graph.data : null;
  useEffect(() => { if (g) onIntervals({ list: g.intervals, current: g.interval }); }, [g && g.interval, g && g.intervals.join()]);
  const stats = top.data && top.data.stats;
  const comparing = top.data && top.data.comparing;
  const busy = top.loading || graph.loading;
  return html`<section class="card graph-card" aria-label="Top stats and graph">
    <div class="metric-tabs" role="tablist">${Object.entries(defs).map(([k, [label, fmtv]]) => {
      const s = stats && stats[k];
      return html`<button role="tab" aria-selected=${k === current} title=${HELP[k]} class=${'metric ' + (k === current ? 'on' : '')} onClick=${() => setMetric(k)}>
        <span class="k">${label}</span>
        <span class="v">${s ? fmtv(s.value) : html`<span class="skeleton" style="display:inline-block;width:64px;height:26px"></span>`}</span>
        <span class="d" title=${s && comparing && s.previous !== null && s.previous !== undefined ? 'Previous: ' + fmtv(s.previous) : ''}>${s && comparing && !(s.value === 0 && !s.previous) ? html`<${Delta} metric=${k} change=${s.change} />` : ''}</span></button>`;
    })}</div>
    <div class=${'graph-b ' + (busy && g ? 'stale' : '')}>
      ${graph.error && !g ? html`<div class="panel-empty" style="min-height:300px"><b>Couldn't load the graph</b><p class="muted">${graph.error.message}</p><button class="btn sm" onClick=${() => { graph.reload(); top.reload(); }}>Try again</button></div>` : !g ? html`<div class="skeleton" style="height:300px"></div>` : asTable
        ? html`<${ChartTable} labels=${g.labels} values=${g.plot[current]} prev=${(g.comparison && g.comparison.plot[current]) || null} format=${format} name=${name} interval=${g.interval} />`
        : html`<${LineChart} labels=${g.labels} values=${g.plot[current]} prev=${g.comparison && g.comparison.plot[current] ? g.comparison.plot[current] : null} prevLabels=${g.comparison && g.comparison.labels} format=${format} name=${name} interval=${g.interval} live=${live} />`}
      <div class="graph-foot">
        <span class="legend"><span><i style="background:var(--series-1)"></i>${name}</span>${g && g.comparison && html`<span><i style="background:var(--series-2)"></i>Comparison</span>`}</span>
        <button class="more" onClick=${() => setAsTable(!asTable)}>${asTable ? 'View as chart' : 'View as table'}</button>
      </div>
    </div>
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

function FilterMenu({ site, scope, initial, onClose }) {
  const editing = initial && initial.index !== undefined;
  const [dim, setDim] = useState(initial ? initial.rule[1] : null);
  const [op, setOp] = useState(initial ? initial.rule[0] : 'is');
  const [values, setValues] = useState(initial ? initial.rule[2] : []);
  const [text, setText] = useState('');
  const [find, setFind] = useState('');
  const search = useDebounced(text, 200);
  const props = useApi(`/sites/${site.domain}/stats/props`, { period: 'all' }, []);
  const sugg = useApi(dim ? (dim === 'goal' ? `/sites/${site.domain}/stats/goals` : dim.startsWith('prop:') ? `/sites/${site.domain}/stats/props/${encodeURIComponent(dim.slice(5))}` : `/sites/${site.domain}/stats/breakdown/${dim}`) : null,
    { period: 'all', limit: dim === 'country' ? 250 : 8, search: dim === 'country' ? undefined : search || undefined }, [dim, search], { enabled: !!dim });
  const options = ((sugg.data && (sugg.data.results || [])) || []).filter((r) => !search || String(r.name).toLowerCase().includes(search.toLowerCase()) || (dim === 'country' && countryName(r.name).toLowerCase().includes(search.toLowerCase()))).map((r) => r.name).filter((n) => !values.includes(n)).slice(0, 8);
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
  return html`<div class="pop filter-pop left" role="dialog" aria-label="Filter">
    <div class="fp-head">${dim && !editing ? html`<button class="btn ghost icon sm" aria-label="Back" onClick=${() => { setDim(null); setValues([]); setText(''); }}><${Icon} name="chevron-left" size=${15} /></button>` : null}<b>${dim ? (editing ? 'Edit filter' : dimLabel(dim)) : 'Filter by'}</b></div>
    ${!dim ? html`<div class="searchbox"><${Icon} name="search" size=${14} /><input placeholder="Search filters" aria-label="Search filters" value=${find} onInput=${(e) => setFind(e.target.value)} autofocus /></div>
      <div class="filter-groups">${groups.map(([g, list]) => {
        const shown = list.filter(([, l]) => l.toLowerCase().includes(find.toLowerCase()));
        return shown.length ? html`<div><div class="grp">${g}</div>${shown.map(([k, l]) => html`<button class="item" onClick=${() => { setDim(k); setOp(k === 'goal' ? 'is' : 'is'); }}>${l}</button>`)}</div>` : null;
      })}</div>`
      : html`<div class="form">
        <select aria-label="Operator" value=${op} onChange=${(e) => setOp(e.target.value)}>${FILTER_OPS.filter(([o]) => multi || o === 'is' || o === 'is_not').map(([o, l]) => html`<option value=${o} selected=${op === o}>${l}</option>`)}</select>
        <div class="chips">${values.map((v) => html`<span class="chip"><b><${FilterValueLabel} dim=${dim} v=${v} /></b><button aria-label=${'Remove ' + v} onClick=${() => setValues(values.filter((x) => x !== v))}><${Icon} name="x" size=${12} /></button></span>`)}</div>
        <${Field} label="Value" help=${op.startsWith('contains') || dim === 'page' ? 'Use * as a wildcard, e.g. /blog/*' : ''}><input value=${text} autofocus onInput=${(e) => setText(e.target.value)} onKeyDown=${(e) => { if (e.key === 'Enter') { e.preventDefault(); if (text.trim()) { multi ? add(text) : setValues([norm(text)]); } else apply(); } }} placeholder=${'Type or pick a ' + dimLabel(dim).toLowerCase()} /><//>
        ${options.length > 0 && html`<div class="sugg">${options.map((n) => html`<button onClick=${() => (multi ? add(n) : setValues([n]))}><span><${FilterValueLabel} dim=${dim} v=${n} /></span></button>`)}</div>`}
      </div>`}
    ${dim && html`<div class="fp-foot"><button class="btn primary" onClick=${apply} disabled=${!values.length && !text.trim()}>Apply filter</button></div>`}
  </div>`;
}

function FilterButton({ site, scope, filter, setFilter }) {
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
    <button class="btn" aria-haspopup="dialog" aria-expanded=${!!filter} aria-keyshortcuts="F" onClick=${() => setFilter(filter ? null : {})}><${Icon} name="funnel" size=${14} /> Filter</button>
    ${filter && html`<${FilterMenu} key=${filter.index ?? 'new'} site=${site} scope=${scope} initial=${filter.rule ? filter : null} onClose=${() => setFilter(null)} />`}
  </div>`;
}

function FilterChips({ scope, edit }) {
  const list = parseFilters(scope.query);
  if (!list.length) return null;
  return html`<div class="chips filter-chips" aria-label="Active filters">${list.map(([op, dim, vals], i) => html`<span class="chip">
    <button class="chip-edit" onClick=${() => edit({ index: i, rule: [op, dim, vals] })} title="Edit filter"><span>${dimLabel(dim)}</span><em>${opLabel(op)}</em><b>${vals.map((v) => (dim === 'country' ? countryName(v) : v)).join(' or ')}</b></button>
    <button aria-label=${'Remove filter ' + dimLabel(dim)} onClick=${() => setFilters(list.filter((_, j) => j !== i))}><${Icon} name="x" size=${12} /></button></span>`)}
    ${list.length > 1 && html`<button class="more" onClick=${() => setFilters([])}>Clear all</button>`}</div>`;
}

// ------------------------------------------------------------------------------------------- date picker
function periodLabel(scope, site) {
  const { query, period } = scope;
  const today = todayStr(site.timezone);
  const date = query.date || today;
  const fmtDay = (d, o) => new Date(d + 'T12:00:00Z').toLocaleDateString(undefined, { timeZone: 'UTC', ...o });
  if (period === 'day') return date === today ? 'Today' : fmtDay(date, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
  if (period === 'month') return date.slice(0, 7) === today.slice(0, 7) ? 'Month to date' : fmtDay(date, { month: 'long', year: 'numeric' });
  if (period === 'year') return date.slice(0, 4) === today.slice(0, 4) ? 'Year to date' : date.slice(0, 4);
  if (period === 'custom') return query.from && query.to ? `${fmtDay(query.from, { month: 'short', day: 'numeric' })} – ${fmtDay(query.to, { month: 'short', day: 'numeric', year: 'numeric' })}` : 'Custom range';
  return (PERIODS.find((p) => p[0] === period) || [0, 'Last 28 days'])[1];
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
  const choose = (p) => { setQuery({ period: p === '28d' ? null : p, date: null, from: null, to: null, interval: null }); setOpen(false); };
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
  return html`<div class="row" style="gap:4px">
    ${navigable && html`<button class="btn icon" aria-label="Previous" onClick=${() => shift(-1)}><${Icon} name="chevron-left" size=${15} /></button>`}
    <${Pop} open=${open} setOpen=${setOpen} align="right" ariaLabel="Date range" btnClass="btn" label=${html`<${Icon} name="calendar" size=${14} /><span>${periodLabel(scope, site)}</span>${comparison && canCompare ? html`<span class="muted">vs ${comparison === 'year_over_year' ? 'last year' : 'previous'}</span>` : ''}<${Icon} name="chevron-down" size=${13} class="muted" />`}>
      ${() => html`<div style="min-width:240px">
        ${PERIODS.filter((p) => p[0] !== 'custom').map(([k, l, key]) => html`<button class=${'item ' + (k === period && !(k === 'day' && date !== today) ? 'on' : '')} onClick=${() => choose(k)}><span>${l}</span>${key ? html`<span class="kbd">${key}</span>` : null}</button>`)}
        ${!custom ? html`<button class=${'item ' + (period === 'custom' ? 'on' : '')} onClick=${() => setCustom(true)}><span>Custom range</span><span class="kbd">C</span></button>`
          : html`<div class="form" style="width:240px"><label>From<input type="date" max=${today} value=${from} onInput=${(e) => setFrom(e.target.value)} /></label><label>To<input type="date" max=${today} value=${to} onInput=${(e) => setTo(e.target.value)} /></label>
            <button class="btn primary" disabled=${!from || !to || from > to} onClick=${() => { setQuery({ period: 'custom', from, to, date: null, interval: null }); setOpen(false); }}>Apply</button></div>`}
        ${canCompare && html`<hr /><button class="item" onClick=${() => setQuery({ comparison: comparison ? null : 'previous_period' })}><span>Compare</span><span class="row" style="gap:8px">${comparison && html`<${Icon} name="check" size=${14} class="check" />`}<span class="kbd">X</span></span></button>
          ${comparison && html`<button class=${'item ' + (comparison === 'previous_period' ? 'on' : '')} onClick=${() => setQuery({ comparison: 'previous_period' })}>Previous period</button><button class=${'item ' + (comparison === 'year_over_year' ? 'on' : '')} onClick=${() => setQuery({ comparison: 'year_over_year' })}>Year over year</button>`}`}
      </div>`}
    <//>
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
  if (!linkable) return html`<div class="siteswitch static"><${Icon} name="globe" size=${16} /><b>${site.domain}</b></div>`;
  return html`<${Pop} align="left" ariaLabel="Switch site" btnClass="siteswitch" label=${html`<${Icon} name="globe" size=${16} /><b>${site.domain}</b><${Icon} name="chevron-down" size=${13} class="muted" />`}>
    ${(close) => html`<div style="min-width:240px">
      ${((sites.data && sites.data.data) || []).map((s) => html`<${Link} to=${'/' + s.domain} class=${'item ' + (s.domain === site.domain ? 'on' : '')} onClick=${close}><span>${s.domain}</span><//>`)}
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

export function Dashboard({ domain, shared }) {
  const { user } = useContext(UserCtx) || {};
  const site = useApi(`/sites/${domain}`, null, [domain]);
  const [rev, setRev] = useState(0);
  const [intervals, setIntervals] = useState(null);
  const [filter, setFilter] = useState(null);
  const s = site.data && site.data.data;
  const scope = useScope(s);
  useDocTitle(domain);
  // visitors to a public or shared dashboard see the site's own branding; signed-in members keep the normal look
  const brand = !user && s ? s.brand : null;
  useEffect(() => { setBrand(brand); return () => setBrand(null); }, [brand && brand.color, brand && brand.name, brand && brand.has_logo, brand && brand.logo && brand.logo.length]);
  useEffect(() => {
    const onScroll = () => { const t = document.querySelector('.toolbar.sticky'); if (t) t.classList.toggle('raised', window.scrollY > 8); };
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);
  useEffect(() => {
    const k = (e) => { if (e.key.toUpperCase() === 'F' && !e.metaKey && !e.ctrlKey && !/input|textarea|select/i.test(e.target.tagName || '') && !document.querySelector('.overlay')) { e.preventDefault(); setFilter({}); } };
    document.addEventListener('keydown', k);
    return () => document.removeEventListener('keydown', k);
  }, []);
  if (site.error && site.error.body && site.error.body.error === 'password_required') return html`<${SharePassword} domain=${domain} onDone=${() => site.reload()} />`;
  if (site.error) return html`<main class="main"><${Empty} title=${site.error.status === 404 ? 'Site not found' : 'No access'} icon="lock">${site.error.status === 404 ? 'There is no site with this address.' : "You don't have access to this dashboard."}<${Link} to="/sites" class="btn" style="margin-top:8px">Back to your sites<//><//></main>`;
  if (!s) return html`<main class="main"><${Loading} rows=${8} /></main>`;
  const live = scope.period === 'realtime';
  const poll = live ? 10000 : 0;
  return html`<main class="main dash" id="main" key=${rev}>
    <div class="toolbar sticky">
      <${SiteSwitcher} site=${s} linkable=${!shared && !!user} />
      <${CurrentVisitors} site=${s} scope=${scope} />
      <span class="grow"></span>
      <${FilterButton} site=${s} scope=${scope} filter=${filter} setFilter=${setFilter} />
      <${DatePicker} site=${s} scope=${scope} />
      <${MoreMenu} site=${s} scope=${scope} intervals=${intervals} />
    </div>
    <${FilterChips} scope=${scope} edit=${(f) => setFilter(f)} />
    ${!s.has_data && !live && html`<${Waiting} site=${s} onData=${() => { site.reload(); setRev((r) => r + 1); }} />`}
    <${TopGraph} site=${s} scope=${scope} live=${live} onIntervals=${setIntervals} />
    <div class="grid2 panels">
      <${Sources} site=${s} scope=${scope} poll=${poll} /><${Pages} site=${s} scope=${scope} poll=${poll} />
      <${Locations} site=${s} scope=${scope} poll=${poll} /><${Devices} site=${s} scope=${scope} poll=${poll} />
    </div>
    <${Behaviors} site=${s} scope=${scope} poll=${poll} />
  </main>`;
}
