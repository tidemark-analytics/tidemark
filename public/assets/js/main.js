import { html, render, useState, useEffect, useBrand, api, navigate, useLocation, matchRoute, Link, Icon, toggleTheme, useToasts, UserCtx } from './lib.js';
import { Login, Register, ForgotPassword, ResetPassword } from './pages/auth.js';
import { Dashboard } from './dashboard.js';
import { SitesPage, NewSite, NewRollup, Installation } from './sites.js';
import { SiteSettings, AccountSettings } from './settings.js';
import { Logo, Lockup } from './logo.js';
import { registry, loadExtensions } from './extensions.js';
import { Sidebar, CommandPalette, AccountMenu } from './shell.js';

const head = [
  ['/login', Login],
  ['/register', Register],
  ['/forgot-password', ForgotPassword],
  ['/reset-password', ResetPassword],
  ['/sites', SitesPage],
  ['/sites/new', NewSite],
  ['/sites/rollup', NewRollup],
  ['/settings/:tab?', AccountSettings],
  ['/share/:domain', (p) => html`<${Dashboard} ...${p} shared />`],
];
const tail = [
  ['/:domain/installation', Installation],
  ['/:domain/settings/:tab?', SiteSettings],
  ['/:domain', Dashboard],
];
// extension pages sit between the fixed pages and the "/{domain}" catch-all
const allRoutes = () => [...head, ...registry.routes, ...tail];
const RESERVED = new Set(['', 'api', 'js', 'assets', 'install', 'up', 'admin']);

function PublicFooter() {
  return html`<footer class="pubfoot"><a href=${window.SD.brandUrl || 'https://tidemarkanalytics.com'} target="_blank" rel="noopener"><span>Analytics by</span><${Lockup} size=${15} /></a></footer>`;
}

function TopBar({ user, shared }) {
  const brand = useBrand();
  const custom = !user && brand && (brand.logo || brand.name);
  return html`<a class="skip" href="#main" onClick=${(e) => { e.preventDefault(); const t = document.getElementById('main'); t && (t.setAttribute('tabindex', '-1'), t.focus()); }}>Skip to content</a><header class="topbar"><div class="topbar-in">
    ${custom ? html`<span class="brand custom">${brand.logo && html`<img class="brand-logo" src=${brand.logo} alt=${brand.name || ''} />`}${brand.name && !(brand.hide_name && brand.logo) && html`<span class="brand-name">${brand.name}</span>`}</span>`
      : html`<${Link} to=${user ? '/sites' : '/login'} class="brand" aria-label="Tidemark home"><${Lockup} size=${24} /><//>`}
    ${shared && html`<span class="pill">Shared view</span>`}
    <span class="grow"></span>
    ${!user && html`<button class="btn ghost icon" onClick=${toggleTheme} aria-label="Toggle light and dark theme" title="Toggle theme"><${Icon} name="sun" size=${16} class="theme-sun" /><${Icon} name="moon" size=${16} class="theme-moon" /></button>`}
    ${user ? html`<${AccountMenu} user=${user} />`
      : !shared && html`<${Link} to="/login" class="btn sm">Sign in<//>`}
  </div></header>`;
}

function VerifyBanner({ user }) {
  const [sent, setSent] = useState(false);
  if (!user.verification_required || user.email_verified) return null;
  return html`<div class="banner" role="status"><${Icon} name="info" size=${15} /><span>Please confirm your email address (${user.email}) to add sites. ${sent ? 'We sent you a new link.' : ''}</span>
    ${!sent && html`<button class="btn sm" onClick=${async () => { await api('/auth/verification-notification', { method: 'POST' }); setSent(true); }}>Resend email</button>`}</div>`;
}

function App() {
  const { path } = useLocation();
  const toasts = useToasts();
  const [user, setUser] = useState(undefined);
  const isAuth = ['/login', '/register', '/forgot-password', '/reset-password'].includes(path);
  const isShare = path.startsWith('/share/');

  useEffect(() => {
    if (isShare) { setUser(null); return; }
    api('/me').then((r) => { window.__userTz = r.user.timezone && r.user.timezone !== 'UTC' ? r.user.timezone : undefined; window.__cfg = r.config; setUser(r.user); }).catch(() => setUser(null));
  }, [isAuth, isShare]);

  const first = path.split('/')[1] || '';
  const m = RESERVED.has(first) ? null : matchRoute(allRoutes(), path);
  const publicRoute = isShare || (m && m.component === Dashboard);
  useEffect(() => { if (user === null && !isAuth && !publicRoute) navigate('/login', { replace: true }); }, [user, path]);
  useEffect(() => { if (user && isAuth) navigate('/sites', { replace: true }); }, [user, path]);
  useEffect(() => { if (path === '/' ) navigate(user ? '/sites' : '/login', { replace: true }); }, [path, user]);

  const Page = m && m.component;
  const toastEls = html`<div class="toasts" aria-live="polite">${toasts.map((t) => html`<div class=${'toast ' + t.kind} role="status"><${Icon} name=${t.kind === 'err' ? 'info' : 'check'} size=${15} />${t.message}</div>`)}</div>`;

  if (isAuth) return html`<${UserCtx.Provider} value=${{ user, setUser }}>${Page ? html`<${Page} setUser=${setUser} />` : null}${toastEls}<//>`;
  if (user === undefined) return html`<div style="display:grid;place-items:center;height:100vh"><${Logo} size=${40} /></div>`;
  if (user === null && !publicRoute) return null;
  const side = !!user && !isShare;
  return html`<${UserCtx.Provider} value=${{ user, setUser }}>
    <div class=${'app' + (side ? ' has-side' : '')}>
      ${side && html`<${Sidebar} user=${user} path=${path} />`}
      <div class="app-main">
        <${TopBar} user=${user} shared=${isShare} />
        ${user && html`<${VerifyBanner} user=${user} />`}
        ${user && registry.banners.map((B) => html`<${B} user=${user} />`)}
        ${Page ? html`<${Page} ...${m.params} />` : html`<main class="main"><div class="empty" style="padding-top:80px"><div class="ico"><${Icon} name="search" size=${20} /></div><b>Page not found</b><p>The page you're looking for doesn't exist.</p><${Link} to="/sites" class="btn">Your sites<//></div></main>`}
        ${publicRoute && !user && html`<${PublicFooter} />`}
      </div>
    </div>
    ${side && html`<${CommandPalette} path=${path} />`}
    ${toastEls}<//>`;
}

loadExtensions(window.SD.extensions || []).finally(() => render(html`<${App} />`, document.getElementById('root')));
