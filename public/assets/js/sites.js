// "Your sites" list, adding a site, and the installation step.
import { html, useState, useEffect, useMemo, useApi, api, navigate, Link, Icon, toast, fmtShort, useDocTitle, UserCtx, useContext } from './lib.js';
import { Loading, Empty, Field, ErrorBox, Sparkline, Toggle } from './ui.js';
import { Snippet } from './dashboard.js';

function SiteCard({ s }) {
  const up = s.change > 0, down = s.change < 0;
  return html`<${Link} to=${'/' + s.domain} class="card site" aria-label=${s.domain}>
    <div class="name"><${Icon} name=${s.rollup ? 'layers' : 'globe'} size=${16} class="muted" /><span class="t">${s.domain}</span>${s.rollup && html`<span class="pill brand" title="Adds up several of your sites">Rollup · ${s.members} ${s.members === 1 ? 'site' : 'sites'}</span>`}${s.public && html`<span class="pill">Public</span>`}</div>
    <div class="nums"><div><b>${fmtShort(s.visitors)}</b><span>Visitors, last 24 hours</span></div>
      <div><b class=${up ? 'up' : down ? 'down' : ''}>${s.change === 0 ? '–' : html`${up ? '+' : ''}${s.change}%`}</b><span>vs previous</span></div></div>
    <${Sparkline} data=${s.series} />
  <//>`;
}

