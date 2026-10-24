// The report panels on the dashboard: Sources, Pages, Locations, Devices and Behaviors.
import { html, useState, setFilters, useEffect, useRef, useMemo, useApi, api, addFilter, fmt, fmtShort, moneyShort, dur, flag, countryName, Icon, Link } from './lib.js';
import { Modal, useDebounced, Empty } from './ui.js';
import { WorldMap } from './chart.js';

const DEVICE_ICON = { Desktop: 'monitor', Laptop: 'laptop', Tablet: 'tablet', Mobile: 'smartphone' };

/** The cell content for a row's name, depending on which dimension it belongs to. */
export function Label({ dim, name }) {
  if (dim === 'country') return html`<span class="flag" aria-hidden="true">${flag(name)}</span><span class="t">${countryName(name)}</span>`;
  if (dim === 'device') return html`<${Icon} name=${DEVICE_ICON[name] || 'monitor'} size=${14} class="muted" /><span class="t">${name}</span>`;
  return html`<span class="t" title=${name}>${name}</span>`;
}

const COLUMNS = {
  page: [['visitors', 'Visitors', fmtShort], ['pageviews', 'Pageviews', fmtShort], ['bounce_rate', 'Bounce rate', (v) => (v === null || v === undefined ? '–' : v + '%')]],
  entry_page: [['visitors', 'Unique entrances', fmtShort], ['visits', 'Total entrances', fmtShort], ['visit_duration', 'Visit duration', dur]],
  exit_page: [['visitors', 'Unique exits', fmtShort], ['visits', 'Total exits', fmtShort], ['exit_rate', 'Exit rate', (v) => v + '%']],
  _default: [['visitors', 'Visitors', fmtShort], ['bounce_rate', 'Bounce rate', (v) => v + '%'], ['visit_duration', 'Visit duration', dur]],
};
/** Uniques, Total and CR for a goal, with Revenue and Average between them when the goal reports revenue. */
const goalColumns = (currency) => [['visitors', 'Uniques', fmtShort], ['events', 'Total', fmtShort], ...(currency ? [['revenue', 'Revenue', (v) => moneyShort(v, currency)], ['average_revenue', 'Average', (v) => moneyShort(v, currency)]] : []), ['conversion_rate', 'CR', (v) => v + '%']];
const columnsFor = (dim, goalMode, currency) => (goalMode ? goalColumns(currency) : COLUMNS[dim] || COLUMNS._default);

const pickRow = (dim, row) => addFilter(dim, row.name);

