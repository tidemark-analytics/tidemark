// Pieces shared by the site, alert and account settings screens.
import { html, useEffect, Link } from './lib.js';
import { Breadcrumb } from './ui.js';

export const zones = () => { try { return ['UTC', ...Intl.supportedValuesOf('timeZone')]; } catch (e) { return ['UTC']; } };
export const firstError = (x) => (x.errors && Object.values(x.errors)[0][0]) || x.message;

export function SettingsShell({ title, back, tabs, tab, base, children, scope, dup }) {
  // on phones the section list scrolls sideways: bring the active section into view
  useEffect(() => { const a = document.querySelector('.subnav a.active'); if (a && a.scrollIntoView) a.scrollIntoView({ block: 'nearest', inline: 'center' }); }, [tab]);
  const label = (tabs.find((x) => x[0] === tab) || [])[1];
  return html`<main class="main"><header class="page-head"><div class="grow"><${Breadcrumb} items=${[{ label: scope || back[1], to: back[0] }, { label: 'Settings' }]} /><h1 style="margin-top:8px">${title}</h1></div></header>
    <div class=${'settings' + (dup ? ' dup' : '')}><nav class="subnav" aria-label="Settings sections">${tabs.map(([k, l, dot]) => html`<${Link} to=${base + (k === tabs[0][0] ? '' : '/' + k)} class=${k === tab ? 'active' : ''} aria-current=${k === tab ? 'page' : undefined}>${l}${dot && html`<span class="notif inline" aria-hidden="true"></span><span class="sr-only"> (update available)</span>`}<//>`)}</nav>
      <div class="stack" aria-label=${label}>${children}</div></div></main>`;
}
export const SaveFooter = ({ help, dirty, onSave, busy, label = 'Save' }) => html`<span class="help">${dirty ? 'You have unsaved changes.' : help}</span><button class="btn primary sm" type="button" disabled=${!dirty || busy} onClick=${onSave}>${label}</button>`;
