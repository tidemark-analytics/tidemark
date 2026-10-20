import { html, render, useState, useEffect, api, navigate, useLocation, matchRoute, Link, Icon, toggleTheme, useToasts, UserCtx } from './lib.js';
import { Pop } from './ui.js';
import { Login, Register } from './pages/auth.js';
import { Dashboard } from './dashboard.js';
import { SitesPage, NewSite, Installation } from './sites.js';
import { SiteSettings, AccountSettings } from './settings.js';
import { Logo, Lockup } from './logo.js';

const routes = [
  ['/login', Login],
  ['/register', Register],
  ['/sites', SitesPage],
  ['/sites/new', NewSite],
  ['/settings/:tab?', AccountSettings],
  ['/share/:domain', (p) => html`<${Dashboard} ...${p} shared />`],
  ['/:domain/installation', Installation],
  ['/:domain/settings/:tab?', SiteSettings],
  ['/:domain', Dashboard],
];
const RESERVED = new Set(['', 'api', 'js', 'assets', 'install', 'up']);

function TopBar({ user, shared }) {
  return html`<a class="skip" href="#main" onClick=${(e) => { e.preventDefault(); const t = document.getElementById('main'); t && (t.setAttribute('tabindex', '-1'), t.focus()); }}>Skip to content</a><header class="topbar"><div class="topbar-in">
    <${Link} to=${user ? '/sites' : '/login'} class="brand" aria-label="Tidemark home"><${Lockup} size=${24} /><//>
    ${shared && html`<span class="pill">Shared view</span>`}
    <span class="grow"></span>
    <button class="btn ghost icon" onClick=${toggleTheme} aria-label="Toggle light and dark theme" title="Toggle theme"><${Icon} name="sun" size=${16} class="theme-sun" /><${Icon} name="moon" size=${16} class="theme-moon" /></button>
    ${user ? html`<${Pop} align="right" ariaLabel="Account menu" btnClass="btn ghost avatar-btn" label=${html`<span class="avatar" aria-hidden="true">${(user.name || '?').slice(0, 1).toUpperCase()}</span>`}>
        ${(close) => html`<div style="min-width:220px"><div class="who"><b>${user.name}</b><span class="muted">${user.email}</span></div><hr />
          <${Link} to="/sites" class="item" onClick=${close}><span>Your sites</span><${Icon} name="globe" size=${14} /><//>
          <${Link} to="/settings" class="item" onClick=${close}><span>Account settings</span><${Icon} name="settings" size=${14} /><//>
          <button class="item" onClick=${async () => { close(); await api('/auth/logout', { method: 'POST' }); window.location.assign(window.SD.base + '/login'); }}><span>Sign out</span><${Icon} name="log-out" size=${14} /></button></div>`}<//>`
      : !shared && html`<${Link} to="/login" class="btn sm">Sign in<//>`}
  </div></header>`;
}

function App() {
  const { path } = useLocation();
  const toasts = useToasts();
  const [user, setUser] = useState(undefined);
  const isAuth = path === '/login' || path === '/register';
  const isShare = path.startsWith('/share/');

  useEffect(() => {
    if (isShare) { setUser(null); return; }
    api('/me').then((r) => { window.__userTz = r.user.timezone && r.user.timezone !== 'UTC' ? r.user.timezone : undefined; window.__cfg = r.config; setUser(r.user); }).catch(() => setUser(null));
  }, [isAuth, isShare]);

  const first = path.split('/')[1] || '';
  const m = RESERVED.has(first) ? null : matchRoute(routes, path);
  const publicRoute = isShare || (m && m.component === Dashboard);
  useEffect(() => { if (user === null && !isAuth && !publicRoute) navigate('/login', { replace: true }); }, [user, path]);
  useEffect(() => { if (user && isAuth) navigate('/sites', { replace: true }); }, [user, path]);
  useEffect(() => { if (path === '/' ) navigate(user ? '/sites' : '/login', { replace: true }); }, [path, user]);

  const Page = m && m.component;
  const toastEls = html`<div class="toasts" aria-live="polite">${toasts.map((t) => html`<div class=${'toast ' + t.kind} role="status"><${Icon} name=${t.kind === 'err' ? 'info' : 'check'} size=${15} />${t.message}</div>`)}</div>`;

  if (isAuth) return html`<${UserCtx.Provider} value=${{ user, setUser }}>${Page ? html`<${Page} setUser=${setUser} />` : null}${toastEls}<//>`;
  if (user === undefined) return html`<div style="display:grid;place-items:center;height:100vh"><${Logo} size=${40} /></div>`;
  if (user === null && !publicRoute) return null;
  return html`<${UserCtx.Provider} value=${{ user, setUser }}>
    <${TopBar} user=${user} shared=${isShare} />
    ${Page ? html`<${Page} ...${m.params} />` : html`<main class="main"><div class="empty" style="padding-top:80px"><div class="ico"><${Icon} name="search" size=${20} /></div><b>Page not found</b><p>The page you're looking for doesn't exist.</p><${Link} to="/sites" class="btn">Your sites<//></div></main>`}
    ${toastEls}<//>`;
}

render(html`<${App} />`, document.getElementById('root'));
