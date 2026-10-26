// The signed-in app shell: top bar with breadcrumb switchers, the context-aware sidebar, the live-visitors sheet,
// the ⌘K command palette and the keyboard shortcuts.
import { html, useState, useEffect, useMemo, useRef, useApi, api, navigate, Link, Icon, fmtShort, fmt, BASE, PERIODS, UserCtx, useContext, setTheme, currentTheme, countryName, flag, scopeQuery } from './lib.js';
import { Pop, Sheet, FavTile } from './ui.js';
import { Logo } from './logo.js';
import { registry } from './extensions.js';

const NOT_A_SITE = new Set(['', 'sites', 'settings', 'login', 'register', 'forgot-password', 'reset-password', 'share']);
// the first segment of an extension's page ('/support' for '/support/:number?') is not a site either
const isExtensionPage = (seg) => registry.routes.some(([pattern]) => pattern.split('/')[1] === seg);
export const siteFromPath = (path) => { const seg = path.split('/')[1] || ''; return NOT_A_SITE.has(seg) || isExtensionPage(seg) ? null : decodeURIComponent(seg); };

// ------------------------------------------------------------------------------------------------ route config
/** Items of the sidebar while a site is open, and the targets of the `g` chords. ONE place to change when a report is added:
 *  - `route`: a path suffix; the item links to `/<domain><route>` ('' is the overview) and is highlighted by the path.
 *  - `scoped`: the link carries the period, comparison and filters in the URL, so moving between reports keeps what you look at.
 *  - `key`: the second key of the `g` chord ('g' then 'p' goes to Pages).
 *  - `manage`: only for owners and admins of the site.
 *  The routes themselves are registered in main.js (see REPORT_ROUTES). */
export const SITE_NAV = [
  { id: 'overview', label: 'Overview', icon: 'chart-column', route: '', key: 'o', scoped: true },
  { id: 'pages', label: 'Pages', icon: 'file-text', route: '/pages', key: 'p', scoped: true },
  { id: 'sources', label: 'Sources', icon: 'compass', route: '/sources', key: 's', scoped: true },
  { id: 'locations', label: 'Locations', icon: 'globe', route: '/locations', key: 'l', scoped: true },
  { id: 'devices', label: 'Devices', icon: 'monitor', route: '/devices', key: 'd', scoped: true },
  { id: 'events', label: 'Events and goals', icon: 'target', route: '/events', key: 'e', scoped: true },
  { id: 'funnels', label: 'Funnels', icon: 'funnel', route: '/funnels', key: 'f', scoped: true },
  { id: 'journeys', label: 'Journeys', icon: 'route', route: '/journeys', key: 'j', scoped: true },
  { id: 'settings', label: 'Settings', icon: 'settings', route: '/settings', key: ',', manage: true },
];
/** Sidebar and chord targets outside a site. */
export const GLOBAL_NAV = [
  { id: 'sites', label: 'Sites', icon: 'layout-dashboard', to: '/sites', key: 's' },
];
const ACCOUNT_ICONS = { profile: 'user', security: 'shield-check', keys: 'key', reports: 'mail', users: 'users', updates: 'refresh-cw', billing: 'credit-card' };
/** The tabs of Account settings: [key, label, dot?]. Also drives the sidebar and the palette. */
export function accountTabs(user) {
  const extra = registry.accountTabs.filter((x) => !x.visible || x.visible(user));
  return [['profile', 'Profile'], ['security', 'Security'], ['keys', 'API keys'], ['reports', 'Email reports'], ...extra.map((x) => [x.key, x.label, !!(x.dot && x.dot(user))]),
    ...(user.is_admin && !registry.adminUi ? [['users', 'Users'], ...(user.updater_enabled === false ? [] : [['updates', 'Updates', !!user.update]])] : [])];
}

