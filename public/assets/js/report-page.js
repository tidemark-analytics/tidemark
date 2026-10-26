// The full report pages: /:domain/pages, /sources, /locations, /devices, /events, /funnels and /journeys.
// Each is a data table (search, sortable columns, dimension tabs, paging, CSV export, change against the comparison period) on top of the
// breakdown endpoints the overview already uses; funnels and journeys reuse their views from reports.js.
import { html, useState, useEffect, useMemo, useRef, useApi, useLocation, setQuery, addFilter, setFilters, Link, Icon, fmt, fmtShort, countryName, scopeQuery } from './lib.js';
import { Tabs, SortTh, useSort, Pagination, Delta, useDebounced, Empty } from './ui.js';
import { WorldMap } from './chart.js';
import { Label, columnsFor, goalColumns, rowChange, FunnelsBody, ExploreBody } from './reports.js';
import { SiteFrame } from './dashboard.js';
import { comparisonParams } from './overview.js';
import { typingIn } from './scopebar.js';

export const REPORTS = {
  pages: { title: 'Pages', key: 'p', tabs: [['page', 'Top pages'], ['entry_page', 'Entry pages'], ['exit_page', 'Exit pages']] },
  sources: { title: 'Sources', key: 's', tabs: [['channel', 'Channels'], ['source', 'Sources'], ['referrer', 'Referrers'], ['utm_medium', 'Mediums'], ['utm_source', 'UTM sources'], ['utm_campaign', 'Campaigns'], ['utm_content', 'Content'], ['utm_term', 'Terms']] },
  locations: { title: 'Locations', key: 'l', tabs: [['country', 'Countries'], ['region', 'Regions'], ['city', 'Cities']] },
  devices: { title: 'Devices', key: 'd', tabs: [['browser', 'Browsers'], ['os', 'Operating systems'], ['device', 'Devices']] },
  events: { title: 'Events and goals', key: 'e', tabs: [['goals', 'Goals'], ['props', 'Properties']] },
  funnels: { title: 'Funnels', key: 'f', tabs: null },
  journeys: { title: 'Journeys', key: 'j', tabs: null },
};
const SINGULAR = { page: 'Page', entry_page: 'Entry page', exit_page: 'Exit page', channel: 'Channel', source: 'Source', referrer: 'Referrer', utm_medium: 'Medium', utm_source: 'UTM source', utm_campaign: 'Campaign', utm_content: 'Content', utm_term: 'Term', country: 'Country', region: 'Region', city: 'City', browser: 'Browser', os: 'Operating system', device: 'Device', goals: 'Goal' };

