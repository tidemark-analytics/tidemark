// Extension points for packages that add features to the dashboard (for example a hosted service's billing pages).
// A package adds the URL of an ES module to config('tidemark.extensions'). The module's default export is called once, before
// the app renders, with the `api` object below:
//
//   export default function (api) {
//     const { html, lib, ui } = api;
//     api.registerAccountTab({ key: 'billing', label: 'Billing', component: Billing, dot: (user) => user.needs_attention });
//     api.registerBanner(({ user }) => html`<div class="notice">…</div>`);
//     api.registerSignupField(({ values, set }) => html`<label class="check"><input type="checkbox" checked=${!!values.news} onChange=${(e) => set('news', e.target.checked)} /> Send me news</label>`); // sent with the sign-up request
//     api.registerRoute('/pricing', PricingPage);
//     api.registerMenuItem({ label: 'Support', href: 'https://help.example.com', icon: 'life-buoy', external: true });
//     api.registerMenuItem({ label: 'Chat', onClick: () => openChat(), icon: 'life-buoy' });
//     // `help: true` puts the entry in the Help (?) menu instead of the account menu; `badge` returns a number (it is asked on every page change,
//     // when the window regains focus and every minute) shown as a count beside the entry, with a dot on the Help button when it is above 0:
//     api.registerMenuItem({ label: 'Support', href: '/support', icon: 'life-buoy', help: true, badge: async () => waitingTickets(), badgeLabel: (n) => `${n} waiting on you` });
//     api.registerAdminUi({ href: '/admin' }); // operators get this separate interface; Users and Updates move out of Account settings
//   }
//
// Styling: pages use shadcn-style tokens in app.css (--background, --card, --foreground, --muted, --muted-foreground, --border, --input,
// --ring, --primary, --destructive, --success and the brand teal --brand / --brand-ink / --brand-soft). The older names (--bg, --surface,
// --surface-2, --line, --ink, --ink-2, --ink-3, --accent-ink, --accent-soft, --good, --bad ...) still resolve to the same colors.
// The one exception is --accent: it now means the neutral hover surface, so use --brand where you meant Tide teal.
// ui.js also exports Tabs, Badge, Breadcrumb, Sheet, SettingCard, TypedConfirm, Tooltip, FavTile and table helpers (useSort, SortTh, Pagination).
import * as lib from './lib.js';
import * as ui from './ui.js';
import * as org from './org.js';
import * as chart from './chart.js';

export const registry = { routes: [], accountTabs: [], banners: [], signupFields: [], menuItems: [], adminUi: null, afterCreateOrganization: null };

/** Where to go after someone creates an organization: an extension may choose (for example a page that finishes setting it up), otherwise its settings. */
export const pathAfterOrganizationCreated = (id) => { try { return (registry.afterCreateOrganization && registry.afterCreateOrganization(id)) || '/settings/organization'; } catch (e) { return '/settings/organization'; } };

// ---------------------------------------------------------------------------------------------- menu item badges
const badgeCounts = new Map();
const badgeListeners = new Set();
/** Ask every badge-carrying menu item for its count; a failing one just shows no badge. */
export async function refreshBadges() {
  await Promise.all(registry.menuItems.filter((m) => typeof m.badge === 'function').map(async (m) => {
    let n = 0;
    try { n = Math.max(0, Math.floor(Number(await m.badge()) || 0)); } catch { n = 0; }
    if (badgeCounts.get(m) !== n) { badgeCounts.set(m, n); badgeListeners.forEach((fn) => fn()); }
  }));
}
export const badgeOf = (m) => badgeCounts.get(m) || 0;
export const badgeText = (m, n) => (typeof m.badgeLabel === 'function' ? m.badgeLabel(n) : `${n} new`);
/** Re-render when a count changes; returns whether any Help-menu item has one. */
export function useBadgeCounts() {
  const [, set] = lib.useState(0);
  lib.useEffect(() => { const fn = () => set((n) => n + 1); badgeListeners.add(fn); return () => badgeListeners.delete(fn); }, []);
  return registry.menuItems.some((m) => m.help && badgeOf(m) > 0);
}
/** Keep the counts fresh: on mount, on every page change, when the window regains focus and every minute. Mount it once. */
export function useBadgePolling(path) {
  lib.useEffect(() => { refreshBadges(); }, [path]);
  lib.useEffect(() => {
    const on = () => refreshBadges();
    const t = setInterval(on, 60000);
    window.addEventListener('focus', on);
    return () => { clearInterval(t); window.removeEventListener('focus', on); };
  }, []);
}

const api = {
  version: 1,
  html: lib.html,
  lib, ui, chart,
  /** The organization chosen in the top-bar switcher: `org.useScope(user)` gives { id, org, orgs, shared, personal, owner, set }, `org.getScope()` and `org.setScope(id)` read and change it. */
  org,
  /** A full page: pattern like '/pricing' or '/billing/:id?'; component receives the matched params. */
  registerRoute: (pattern, component) => registry.routes.push([pattern, component]),
  /** A tab in Account settings: { key, label, component, visible?(user), dot?(user) }. */
  registerAccountTab: (tab) => registry.accountTabs.push(tab),
  /** An entry in the account (avatar) menu, above Sign out: { label, href | onClick, icon?, external?, visible?(user), help?, badge?(), badgeLabel?(n) }. Give `href` for a link (`external` opens a new tab) or `onClick` to run a function instead. `icon` is a name in the icon sprite. `help: true` moves it to the Help menu (the sidebar's Resources list shows every entry). `badge` is an async function returning a count: above 0 it is shown beside the entry (and as a dot on the Help button); `badgeLabel(n)` is its text for screen readers (default `${n} new`). */
  registerMenuItem: (item) => registry.menuItems.push(item),
  /** A separate administration interface served at `href` (a full page, not a route of this app): { href, label? }. Administrators get a link to it in the sidebar, and Users and Updates leave Account settings (the other interface can show them). */
  registerAdminUi: (ui) => { registry.adminUi = { label: 'Admin', ...ui }; },
  /** Where to send someone right after they create an organization: `fn(organizationId)` returns a path (default: Settings → Organization). Hosted services use it to finish setting the organization up, such as choosing its plan. */
  registerAfterCreateOrganization: (fn) => { registry.afterCreateOrganization = fn; },
  /** A component shown under the top bar on every signed-in page; receives { user }. */
  registerBanner: (component) => registry.banners.push(component),
  /** A field on the sign-up form, shown above the Create account button: a component that receives { values, set(key, value) }. Whatever it sets is sent with the sign-up request next to the name, email and password, so a listener on the `Registered` event can read it from the request. */
  registerSignupField: (component) => registry.signupFields.push(component),
};

export async function loadExtensions(urls = []) {
  for (const url of urls) {
    try {
      const mod = await import(url);
      if (typeof mod.default === 'function') await mod.default(api);
    } catch (e) {
      console.error('Tidemark extension failed to load:', url, e);
    }
  }
}