const smooth = () => (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth');
/** Scroll the dashboard to a section; from another page of the site, open the dashboard and let it do the scrolling. */
export const scrollToSection = (id) => { const el = document.getElementById('sec-' + id); if (el) el.scrollIntoView({ behavior: smooth(), block: 'start' }); };
export function jumpToSection(domain, id) {
  if (location.pathname.replace(BASE, '').replace(/\/$/, '') === '/' + domain) scrollToSection(id);
  else { window.__tdmJump = id; navigate('/' + domain); }
}
/** Tell the open dashboard to do something (change the period, toggle the comparison). */
export const command = (detail) => window.dispatchEvent(new CustomEvent('tdm:cmd', { detail }));
const openPalette = () => window.dispatchEvent(new CustomEvent('tdm:palette'));
const openShortcuts = () => window.dispatchEvent(new CustomEvent('tdm:shortcuts'));
const isMac = () => /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
const modKey = () => (isMac() ? '⌘' : 'Ctrl');

const sortSites = (list) => list.slice().sort((a, b) => (a.rollup !== b.rollup ? (a.rollup ? -1 : 1) : a.domain.localeCompare(b.domain)));
/** The user's sites, fetched once for the shell (top bar, sidebar, palette). */
export function useSites(path, enabled = true) {
  const res = useApi('/sites', null, [path.startsWith('/sites') ? path : ''], { enabled });
  const list = useMemo(() => sortSites((res.data && res.data.data) || []), [res.data]);
  return { list, loading: res.loading && !res.data, other: res.data && res.data.other_sites };
}

// ---------------------------------------------------------------------------------------------- recents
const RECENT_KEY = 'tidemark.recent';
const readRecents = () => { try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]').filter((d) => typeof d === 'string'); } catch (e) { return []; } };
function useRecents(domain) {
  const [recents, setRecents] = useState(readRecents);
  useEffect(() => {
    if (!domain) return;
    const next = [domain, ...readRecents().filter((d) => d !== domain)].slice(0, 6);
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(next)); } catch (e) { /* private mode */ }
    setRecents(next);
  }, [domain]);
  return recents;
}

// -------------------------------------------------------------------------------------------- sidebar state
const SIDE_KEY = 'tidemark.side';
export function useSidebarState(path) {
  const [collapsed, setCollapsed] = useState(() => { try { return localStorage.getItem(SIDE_KEY) === 'collapsed'; } catch (e) { return false; } });
  const [open, setOpen] = useState(false);
  useEffect(() => setOpen(false), [path]);
  useEffect(() => {
    if (!open) return;
    const k = (e) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('keydown', k);
    return () => document.removeEventListener('keydown', k);
  }, [open]);
  const toggleCollapsed = () => setCollapsed((c) => { const n = !c; try { localStorage.setItem(SIDE_KEY, n ? 'collapsed' : 'expanded'); } catch (e) { /* private mode */ } return n; });
  return { collapsed, open, setOpen, toggleCollapsed };
}

// ------------------------------------------------------------------------------------------------- account
/** `compact` is kept for older callers; the account menu is the same everywhere now. */
export function AccountMenu({ user, align = 'right', btnClass = 'btn ghost avatar-btn', named }) {
  const [theme, setT] = useState(currentTheme());
  const pick = (t) => { setTheme(t); setT(t); };
  return html`<${Pop} align=${align} ariaLabel="Account menu" btnClass=${btnClass} label=${html`<span class="avatar" aria-hidden="true">${(user.name || '?').slice(0, 1).toUpperCase()}</span>${named && html`<span class="who-name"><b>${user.name}</b><span>${user.email}</span></span>`}${user.update && html`<span class="notif on-avatar" aria-hidden="true"></span><span class="sr-only">Update available</span>`}`}>
    ${(close) => html`<div style="min-width:240px"><div class="who"><b>${user.name}</b><span class="muted">${user.email}</span></div><hr />
      <${Link} to="/sites" class="item" onClick=${close}><span>Your sites</span><${Icon} name="globe" size=${14} /><//>
      <${Link} to="/settings" class="item" onClick=${close}><span>Account settings${user.update && html`<span class="notif inline" aria-hidden="true"></span><span class="sr-only"> (update available)</span>`}</span><${Icon} name="settings" size=${14} /><//>
      ${user.is_admin && registry.adminUi && html`<a class="item" href=${BASE + registry.adminUi.href} onClick=${close}><span>${registry.adminUi.label}</span><${Icon} name="shield-check" size=${14} /></a>`}
      ${registry.menuItems.filter((m) => !m.visible || m.visible(user)).map((m) => (m.onClick
        ? html`<button class="item" onClick=${() => { close(); m.onClick(); }}><span>${m.label}</span><${Icon} name=${m.icon || 'external-link'} size=${14} /></button>`
        : html`<a class="item" href=${m.href} target=${m.external ? '_blank' : null} rel=${m.external ? 'noopener' : null} onClick=${close}><span>${m.label}</span><${Icon} name=${m.icon || 'external-link'} size=${14} /></a>`))}
      <hr /><div class="grp" id="theme-label">Theme</div>
      <div class="theme-seg" role="radiogroup" aria-labelledby="theme-label">${[['light', 'Light', 'sun'], ['dark', 'Dark', 'moon'], ['system', 'System', 'monitor']].map(([k, l, i]) => html`<button type="button" role="radio" aria-checked=${theme === k} aria-label=${l} title=${l} class=${theme === k ? 'on' : ''} onClick=${() => pick(k)}><${Icon} name=${i} size=${15} /></button>`)}</div>
      <hr />
      <button class="item" onClick=${async () => { close(); await api('/auth/logout', { method: 'POST' }); window.location.assign(window.SD.base + '/login'); }}><span>Sign out</span><${Icon} name="log-out" size=${14} /></button></div>`}<//>`;
}

