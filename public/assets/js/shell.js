// The signed-in app shell: the harbor sidebar (sites, sections of the open dashboard, account) and the ⌘K command palette.
import { html, useState, useEffect, useMemo, useRef, useApi, api, navigate, Link, Icon, fmtShort, setQuery, BASE, PERIODS, UserCtx, useContext } from './lib.js';
import { Pop } from './ui.js';
import { Lockup } from './logo.js';
import { registry } from './extensions.js';

const NOT_A_SITE = new Set(['', 'sites', 'settings', 'login', 'register', 'forgot-password', 'reset-password', 'share']);
export const siteFromPath = (path) => { const seg = path.split('/')[1] || ''; return NOT_A_SITE.has(seg) ? null : decodeURIComponent(seg); };

/** The sections of a site's dashboard. They stay in the sidebar on every page of the site, so the menu doesn't change under you. */
const SECTIONS = [{ id: 'overview', label: 'Overview', icon: 'chart-column' }, { id: 'traffic', label: 'Traffic', icon: 'compass' }, { id: 'audience', label: 'Audience', icon: 'users' }, { id: 'conversions', label: 'Conversions', icon: 'target' }];
const smooth = () => (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth');
/** Scroll the dashboard to a section; from another page of the site, open the dashboard and let it do the scrolling. */
export const scrollToSection = (id) => { const el = document.getElementById('sec-' + id); if (el) el.scrollIntoView({ behavior: smooth(), block: 'start' }); };
/** Tell the open dashboard to do something (change the period, toggle the comparison). */
export const command = (detail) => window.dispatchEvent(new CustomEvent('tdm:cmd', { detail }));

/** `compact` is for the sidebar, which already has these links; the top bar on narrow screens shows them all. */
export function AccountMenu({ user, align = 'right', btnClass = 'btn ghost avatar-btn', named, compact }) {
  return html`<${Pop} align=${align} ariaLabel="Account menu" btnClass=${btnClass} label=${html`<span class="avatar" aria-hidden="true">${(user.name || '?').slice(0, 1).toUpperCase()}</span>${named && html`<span class="who-name"><b>${user.name}</b><span>${user.email}</span></span>`}${user.update && html`<span class="notif on-avatar" aria-hidden="true"></span><span class="sr-only">Update available</span>`}`}>
    ${(close) => html`<div style="min-width:220px"><div class="who"><b>${user.name}</b><span class="muted">${user.email}</span></div><hr />
      ${!compact && html`<div>      <${Link} to="/sites" class="item" onClick=${close}><span>Your sites</span><${Icon} name="globe" size=${14} /><//>
      <${Link} to="/settings" class="item" onClick=${close}><span>Account settings${user.update && html`<span class="notif inline" aria-hidden="true"></span><span class="sr-only"> (update available)</span>`}</span><${Icon} name="settings" size=${14} /><//>
      ${user.is_admin && registry.adminUi && html`<a class="item" href=${BASE + registry.adminUi.href} onClick=${close}><span>${registry.adminUi.label}</span><${Icon} name="shield-check" size=${14} /></a>`}
      <a class="item" href="https://support.tidemarkanalytics.com/en/docs" target="_blank" rel="noopener" onClick=${close}><span>Documentation</span><${Icon} name="book-open" size=${14} /></a>
      <a class="item" href="https://discord.gg/sCkHD3PPZh" target="_blank" rel="noopener" onClick=${close}><span>Community (Discord)</span><${Icon} name="users" size=${14} /></a>
      ${registry.menuItems.filter((m) => !m.visible || m.visible(user)).map((m) => (m.onClick
        ? html`<button class="item" onClick=${() => { close(); m.onClick(); }}><span>${m.label}</span><${Icon} name=${m.icon || 'external-link'} size=${14} /></button>`
        : html`<a class="item" href=${m.href} target=${m.external ? '_blank' : null} rel=${m.external ? 'noopener' : null} onClick=${close}><span>${m.label}</span><${Icon} name=${m.icon || 'external-link'} size=${14} /></a>`))}</div>`}
      <button class="item" onClick=${async () => { close(); await api('/auth/logout', { method: 'POST' }); window.location.assign(window.SD.base + '/login'); }}><span>Sign out</span><${Icon} name="log-out" size=${14} /></button></div>`}<//>`;
}

/** Which section is in view: the last one whose top has passed the sticky toolbar. */
function useScrollSpy(ids) {
  const [active, setActive] = useState(ids[0]);
  useEffect(() => {
    if (!ids.length) return;
    const on = () => {
      const els = ids.map((id) => [id, document.getElementById('sec-' + id)]).filter(([, el]) => el);
      if (!els.length) { setActive(ids[0]); return; } // the page hasn't rendered its sections yet
      let cur = els[0][0];
      for (const [id, el] of els) if (el.getBoundingClientRect().top <= 120) cur = id;
      const scrollable = document.documentElement.scrollHeight > window.innerHeight + 80;
      if (scrollable && window.scrollY > 0 && window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) cur = els[els.length - 1][0];
      setActive(cur);
    };
    on();
    window.addEventListener('scroll', on, { passive: true });
    // the sections appear and grow as their data loads; follow that too
    const ro = typeof ResizeObserver === 'function' ? new ResizeObserver(on) : null;
    if (ro) ro.observe(document.body);
    return () => { window.removeEventListener('scroll', on); if (ro) ro.disconnect(); };
  }, [ids.join()]);
  return active;
}

/** Vercel-style picker: the open site on top, a searchable list underneath. */
function SiteSwitcher({ list, current, onPick }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [sel, setSel] = useState(0);
  const wrap = useRef();
  const input = useRef();
  const shown = list.filter((s) => s.domain.toLowerCase().includes(q.trim().toLowerCase()));
  useEffect(() => {
    if (!open) return;
    setQ(''); setSel(Math.max(0, list.findIndex((s) => current && s.domain === current.domain)));
    setTimeout(() => input.current && input.current.focus(), 0);
    const out = (e) => wrap.current && !wrap.current.contains(e.target) && setOpen(false);
    document.addEventListener('mousedown', out);
    return () => document.removeEventListener('mousedown', out);
  }, [open]);
  useEffect(() => setSel(0), [q]);
  const pick = (s) => { setOpen(false); onPick(s); };
  const onKey = (e) => {
    if (e.key === 'Escape') { setOpen(false); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); setSel((n) => Math.min(shown.length - 1, n + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setSel((n) => Math.max(0, n - 1)); }
    else if (e.key === 'Enter' && shown[sel]) { e.preventDefault(); pick(shown[sel]); }
  };
  const icon = (s) => (s && s.rollup ? 'layers' : 'globe');
  return html`<div class="side-switch" ref=${wrap}>
    <button class=${'side-switch-btn' + (open ? ' open' : '')} aria-haspopup="listbox" aria-expanded=${open} onClick=${() => setOpen(!open)}>
      <${Icon} name=${current ? icon(current) : 'layout-dashboard'} size=${16} /><span class="trunc">${current ? current.domain : 'Select a site'}</span><${Icon} name="chevrons-up-down" size=${14} class="chev" />
    </button>
    ${open && html`<div class="side-pop" role="dialog" aria-label="Switch site">
      <div class="side-pop-in"><${Icon} name="search" size=${14} /><input ref=${input} value=${q} placeholder="Find site…" aria-label="Find site" onInput=${(e) => setQ(e.target.value)} onKeyDown=${onKey} /><kbd>esc</kbd></div>
      <div class="side-pop-list" role="listbox">${shown.length === 0 ? html`<div class="side-pop-empty">No site matches “${q}”</div>` : shown.map((s, n) => html`<button role="option" aria-selected=${current && s.domain === current.domain} class=${'side-pop-item ' + (n === sel ? 'on' : '')} onMouseMove=${() => setSel(n)} onClick=${() => pick(s)}>
        <${Icon} name=${icon(s)} size=${14} /><span class="trunc">${s.domain}</span>${s.rollup && html`<em>rollup</em>`}${current && s.domain === current.domain && html`<${Icon} name="check" size=${14} class="tick" />`}</button>`)}</div>
      <div class="side-pop-foot"><button class="side-pop-item" onClick=${() => { setOpen(false); navigate('/sites'); }}><${Icon} name="layout-dashboard" size=${14} /><span>All sites</span></button>
        <button class="side-pop-item" onClick=${() => { setOpen(false); navigate('/sites/new'); }}><${Icon} name="plus" size=${14} /><span>Add a site</span></button></div>
    </div>`}
  </div>`;
}

export function Sidebar({ user, path }) {
  const sites = useApi('/sites', null, [path.startsWith('/sites') ? path : '']);
  const domain = siteFromPath(path);
  const list = ((sites.data && sites.data.data) || []).slice().sort((a, b) => (a.rollup !== b.rollup ? (a.rollup ? -1 : 1) : a.domain.localeCompare(b.domain)));
  const current = domain && list.find((s) => s.domain === domain);
  const recent = list.filter((s) => !s.rollup).sort((a, b) => (b.visitors || 0) - (a.visitors || 0)).slice(0, 5);
  const onDash = !!domain && path === '/' + domain;
  const active = useScrollSpy(onDash ? SECTIONS.map((x) => x.id) : []);
  const jump = (id) => { if (onDash) scrollToSection(id); else { window.__tdmJump = id; navigate('/' + domain); } };
  const canManage = current && (current.role === 'owner' || current.role === 'admin');
  const onSite = (p) => path === p || path.startsWith(p + '/');
  return html`<aside class="side" aria-label="Main">
    <${Link} to="/sites" class="side-brand" aria-label="Tidemark home"><${Lockup} size=${22} /><//>
    <button class="side-search" onClick=${() => window.dispatchEvent(new CustomEvent('tdm:palette'))}><${Icon} name="search" size=${14} /><span>Search or jump to…</span><kbd>⌘K</kbd></button>
    ${domain && html`<${SiteSwitcher} list=${list} current=${current} onPick=${(s) => navigate('/' + s.domain + (path.startsWith('/' + domain + '/settings') ? path.slice(domain.length + 1) : ''))} />`}
    <nav class="side-nav">
      ${current && html`<div class="side-grp">On this site</div>
        ${SECTIONS.map((s) => html`<button class=${'side-link ' + (onDash && active === s.id ? 'on' : '')} onClick=${() => jump(s.id)}><${Icon} name=${s.icon} size=${16} /><span>${s.label}</span></button>`)}
        ${canManage && html`<${Link} to=${'/' + domain + '/settings'} class=${'side-link ' + (onSite('/' + domain + '/settings') ? 'on' : '')}><${Icon} name="settings" size=${16} /><span>Site settings</span><//>`}`}
      <div class="side-grp">Workspace</div>
      <${Link} to="/sites" class=${'side-link ' + (path === '/sites' || path.startsWith('/sites/') ? 'on' : '')}><${Icon} name="layout-dashboard" size=${16} /><span>Sites</span>${list.length > 0 && html`<em class="side-count">${list.length}</em>`}<//>
      <${Link} to="/settings" class=${'side-link ' + (path === '/settings' || (path.startsWith('/settings/') && !/^\/settings\/(users|updates)/.test(path)) ? 'on' : '')}><${Icon} name="user" size=${16} /><span>Account</span><//>
      ${user.is_admin && registry.adminUi && html`<a class="side-link" href=${BASE + registry.adminUi.href}><${Icon} name="shield-check" size=${16} /><span>${registry.adminUi.label}</span><${Icon} name="chevron-right" size=${13} class="side-ext" /></a>`}
      ${user.is_admin && !registry.adminUi && html`<${Link} to="/settings/users" class=${'side-link ' + (path.startsWith('/settings/users') ? 'on' : '')}><${Icon} name="users" size=${16} /><span>Users</span><//>`}
      ${user.is_admin && !registry.adminUi && user.updater_enabled !== false && html`<${Link} to="/settings/updates" class=${'side-link ' + (path.startsWith('/settings/updates') ? 'on' : '')}><${Icon} name="refresh-cw" size=${16} /><span>Updates</span>${user.update && html`<span class="notif inline" aria-hidden="true"></span><span class="sr-only"> (update available)</span>`}<//>`}
      ${!current && recent.length > 0 && html`<div class="side-grp">Busiest sites</div>
        ${recent.map((s) => html`<${Link} to=${'/' + s.domain} class="side-site" title=${s.domain}><${Icon} name=${s.rollup ? 'layers' : 'globe'} size=${14} /><span class="trunc">${s.domain}</span><em>${fmtShort(s.visitors)}</em><//>`)}`}
      <div class="side-grp">Help</div>
      <a class="side-link" href="https://support.tidemarkanalytics.com/en/docs" target="_blank" rel="noopener"><${Icon} name="book-open" size=${16} /><span>Documentation</span><${Icon} name="external-link" size=${12} class="side-ext" /></a>
      <a class="side-link" href="https://discord.gg/sCkHD3PPZh" target="_blank" rel="noopener"><${Icon} name="users" size=${16} /><span>Community</span><${Icon} name="external-link" size=${12} class="side-ext" /></a>
      ${registry.menuItems.filter((m) => !m.visible || m.visible(user)).map((m) => (m.onClick
        ? html`<button class="side-link" onClick=${m.onClick}><${Icon} name=${m.icon || 'life-buoy'} size=${16} /><span>${m.label}</span></button>`
        : html`<a class="side-link" href=${m.href} target=${m.external ? '_blank' : null} rel=${m.external ? 'noopener' : null}><${Icon} name=${m.icon || 'external-link'} size=${16} /><span>${m.label}</span>${m.external && html`<${Icon} name="external-link" size=${12} class="side-ext" />`}</a>`))}
    </nav>
    <div class="side-foot">
      <${AccountMenu} user=${user} align="left" btnClass="side-account" named compact />
    </div>
  </aside>`;
}

// ------------------------------------------------------------------------------------------ command palette
export function CommandPalette({ path }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [sel, setSel] = useState(0);
  const input = useRef();
  const domain = siteFromPath(path);
  const sites = useApi(open ? '/sites' : null, null, [open], { enabled: open });
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
  const items = useMemo(() => {
    const go = (to) => () => navigate(to);
    const out = [];
    if (domain) {
      PERIODS.filter((p) => p[0] !== 'custom').forEach(([k, l]) => out.push({ group: 'Date range', icon: 'calendar', label: l, run: () => command({ type: 'period', key: k }) }));
      out.push({ group: 'View', icon: 'trending-up', label: 'Toggle comparison with the previous period', run: () => command({ type: 'compare' }) });
      out.push({ group: 'View', icon: 'funnel', label: 'Add a filter…', run: () => command({ type: 'filter' }) });
      out.push({ group: 'View', icon: 'download', label: 'Export this view as CSV', run: () => command({ type: 'export' }) });
    }
    ((sites.data && sites.data.data) || []).forEach((s) => out.push({ group: 'Sites', icon: s.rollup ? 'layers' : 'globe', label: s.domain, hint: s.rollup ? 'Rollup' : '', run: go('/' + s.domain) }));
    if (domain) out.push({ group: 'Go to', icon: 'settings', label: 'Site settings', run: go(`/${domain}/settings`) });
    out.push({ group: 'Go to', icon: 'layout-dashboard', label: 'All sites', run: go('/sites') }, { group: 'Go to', icon: 'plus', label: 'Add a site', run: go('/sites/new') }, { group: 'Go to', icon: 'settings', label: 'Account settings', run: go('/settings') });
    return out;
  }, [domain, sites.data]);
  const shown = useMemo(() => items.filter((i) => i.label.toLowerCase().includes(q.toLowerCase())).slice(0, 14), [items, q]);
  useEffect(() => setSel(0), [q]);
  if (!open) return null;
  const run = (i) => { setOpen(false); i && i.run(); };
  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setSel((s) => Math.min(shown.length - 1, s + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setSel((s) => Math.max(0, s - 1)); }
    else if (e.key === 'Enter') { e.preventDefault(); run(shown[sel]); }
  };
  let last = null;
  return html`<div class="overlay palette-wrap" onMouseDown=${(e) => e.target === e.currentTarget && setOpen(false)}>
    <div class="palette" role="dialog" aria-label="Command palette">
      <div class="palette-in"><${Icon} name="search" size=${16} /><input ref=${input} value=${q} placeholder="Jump to a site, change the date range, add a filter…" aria-label="Search commands" onInput=${(e) => setQ(e.target.value)} onKeyDown=${onKey} /><kbd>esc</kbd></div>
      <div class="palette-list" role="listbox">${shown.length === 0 ? html`<div class="palette-empty muted">Nothing matches “${q}”</div>` : shown.map((i, n) => {
        const head = i.group !== last ? html`<div class="grp">${i.group}</div>` : null;
        last = i.group;
        return html`${head}<button role="option" aria-selected=${n === sel} class=${'palette-item ' + (n === sel ? 'on' : '')} onMouseMove=${() => setSel(n)} onClick=${() => run(i)}><${Icon} name=${i.icon} size=${15} /><span>${i.label}</span>${i.hint && html`<em>${i.hint}</em>`}</button>`;
      })}</div>
    </div></div>`;
}
