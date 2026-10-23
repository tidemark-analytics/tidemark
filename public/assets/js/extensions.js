// Extension points for packages that add features to the dashboard (for example a hosted service's billing pages).
// A package adds the URL of an ES module to config('tidemark.extensions'). The module's default export is called once, before
// the app renders, with the `api` object below:
//
//   export default function (api) {
//     const { html, lib, ui } = api;
//     api.registerAccountTab({ key: 'billing', label: 'Billing', component: Billing, dot: (user) => user.needs_attention });
//     api.registerBanner(({ user }) => html`<div class="notice">…</div>`);
//     api.registerRoute('/pricing', PricingPage);
//     api.registerMenuItem({ label: 'Support', href: 'https://help.example.com', icon: 'life-buoy', external: true });
//     api.registerMenuItem({ label: 'Chat', onClick: () => openChat(), icon: 'life-buoy' });
//   }
import * as lib from './lib.js';
import * as ui from './ui.js';
import * as chart from './chart.js';

export const registry = { routes: [], accountTabs: [], banners: [], menuItems: [] };

const api = {
  version: 1,
  html: lib.html,
  lib, ui, chart,
  /** A full page: pattern like '/pricing' or '/billing/:id?'; component receives the matched params. */
  registerRoute: (pattern, component) => registry.routes.push([pattern, component]),
  /** A tab in Account settings: { key, label, component, visible?(user), dot?(user) }. */
  registerAccountTab: (tab) => registry.accountTabs.push(tab),
  /** An entry in the account (avatar) menu, above Sign out: { label, href | onClick, icon?, external?, visible?(user) }. Give `href` for a link (`external` opens a new tab) or `onClick` to run a function instead. `icon` is a name in the icon sprite. */
  registerMenuItem: (item) => registry.menuItems.push(item),
  /** A component shown under the top bar on every signed-in page; receives { user }. */
  registerBanner: (component) => registry.banners.push(component),
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
