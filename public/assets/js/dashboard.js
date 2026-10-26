// The site frame shared by the overview and the report pages: loads the site, shows the title and the scope bar, handles public and shared views.
import { setBrand, html, useState, useEffect, useApi, api, useLocation, Link, Icon, fmt, share, useDocTitle, useScope, setQuery, UserCtx, useContext } from './lib.js';
import { Loading, Empty, Field, CodeBlock } from './ui.js';
import { scrollToSection } from './shell.js';
import { ScopeBar } from './scopebar.js';
import { Overview } from './overview.js';

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


function CurrentVisitors({ site, scope }) {
  const res = useApi(`/sites/${site.domain}/stats/current-visitors`, scope.params.filters ? { filters: scope.params.filters } : {}, [], { poll: 30000 });
  const n = res.data ? res.data.visitors : null;
  return html`<button class="live" onClick=${() => setQuery({ period: 'realtime', date: null, from: null, to: null, comparison: null, zoom: null })} title="See realtime activity"><span class=${'dot ' + (n ? '' : 'off')}></span>${n === null ? '…' : fmt(n)} <span class="muted">current visitor${n === 1 ? '' : 's'}</span></button>`;
}

/**
 * Everything around a report: the site, the page title, the scope bar and the first-visit banner. `children` is called with the loaded
 * site and the scope (period, comparison and filters read from the URL).
 */
export function SiteFrame({ domain, shared, title, crumb, children, banner = true }) {
  const { user } = useContext(UserCtx) || {};
  const site = useApi(`/sites/${domain}`, null, [domain]);
  const [rev, setRev] = useState(0);
  const s = site.data && site.data.data;
  const scope = useScope(s);
  useDocTitle(title ? `${title} · ${domain}` : domain);
  // visitors to a public or shared dashboard see the site's own branding; signed-in members keep the normal look
  const brand = !user && s ? s.brand : null;
  useEffect(() => { setBrand(brand); return () => setBrand(null); }, [brand && brand.color, brand && brand.name, brand && brand.has_logo, brand && brand.hide_name, brand && brand.logo && brand.logo.length]);
  useEffect(() => {
    // arriving from another page of the site via a sidebar section link
    if (!s || !window.__tdmJump) return;
    const id = window.__tdmJump;
    window.__tdmJump = null;
    setTimeout(() => scrollToSection(id), 250);
  }, [s && s.domain]);
  if (site.error && site.error.body && site.error.body.error === 'password_required') return html`<${SharePassword} domain=${domain} onDone=${() => site.reload()} />`;
  if (site.error) return html`<main class="main"><${Empty} title=${site.error.status === 404 ? 'Site not found' : 'No access'} icon="lock">${site.error.status === 404 ? 'There is no site with this address.' : "You don't have access to this dashboard."}<${Link} to="/sites" class="btn" style="margin-top:8px">Back to your sites<//><//></main>`;
  if (!s) return html`<main class="main"><${Loading} rows=${8} /></main>`;
  const live = scope.period === 'realtime';
  return html`<main class="main dash" id="main" key=${rev}>
    <header class="dash-head"><div class="grow">${crumb}<h1>${title}</h1>${(shared || !user) && html`<p class="muted small dash-sub">${s.domain}</p>`}</div>${(shared || !user) && html`<${CurrentVisitors} site=${s} scope=${scope} />`}</header>
    <${ScopeBar} site=${s} scope=${scope} />
    ${s.imported && scope.filters.length > 0 && html`<p class="muted small imported-note">Data imported from Plausible (${s.imported.start} to ${s.imported.end}) is left out while a filter is applied.</p>`}
    ${banner && !s.has_data && !live && html`<${Waiting} site=${s} onData=${() => { site.reload(); setRev((r) => r + 1); }} />`}
    ${children({ site: s, scope, live, shared, user })}
  </main>`;
}

export function Dashboard({ domain, shared }) {
  return html`<${SiteFrame} domain=${domain} shared=${shared} title="Overview">${({ site, scope, live }) => html`<${Overview} site=${site} scope=${scope} shared=${shared} live=${live} />`}<//>`;
}
Dashboard.public = true;
