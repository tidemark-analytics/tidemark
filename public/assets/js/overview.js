// The overview: headline numbers, the main chart, "What changed", the report cards and the setup checklist.
import { html, useState, useEffect, useMemo, useApi, api, setQuery, addFilter, Link, Icon, fmt, fmtShort, moneyShort, dur, countryName, UserCtx, useContext, scopeQuery } from './lib.js';
import { Tabs, Segmented, Delta, Sparkline, Progress } from './ui.js';
import { LineChart, ChartTable, bucketText } from './chart.js';
import { AnnotationModal, todayIn } from './annotations.js';
import { PagesCard, SourcesCard, LocationsCard, DevicesCard, GoalsCard, CampaignsCard, reportHref } from './reports.js';
import { rangeLabel, periodLabel } from './scopebar.js';
import { zoomPatch, encodeZoom } from './scope-math.js';
import { computeInsights, hasEnoughData } from './insights-rules.js';

// ------------------------------------------------------------------------------------------------ metrics
export const METRICS = {
  visitors: ['Visitors', fmtShort], pageviews: ['Pageviews', fmtShort], visits: ['Visits', fmtShort],
  views_per_visit: ['Views per visit', (v) => fmt(v)], bounce_rate: ['Bounce rate', (v) => v + '%'], visit_duration: ['Visit duration', dur],
};
/** The top stats of a goal; a goal that reports revenue also gets its total and average, in the goal's currency. */
export const goalMetrics = (currency) => ({
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
const INTERVAL_LABEL = { minute: 'Minutes', hour: 'Hourly', day: 'Daily', week: 'Weekly', month: 'Monthly' };

/** Parameters that read the comparison period with the same filters (for the delta columns of the report cards and for Insights). */
export function comparisonParams(scope, top) {
  const cp = top && top.comparison_period;
  if (!cp) return null;
  const p = { period: 'custom', from: cp.from.slice(0, 10), to: cp.to.slice(0, 10) };
  if (scope.params.filters) p.filters = scope.params.filters;
  return p;
}

// ------------------------------------------------------------------------------------------------ headline
function StatCard({ big, label, value, change, metric, series, to, caption, help, empty }) {
  const body = html`<span class="sc-k">${label}</span>
    <span class="sc-vd"><span class="sc-v num">${value === null ? html`<span class="skeleton" style=${`display:inline-block;width:${big ? 96 : 64}px;height:${big ? 40 : 28}px`}></span>` : value}</span>${change !== undefined && change !== null && html`<${Delta} metric=${metric} change=${change} />`}</span>
    ${caption && html`<span class="sc-c muted">${caption}</span>`}
    ${series && series.length > 1 ? html`<span class="sc-spark"><${Sparkline} data=${series} height=${big ? 44 : 30} /></span>` : html`<span class="sc-spark"></span>`}`;
  return to ? html`<${Link} to=${to} class=${'stat-card' + (big ? ' big' : '')} title=${help}>${body}<//>` : html`<div class=${'stat-card' + (big ? ' big' : '')} title=${help}>${body}</div>`;
}

function Headline({ site, scope, shared, top, graph, goals }) {
  const stats = top.data && top.data.stats;
  const comparing = !!(top.data && top.data.comparing);
  const g = graph.data && graph.data.plot ? graph.data : null;
  const href = (r, extra) => reportHref(site, shared, r, extra);
  const cap = comparing && top.data.comparison_period ? 'vs ' + rangeLabel(top.data.comparison_period) : '';
  if (scope.goal) {
    const defs = Object.entries(goalMetrics(top.data && top.data.currency)).slice(0, 5);
    return html`<section class="headline" aria-label="Headline numbers">${defs.map(([k, [label, f]], i) => {
      const s = stats && stats[k];
      return html`<${StatCard} big=${i === 0} key=${k} label=${label} help=${HELP[k]} metric=${k} value=${s ? f(s.value) : null} change=${comparing && s ? s.change : null} series=${g && g.plot[k]} to=${href('events', { tab: 'goals' })} caption=${i === 0 ? cap : ''} />`;
    })}</section>`;
  }
  const s = (k) => stats && stats[k];
  const val = (k) => (s(k) ? METRICS[k][1](s(k).value) : null);
  const chg = (k) => (comparing && s(k) && !(s(k).value === 0 && !s(k).previous) ? s(k).change : null);
  const sum = (r) => (r.data ? r.data.results.reduce((a, x) => a + x.events, 0) : null);
  const nowConv = sum(goals.now), prevConv = sum(goals.before);
  const convChange = comparing && nowConv !== null && prevConv ? ((nowConv - prevConv) / prevConv) * 100 : null;
  const noGoals = goals.now.data && goals.now.data.results.length === 0;
  return html`<section class="headline" aria-label="Headline numbers">
    <${StatCard} big label="Visitors" help=${HELP.visitors} metric="visitors" value=${val('visitors')} change=${chg('visitors')} series=${g && g.plot.visitors} to=${href('sources')} caption=${cap} />
    <${StatCard} label="Pageviews" help=${HELP.pageviews} metric="pageviews" value=${val('pageviews')} change=${chg('pageviews')} series=${g && g.plot.pageviews} to=${href('pages')} />
    <${StatCard} label="Bounce rate" help=${HELP.bounce_rate} metric="bounce_rate" value=${val('bounce_rate')} change=${chg('bounce_rate')} series=${g && g.plot.bounce_rate} to=${href('pages', { tab: 'page', sort: 'bounce_rate' })} />
    <${StatCard} label="Visit duration" help=${HELP.visit_duration} metric="visit_duration" value=${val('visit_duration')} change=${chg('visit_duration')} series=${g && g.plot.visit_duration} to=${href('pages', { tab: 'entry_page', sort: 'visit_duration' })} />
    <${StatCard} label="Goal conversions" help="Completed goals, counting every time" metric="conversions" value=${noGoals ? '–' : nowConv === null ? null : fmtShort(nowConv)} change=${noGoals ? null : convChange} to=${href('events', { tab: 'goals' })} caption=${noGoals ? 'No goals yet' : ''} />
  </section>`;
}

// ------------------------------------------------------------------------------------------------ main chart
function MainChart({ site, scope, top, graph, live }) {
  const [metric, setMetric] = useState('visitors');
  const [asTable, setAsTable] = useState(false);
  const [noteModal, setNoteModal] = useState(null); // { date, list } while the notes dialog is open
  const defs = scope.goal ? goalMetrics(top.data && top.data.currency) : METRICS;
  const current = defs[metric] ? metric : Object.keys(defs)[0];
  const [name, format] = defs[current];
  // while the filter changes, the previous response may use the other set of metrics; treat that as still loading
  const g = graph.data && graph.data.plot[current] ? graph.data : null;
  const busy = top.loading || graph.loading;
  const brushable = g && new Set(g.labels.map((l) => l.slice(0, 10))).size > 1;
  const brush = (i, j) => setQuery(zoomPatch(g.labels, i, j, scope.query.zoom || encodeZoom({ ...scope.query, period: scope.period })));
  const intervals = g ? g.intervals : [];
  return html`<section class="card graph-card" aria-label="Graph">
    <div class="card-h chart-h"><${Tabs} label="Metric" value=${current} onChange=${setMetric} items=${Object.entries(defs).map(([k, [l]]) => [k, l])} />
      <div class="row chart-tools">${intervals.length > 1 && html`<${Segmented} label="Interval" value=${g.interval} onChange=${(i) => setQuery({ interval: i })} items=${intervals.map((i) => [i, INTERVAL_LABEL[i] || i])} />`}
        ${site.role && !scope.goal && html`<button class="btn ghost" onClick=${() => setNoteModal({ date: todayIn(site.timezone), list: [] })}><${Icon} name="plus" size=${13} /> Note</button>`}
        <button class="btn ghost" onClick=${() => setAsTable(!asTable)}>${asTable ? 'Chart' : 'Table'}</button></div></div>
    <div class="chart-meta"><span class="legend"><span><i style="background:var(--series-1)"></i>${name}</span>${g && g.comparison && html`<span><i style="background:var(--series-2)"></i>${scope.comparison === 'year_over_year' ? 'Previous year' : 'Previous period'}</span>`}</span>
      ${brushable && !asTable && html`<span class="muted small hint-zoom">Drag across the chart to zoom in</span>`}</div>
    <div class=${'graph-b ' + (busy && g ? 'stale' : '')}>
      ${graph.error && !g ? html`<div class="panel-empty" style="min-height:300px"><b>Couldn't load the graph</b><p class="muted">${graph.error.message}</p><button class="btn sm" onClick=${() => { graph.reload(); top.reload(); }}>Try again</button></div>` : !g ? html`<div class="skeleton" style="height:300px"></div>` : asTable
        ? html`<${ChartTable} labels=${g.labels} values=${g.plot[current]} prev=${(g.comparison && g.comparison.plot[current]) || null} format=${format} name=${name} interval=${g.interval} annotations=${g.annotations} />`
        : html`<${LineChart} labels=${g.labels} values=${g.plot[current]} prev=${g.comparison && g.comparison.plot[current] ? g.comparison.plot[current] : null} prevLabels=${g.comparison && g.comparison.labels} format=${format} name=${name} interval=${g.interval} live=${live} annotations=${g.annotations || []} onNote=${(p) => setNoteModal({ date: p.list[0].date, list: p.list })} onBrush=${brushable ? brush : undefined} />`}
    </div>
    ${noteModal && html`<${AnnotationModal} site=${site} start=${noteModal} onClose=${() => setNoteModal(null)} onChanged=${() => graph.reload()} />`}
  </section>`;
}

// ------------------------------------------------------------------------------------------------ insights
const CARD_OF = { page: ['pages', 'page'], source: ['sources', 'source'], country: ['locations', 'country'], goal: ['goals', 'goals'] };
const ICON_OF = { riser: 'trending-up', faller: 'trending-down', new: 'sparkles', high_day: 'activity', low_day: 'activity' };
const smooth = () => (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth');

/** "What changed": plain-language findings from the rules in insights-rules.js, each one a shortcut to its evidence. */
function Insights({ site, scope, top, graph }) {
  const live = ['realtime', 'all', '24h'].includes(scope.period);
  const ok = !live && !scope.goal && scope.filters.length === 0 && scope.comparable;
  // the card compares with the previous period even when the comparison line is switched off in the scope bar
  const own = useApi(`/sites/${site.domain}/stats/top`, { ...scope.params, comparison: scope.comparison || 'previous_period' }, [], { enabled: ok && !(top.data && top.data.comparing) });
  const t = top.data && top.data.comparing ? top.data : own.data;
  const totalNow = t && t.stats ? t.stats.visitors.value : null, totalBefore = t && t.stats ? t.stats.visitors.previous : null;
  const enough = !!t && hasEnoughData(totalNow, totalBefore);
  const cmp = comparisonParams(scope, t);
  const ready = ok && enough && !!cmp;
  const base = `/sites/${site.domain}/stats`;
  const get = (path, params) => useApi(`${base}/${path}`, params, [], { enabled: ready });
  const page = get('breakdown/page', { ...scope.params, comparison: undefined, limit: 100 }), pageB = get('breakdown/page', { ...cmp, limit: 100 });
  const source = get('breakdown/source', { ...scope.params, comparison: undefined, limit: 100 }), sourceB = get('breakdown/source', { ...cmp, limit: 100 });
  const country = get('breakdown/country', { ...scope.params, comparison: undefined, limit: 100 }), countryB = get('breakdown/country', { ...cmp, limit: 100 });
  const goals = get('goals', { ...scope.params, comparison: undefined }), goalsB = get('goals', cmp);
  const all = [page, pageB, source, sourceB, country, countryB, goals, goalsB];
  const done = all.every((r) => r.data || r.error);
  const findings = useMemo(() => {
    if (!ready || !done) return null;
    const rows = (r) => (r.data && r.data.results) || [];
    const series = graph.data && graph.data.plot && graph.data.plot.visitors ? { labels: graph.data.labels, values: graph.data.plot.visitors, interval: graph.data.interval } : null;
    return computeInsights({ totalNow, totalBefore, now: { page: rows(page), source: rows(source), country: rows(country) }, before: { page: rows(pageB), source: rows(sourceB), country: rows(countryB) }, goalsNow: rows(goals).map((x) => ({ name: x.name, visitors: x.events })), goalsBefore: rows(goalsB).map((x) => ({ name: x.name, visitors: x.events })), series, country: countryName, dayText: (l, i) => bucketText(l, i) });
  }, [ready, done, ...all.map((r) => r.data)]);
  if (!ok || !t || !enough) return null; // thin data or nothing to compare with: the card isn't shown
  const act = (f) => {
    if (f.group === 'day') { setQuery({ period: 'day', date: f.date, from: null, to: null, interval: null, zoom: scope.query.zoom || encodeZoom({ ...scope.query, period: scope.period }) }); return; }
    const [card, tab] = CARD_OF[f.group];
    addFilter(f.filter[0], f.filter[1]);
    window.dispatchEvent(new CustomEvent('tdm:tab', { detail: { card, tab } }));
    setTimeout(() => { const el = document.getElementById('card-' + card); if (el) el.scrollIntoView({ behavior: smooth(), block: 'center' }); }, 350);
  };
  const bold = (f) => { const i = f.name && f.group !== 'day' ? f.text.indexOf(f.name) : -1; return i < 0 ? f.text : html`${f.text.slice(0, i)}<b>${f.name}</b>${f.text.slice(i + f.name.length)}`; };
  return html`<section class="card insights" aria-label="What changed" id="sec-insights">
    <div class="card-h"><h3><${Icon} name="lightbulb" size=${15} /> What changed</h3><span class="muted small">${t.comparison_period ? 'vs ' + rangeLabel(t.comparison_period) : ''}</span></div>
    <div class="card-b ins-b">${findings === null ? html`<div class="stack sm" aria-busy="true" aria-label="Loading">${[0, 1, 2].map((i) => html`<div class="skeleton" style=${`height:40px;width:${100 - i * 8}%`}></div>`)}</div>`
      : findings.length === 0 ? html`<p class="ins-calm"><${Icon} name="circle-check" size=${16} /> Nothing stands out. Pages, sources and countries moved about as much as usual.</p>`
      : html`<ul class="ins-list">${findings.map((f) => html`<li><button type="button" class="ins-row" onClick=${() => act(f)} title=${f.group === 'day' ? 'Zoom in on this day' : 'Filter by ' + f.name + ' and show where it comes from'}>
        <span class=${'ins-ico ' + f.tone} aria-hidden="true"><${Icon} name=${ICON_OF[f.kind]} size=${15} /></span>
        <span class="ins-t">${bold(f)}</span>
        ${f.change !== null && f.change !== undefined ? html`<${Delta} change=${f.change} />` : f.kind === 'new' ? html`<span class="delta flat">New</span>` : null}
        <${Icon} name="chevron-right" size=${15} class="ins-go" /></button></li>`)}</ul>`}</div>
  </section>`;
}

// ------------------------------------------------------------------------------------------------ checklist
const dismissKey = (domain) => 'tidemark.checklist.' + domain;
const readDismissed = (domain) => { try { return localStorage.getItem(dismissKey(domain)) === '1'; } catch (e) { return false; } };

/** New-site checklist: install, goal, alert, report, teammate. Steps tick themselves off from what already exists. Dismissible per site. */
function SetupChecklist({ site, scope }) {
  const { user } = useContext(UserCtx) || {};
  const [hidden, setHidden] = useState(() => readDismissed(site.domain));
  const eligible = !!user && site.can_manage && !site.rollup && !hidden;
  const base = `/sites/${site.domain}`;
  const goals = useApi(`${base}/goals`, null, [site.domain], { enabled: eligible });
  const alerts = useApi(`${base}/alerts`, null, [site.domain], { enabled: eligible });
  const reports = useApi('/me/reports', null, [site.domain], { enabled: eligible });
  const people = useApi(`${base}/people`, null, [site.domain], { enabled: eligible });
  // first visit came in the last 14 days (or hasn't come yet): a young site
  const young = !site.has_data || (site.first_visit_at && Date.now() - Date.parse(site.first_visit_at) < 14 * 86400000);
  if (!eligible || !young) return null;
  if (![goals, alerts, reports, people].every((r) => r.data || r.error)) return null;
  const sub = reports.data && reports.data.data.find((x) => x.domain === site.domain);
  const steps = [
    { id: 'install', label: 'Install the tracking snippet', hint: 'This card ticks itself off when the first pageview arrives.', done: site.has_data, to: `/${site.domain}/installation`, cta: 'Get the snippet' },
    { id: 'goal', label: 'Add a goal', hint: 'Count signups, purchases or downloads.', done: !!(goals.data && goals.data.data.length), to: `/${site.domain}/settings/conversions`, cta: 'Add a goal' },
    ...(alerts.data && alerts.data.allowed !== false ? [{ id: 'alert', label: 'Create an alert', hint: 'Hear about traffic drops and spikes.', done: !!(alerts.data.rules && alerts.data.rules.length), to: `/${site.domain}/settings/alerts`, cta: 'Create an alert' }] : []),
    ...(reports.data && reports.data.mail ? [{ id: 'report', label: 'Subscribe to the email report', hint: 'A weekly summary in your inbox.', done: !!(sub && sub.frequency), to: '/settings/reports', cta: 'Subscribe' }] : []),
    { id: 'team', label: 'Invite a teammate', hint: 'Give a colleague access to this site.', done: !!(people.data && people.data.data.length > 1), to: `/${site.domain}/settings/access`, cta: 'Invite' },
  ];
  const doneCount = steps.filter((s) => s.done).length;
  if (doneCount === steps.length) return null;
  const dismiss = () => { try { localStorage.setItem(dismissKey(site.domain), '1'); } catch (e) { /* private mode */ } setHidden(true); };
  return html`<section class="card checklist" aria-label="Setup checklist" id="sec-setup">
    <div class="card-h"><div><h3>Finish setting up ${site.domain}</h3><p class="muted small">${doneCount} of ${steps.length} done</p></div>
      <button class="btn ghost icon sm" aria-label="Dismiss the checklist" title="Dismiss" onClick=${dismiss}><${Icon} name="x" size=${15} /></button></div>
    <div class="card-b"><${Progress} value=${(doneCount / steps.length) * 100} label="Setup progress" />
      <ul class="cl-list">${steps.map((s) => html`<li class=${s.done ? 'done' : ''}><span class="cl-ico" aria-hidden="true"><${Icon} name=${s.done ? 'circle-check' : 'circle'} size=${18} /></span>
        <div class="cl-t"><b>${s.label}</b><span class="muted small">${s.done ? 'Done' : s.hint}</span></div>
        ${!s.done && html`<${Link} to=${s.to} class="btn sm">${s.cta}<//>`}</li>`)}</ul></div>
  </section>`;
}

// ------------------------------------------------------------------------------------------------ the page
/** Headline, chart, Insights, report cards and checklist for one site and scope. */
export function Overview({ site, scope, shared, live, afterCards }) {
  const base = `/sites/${site.domain}/stats`;
  const poll = live ? 5000 : 0;
  const top = useApi(`${base}/top`, scope.params, [], { poll });
  const graph = useApi(`${base}/graph`, scope.params, [], { poll });
  const cmp = comparisonParams(scope, top.data);
  const cscope = useMemo(() => ({ ...scope, cmp }), [scope.key, cmp && cmp.from, cmp && cmp.to]);
  const goalsNow = useApi(`${base}/goals`, scope.params, [], { enabled: !scope.goal });
  const goalsBefore = useApi(`${base}/goals`, cmp, [], { enabled: !!cmp && !scope.goal });
  const cardPoll = live ? 10000 : 0;
  const cardProps = { site, scope: cscope, poll: cardPoll, shared };
  return html`<${Headline} site=${site} scope=${scope} shared=${shared} top=${top} graph=${graph} goals=${{ now: goalsNow, before: goalsBefore }} />
    <${MainChart} site=${site} scope=${scope} top=${top} graph=${graph} live=${live} />
    <${Insights} site=${site} scope=${scope} top=${top} graph=${graph} />
    <div class="cards-grid">
      <${PagesCard} ...${cardProps} /><${SourcesCard} ...${cardProps} />
      <${LocationsCard} ...${cardProps} /><${DevicesCard} ...${cardProps} />
      <${GoalsCard} ...${cardProps} /><${CampaignsCard} ...${cardProps} />
    </div>
    ${afterCards}
    ${!shared && html`<${SetupChecklist} site=${site} scope=${scope} />`}`;
}