/** A ranked list with proportional bars. */
export function RankedList({ rows, dim, goalMode, currency, loading, empty = 'No data yet', head, onPick = pickRow, limit, error, onRetry, filtered, linkOut }) {
  if (error && !rows) return html`<div class="panel-empty"><b>Couldn't load this report</b><p class="muted">${error.message}</p>${onRetry && html`<button class="btn sm" onClick=${onRetry}><${Icon} name="refresh-cw" size=${13} /> Try again</button>`}</div>`;
  if (!rows) return html`<div class="stack sm" aria-busy="true">${Array.from({ length: 6 }, (_, i) => html`<div class="skeleton" style=${`height:28px;width:${100 - i * 6}%`}></div>`)}</div>`;
  const shown = limit ? rows.slice(0, limit) : rows;
  if (!shown.length) return html`<div class="panel-empty"><span class="muted">${filtered ? 'Nothing matches the current filters' : empty}</span>${filtered && html`<button class="btn sm" onClick=${() => setFilters([])}>Clear filters</button>`}</div>`;
  const max = Math.max(1, ...shown.map((r) => r.visitors));
  return html`<div class=${'mlist ' + (loading ? 'stale' : '')}>
    <div class="mhead"><span class="l">${head}</span><span class="r">${goalMode ? html`<span class="cr w">Conversions</span>${currency && html`<span class="cr w">Revenue</span>`}<span class="cr2">CR</span>` : 'Visitors'}</span></div>
    ${shown.map((r) => html`<button class="mrow" onClick=${() => onPick(dim, r)} title=${`Filter by ${r.name}`}>
      <span class="bar" style=${`width:${(r.visitors / max) * 100}%`}></span>
      <span class="l"><${Label} dim=${dim} name=${r.name} />${linkOut && /^https?:\/\//i.test(r.name) && html`<span class="ext" role="link" tabindex="0" aria-label="Open in a new tab" title="Open in a new tab" onClick=${(e) => { e.stopPropagation(); window.open(r.name, '_blank', 'noopener,noreferrer'); }} onKeyDown=${(e) => { if (e.key === 'Enter') { e.stopPropagation(); window.open(r.name, '_blank', 'noopener,noreferrer'); } }}><${Icon} name="external-link" size=${12} /></span>`}</span>
      <span class="r">${goalMode ? html`<span class="cr w">${fmtShort(r.visitors)}</span>${currency && html`<span class="cr w">${r.revenue === undefined ? '–' : moneyShort(r.revenue, currency)}</span>`}<small class="cr2">${r.conversion_rate}%</small>` : fmtShort(r.visitors)}</span></button>`)}
  </div>`;
}

/** Everything in a dimension, with search, paging and the extra columns. */
function DetailsModal({ title, label, dim, site, scope, onClose, source }) {
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const search = useDebounced(q);
  useEffect(() => setPage(1), [search]);
  const goalMode = !!scope.goal;
  const res = useApi(source ? source.path : `/sites/${site.domain}/stats/breakdown/${dim}`, { ...scope.params, limit: 20, page, search: search || undefined }, []);
  const rows = res.data && res.data.results;
  const cols = source ? source.columns : columnsFor(dim, goalMode, res.data && res.data.currency);
  const pick = (row) => { onClose(); (source ? source.pick : pickRow)(dim, row); };
  return html`<${Modal} title=${title} onClose=${onClose} wide footer=${html`<div class="row spread" style="width:100%"><span class="muted">Page ${page}</span><div class="row">
      <button class="btn sm" disabled=${page <= 1} onClick=${() => setPage(page - 1)}><${Icon} name="chevron-left" size=${13} /> Prev</button>
      <button class="btn sm" disabled=${!(res.data && res.data.has_more)} onClick=${() => setPage(page + 1)}>Next <${Icon} name="chevron-right" size=${13} /></button></div></div>`}>
    <div class="searchbox"><${Icon} name="search" size=${14} /><input placeholder="Search" aria-label="Search" value=${q} onInput=${(e) => setQ(e.target.value)} autofocus /></div>
    <div class=${'tscroll ' + (res.loading ? 'stale' : '')} style="margin-top:12px"><table class="t"><thead><tr><th>${label || title}</th>${cols.map((c) => html`<th class="right">${c[1]}</th>`)}</tr></thead>
      <tbody>${(rows || []).map((r) => html`<tr class="click" onClick=${() => pick(r)}><td><span class="cell-l"><${Label} dim=${dim} name=${r.name} /></span></td>${cols.map((c) => html`<td class="right">${r[c[0]] === undefined || r[c[0]] === null ? '–' : c[2](r[c[0]])}</td>`)}</tr>`)}
      ${rows && !rows.length && html`<tr><td colspan=${cols.length + 1} class="muted" style="text-align:center;padding:28px">No results</td></tr>`}</tbody></table></div>
  <//>`;
}

const storedTab = (id, fallback) => { try { return localStorage.getItem('tidemark.tab.' + id) || fallback; } catch (e) { return fallback; } };
const saveTab = (id, v) => { try { localStorage.setItem('tidemark.tab.' + id, v); } catch (e) { /* ignore */ } };

/** A card with tabs; each tab renders its own body, and `details` opens the full list. */
function Panel({ id, title, tabs, children, wide, prefer }) {
  const [tab, setTab] = useState(() => (tabs.find((t) => t.key === prefer) || tabs.find((t) => t.key === storedTab(id)) || tabs[0]).key);
  useEffect(() => { if (prefer) setTab(prefer); }, [prefer]);
  const active = tabs.find((t) => t.key === tab) || tabs[0];
  return html`<section class=${'card panel' + (wide ? ' wide' : '')} aria-label=${title}>
    <div class="card-h"><h3>${title}</h3><div class="tabs" role="tablist">${tabs.map((t) => html`<button role="tab" aria-selected=${t.key === active.key} class=${t.key === active.key ? 'on' : ''} onClick=${() => { setTab(t.key); saveTab(id, t.key); }}>${t.label}</button>`)}</div></div>
    <div class="card-b panel-b">${children(active)}</div>
  </section>`;
}

/** A breakdown list for one dimension plus a "Details" link. */
function DimBody({ dim, label, title, site, scope, poll, limit = 7, extra }) {
  const [more, setMore] = useState(false);
  const goalMode = !!scope.goal;
  const res = useApi(`/sites/${site.domain}/stats/breakdown/${dim}`, { ...scope.params, limit }, [], { poll });
  const rows = res.data && res.data.results;
  return html`<${RankedList} rows=${rows} dim=${dim} goalMode=${goalMode} currency=${res.data && res.data.currency} loading=${res.loading} head=${label} error=${res.error} onRetry=${res.reload} filtered=${scope.filters.length > 0} />
    ${rows && rows.length > 0 && html`<div class="panel-foot"><button class="more" onClick=${() => setMore(true)}><${Icon} name="search" size=${13} /> Details</button></div>`}
    ${more && html`<${DetailsModal} title=${title || label} label=${label} dim=${dim} site=${site} scope=${scope} onClose=${() => setMore(false)} />`}`;
}

const UTM = [['utm_campaign', 'Campaign'], ['utm_medium', 'Medium'], ['utm_source', 'Source'], ['utm_content', 'Content'], ['utm_term', 'Term']];

export function Sources({ site, scope, poll }) {
  const [utm, setUtm] = useState('utm_campaign');
  return html`<${Panel} id="sources" title="Sources" tabs=${[{ key: 'channel', label: 'Channels' }, { key: 'source', label: 'Sources' }, { key: 'utm', label: 'Campaigns' }]}>
    ${(t) => t.key === 'utm'
      ? html`<div class="subselect"><label class="sr-only" for="utm-dim">UTM parameter</label><select id="utm-dim" value=${utm} onChange=${(e) => setUtm(e.target.value)}>${UTM.map(([k, l]) => html`<option value=${k}>${l}</option>`)}</select></div>
        <${DimBody} key=${utm} dim=${utm} label=${UTM.find((u) => u[0] === utm)[1]} title=${'Campaigns: ' + UTM.find((u) => u[0] === utm)[1]} site=${site} scope=${scope} poll=${poll} limit=${6} />`
      : html`<${DimBody} key=${t.key} dim=${t.key} label=${t.label.replace(/s$/, '')} title=${t.label} site=${site} scope=${scope} poll=${poll} />`}<//>`;
}

export function Pages({ site, scope, poll }) {
  return html`<${Panel} id="pages" title="Pages" tabs=${[{ key: 'page', label: 'Top pages' }, { key: 'entry_page', label: 'Entry pages' }, { key: 'exit_page', label: 'Exit pages' }]}>
    ${(t) => html`<${DimBody} key=${t.key} dim=${t.key} label=${t.label.replace(/s$/, '')} title=${t.label} site=${site} scope=${scope} poll=${poll} />`}<//>`;
}

function MapBody({ site, scope, poll }) {
  const [more, setMore] = useState(false);
  const res = useApi(`/sites/${site.domain}/stats/breakdown/country`, { ...scope.params, limit: 250 }, [], { poll });
  const rows = res.data && res.data.results;
  return html`${rows ? html`<${WorldMap} rows=${rows} valueLabel=${scope.goal ? 'Conversions' : 'Visitors'} onPick=${(c) => addFilter('country', c)} />` : html`<div class="skeleton" style="height:230px"></div>`}
    ${rows && rows.length > 0 && html`<div style="margin-top:10px"><${RankedList} rows=${rows} dim="country" goalMode=${!!scope.goal} head="Country" limit=${4} filtered=${scope.filters.length > 0} /></div>`}
    ${rows && rows.length > 0 && html`<div class="panel-foot"><button class="more" onClick=${() => setMore(true)}><${Icon} name="search" size=${13} /> Details</button></div>`}
    ${more && html`<${DetailsModal} title="Countries" label="Country" dim="country" site=${site} scope=${scope} onClose=${() => setMore(false)} />`}
    ${rows && !rows.length && html`<div class="panel-empty"><span class="muted">${scope.filters.length ? 'Nothing matches the current filters' : 'No location data yet'}</span>${scope.filters.length > 0 && html`<button class="btn sm" onClick=${() => setFilters([])}>Clear filters</button>`}</div>`}
    ${res.error && !rows && html`<div class="panel-empty"><b>Couldn't load the map</b><button class="btn sm" onClick=${res.reload}>Try again</button></div>`}`;
}

export function Locations({ site, scope, poll }) {
  return html`<${Panel} id="locations" title="Locations" tabs=${[{ key: 'map', label: 'Map' }, { key: 'country', label: 'Countries' }, { key: 'region', label: 'Regions' }, { key: 'city', label: 'Cities' }]}>
    ${(t) => t.key === 'map' ? html`<${MapBody} site=${site} scope=${scope} poll=${poll} />` : html`<${DimBody} key=${t.key} dim=${t.key} label=${t.label.replace(/ies$/, 'y').replace(/s$/, '')} title=${t.label} site=${site} scope=${scope} poll=${poll} />`}<//>`;
}

export function Devices({ site, scope, poll }) {
  return html`<${Panel} id="devices" title="Devices" tabs=${[{ key: 'browser', label: 'Browsers' }, { key: 'os', label: 'Operating systems' }, { key: 'device', label: 'Devices' }]}>
    ${(t) => html`<${DimBody} key=${t.key} dim=${t.key} label=${{ browser: 'Browser', os: 'Operating system', device: 'Device' }[t.key]} title=${t.label} site=${site} scope=${scope} poll=${poll} />`}<//>`;
}

function GoalsBody({ site, scope, poll }) {
  const res = useApi(`/sites/${site.domain}/stats/goals`, scope.params, [], { poll });
  const rows = res.data && res.data.results;
  if (!rows) return html`<${RankedList} rows=${null} />`;
  if (!rows.length) return html`<div class="panel-empty"><b>Measure what matters</b><p class="muted">Goals count the conversions that matter — signups, purchases, downloads.</p>${site.can_manage && html`<${Link} to=${`/${site.domain}/settings/goals`} class="btn sm">Set up goals<//>`}</div>`;
  const max = Math.max(1, ...rows.map((r) => r.visitors));
  const revenue = rows.some((r) => r.currency);
  const mixed = new Set(rows.filter((r) => r.currency).map((r) => r.currency)).size > 1;
  return html`<div class=${'mlist ' + (res.loading ? 'stale' : '')}>
    <div class="mhead"><span class="l">Goal</span><span class="r"><span class="cr">Uniques</span><span class="cr">Total</span>${revenue && html`<span class="cr w">Revenue</span><span class="cr w">Average</span>`}<span class="cr2">CR</span></span></div>
    ${rows.map((r) => html`<button class="mrow" onClick=${() => addFilter('goal', r.name)} title="Filter by this goal"><span class="bar" style=${`width:${(r.visitors / max) * 100}%`}></span><span class="l"><span class="t">${r.name}</span></span>
      <span class="r"><span class="cr">${fmtShort(r.visitors)}</span><span class="cr">${fmtShort(r.events)}</span>${revenue && html`<span class="cr w">${r.currency ? moneyShort(r.revenue, r.currency) : '–'}</span><span class="cr w">${r.currency ? moneyShort(r.average_revenue, r.currency) : '–'}</span>`}<small class="cr2">${r.conversion_rate}%</small></span></button>`)}
    ${mixed && html`<p class="muted" style="font-size:12px;margin:6px 8px 0">Revenue is shown in each goal's own currency.</p>`}</div>`;
}

function PropsBody({ site, scope, poll, fixed, linkOut }) {
  const keys = useApi(`/sites/${site.domain}/stats/props`, scope.params, [], { poll, enabled: !fixed });
  const list = fixed ? [{ name: fixed }] : (keys.data && keys.data.results) || [];
  const [key, setKey] = useState(null);
  const [more, setMore] = useState(false);
  const active = list.find((k) => k.name === key) ? key : list[0] && list[0].name;
  const res = useApi(`/sites/${site.domain}/stats/props/${encodeURIComponent(active || '')}`, { ...scope.params, limit: 8 }, [], { enabled: !!active, poll });
  if (!fixed && !keys.data) return html`<${RankedList} rows=${null} />`;
  if (!list.length) return html`<div class="panel-empty"><b>Custom properties</b><p class="muted">Send extra details with your events, like plan or button label, and break results down by them.</p></div>`;
  const rows = res.data && res.data.results;
  const dim = 'prop:' + active;
  const source = { path: `/sites/${site.domain}/stats/props/${encodeURIComponent(active)}`, columns: goalColumns(null), pick: (d, row) => addFilter(d, row.name) };
  return html`${!fixed && html`<div class="subselect"><label class="sr-only" for="prop-key">Property</label><select id="prop-key" value=${active} onChange=${(e) => setKey(e.target.value)}>${list.map((k) => html`<option value=${k.name}>${k.name}</option>`)}</select></div>`}
    <${RankedList} rows=${rows} dim=${dim} goalMode=${true} head=${active} loading=${res.loading} linkOut=${linkOut} error=${res.error} onRetry=${res.reload} empty="No data for this goal yet" />
    ${rows && rows.length > 0 && html`<div class="panel-foot"><button class="more" onClick=${() => setMore(true)}><${Icon} name="search" size=${13} /> Details</button></div>`}
    ${more && html`<${DetailsModal} title=${active} dim=${dim} site=${site} scope=${scope} source=${source} onClose=${() => setMore(false)} />`}`;
}

/** Goals that carry one obvious detail: filtering by them drills down to a list of it (like clicking "Outbound Link: Click"). */
const GOAL_DETAIL = {
  'Outbound Link: Click': { prop: 'url', label: 'Outbound links', linkOut: true },
  'File Download': { prop: 'url', label: 'File downloads', linkOut: true },
  'Form: Submission': { prop: 'path', label: 'Form submissions' },
};

/** One funnel as columns, one per step: how many visitors reached it, how many of those came from the step before, and how many dropped off. */
function FunnelChart({ funnel, loading }) {
  const steps = funnel.steps;
  const first = steps.length ? steps[0].visitors : 0;
  return html`<div class=${'funnel ' + (loading ? 'stale' : '')}>
    <div class="funnel-head"><b>${funnel.name}</b><span class="muted">${steps.length}-step funnel<span class="sep">|</span><span title="All steps are required, in this order. Other activity is allowed between steps.">Sequential</span><span class="sep">|</span>Conversion rate: <b class="cr-all">${funnel.conversion_rate}%</b></span></div>
    <div class="funnel-steps" style=${`--steps:${steps.length}`}>${steps.map((st, i) => html`<div class="fstep">
      <div class="fl"><span class="t" title=${st.name}>${st.name}</span><b>${st.conversion_rate}%</b><span class="muted">${fmtShort(st.visitors)} ${st.visitors === 1 ? 'visitor' : 'visitors'}</span></div>
      <div class="fbar"><div class="fill" style=${`height:${first ? Math.max(st.visitors ? 2 : 0, (st.visitors / first) * 100) : 0}%`}>
        <span class="fbadge">${i === steps.length - 1 ? html`<${Icon} name="check" size=${12} />` : html`<${Icon} name="arrow-right" size=${12} />`} ${st.conversion_rate_step}%${i > 0 && html` <${Icon} name="arrow-down" size=${12} /> ${st.dropoff_percentage}%`}</span></div></div></div>`)}</div></div>`;
}

function FunnelsBody({ site, scope, poll }) {
  const list = useApi(`/sites/${site.domain}/stats/funnels`, undefined, [], {});
  const [pick, setPick] = useState(null);
  const funnels = (list.data && list.data.results) || [];
  const active = funnels.find((f) => f.id === pick) || funnels[0];
  const res = useApi(active ? `/sites/${site.domain}/stats/funnels/${active.id}` : null, scope.params, [active && active.id], { enabled: !!active, poll });
  if (list.error) return html`<div class="panel-empty"><b>Couldn't load funnels</b><p class="muted">${list.error.message}</p><button class="btn sm" onClick=${list.reload}>Try again</button></div>`;
  if (!list.data) return html`<${RankedList} rows=${null} />`;
  if (list.data.allowed === false) return html`<div class="panel-empty"><b>Funnels come with Pro</b><p class="muted">See where visitors drop off between the steps of a signup or purchase.</p>${site.can_manage && html`<${Link} to="/settings" class="btn sm">Upgrade<//>`}</div>`;
  if (!funnels.length) return html`<div class="panel-empty"><b>See where visitors drop off</b><p class="muted">A funnel follows visitors through a series of goals, like pricing page, signup and purchase.</p>${site.can_manage && html`<${Link} to=${`/${site.domain}/settings/funnels`} class="btn sm">Set up a funnel<//>`}</div>`;
  return html`${funnels.length > 1 && html`<div class="subselect"><label class="sr-only" for="funnel-pick">Funnel</label><select id="funnel-pick" value=${active.id} onChange=${(e) => setPick(e.target.value)}>${funnels.map((f) => html`<option value=${f.id} selected=${f.id === active.id}>${f.name}</option>`)}</select></div>`}
    ${res.data ? html`<${FunnelChart} funnel=${res.data} loading=${res.loading} />` : res.error ? html`<div class="panel-empty"><b>Couldn't load this funnel</b><p class="muted">${res.error.message}</p><button class="btn sm" onClick=${res.reload}>Try again</button></div>` : html`<div class="skeleton" style="height:240px"></div>`}`;
}

export function Behaviors({ site, scope, poll }) {
  const detail = scope.goal ? GOAL_DETAIL[scope.goal] : null;
  const f = site.features || {};
  const showFunnels = f.funnels ? true : !!site.can_manage;
  const tabs = [...(detail ? [{ key: 'detail', label: detail.label }] : []), { key: 'goals', label: 'Goals' }, { key: 'props', label: 'Properties' }, ...(showFunnels ? [{ key: 'funnels', label: 'Funnels' }] : [])];
  return html`<${Panel} id="behaviors" wide title="Behaviors" tabs=${tabs} prefer=${detail ? 'detail' : undefined}>
    ${(t) => t.key === 'detail' ? html`<${PropsBody} key=${detail.prop} site=${site} scope=${scope} poll=${poll} fixed=${detail.prop} linkOut=${detail.linkOut} />`
      : t.key === 'goals' ? html`<${GoalsBody} site=${site} scope=${scope} poll=${poll} />`
      : t.key === 'funnels' ? html`<${FunnelsBody} site=${site} scope=${scope} poll=${poll} />` : html`<${PropsBody} site=${site} scope=${scope} poll=${poll} />`}<//>`;
}