function HelpMenu() {
  return html`<${Pop} align="right" ariaLabel="Help" btnClass="btn ghost icon" label=${html`<${Icon} name="circle-help" size=${17} />`}>
    ${(close) => html`<div style="min-width:220px">
      <a class="item" href="https://support.tidemarkanalytics.com/en/docs" target="_blank" rel="noopener" onClick=${close}><span>Documentation</span><${Icon} name="book-open" size=${14} /></a>
      <button class="item" onClick=${() => { close(); openShortcuts(); }}><span>Keyboard shortcuts</span><kbd>?</kbd></button>
      <a class="item" href="https://discord.gg/sCkHD3PPZh" target="_blank" rel="noopener" onClick=${close}><span>Community (Discord)</span><${Icon} name="users" size=${14} /></a></div>`}<//>`;
}

// ------------------------------------------------------------------------------------------- site switcher
/** Breadcrumb switcher: search, recents, then sites and rollups in their own groups. */
function SiteSwitcher({ list, current, domain, recents }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [sel, setSel] = useState(0);
  const wrap = useRef();
  const input = useRef();
  const trigger = useRef();
  const query = q.trim().toLowerCase();
  const groups = useMemo(() => {
    if (query) return [['Results', list.filter((s) => s.domain.toLowerCase().includes(query))]];
    const byDomain = Object.fromEntries(list.map((s) => [s.domain, s]));
    const recent = recents.map((d) => byDomain[d]).filter((s) => s && s.domain !== domain).slice(0, 3);
    return [['Recent', recent], ['Sites', list.filter((s) => !s.rollup)], ['Rollups', list.filter((s) => s.rollup)]].filter(([, l]) => l.length);
  }, [list, query, recents, domain]);
  const flat = groups.flatMap(([, l]) => l);
  useEffect(() => {
    if (!open) return;
    setQ(''); setSel(0);
    setTimeout(() => input.current && input.current.focus(), 0);
    const out = (e) => wrap.current && !wrap.current.contains(e.target) && setOpen(false);
    document.addEventListener('mousedown', out);
    return () => document.removeEventListener('mousedown', out);
  }, [open]);
  useEffect(() => setSel(0), [q]);
  const close = () => { setOpen(false); trigger.current && trigger.current.focus(); };
  const pick = (s) => { setOpen(false); navigate('/' + s.domain + (location.pathname.includes('/' + domain + '/settings') ? '/settings' : '')); };
  const onKey = (e) => {
    if (e.key === 'Escape') { e.stopPropagation(); close(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); setSel((n) => Math.min(flat.length - 1, n + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setSel((n) => Math.max(0, n - 1)); }
    else if (e.key === 'Enter' && flat[sel]) { e.preventDefault(); pick(flat[sel]); }
  };
  let n = -1;
  return html`<div class="pop-wrap" ref=${wrap}>
    <button ref=${trigger} class=${'crumb-btn' + (open ? ' open' : '')} aria-haspopup="dialog" aria-expanded=${open} aria-label=${'Switch site, current: ' + domain} onClick=${() => setOpen(!open)}>
      <${FavTile} name=${domain} rollup=${current && current.rollup} size="sm" /><span class="trunc">${domain}</span><${Icon} name="chevrons-up-down" size=${14} class="chev" />
    </button>
    ${open && html`<div class="switch-pop" role="dialog" aria-label="Switch site">
      <div class="cmd-in"><${Icon} name="search" size=${15} /><input ref=${input} value=${q} placeholder="Find site…" role="combobox" aria-expanded="true" aria-controls="switch-list" aria-activedescendant=${flat[sel] ? 'sw-' + sel : undefined} aria-label="Find site" onInput=${(e) => setQ(e.target.value)} onKeyDown=${onKey} /></div>
      <div class="cmd-list" id="switch-list" role="listbox" aria-label="Sites">${flat.length === 0 ? html`<div class="cmd-empty">No site matches “${q}”</div>` : groups.map(([label, items]) => html`<div role="group" aria-label=${label}><div class="grp" aria-hidden="true">${label}</div>${items.map((s) => { n++; const i = n; return html`<button id=${'sw-' + i} tabindex="-1" role="option" aria-selected=${s.domain === domain} class=${'cmd-item ' + (i === sel ? 'on' : '')} onMouseMove=${() => setSel(i)} onClick=${() => pick(s)}>
        <${FavTile} name=${s.domain} rollup=${s.rollup} size="sm" /><span class="trunc">${s.domain}</span>${s.domain === domain && html`<${Icon} name="check" size=${14} class="tick" />`}</button>`; })}</div>`)}</div>
      <div class="cmd-foot"><button class="cmd-item" onClick=${() => { setOpen(false); navigate('/sites'); }}><${Icon} name="layout-dashboard" size=${15} /><span>All sites</span></button>
        <button class="cmd-item" onClick=${() => { setOpen(false); navigate('/sites/new'); }}><${Icon} name="plus" size=${15} /><span>New site</span></button></div>
    </div>`}
  </div>`;
}

// ---------------------------------------------------------------------------------------------- live visitors
function LiveSheet({ domain, count, onClose }) {
  const pages = useApi(`/sites/${domain}/stats/breakdown/page`, { period: 'realtime', limit: 8 }, [domain]);
  const countries = useApi(`/sites/${domain}/stats/breakdown/country`, { period: 'realtime', limit: 8 }, [domain]);
  const List = ({ res, label, render }) => {
    const rows = (res.data && res.data.results) || [];
    const max = Math.max(1, ...rows.map((r) => r.visitors));
    return html`<section class="stack sm" style="margin-top:20px"><h3>${label}</h3>${res.loading && !res.data ? html`<div class="skeleton" style="height:96px"></div>` : rows.length === 0 ? html`<p class="muted small">Nobody in the last 30 minutes.</p>`
      : html`<div class="mlist">${rows.map((r) => html`<div class="mrow static"><span class="bar" style=${`width:${(r.visitors / max) * 100}%`}></span><span class="l"><span class="t">${render(r)}</span></span><span class="r">${fmt(r.visitors)}</span></div>`)}</div>`}</section>`;
  };
  return html`<${Sheet} title="Live visitors" description="Activity on ${domain} in the last 30 minutes." onClose=${onClose}
    footer=${html`<button class="btn" onClick=${onClose}>Close</button><button class="btn primary" onClick=${() => { onClose(); navigate(`/${domain}?period=realtime`); }}>Open realtime view</button>`}>
    <div class="row" style="gap:12px"><span class=${'dot ' + (count ? '' : 'off')}></span><span style="font-size:28px;font-weight:600;letter-spacing:-0.03em" class="num">${count === null ? '…' : fmt(count)}</span><span class="muted">${count === 1 ? 'visitor right now' : 'visitors right now'}</span></div>
    <${List} res=${pages} label="Current pages" render=${(r) => r.name} />
    <${List} res=${countries} label="Countries" render=${(r) => html`${flag(r.name)} ${countryName(r.name)}`} />
  <//>`;
}

function LivePill({ domain }) {
  const res = useApi(`/sites/${domain}/stats/current-visitors`, {}, [domain], { poll: 30000 });
  const [open, setOpen] = useState(false);
  const n = res.data ? res.data.visitors : null;
  return html`<button class="live-pill" onClick=${() => setOpen(true)} aria-haspopup="dialog" title="Live visitors"><span class=${'dot ' + (n ? '' : 'off')} aria-hidden="true"></span><span class="num">${n === null ? '…' : fmt(n)}</span><span class="muted">${n === 1 ? 'visitor' : 'visitors'}<span class="sr-only"> online now</span></span></button>
    ${open && html`<${LiveSheet} domain=${domain} count=${n} onClose=${() => setOpen(false)} />`}`;
}

// ------------------------------------------------------------------------------------------------- top bar
function sectionLabel(path, domain) {
  if (domain) {
    const rest = path.slice(domain.length + 1);
    if (rest.startsWith('/installation')) return 'Installation';
    const item = SITE_NAV.find((i) => i.route && rest.startsWith(i.route));
    return item ? item.label : 'Overview';
  }
  if (path === '/sites') return 'Sites';
  if (path === '/sites/new') return 'New site';
  if (path === '/sites/rollup') return 'New rollup';
  if (path.startsWith('/settings')) return 'Account';
  const seg = (path.split('/')[1] || '');
  return seg ? seg.charAt(0).toUpperCase() + seg.slice(1) : 'Sites';
}

/** The signed-in top bar: [logo] / [site switcher] / [section], then Find, live visitors, help and the account menu. */
export function AppBar({ user, path, sites, side }) {
  const domain = siteFromPath(path);
  const current = domain ? sites.list.find((s) => s.domain === domain) : null;
  const recents = useRecents(domain);
  return html`<header class="topbar"><div class="topbar-in">
    <button class="btn ghost icon side-toggle" aria-label="Open navigation" aria-expanded=${side.open} aria-controls="app-side" onClick=${() => side.setOpen(!side.open)}><${Icon} name="menu" size=${18} /></button>
    <${Link} to="/sites" class="brand-mark" aria-label="Tidemark, all sites"><${Logo} size=${24} /><//>
    <nav class="crumbs" aria-label="Breadcrumb">
      <span class="crumb-sep" aria-hidden="true">/</span>
      ${domain ? html`<${SiteSwitcher} list=${sites.list} current=${current} domain=${domain} recents=${recents} /><span class="crumb-sep s2" aria-hidden="true">/</span><span class="crumb-cur muted sec" aria-current="page">${sectionLabel(path, domain)}</span>`
        : html`<span class="crumb-cur" aria-current="page">${sectionLabel(path, domain)}</span>`}
    </nav>
    <div class="tb-right">
      <button class="find" onClick=${openPalette} aria-label=${'Find, ' + modKey() + ' K'} aria-haspopup="dialog"><${Icon} name="search" size=${14} /><span>Find</span><kbd>${modKey()} K</kbd></button>
      ${domain && html`<${LivePill} domain=${domain} />`}
      <${HelpMenu} />
      <${AccountMenu} user=${user} />
    </div>
  </div></header>`;
}

// -------------------------------------------------------------------------------------------------- sidebar
const SideLink = ({ to, icon, label, on, children, ...rest }) => html`<${Link} to=${to} class=${'side-link ' + (on ? 'on' : '')} title=${label} aria-current=${on ? 'page' : undefined} ...${rest}><${Icon} name=${icon} size=${16} /><span>${label}</span>${children}<//>`;
const SideExt = ({ href, icon, label, external = true }) => html`<a class="side-link" title=${label} href=${href} target=${external ? '_blank' : null} rel=${external ? 'noopener' : null}><${Icon} name=${icon} size=${16} /><span>${label}</span>${external && html`<${Icon} name="external-link" size=${12} class="side-ext" />`}</a>`;

export function Sidebar({ user, path, sites, side }) {
  const domain = siteFromPath(path);
  const list = sites ? sites.list : [];
  const current = domain && list.find((s) => s.domain === domain);
  const canManage = !current || current.role === 'owner' || current.role === 'admin';
  const hasRollups = list.some((s) => s.rollup);
  const tabs = accountTabs(user);
  const onAccount = path === '/settings' || path.startsWith('/settings/');
  const accountTab = path === '/settings' ? 'profile' : path.split('/')[2];
  const tabPath = (k) => (k === 'profile' ? '/settings' : '/settings/' + k);
  const accountOn = (k) => onAccount && (accountTab === k || (k === 'security' && accountTab === 'preferences') || (k === 'profile' && accountTab === 'preferences'));
  const itemOn = (i) => (i.route === '' ? path === '/' + domain : path.startsWith('/' + domain + i.route));
  const body = domain
    ? html`<${Link} to="/sites" class="side-back" title="All sites"><${Icon} name="chevron-left" size=${15} /><span>All sites</span><//>
      <div class="side-site-head"><${FavTile} name=${domain} rollup=${current && current.rollup} /><b>${domain}</b></div>
      ${SITE_NAV.filter((i) => !i.manage || canManage).map((i) => html`<${SideLink} to=${'/' + domain + i.route + (i.scoped ? scopeQuery() : '')} icon=${i.icon} label=${i.label} on=${itemOn(i)} />`)}`
    : html`<div class="side-grp">Workspace</div>
      <${SideLink} to="/sites" icon="layout-dashboard" label="Sites" on=${path === '/sites' || path === '/sites/new' || path === '/sites/rollup'}>${list.length > 0 && html`<em class="side-count">${list.length}</em>`}<//>
      ${hasRollups && html`<${SideLink} to="/sites?kind=rollups" icon="layers" label="Rollups" on=${false}><em class="side-count">${list.filter((s) => s.rollup).length}</em><//>`}
      <div class="side-grp">Account</div>
      ${tabs.map(([k, l, dot]) => html`<${SideLink} to=${tabPath(k)} icon=${ACCOUNT_ICONS[k] || 'settings'} label=${l} on=${accountOn(k)}>${dot && html`<span class="notif inline" aria-hidden="true"></span><span class="sr-only"> (update available)</span>`}<//>`)}
      ${user.is_admin && registry.adminUi && html`<a class="side-link" title=${registry.adminUi.label} href=${BASE + registry.adminUi.href}><${Icon} name="shield-check" size=${16} /><span>${registry.adminUi.label}</span><${Icon} name="chevron-right" size=${13} class="side-ext" /></a>`}
      <div class="side-grp">Resources</div>
      <${SideExt} href="https://support.tidemarkanalytics.com/en/docs" icon="book-open" label="Documentation" />
      ${registry.menuItems.filter((m) => !m.visible || m.visible(user)).map((m) => (m.onClick
        ? html`<button class="side-link" title=${m.label} onClick=${m.onClick}><${Icon} name=${m.icon || 'life-buoy'} size=${16} /><span>${m.label}</span></button>`
        : html`<${SideExt} href=${m.href} icon=${m.icon || 'external-link'} label=${m.label} external=${!!m.external} />`))}
      <${SideExt} href="https://discord.gg/sCkHD3PPZh" icon="users" label="Community" />`;
  return html`<aside class="side" id="app-side" aria-label="Main">
    <nav class="side-scroll" aria-label=${domain ? 'Site' : 'Workspace'}>${body}</nav>
    <div class="side-foot"><button class="side-collapse" onClick=${side.toggleCollapsed} aria-pressed=${side.collapsed} aria-label=${side.collapsed ? 'Expand sidebar' : 'Collapse sidebar'}><${Icon} name="panel-left" size=${16} /><span>Collapse</span></button></div>
  </aside><div class="side-scrim" onClick=${() => side.setOpen(false)} aria-hidden="true"></div>`;
}

// ------------------------------------------------------------------------------------------ command palette
export function CommandPalette({ path, sites }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [sel, setSel] = useState(0);
  const input = useRef();
  const domain = siteFromPath(path);
  const { user } = useContext(UserCtx) || {};
  const list = sites ? sites.list : [];
  useEffect(() => {
    const key = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setOpen((o) => !o); }
      else if (e.key === 'Escape') setOpen(false);
    };
    const show = () => setOpen(true);
    document.addEventListener('keydown', key);
    window.addEventListener('tdm:palette', show);
    return () => { document.removeEventListener('keydown', key); window.removeEventListener('tdm:palette', show); };
  }, []);
  useEffect(() => { if (open) { setQ(''); setSel(0); setTimeout(() => input.current && input.current.focus(), 0); } }, [open]);
  useEffect(() => {
    if (!open) return;
    const prev = document.activeElement;
    return () => { prev && prev.focus && prev.isConnected && prev.focus(); };
  }, [open]);
  const items = useMemo(() => {
    const go = (to) => () => navigate(to);
    const out = [];
    if (domain) {
      PERIODS.filter((p) => p[0] !== 'custom').forEach(([k, l, key]) => out.push({ group: 'Date range', icon: 'calendar', label: l, hint: key, run: () => command({ type: 'period', key: k }) }));
      out.push({ group: 'Date range', icon: 'calendar', label: 'Custom range…', hint: 'U', run: () => command({ type: 'period', key: 'custom' }) });
      out.push({ group: 'Date range', icon: 'chevron-left', label: 'Previous period', hint: '[', run: () => command({ type: 'step', dir: -1 }) });
      out.push({ group: 'Date range', icon: 'chevron-right', label: 'Next period', hint: ']', run: () => command({ type: 'step', dir: 1 }) });
      out.push({ group: 'View', icon: 'git-compare', label: 'Toggle comparison with the previous period', hint: 'C', run: () => command({ type: 'compare' }) });
      out.push({ group: 'View', icon: 'plus', label: 'Add a filter…', hint: '', run: () => command({ type: 'filter' }) });
      out.push({ group: 'View', icon: 'rotate-ccw', label: 'Reset filters and zoom', run: () => command({ type: 'reset' }) });
      out.push({ group: 'View', icon: 'download', label: 'Export this view as CSV', run: () => command({ type: 'export' }) });
      SITE_NAV.filter((i) => !i.manage).forEach((i) => out.push({ group: 'This site', icon: i.icon, label: i.label, hint: 'g ' + i.key, run: go('/' + domain + i.route + scopeQuery()) }));
      out.push({ group: 'This site', icon: 'settings', label: 'Site settings', hint: 'g ,', run: go(`/${domain}/settings`) });
    }
    list.forEach((s) => out.push({ group: 'Sites', icon: s.rollup ? 'layers' : 'globe', label: s.domain, hint: s.rollup ? 'Rollup' : '', run: go('/' + s.domain) }));
    out.push({ group: 'Go to', icon: 'layout-dashboard', label: 'All sites', hint: 'g a', run: go('/sites') }, { group: 'Go to', icon: 'plus', label: 'Add a site', run: go('/sites/new') });
    if (user) accountTabs(user).forEach(([k, l]) => out.push({ group: 'Account', icon: ACCOUNT_ICONS[k] || 'settings', label: l, run: go(k === 'profile' ? '/settings' : '/settings/' + k) }));
    out.push({ group: 'Preferences', icon: 'sun', label: 'Theme: light', run: () => setTheme('light') }, { group: 'Preferences', icon: 'moon', label: 'Theme: dark', run: () => setTheme('dark') }, { group: 'Preferences', icon: 'monitor', label: 'Theme: match system', run: () => setTheme('system') },
      { group: 'Preferences', icon: 'keyboard', label: 'Keyboard shortcuts', hint: '?', run: openShortcuts }, { group: 'Preferences', icon: 'book-open', label: 'Documentation', run: () => window.open('https://support.tidemarkanalytics.com/en/docs', '_blank', 'noopener') });
    return out;
  }, [domain, list, user && user.is_admin]);
  const shown = useMemo(() => {
    const t = q.trim().toLowerCase();
    // an empty search shows a short, useful start; typing searches everything
    const base = t ? items.filter((i) => i.label.toLowerCase().includes(t) || (i.group + ' ' + i.label).toLowerCase().includes(t)) : items.filter((i) => !['Date range', 'Preferences', 'Account'].includes(i.group) || (i.group === 'Date range' && i.label === 'Today'));
    return base.slice(0, t ? 20 : 14);
  }, [items, q]);
  useEffect(() => setSel(0), [q]);
  if (!open) return null;
  const run = (i) => { setOpen(false); i && setTimeout(i.run, 0); };
  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setSel((s) => Math.min(shown.length - 1, s + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setSel((s) => Math.max(0, s - 1)); }
    else if (e.key === 'Enter') { e.preventDefault(); run(shown[sel]); }
    else if (e.key === 'Tab') e.preventDefault();
  };
  const groups = [];
  shown.forEach((i, n) => { let g = groups.find((x) => x[0] === i.group); if (!g) groups.push((g = [i.group, []])); g[1].push([i, n]); });
  return html`<div class="overlay palette-wrap" onMouseDown=${(e) => e.target === e.currentTarget && setOpen(false)}>
    <div class="palette" role="dialog" aria-modal="true" aria-label="Command palette">
      <div class="palette-in"><${Icon} name="search" size=${16} /><input ref=${input} value=${q} placeholder="Type a command or search…" role="combobox" aria-expanded="true" aria-controls="palette-list" aria-activedescendant=${shown[sel] ? 'pal-' + sel : undefined} aria-label="Search commands" onInput=${(e) => setQ(e.target.value)} onKeyDown=${onKey} /><kbd>esc</kbd></div>
      <div class="palette-list" id="palette-list" role="listbox" aria-label="Results">${shown.length === 0 ? html`<div class="palette-empty muted">No results for “${q}”.</div>` : groups.map(([label, rows]) => html`<div role="group" aria-label=${label}><div class="grp" aria-hidden="true">${label}</div>${rows.map(([i, n]) => html`<button id=${'pal-' + n} tabindex="-1" role="option" aria-selected=${n === sel} class=${'palette-item ' + (n === sel ? 'on' : '')} onMouseMove=${() => setSel(n)} onClick=${() => run(i)}><${Icon} name=${i.icon} size=${15} /><span>${i.label}</span>${i.hint && html`<em>${i.hint}</em>`}</button>`)}</div>`)}</div>
      <div class="palette-foot"><span><kbd>↑</kbd><kbd>↓</kbd> Navigate</span><span><kbd>↵</kbd> Select</span><span><kbd>esc</kbd> Close</span></div>
    </div></div>`;
}

// ----------------------------------------------------------------------------------------------- shortcuts
const typingIn = (e) => {
  const t = (e.composedPath && e.composedPath()[0]) || e.target;
  const is = (n) => n && (/input|textarea|select/i.test(n.tagName || '') || n.isContentEditable);
  return is(t) || is(e.target) || /-/.test((e.target && e.target.tagName) || '');
};

/** Global keys: `?` opens the shortcut list, `g` then a letter jumps (sections of the open site, or the sites list). */
export function ShellKeys({ path }) {
  const [open, setOpen] = useState(false);
  const domain = siteFromPath(path);
  const state = useRef({});
  state.current = { domain, path };
  useEffect(() => {
    const show = () => setOpen(true);
    window.addEventListener('tdm:shortcuts', show);
    let armed = 0;
    const onKey = (e) => {
      if (e.metaKey || e.ctrlKey || e.altKey || typingIn(e)) return;
      if (e.key === '?') { if (!document.querySelector('.overlay')) { e.preventDefault(); setOpen(true); } return; }
      if (document.querySelector('.overlay') || document.querySelector('.sheet')) return;
      const now = Date.now();
      if (armed && now - armed < 1200) {
        armed = 0;
        const k = e.key.toLowerCase();
        const { domain: d } = state.current;
        const site = d && SITE_NAV.find((i) => i.key === k);
        if (site) { e.preventDefault(); e.stopImmediatePropagation(); navigate('/' + d + site.route + (site.scoped ? scopeQuery() : '')); }
        else if (k === 'a') { e.preventDefault(); e.stopImmediatePropagation(); navigate('/sites'); }
        return;
      }
      armed = e.key === 'g' ? now : 0;
    };
    window.addEventListener('keydown', onKey, true);
    return () => { window.removeEventListener('keydown', onKey, true); window.removeEventListener('tdm:shortcuts', show); };
  }, []);
  if (!open) return null;
  const Row = ({ label, keys }) => html`<div class="sc-row"><span>${label}</span><span class="keys">${keys.map((k) => html`<kbd>${k}</kbd>`)}</span></div>`;
  return html`<${Sheet} title="Keyboard shortcuts" description="Shortcuts are off while you type in a field." onClose=${() => setOpen(false)}>
    <div class="shortcuts">
      <h3>General</h3>
      <${Row} label="Command palette" keys=${[modKey(), 'K']} /><${Row} label="This list" keys=${['?']} /><${Row} label="Close a menu or dialog" keys=${['Esc']} />
      <h3>Go to (press G, then)</h3>
      <${Row} label="All sites" keys=${['g', 'a']} />
      ${SITE_NAV.map((i) => html`<${Row} label=${i.label} keys=${['g', i.key]} />`)}
      <h3>Period and comparison</h3>
      <${Row} label="Previous period" keys=${['[']} /><${Row} label="Next period" keys=${[']']} />
      <${Row} label="Today" keys=${['T']} /><${Row} label="Turn comparison on or off" keys=${['C']} />
      ${PERIODS.filter((p) => p[2] && p[0] !== 'day').map(([, l, k]) => html`<${Row} label=${l} keys=${[k]} />`)}
      <${Row} label="Previous / next day, month or year" keys=${['←', '→']} />
      <h3>In reports</h3>
      <${Row} label="Search the table" keys=${['/']} />

    </div>
  <//>`;
}