export function SitesPage() {
  useDocTitle('Your sites');
  const res = useApi('/sites', null, []);
  const [q, setQ] = useState('');
  const [sort, setSort] = useState('visitors');
  const list = useMemo(() => {
    const all = ((res.data && res.data.data) || []).filter((s) => s.domain.includes(q.toLowerCase()));
    return all.sort((a, b) => (a.rollup !== b.rollup ? (a.rollup ? -1 : 1) : sort === 'name' ? a.domain.localeCompare(b.domain) : b.visitors - a.visitors || a.domain.localeCompare(b.domain)));
  }, [res.data, q, sort]);
  const total = res.data ? res.data.data.length : 0;
  const { user } = useContext(UserCtx) || {};
  const realSites = res.data ? res.data.data.filter((s) => !s.rollup && s.role).length : 0;
  return html`<main class="main"><header class="page-head"><div class="grow"><h1>Your sites</h1></div>
    <div class="row wrap">${total > 3 && html`<div class="searchbox"><${Icon} name="search" size=${14} /><input placeholder="Search sites" aria-label="Search sites" value=${q} onInput=${(e) => setQ(e.target.value)} /></div>`}
      ${total > 1 && html`<select aria-label="Sort" value=${sort} onChange=${(e) => setSort(e.target.value)} style="width:auto"><option value="visitors">Most visitors</option><option value="name">Name</option></select>`}
      ${realSites >= 2 && html`<${Link} to="/sites/rollup" class="btn" title="Add up several of your sites in one dashboard"><${Icon} name="layers" size=${14} /> Create a rollup<//>`}
      <${Link} to="/sites/new" class="btn primary"><${Icon} name="plus" size=${14} /> Add a site<//></div></header>
    ${res.data && res.data.other_sites && res.data.other_sites.count > 0 && html`<div class="notice other-sites"><${Icon} name="info" size=${16} /><div>These are the sites you belong to. ${res.data.other_sites.count.toLocaleString()} other ${res.data.other_sites.count === 1 ? 'site' : 'sites'} on this installation ${res.data.other_sites.count === 1 ? 'is' : 'are'} listed separately. <${Link} to=${res.data.other_sites.path}>View them<//></div></div>`}
    ${res.loading && !res.data ? html`<${Loading} />` : total === 0 ? html`<${Empty} title="Add your first site" icon="globe">Tidemark counts visitors without cookies or personal data. Add a site to get a tracking snippet.<${Link} to="/sites/new" class="btn primary" style="margin-top:10px">Add a site<//><//>`
      : list.length ? html`<div class="sites">${list.map((s) => html`<${SiteCard} s=${s} />`)}</div>` : html`<${Empty} title="No matching sites" icon="search" />`}
  </main>`;
}

const zones = () => { try { return ['UTC', ...Intl.supportedValuesOf('timeZone')]; } catch (e) { return ['UTC']; } };

export function NewSite() {
  useDocTitle('Add a site');
  const [domain, setDomain] = useState('');
  const [timezone, setTimezone] = useState(Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setErr(null);
    try { const r = await api('/sites', { method: 'POST', body: { domain, timezone } }); navigate(`/${r.data.domain}/installation`); }
    catch (x) { setErr((x.errors && x.errors.domain && x.errors.domain[0]) || x.message); setBusy(false); }
  };
  return html`<main class="main narrow"><header class="page-head"><div class="grow"><h1>Add a site</h1><div class="sub">Tell Tidemark which domain you want to measure.</div></div></header>
    <form class="card pad stack" onSubmit=${submit}>
      ${err && html`<div class="alert" role="alert">${err}</div>`}
      <${Field} label="Domain" help="Just the domain — no https:// and no path."><input required autofocus placeholder="example.com" value=${domain} onInput=${(e) => setDomain(e.target.value)} autocapitalize="off" spellcheck="false" /><//>
      <${Field} label="Reporting timezone" help="Days start and end at midnight in this timezone."><select value=${timezone} onChange=${(e) => setTimezone(e.target.value)}>${zones().map((z) => html`<option value=${z} selected=${z === timezone}>${z}</option>`)}</select><//>
      <div class="row"><button class="btn primary" disabled=${busy || !domain}>${busy ? 'Adding…' : 'Add site'}</button><${Link} to="/sites" class="btn ghost">Cancel<//></div>
    </form></main>`;
}

export function Installation({ domain }) {
  useDocTitle('Install ' + domain);
  const res = useApi(`/sites/${domain}`, null, [domain], { poll: 4000 });
  const s = res.data && res.data.data;
  useEffect(() => { if (s && s.has_data) { toast('First pageview received'); navigate('/' + domain, { replace: true }); } }, [s && s.has_data]);
  if (res.error) return html`<main class="main"><${ErrorBox} error=${res.error} /></main>`;
  if (!s) return html`<main class="main"><${Loading} /></main>`;
  const set = async (k, v) => { await api(`/sites/${domain}`, { method: 'PATCH', body: { [k]: v } }); res.reload(); };
  return html`<main class="main narrow"><header class="page-head"><div class="grow"><h1>Install the snippet</h1><div class="sub">Add this to the <code>${"<head>"}</code> of every page on ${domain}.</div></div></header>
    <div class="stack">
      <section class="card pad stack"><${Snippet} site=${s} />
        <p class="muted">Using a framework or a single-page app? Pageviews are tracked automatically on route changes. You can also send custom events with <code>window.plausible('Signup')</code>.</p></section>
      <section class="card pad stack"><h2>Also track</h2>
        <${Toggle} label="Outbound link clicks" help="Counts clicks on links that leave your site." checked=${s.track_outbound} onChange=${(v) => set('track_outbound', v)} />
        <${Toggle} label="File downloads" help="Counts clicks on links to PDFs, archives and similar." checked=${s.track_downloads} onChange=${(v) => set('track_downloads', v)} />
        <${Toggle} label="Form submissions" help="Counts submitted forms." checked=${s.track_forms} onChange=${(v) => set('track_forms', v)} /></section>
      <div class="notice center"><span class="dot"></span><div><b>Waiting for your first pageview…</b> This page continues automatically once data arrives.</div></div>
      <div><${Link} to=${'/' + domain} class="btn">Skip to the dashboard<//></div>
    </div></main>`;
}

/** Make a rollup: one dashboard that adds up several of your sites. */
export function NewRollup() {
  useDocTitle('Create a rollup');
  const { user } = useContext(UserCtx) || {};
  const sites = useApi('/rollups/candidates', null, []);
  const [name, setName] = useState('');
  const [all, setAll] = useState(true);
  const [chosen, setChosen] = useState([]);
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const list = (sites.data && sites.data.data) || [];
  const allowed = !user || user.can_rollup !== false;
  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setErr(null);
    try { const r = await api('/rollups', { method: 'POST', body: { name, all, sites: all ? undefined : chosen } }); navigate('/' + r.data.domain); }
    catch (x) { setErr((x.errors && Object.values(x.errors)[0][0]) || x.message); setBusy(false); }
  };
  return html`<main class="main narrow"><header class="page-head"><div class="grow"><h1>Create a rollup</h1><div class="sub">One dashboard that adds up several of your sites. Your sites keep their own dashboards and data.</div></div></header>
    <form class="card pad stack" onSubmit=${submit}>
      ${!allowed && html`<div class="notice"><${Icon} name="info" size=${16} /><div>Rollups are included with Pro. <${Link} to="/settings">Open your account settings<//> to upgrade.</div></div>`}
      ${err && html`<div class="alert" role="alert">${err}</div>`}
      <${Field} label="Name" help="Letters, numbers and hyphens, like all-sites. It becomes the dashboard's address."><input required autofocus placeholder="all-sites" value=${name} onInput=${(e) => setName(e.target.value)} autocapitalize="off" spellcheck="false" maxlength="63" /><//>
      <${Field} label="Sites">
        <div class="stack sm"><label class="row"><input type="radio" name="scope" checked=${all} onChange=${() => setAll(true)} /> <span><b>All the sites I own</b>, including ones I add later</span></label>
          <label class="row"><input type="radio" name="scope" checked=${!all} onChange=${() => setAll(false)} /> <span>Only the sites I choose</span></label></div><//>
      ${!all && html`<div class="stack sm" role="group" aria-label="Sites in this rollup">${list.map((s) => html`<label class="row"><input type="checkbox" checked=${chosen.includes(s.domain)} onChange=${(e) => setChosen(e.target.checked ? [...chosen, s.domain] : chosen.filter((d) => d !== s.domain))} /> <span>${s.domain}</span></label>`)}</div>`}
      <p class="muted small">Imported data is left out, and a revenue goal adds up only amounts sent in its own currency. The rollup's timezone starts as the one most of your sites use.</p>
      <div class="row"><button class="btn primary" disabled=${busy || !name || !allowed || (!all && !chosen.length)}>${busy ? 'Creating…' : 'Create rollup'}</button><${Link} to="/sites" class="btn ghost">Cancel<//></div>
    </form></main>`;
}