/** A cell of CSV text; a leading = + - @ would be run as a formula by spreadsheets, so it gets a quote in front (same as the server's export). */
export const csvCell = (v) => { let s = v === null || v === undefined ? '' : String(v); if (/^[=+\-@\t\r]/.test(s)) s = "'" + s; return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
function downloadCsv(name, header, rows) {
  const text = [header, ...rows].map((r) => r.map(csvCell).join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
  a.download = name;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

const PAGE_SIZE = 25;

/** The table every report uses. `rows` null = loading. `prev` = Map(name -> visitors) of the comparison period or null. */
function DataTable({ rows, loading, error, onRetry, dim, nameLabel, columns, prev, prevComplete, onPick, filtered, exportName, initialSort, truncated, goalMode, nameText }) {
  const [q, setQ] = useState('');
  const search = useDebounced(q, 150);
  const [page, setPage] = useState(0);
  const sort = useSort(initialSort && (initialSort === 'name' || columns.some((c) => c[0] === initialSort) || initialSort === 'change') ? initialSort : 'visitors');
  const searchRef = useRef();
  useEffect(() => setPage(0), [search, dim, sort.key, sort.dir]);
  useEffect(() => {
    const k = (e) => { if (e.key === '/' && !e.metaKey && !e.ctrlKey && !typingIn(e) && !document.querySelector('.overlay, .sheet')) { e.preventDefault(); searchRef.current && searchRef.current.focus(); } };
    document.addEventListener('keydown', k);
    return () => document.removeEventListener('keydown', k);
  }, []);
  const view = useMemo(() => {
    if (!rows) return null;
    const t = search.trim().toLowerCase();
    const text = (r) => (nameText ? nameText(r.name) : String(r.name)).toLowerCase();
    const list = (t ? rows.filter((r) => text(r).includes(t) || String(r.name).toLowerCase().includes(t)) : rows).map((r) => ({ ...r, _c: rowChange(prev && !goalMode ? { map: prev, complete: prevComplete } : null, r.name, r.visitors) }));
    return sort.sorted(list, { name: (r) => text(r), change: (r) => (r._c ? (r._c.isNew ? Infinity : r._c.change) : -Infinity) });
  }, [rows, search, sort.key, sort.dir, prev]);
  const shown = view ? view.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE) : [];
  const showChange = !!prev && !goalMode;
  const exportCsv = () => downloadCsv(exportName + '.csv', [nameLabel, ...columns.map((c) => c[1]), ...(showChange ? ['Previous visitors', 'Change %'] : [])],
    view.map((r) => [nameText ? nameText(r.name) : r.name, ...columns.map((c) => r[c[0]]), ...(showChange ? [prev.get(r.name) ?? 0, r._c ? (r._c.isNew ? 'new' : Math.round(r._c.change * 10) / 10) : ''] : [])]));
  return html`<div class="card report-table">
    <div class="rt-bar"><div class="searchbox"><${Icon} name="search" size=${14} /><input ref=${searchRef} type="search" placeholder=${'Search ' + nameLabel.toLowerCase() + 's'} aria-label=${'Search ' + nameLabel.toLowerCase() + 's'} value=${q} onInput=${(e) => setQ(e.target.value)} /><kbd aria-hidden="true">/</kbd></div>
      <button class="btn sm" disabled=${!view || !view.length} onClick=${exportCsv}><${Icon} name="download" size=${14} /> Export CSV</button></div>
    ${error && !rows ? html`<div class="panel-empty"><b>Couldn't load this report</b><p class="muted">${error.message}</p><button class="btn sm" onClick=${onRetry}><${Icon} name="refresh-cw" size=${13} /> Try again</button></div>`
      : !rows ? html`<div class="rt-skel" aria-busy="true" aria-label="Loading">${Array.from({ length: 10 }, (_, i) => html`<div class="skeleton" style=${`height:20px;width:${96 - (i % 4) * 9}%`}></div>`)}</div>`
      : html`<div class=${'tscroll ' + (loading ? 'stale' : '')}><table class="t stack-sm"><thead><tr>
          <${SortTh} sort=${sort} k="name">${nameLabel}<//>
          ${columns.map((c) => html`<${SortTh} sort=${sort} k=${c[0]} right>${c[1]}<//>`)}
          ${showChange && html`<${SortTh} sort=${sort} k="change" right>Change<//>`}</tr></thead>
        <tbody>${shown.map((r) => html`<tr class="click" onClick=${() => onPick(r)}>
          <td data-label=${nameLabel}><button type="button" class="cell-btn" onClick=${(e) => { e.stopPropagation(); onPick(r); }} title=${'Filter by ' + (nameText ? nameText(r.name) : r.name)}><span class="cell-l"><${Label} dim=${dim} name=${r.name} /></span></button></td>
          ${columns.map((c) => html`<td class="right" data-label=${c[1]}>${r[c[0]] === undefined || r[c[0]] === null ? '–' : c[2](r[c[0]])}</td>`)}
          ${showChange && html`<td class="right" data-label="Change">${r._c ? html`<${Delta} change=${r._c.change} isNew=${r._c.isNew} compact />` : html`<span class="muted">–</span>`}</td>`}</tr>`)}
        ${view && !view.length && html`<tr><td colspan=${columns.length + 2} class="rt-empty">${search ? html`Nothing matches “${search}”.` : filtered ? html`Nothing matches the current filters. <button class="btn sm" onClick=${() => setFilters([])}>Clear filters</button>` : 'No data in this period.'}</td></tr>`}</tbody></table></div>
        ${view && view.length > 0 ? html`<${Pagination} page=${page} size=${PAGE_SIZE} total=${view.length} onPage=${setPage} />` : null}
        ${truncated && html`<p class="muted small rt-note">Showing the top 1,000 rows.</p>`}`}
  </div>`;
}

function DimReport({ site, scope, dim, cmp }) {
  const { query } = useLocation();
  const goalMode = !!scope.goal;
  const res = useApi(`/sites/${site.domain}/stats/breakdown/${dim}`, { ...scope.params, comparison: undefined, limit: 1000 }, [dim]);
  const before = useApi(`/sites/${site.domain}/stats/breakdown/${dim}`, { ...cmp, limit: 1000 }, [dim], { enabled: !!cmp && !goalMode });
  const rows = res.data && res.data.results;
  const prev = before.data ? new Map(before.data.results.map((r) => [r.name, r.visitors])) : null;
  return html`${dim === 'country' && rows && rows.length > 0 && html`<div class="card pad rt-map"><${WorldMap} rows=${rows} valueLabel=${goalMode ? 'Conversions' : 'Visitors'} onPick=${(c) => addFilter('country', c)} /></div>`}
    <${DataTable} rows=${rows} loading=${res.loading} error=${res.error} onRetry=${res.reload} dim=${dim} nameLabel=${SINGULAR[dim]} columns=${columnsFor(dim, goalMode, res.data && res.data.currency)} prev=${prev} prevComplete=${before.data && !before.data.has_more}
      goalMode=${goalMode} onPick=${(r) => addFilter(dim, r.name)} filtered=${scope.filters.length > 0} exportName=${`${site.domain}-${dim}`} initialSort=${query.sort} truncated=${res.data && res.data.has_more} nameText=${dim === 'country' ? countryName : undefined} />
    ${res.data && res.data.hidden_below && html`<p class="muted small rt-note">Rows with fewer than ${res.data.hidden_below} visitors are hidden.</p>`}`;
}

function GoalsReport({ site, scope, cmp }) {
  const res = useApi(`/sites/${site.domain}/stats/goals`, { ...scope.params, comparison: undefined }, []);
  const before = useApi(`/sites/${site.domain}/stats/goals`, cmp, [], { enabled: !!cmp });
  const rows = res.data && res.data.results;
  const currency = rows && rows.find((r) => r.currency) ? rows.find((r) => r.currency).currency : null;
  const prev = before.data ? new Map(before.data.results.map((r) => [r.name, r.visitors])) : null;
  if (rows && !rows.length) return html`<${Empty} title="Measure what matters" icon="target"><p>Goals count the conversions that matter: signups, purchases, downloads.</p>${site.can_manage && html`<${Link} to=${`/${site.domain}/settings/conversions`} class="btn">Set up goals<//>`}<//>`;
  return html`<${DataTable} rows=${rows} loading=${res.loading} error=${res.error} onRetry=${res.reload} dim="goal" nameLabel="Goal" columns=${goalColumns(currency)} prev=${prev} prevComplete=${true} onPick=${(r) => addFilter('goal', r.name)} exportName=${`${site.domain}-goals`} />`;
}

function PropsReport({ site, scope }) {
  const keys = useApi(`/sites/${site.domain}/stats/props`, { ...scope.params, comparison: undefined }, []);
  const list = (keys.data && keys.data.results) || [];
  const [key, setKey] = useState(null);
  const active = list.find((k) => k.name === key) ? key : list[0] && list[0].name;
  const res = useApi(`/sites/${site.domain}/stats/props/${encodeURIComponent(active || '')}`, { ...scope.params, comparison: undefined, limit: 1000 }, [active], { enabled: !!active });
  if (keys.data && !list.length) return html`<${Empty} title="Custom properties" icon="tag"><p>Send extra details with your events, like plan or button label, and break results down by them.</p><//>`;
  return html`${list.length > 0 && html`<div class="rt-select"><label for="prop-key-r" class="muted small">Property</label><select id="prop-key-r" value=${active} onChange=${(e) => setKey(e.target.value)}>${list.map((k) => html`<option value=${k.name}>${k.name}</option>`)}</select></div>`}
    <${DataTable} rows=${active ? res.data && res.data.results : keys.data ? [] : null} loading=${res.loading} error=${res.error || keys.error} onRetry=${res.reload} dim=${'prop:' + active} nameLabel=${active || 'Value'} columns=${goalColumns(null)} goalMode onPick=${(r) => addFilter('prop:' + active, r.name)} exportName=${`${site.domain}-${active}`} />`;
}

function Body({ report, site, scope, shared }) {
  const { query } = useLocation();
  const cfg = REPORTS[report];
  const tab = cfg.tabs ? (cfg.tabs.find((t) => t[0] === query.tab) || cfg.tabs[0])[0] : null;
  const top = useApi(`/sites/${site.domain}/stats/top`, scope.params, [], { enabled: scope.comparable });
  const cmp = comparisonParams(scope, top.data);
  if (report === 'funnels') return html`<section class="card"><div class="card-b rp-pad"><${FunnelsBody} site=${site} scope=${scope} poll=${0} /></div></section>`;
  if (report === 'journeys') return html`<section class="card"><div class="card-b rp-pad"><${ExploreBody} site=${site} scope=${scope} /></div></section>`;
  return html`<${Tabs} label=${cfg.title + ' views'} value=${tab} onChange=${(t) => setQuery({ tab: t, sort: null })} items=${cfg.tabs} />
    ${report === 'events' ? (tab === 'goals' ? html`<${GoalsReport} site=${site} scope=${scope} cmp=${cmp} />` : html`<${PropsReport} site=${site} scope=${scope} />`) : html`<${DimReport} key=${tab} site=${site} scope=${scope} dim=${tab} cmp=${cmp} />`}`;
}

/** One report page. `shared` pages (/share/...) keep the share credentials in the links. */
export function ReportPage({ domain, report, shared }) {
  const cfg = REPORTS[report];
  if (!cfg) return html`<main class="main"><${Empty} title="Page not found" icon="search"><p>There is no report called “${report}”.</p><//></main>`;
  const back = shared ? `/share/${domain}` + scopeQuery() : `/${domain}` + scopeQuery();
  const crumb = shared ? html`<${Link} to=${back} class="rp-back muted small"><${Icon} name="arrow-left" size=${13} /> Overview<//>` : null;
  return html`<${SiteFrame} domain=${domain} shared=${shared} title=${cfg.title} crumb=${crumb} banner=${false}>${({ site, scope }) => html`<${Body} report=${report} site=${site} scope=${scope} shared=${shared} />`}<//>`;
}
ReportPage.public = true;

/** A route component for one report (made once per report, so the router sees a stable component). */
export function reportRoute(report, shared = false) {
  const Route = (p) => html`<${ReportPage} ...${p} report=${report} shared=${shared} />`;
  Route.public = true;
  return Route;
}
