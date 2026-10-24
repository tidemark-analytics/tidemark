// Site settings (General, Visibility, Branding, Goals, People, Shields) and account settings (Preferences, Security, API keys, Users).
import { html, useState, useEffect, useApi, api, navigate, Link, Icon, toast, copy, toggleTheme, UserCtx, useContext, useDocTitle, ago, countryName, BASE } from './lib.js';
import { Loading, Empty, ErrorBox, Field, Confirm, Modal, Toggle, CodeBlock } from './ui.js';
import { Snippet } from './dashboard.js';
import { registry } from './extensions.js';
import { brandTheme } from './brand.js';
import { importPlausible, removeImported } from './plausible.js';

const zones = () => { try { return ['UTC', ...Intl.supportedValuesOf('timeZone')]; } catch (e) { return ['UTC']; } };
const firstError = (x) => (x.errors && Object.values(x.errors)[0][0]) || x.message;

function SettingsShell({ title, back, tabs, tab, base, children }) {
  // on phones the section list scrolls sideways: bring the active section into view
  useEffect(() => { const a = document.querySelector('.subnav a.active'); if (a && a.scrollIntoView) a.scrollIntoView({ block: 'nearest', inline: 'center' }); }, [tab]);
  return html`<main class="main"><header class="page-head"><div class="grow"><div class="crumb"><${Link} to=${back[0]}><${Icon} name="chevron-left" size=${14} /> ${back[1]}<//></div><h1>${title}</h1></div></header>
    <div class="settings"><nav class="subnav" aria-label="Settings sections">${tabs.map(([k, l, dot]) => html`<${Link} to=${base + (k === tabs[0][0] ? '' : '/' + k)} class=${k === tab ? 'active' : ''} aria-current=${k === tab ? 'page' : undefined}>${l}${dot && html`<span class="notif inline" aria-hidden="true"></span><span class="sr-only"> (update available)</span>`}<//>`)}</nav>
      <div class="stack">${children}</div></div></main>`;
}
const Section = ({ title, help, children, danger }) => html`<section class=${'card pad stack ' + (danger ? 'danger-zone' : '')}><div><h2>${title}</h2>${help && html`<p class="muted" style="margin-top:3px">${help}</p>`}</div>${children}</section>`;

// ----------------------------------------------------------------------------------------- site settings
// Reads an export file in the browser (gzip or plain) and sends it in small batches, so file size never hits a server limit.
async function* exportLines(file) {
  let stream = file.stream();
  if (/\.gz$/i.test(file.name)) {
    if (typeof DecompressionStream === 'undefined') throw new Error('This browser cannot open .gz files. Use a current version of Chrome, Firefox or Safari.');
    stream = stream.pipeThrough(new DecompressionStream('gzip'));
  }
  const reader = stream.pipeThrough(new TextDecoderStream()).getReader();
  let rest = '';
  for (;;) {
    const { done, value } = await reader.read();
    rest += value || '';
    const parts = rest.split('\n');
    rest = done ? '' : parts.pop();
    for (const p of parts) if (p.trim()) yield p;
    if (done) { if (rest.trim()) yield rest; return; }
  }
}

function MoveData({ site, reload }) {
  const [state, setState] = useState(null);
  const run = async (file) => {
    const total = { imported: 0, events: 0, skipped: 0, outdated: 0, invalid: 0 };
    setState({ busy: true, ...total });
    let header = null; let batch = []; let bytes = 0; let first = true;
    const flush = async () => {
      if (!batch.length && !header) return;
      const r = await api(`/sites/${site.domain}/import`, { method: 'POST', body: { ...(first && header ? { header } : {}), visits: batch } });
      first = false; batch = []; bytes = 0;
      for (const k of Object.keys(total)) total[k] += r[k] || 0;
      setState({ busy: true, ...total });
    };
    try {
      let n = 0;
      for await (const line of exportLines(file)) {
        const row = JSON.parse(line);
        if (n++ === 0) { if (row.format !== 'tidemark-export') throw new Error('This is not a Tidemark export file.'); header = row; continue; }
        batch.push(row); bytes += line.length;
        if (batch.length >= 500 || bytes > 1.5e6) await flush();
      }
      if (n === 0) throw new Error('The file is empty.');
      await flush();
      setState({ done: true, ...total });
      reload();
    } catch (x) { setState({ error: firstError(x) || x.message, ...total }); }
  };
  const s = state || {};
  return html`<${Section} title="Move your data" help="Export this site's raw visits and events, or import an export from another Tidemark install, such as moving from self-hosted to Tidemark Cloud.">
    <div class="row wrap"><a class="btn" href=${`${BASE}/api/sites/${site.domain}/export/data`} download>Download export</a>
      <label class=${'btn ' + (s.busy ? 'disabled' : '')}>Import a file<input type="file" accept=".gz,.ndjson,.json" hidden disabled=${s.busy} onChange=${(e) => { const f = e.target.files[0]; e.target.value = ''; if (f) run(f); }} /></label></div>
    ${state && html`<div class=${'notice ' + (s.error ? 'danger' : '')} role="status"><div>${s.error ? html`Import stopped: ${s.error}. ` : s.done ? 'Import finished. ' : 'Importing… keep this tab open. '}${s.imported.toLocaleString()} visits and ${s.events.toLocaleString()} events added${s.skipped ? `, ${s.skipped.toLocaleString()} already here` : ''}${s.outdated ? `, ${s.outdated.toLocaleString()} older than your plan keeps` : ''}${s.invalid ? `, ${s.invalid.toLocaleString()} unreadable` : ''}.</div></div>`}<//>`;
}

function ImportPlausible({ site, reload }) {
  const [state, setState] = useState(null);
  const [confirm, setConfirm] = useState(false);
  const bytes = (n) => (n >= 1e6 ? (n / 1e6).toFixed(0) + ' MB' : Math.round(n / 1e3) + ' KB');
  const run = async (file) => {
    setState({ busy: true, phase: 'Reading the file', done: 0, total: 1 });
    try {
      const r = await importPlausible(file, site.domain, (p) => setState({ busy: true, ...p }));
      setState({ result: r });
      reload();
    } catch (x) { setState({ error: firstError(x) || x.message }); reload(); }
  };
  const s = state || {};
  const imp = site.imported;
  const pct = s.total ? Math.min(100, Math.round((s.done / s.total) * 100)) : 0;
  return html`<${Section} title="Import from Plausible" help="Bring in your history from Plausible. In Plausible, open this site's Settings → Imports & exports, click Prepare download, then upload the .zip file here.">
    ${imp && html`<div class="notice"><${Icon} name="info" size=${16} /><div>Imported from Plausible: <b>${imp.start}</b> to <b>${imp.end}</b>. These days are added to your dashboard when no filter is applied.</div></div>`}
    <div class="row wrap">
      <label class=${'btn ' + (s.busy ? 'disabled' : '')}>${imp ? 'Import again' : 'Choose the .zip file'}<input type="file" accept=".zip,application/zip" hidden disabled=${s.busy} onChange=${(e) => { const f = e.target.files[0]; e.target.value = ''; if (f) run(f); }} /></label>
      ${imp && html`<button class="btn danger" disabled=${s.busy} onClick=${() => setConfirm(true)}>Remove imported data</button>`}</div>
    ${s.busy && html`<div class="stack sm" role="status"><div class="muted">${s.phase}… keep this tab open. ${s.sent ? s.sent.toLocaleString() + ' rows sent' : ''}</div><progress max="100" value=${pct} style="width:100%"></progress></div>`}
    ${s.error && html`<div class="notice danger" role="status"><div>Import stopped: ${s.error}</div></div>`}
    ${s.result && html`<div class="notice" role="status"><${Icon} name="check" size=${16} /><div>${s.result.days ? `Imported ${s.result.days.toLocaleString()} days (${s.result.from} to ${s.result.to}).` : 'Nothing was imported.'}${s.result.skipped ? ` ${s.result.skipped.toLocaleString()} rows were left out because they are older than your plan keeps or overlap days Tidemark already recorded.` : ''}${s.result.customProps ? ' Custom properties are not imported.' : ''}</div></div>`}
    <p class="muted small">Imported numbers are daily totals, so they appear in the graph, top stats and reports but not when you filter, and visitors are added up day by day. Importing again replaces the earlier import.</p>
    ${confirm && html`<${Confirm} title="Remove imported data?" message="The days imported from Plausible will be removed from this dashboard. What Tidemark collected itself is not touched." confirmLabel="Remove" onClose=${() => setConfirm(false)} onConfirm=${async () => { await removeImported(site.domain); setState(null); reload(); toast('Imported data removed'); }} />`}<//>`;
}

/** How visits get in: the snippet, its extensions and the rules that drop traffic you don't want counted. */
function Tracking({ site, reload }) {
  const patch = async (body) => { try { await api(`/sites/${site.domain}`, { method: 'PATCH', body }); reload(); toast('Saved'); } catch (x) { toast(firstError(x), 'err'); } };
  return html`<${Section} title="Tracking snippet" help="Place this in the <head> of every page."><${Snippet} site=${site} /><//>
    <${Section} title="Tracking extras" help="Each one adds a small extension to the script and a matching goal.">
      <${Toggle} label="Outbound link clicks" checked=${site.track_outbound} onChange=${(v) => patch({ track_outbound: v })} />
      <${Toggle} label="File downloads" checked=${site.track_downloads} onChange=${(v) => patch({ track_downloads: v })} />
      <${Toggle} label="Form submissions" checked=${site.track_forms} onChange=${(v) => patch({ track_forms: v })} /><//>
    <${Shields} site=${site} />`;
}

/** What the dashboard counts as a conversion. */
const Conversions = ({ site }) => html`<${Goals} site=${site} /><${Funnels} site=${site} /><${EventDocs} site=${site} />`;

/** Who can see the site, and how it looks to them. */
const Access = ({ site, reload }) => html`${!site.rollup && html`<${People} site=${site} />`}<${Visibility} site=${site} reload=${reload} /><${Branding} site=${site} reload=${reload} />`;

function DataTab({ site, reload }) {
  const [tz, setTz] = useState(site.timezone);
  const [confirm, setConfirm] = useState(null);
  const patch = async (body) => { try { await api(`/sites/${site.domain}`, { method: 'PATCH', body }); reload(); toast('Saved'); } catch (x) { toast(firstError(x), 'err'); } };
 if (site.rollup) return html`<${Section} title="Reporting timezone" help="Days start and end at midnight in this timezone. It starts as the timezone most of the rollup's sites use."><div class="row"><select value=${tz} onChange=${(e) => setTz(e.target.value)} style="max-width:320px">${zones().map((z) => html`<option value=${z} selected=${z === tz}>${z}</option>`)}</select><button class="btn" disabled=${tz === site.timezone} onClick=${() => patch({ timezone: tz })}>Save</button></div><//>
    ${site.is_owner && html`<${Section} danger title="Danger zone"><div class="row spread wrap"><div><b>Delete rollup</b><div class="muted">Removes this dashboard and its goals. Your sites and their data are not touched.</div></div><button class="btn danger" onClick=${() => setConfirm('delete')}>Delete rollup</button></div><//>`}
    ${confirm === 'delete' && html`<${Confirm} title=${`Delete ${site.domain}?`} message="The rollup and its goals will be deleted. The sites it adds up are not affected." onClose=${() => setConfirm(null)} onConfirm=${async () => { await api(`/sites/${site.domain}`, { method: 'DELETE' }); navigate('/sites'); }} />`}`;
  return html`<${Section} title="Reporting timezone" help="Days start and end at midnight in this timezone."><div class="row"><select value=${tz} onChange=${(e) => setTz(e.target.value)} style="max-width:320px">${zones().map((z) => html`<option value=${z} selected=${z === tz}>${z}</option>`)}</select><button class="btn" disabled=${tz === site.timezone} onClick=${() => patch({ timezone: tz })}>Save</button></div><//>
    ${site.can_manage && html`<${MoveData} site=${site} reload=${reload} />`}
    ${site.can_manage && html`<${ImportPlausible} site=${site} reload=${reload} />`}
    ${site.is_owner && html`<${Section} danger title="Danger zone"><div class="row spread wrap"><div><b>Reset statistics</b><div class="muted">Deletes all collected data but keeps the site.</div></div><button class="btn danger" onClick=${() => setConfirm('reset')}>Reset</button></div>
      <hr /><div class="row spread wrap"><div><b>Delete site</b><div class="muted">Removes the site and all its data permanently.</div></div><button class="btn danger" onClick=${() => setConfirm('delete')}>Delete site</button></div><//>`}
    ${confirm === 'reset' && html`<${Confirm} title="Reset statistics?" message=${`All collected data for ${site.domain} will be deleted. This can't be undone.`} confirmLabel="Reset" onClose=${() => setConfirm(null)} onConfirm=${async () => { await api(`/sites/${site.domain}/reset`, { method: 'POST' }); toast('Statistics reset'); reload(); }} />`}
    ${confirm === 'delete' && html`<${Confirm} title=${`Delete ${site.domain}?`} message="The site and all of its data will be permanently deleted." onClose=${() => setConfirm(null)} onConfirm=${async () => { await api(`/sites/${site.domain}`, { method: 'DELETE' }); navigate('/sites'); }} />`}`;
}

function Visibility({ site, reload }) {
  const links = useApi(`/sites/${site.domain}/shared-links`, null, []);
  const [adding, setAdding] = useState(false);
  const [del, setDel] = useState(null);
  const setPublic = async (v) => { await api(`/sites/${site.domain}`, { method: 'PATCH', body: { public: v } }); reload(); };
  const url = (l) => `${window.SD.origin}/share/${site.domain}?auth=${l.slug}`;
  return html`<${Section} title="Public dashboard" help="Anyone with the address can view this site's stats — no sign-in needed."><${Toggle} label="Make the dashboard public" checked=${site.public} onChange=${setPublic} />
      ${site.public && html`<${CodeBlock} text=${`${window.SD.origin}/${site.domain}`} />`}<//>
    <${Section} title="Shared links" help="Private, read-only links you can revoke at any time. Add a password if you like.">
      ${links.data && links.data.data.length === 0 && html`<p class="muted">No shared links yet.</p>`}
      ${(links.data ? links.data.data : []).map((l) => html`<div class="row spread wrap linkrow"><div class="grow"><b>${l.name}</b> ${l.password_protected && html`<span class="pill warn"><${Icon} name="lock" size=${11} /> Password</span>`}<div class="mono muted trunc">${url(l)}</div></div>
        <div class="row"><button class="btn sm" onClick=${() => copy(url(l))}><${Icon} name="copy" size=${13} /> Copy</button><button class="btn sm danger" onClick=${() => setDel(l)}>Delete</button></div></div>`)}
      <div><button class="btn" onClick=${() => setAdding(true)}><${Icon} name="plus" size=${14} /> New shared link</button></div><//>
    ${adding && html`<${NewLink} site=${site} onClose=${() => setAdding(false)} onDone=${() => { setAdding(false); links.reload(); }} />`}
    ${del && html`<${Confirm} title="Delete shared link?" message=${`Anyone using "${del.name}" will lose access immediately.`} onClose=${() => setDel(null)} onConfirm=${async () => { await api(`/sites/${site.domain}/shared-links/${del.id}`, { method: 'DELETE' }); links.reload(); }} />`}`;
}

function Branding({ site, reload }) {
  const b = site.branding || { allowed: true, name: '', color: null, has_logo: false };
  const [name, setName] = useState(b.name || '');
  const [color, setColor] = useState(b.color || '');
  const [busy, setBusy] = useState(false);
  const valid = color === '' || /^#[0-9a-fA-F]{6}$/.test(color);
  const changed = name !== (b.name || '') || color !== (b.color || '');
  const shown = (() => { if (!valid || !color) return { changed: false }; const l = brandTheme(color, false); const d = brandTheme(color, true); return { changed: l.changed || d.changed, light: l.accent, dark: d.accent }; })();
  const run = async (fn, ok) => { setBusy(true); try { await fn(); reload(); toast(ok); } catch (x) { toast(firstError(x), 'err'); } setBusy(false); };
  const save = () => run(() => api(`/sites/${site.domain}/branding`, { method: 'PATCH', body: { brand_name: name.trim(), brand_color: color } }), 'Saved');
  const upload = (e) => {
    const f = e.target.files && e.target.files[0];
    e.target.value = '';
    if (!f) return;
    const body = new FormData();
    body.append('logo', f);
    run(() => api(`/sites/${site.domain}/branding/logo`, { method: 'POST', body }), 'Logo updated');
  };
  return html`<${Section} title="Branding" help="Show your own logo, name and color to people who open the public dashboard or a shared link. A small “Analytics by Tidemark” link always stays in the footer.">
      ${!b.allowed && html`<div class="notice"><${Icon} name="info" size=${16} /><div>Custom branding is included with Pro. <${Link} to="/settings">Open your account settings<//> to upgrade.</div></div>`}
      <div class="form">
        <${Field} label="Display name" help="Shown in the header next to (or instead of) the logo. Leave empty to keep the Tidemark logo."><input value=${name} maxlength="60" disabled=${!b.allowed} onInput=${(e) => setName(e.target.value)} placeholder=${site.domain} /><//>
        <${Field} label="Brand color" help="Used for buttons, links and the chart."><div class="row">
          <input type="color" value=${valid && color ? color : '#0e7c86'} disabled=${!b.allowed} onInput=${(e) => setColor(e.target.value)} style="width:48px;padding:2px;height:36px" aria-label="Pick a color" />
          <input value=${color} disabled=${!b.allowed} onInput=${(e) => setColor(e.target.value.trim())} placeholder="#0e7c86" style="max-width:140px" class="mono" aria-label="Color hex code" />
          ${color && html`<button class="btn sm ghost" disabled=${!b.allowed} onClick=${() => setColor('')}>Reset</button>`}</div>
          ${!valid && html`<span class="help" style="color:var(--bad)">Use six hex digits, like #0e7c86.</span>`}
          ${valid && color && shown.changed && html`<span class="help">So text stays readable (WCAG AA contrast), visitors see this as <b class="mono">${shown.light}</b> on light pages and <b class="mono">${shown.dark}</b> on dark ones, the closest shades that pass.</span>`}<//>
        <div><button class="btn primary" disabled=${!b.allowed || busy || !changed || !valid} onClick=${save}>Save</button></div>
      </div><//>
    <${Section} title="Logo" help="PNG, JPEG, WebP or SVG, up to 100 KB. A wide logo about 28 pixels tall works best.">
      ${site.brand && site.brand.logo && html`<div class="logo-preview"><img src=${site.brand.logo} alt="Current logo" /></div>`}
      <div class="row">
        <label class=${'btn' + (!b.allowed || busy ? ' disabled' : '')}>${b.has_logo ? 'Replace logo' : 'Upload logo'}<input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden disabled=${!b.allowed || busy} onChange=${upload} /></label>
        ${b.has_logo && html`<button class="btn danger" disabled=${!b.allowed || busy} onClick=${() => run(() => api(`/sites/${site.domain}/branding/logo`, { method: 'DELETE' }), 'Logo removed')}>Remove</button>`}</div>
      ${!site.public && html`<p class="muted small">Branding shows on the public dashboard and shared links. This site's dashboard is private, so only shared links use it. <${Link} to=${`/${site.domain}/settings/visibility`}>Visibility settings<//></p>`}<//>`;
}

function NewLink({ site, onClose, onDone }) {
  const [f, setF] = useState({ name: '', password: '' });
  const [err, setErr] = useState(null);
  const save = async () => { try { await api(`/sites/${site.domain}/shared-links`, { method: 'POST', body: { name: f.name, password: f.password || null } }); onDone(); } catch (x) { setErr(firstError(x)); } };
  return html`<${Modal} title="New shared link" onClose=${onClose} footer=${html`<button class="btn" onClick=${onClose}>Cancel</button><button class="btn primary" disabled=${!f.name} onClick=${save}>Create link</button>`}>
    <div class="form">${err && html`<div class="alert">${err}</div>`}<${Field} label="Name" help="Only you will see this."><input autofocus value=${f.name} onInput=${(e) => setF({ ...f, name: e.target.value })} placeholder="Marketing team" /><//>
    <${Field} label="Password (optional)"><input type="password" autocomplete="new-password" value=${f.password} onInput=${(e) => setF({ ...f, password: e.target.value })} /><//></div><//>`;
}

const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'JPY', 'CNY', 'HKD', 'SGD', 'INR', 'BRL', 'MXN', 'ZAR', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK'];
const CurrencySelect = ({ value, onChange, disabled, label = 'Revenue currency', compact }) => html`<select class=${compact ? 'mini-select' : ''} value=${value || ''} onChange=${(e) => onChange(e.target.value || null)} disabled=${disabled} aria-label=${label} style="width:auto"><option value="" selected=${!value}>No revenue</option>${CURRENCIES.map((c) => html`<option value=${c} selected=${c === value}>${c}</option>`)}</select>`;

function Goals({ site }) {
  const res = useApi(`/sites/${site.domain}/goals`, null, []);
  const [type, setType] = useState('event');
  const [value, setValue] = useState('');
  const [currency, setCurrency] = useState(null);
  const [err, setErr] = useState(null);
  const revenue = (site.features || {}).revenue !== false;
  const goals = (res.data && res.data.data) || [];
  const add = async (e) => {
    e.preventDefault(); setErr(null);
    try { await api(`/sites/${site.domain}/goals`, { method: 'POST', body: { type, value, currency: type === 'event' ? currency : null } }); setValue(''); setCurrency(null); res.reload(); } catch (x) { setErr(firstError(x)); }
  };
  const setGoalCurrency = async (g, c) => { setErr(null); try { await api(`/sites/${site.domain}/goals/${g.id}`, { method: 'PATCH', body: { currency: c } }); res.reload(); } catch (x) { setErr(firstError(x)); } };
  const remove = async (g) => { setErr(null); try { await api(`/sites/${site.domain}/goals/${g.id}`, { method: 'DELETE' }); res.reload(); } catch (x) { setErr(firstError(x)); } };
  return html`<${Section} title="Goals" help="A goal counts how many visitors did something: triggered a custom event, or visited a page.">
      <form class="goal-add" onSubmit=${add}>
        <div class="seg" role="radiogroup" aria-label="Goal type">${[['event', 'Custom event', 'mouse-pointer-click'], ['page', 'Page visit', 'eye']].map(([k, l, i]) => html`<button type="button" role="radio" aria-checked=${type === k} class=${type === k ? 'on' : ''} onClick=${() => setType(k)}><${Icon} name=${i} size=${14} />${l}</button>`)}</div>
        <div class="goal-add-row">
          <input class="grow" required placeholder=${type === 'event' ? 'Event name, e.g. Purchase' : 'Page path, e.g. /thanks or /blog/*'} value=${value} onInput=${(e) => setValue(e.target.value)} aria-label="Goal value" />
          ${type === 'event' && html`<${CurrencySelect} value=${currency} onChange=${setCurrency} disabled=${!revenue} />`}
          <button class="btn primary">Add goal</button>
        </div>
      </form>
      ${!revenue && html`<div class="notice"><${Icon} name="info" size=${16} /><div>Revenue reporting is included with Pro. <${Link} to="/settings">Open your account settings<//> to upgrade.</div></div>`}
      ${err && html`<div class="alert">${err}</div>`}
      ${res.data && goals.length === 0 && html`<div class="empty-inline"><${Icon} name="target" size=${18} /><div><b>No goals yet</b><span>Add the first one above. Outbound links, downloads and forms add their own when you turn them on under Tracking.</span></div></div>`}
      ${goals.length > 0 && html`<ul class="rows" aria-label="Goals">${goals.map((g) => html`<li class="rowitem">
        <span class="ri-ico" aria-hidden="true"><${Icon} name=${g.type === 'event' ? 'mouse-pointer-click' : 'eye'} size=${16} /></span>
        <div class="grow ri-main"><b class="trunc">${g.name}</b><span class="muted">${g.type === 'event' ? 'Custom event' : 'Page visit'}</span></div>
        ${g.type === 'event' && html`<${CurrencySelect} value=${g.currency} onChange=${(c) => setGoalCurrency(g, c)} disabled=${!revenue && !g.currency} label=${'Revenue currency for ' + g.name} compact />`}
        <button class="btn ghost icon sm danger" aria-label=${'Delete goal ' + g.name} title="Delete goal" onClick=${() => remove(g)}><${Icon} name="trash-2" size=${15} /></button></li>`)}</ul>`}
      ${site.rollup && html`<p class="muted small">Goals in a rollup match events and pages by name in every site it adds up. A goal with a currency adds up only the amounts the sites sent in that currency.</p>`}
    <//>`;
}

/** How to send events from your own code; sits under the goals it feeds. */
const EventDocs = ({ site }) => (site.rollup ? null : html`<${Section} title="Sending events from your code" help="Goals for custom events count when your site calls the script. Revenue goals also take an amount.">
    <div class="ref"><b>Record a conversion</b><${CodeBlock} text="window.plausible('Signup', { props: { plan: 'pro' } })" />
      <p class="muted small">Or tag an element without code: add <code>class="plausible-event-name=Signup"</code>, with the <code>tagged-events</code> script variant.</p></div>
    <div class="ref"><b>Report revenue</b><${CodeBlock} text="window.plausible('Purchase', { revenue: { amount: 29.99, currency: 'USD' } })" />
      <p class="muted small">Choose a currency for the goal above, then send an amount with the event; totals and averages appear when you filter by that goal. With the tag method, add <code>plausible-revenue-amount=29.99</code> and <code>plausible-revenue-currency=USD</code> to the class. Amounts in another currency are converted with the European Central Bank's daily rates.</p></div><//>`);

function Funnels({ site }) {
  const goals = useApi(`/sites/${site.domain}/goals`, null, []);
  const res = useApi(`/sites/${site.domain}/funnels`, null, []);
  const allowed = (site.features || {}).funnels !== false;
  const [form, setForm] = useState(null);
  const [err, setErr] = useState(null);
  const list = (goals.data && goals.data.data) || [];
  const funnels = (res.data && res.data.data) || [];
  const goalName = (id) => (list.find((g) => g.id === id) || { name: '(deleted goal)' }).name;
  const open = (f) => { setErr(null); setForm(f ? { id: f.id, name: f.name, steps: f.steps.map((s) => s.goal_id) } : { name: '', steps: ['', ''] }); };
  const save = async (e) => {
    e.preventDefault(); setErr(null);
    try {
      await api(`/sites/${site.domain}/funnels${form.id ? '/' + form.id : ''}`, { method: form.id ? 'PUT' : 'POST', body: { name: form.name, steps: form.steps } });
      setForm(null); res.reload(); toast('Funnel saved');
    } catch (x) { setErr(firstError(x)); }
  };
  const setStep = (i, v) => setForm({ ...form, steps: form.steps.map((s, j) => (j === i ? v : s)) });
  const needGoals = allowed && goals.data && list.length < 2;
  return html`<${Section} title="Funnels" help="A funnel follows visitors through a series of goals, in order, and shows where they drop off. Chain two to eight goals.">
      ${!form && html`<div class="section-actions"><button class="btn primary" disabled=${!allowed || list.length < 2} onClick=${() => open(null)}><${Icon} name="plus" size=${14} /> New funnel</button>${needGoals && html`<span class="muted small">Add at least two goals first.</span>`}</div>`}
      ${!allowed && html`<div class="notice"><${Icon} name="info" size=${16} /><div>Funnels are included with Pro. <${Link} to="/settings">Open your account settings<//> to upgrade.</div></div>`}
      ${err && !form && html`<div class="alert">${err}</div>`}
      ${form && html`<form class="funnel-form" onSubmit=${save}>
        ${err && html`<div class="alert">${err}</div>`}
        <${Field} label="Name"><input required maxlength="100" autofocus value=${form.name} onInput=${(e) => setForm({ ...form, name: e.target.value })} placeholder="Signup to purchase" /><//>
        <div class="steps-edit"><span class="lbl">Steps</span>
          ${form.steps.map((id, i) => html`<div class="step-row"><span class="step-n" aria-hidden="true">${i + 1}</span>
            <select required class="grow" value=${id} onChange=${(e) => setStep(i, e.target.value)} aria-label=${'Step ' + (i + 1)}><option value="">Choose a goal</option>${list.map((g) => html`<option value=${g.id} selected=${g.id === id}>${g.name}</option>`)}</select>
            <button type="button" class="btn ghost icon sm" disabled=${form.steps.length <= 2} aria-label=${'Remove step ' + (i + 1)} title="Remove step" onClick=${() => setForm({ ...form, steps: form.steps.filter((_, j) => j !== i) })}><${Icon} name="x" size=${15} /></button></div>`)}
          <div class="row spread wrap"><button type="button" class="btn sm" disabled=${form.steps.length >= 8} onClick=${() => setForm({ ...form, steps: [...form.steps, ''] })}><${Icon} name="plus" size=${13} /> Add step</button>
            <span class="muted small">A step counts when its goal happens after the one before; other activity in between is fine.</span></div></div>
        <div class="row"><button class="btn primary" disabled=${!form.name || form.steps.some((x) => !x)}>Save funnel</button><button type="button" class="btn" onClick=${() => setForm(null)}>Cancel</button></div></form>`}
      ${res.data && funnels.length === 0 && !form && html`<div class="empty-inline"><${Icon} name="funnel" size=${18} /><div><b>No funnels yet</b><span>Pick goals in the order visitors reach them, such as a pricing visit, a signup and a purchase, to see where they drop off.</span></div></div>`}
      ${funnels.length > 0 && html`<ul class="rows" aria-label="Funnels">${funnels.map((f) => html`<li class="rowitem funnel-item">
        <div class="grow ri-main"><b class="trunc">${f.name}</b>
          <ol class="chain">${f.steps.map((st, i) => html`<li>${i > 0 && html`<${Icon} name="chevron-right" size=${13} class="sep" />`}<span class="step-chip"><i>${i + 1}</i>${goalName(st.goal_id)}</span></li>`)}</ol></div>
        <button class="btn ghost icon sm" disabled=${!allowed} aria-label=${'Edit funnel ' + f.name} title="Edit funnel" onClick=${() => open(f)}><${Icon} name="pencil" size=${15} /></button>
        <button class="btn ghost icon sm danger" aria-label=${'Delete funnel ' + f.name} title="Delete funnel" onClick=${async () => { await api(`/sites/${site.domain}/funnels/${f.id}`, { method: 'DELETE' }); res.reload(); }}><${Icon} name="trash-2" size=${15} /></button></li>`)}</ul>`}
    <//>`;
}

/** Which of the owner's sites a rollup adds up. */
function RollupSites({ site, reload }) {
  const cands = useApi('/rollups/candidates', null, []);
  const [all, setAll] = useState(site.rollup.all);
  const [chosen, setChosen] = useState(site.rollup.sites.map((s) => s.domain));
  const [err, setErr] = useState(null);
  const list = (cands.data && cands.data.data) || [];
  const dirty = all !== site.rollup.all || (!all && JSON.stringify([...chosen].sort()) !== JSON.stringify(site.rollup.sites.map((s) => s.domain).sort()));
  const save = async () => { setErr(null); try { await api(`/sites/${site.domain}/rollup`, { method: 'PUT', body: { all, sites: all ? undefined : chosen } }); reload(); toast('Saved'); } catch (x) { setErr(firstError(x)); } };
  return html`<${Section} title="Sites in this rollup" help="Changing the list changes the whole history: a site you take out disappears from this dashboard's past as well as its future, and the other way round. Your sites themselves are never changed.">
    ${err && html`<div class="alert">${err}</div>`}
    <div class="stack sm"><label class="row"><input type="radio" name="scope" checked=${all} onChange=${() => setAll(true)} /> <span><b>All the sites I own</b>, including ones I add later</span></label>
      <label class="row"><input type="radio" name="scope" checked=${!all} onChange=${() => setAll(false)} /> <span>Only the sites I choose</span></label></div>
    ${!all && html`<div class="stack sm" role="group" aria-label="Sites in this rollup">${list.map((s) => html`<label class="row"><input type="checkbox" checked=${chosen.includes(s.domain)} onChange=${(e) => setChosen(e.target.checked ? [...chosen, s.domain] : chosen.filter((d) => d !== s.domain))} /> <span>${s.domain}</span></label>`)}</div>`}
    ${all && html`<p class="muted small">Right now that is ${site.rollup.count} ${site.rollup.count === 1 ? 'site' : 'sites'}.</p>`}
    <div><button class="btn primary" disabled=${!dirty || (!all && !chosen.length)} onClick=${save}>Save</button></div><//>`;
}

function People({ site }) {
  const res = useApi(`/sites/${site.domain}/people`, null, []);
  const [f, setF] = useState({ email: '', role: 'viewer' });
  const [err, setErr] = useState(null);
  const invite = async (e) => { e.preventDefault(); setErr(null); try { await api(`/sites/${site.domain}/people`, { method: 'POST', body: f }); setF({ ...f, email: '' }); res.reload(); toast('Added'); } catch (x) { setErr(firstError(x)); } };
  return html`<${Section} title="People" help="Add existing users of this Tidemark install. Viewers can see stats; admins can change settings.">
    <form class="row wrap" onSubmit=${invite}><input class="grow" type="email" required placeholder="Email address" aria-label="Email" value=${f.email} onInput=${(e) => setF({ ...f, email: e.target.value })} /><select value=${f.role} onChange=${(e) => setF({ ...f, role: e.target.value })} aria-label="Role" style="width:auto"><option value="viewer">Viewer</option><option value="admin">Admin</option></select><button class="btn primary">Add</button></form>
    ${err && html`<div class="alert">${err}</div>`}
    ${res.data && html`<div class="tscroll"><table class="t"><thead><tr><th>Person</th><th>Role</th><th></th></tr></thead><tbody>${res.data.data.map((u) => html`<tr><td><b>${u.name}</b><div class="muted">${u.email}</div></td>
      <td>${u.role === 'owner' ? html`<span class="pill brand">Owner</span>` : html`<select value=${u.role} aria-label="Role" style="width:auto" onChange=${async (e) => { await api(`/sites/${site.domain}/people/${u.id}`, { method: 'PATCH', body: { role: e.target.value } }); res.reload(); }}><option value="viewer" selected=${u.role === 'viewer'}>Viewer</option><option value="admin" selected=${u.role === 'admin'}>Admin</option></select>`}</td>
      <td class="right">${u.role !== 'owner' && html`<button class="btn sm ghost danger" onClick=${async () => { await api(`/sites/${site.domain}/people/${u.id}`, { method: 'DELETE' }); res.reload(); }}>Remove</button>`}</td></tr>`)}</tbody></table></div>`}<//>`;
}

const SHIELDS = [['ip', 'IP address', '203.0.113.7 or 203.0.113.0/24'], ['country', 'Country', 'Two-letter code, e.g. DE'], ['page', 'Page', '/admin/* '], ['hostname', 'Hostname', 'example.com — only count these hostnames']];
function Shields({ site }) {
  const res = useApi(`/sites/${site.domain}/shields`, null, []);
  const [type, setType] = useState('ip');
  const [value, setValue] = useState('');
  const [err, setErr] = useState(null);
  const add = async (e) => { e.preventDefault(); setErr(null); try { await api(`/sites/${site.domain}/shields`, { method: 'POST', body: { type, value } }); setValue(''); res.reload(); } catch (x) { setErr(firstError(x)); } };
  const rows = res.data ? res.data.data : [];
  return html`<${Section} title="Shields" help="Block traffic you don't want counted: your own IP, a country, a path — or only allow specific hostnames. Hits that match are dropped before they're stored.">
    <form class="row wrap" onSubmit=${add}><select value=${type} onChange=${(e) => setType(e.target.value)} aria-label="Type" style="width:auto">${SHIELDS.map(([k, l]) => html`<option value=${k}>${l}</option>`)}</select>
      <input class="grow" required placeholder=${SHIELDS.find((s) => s[0] === type)[2]} value=${value} onInput=${(e) => setValue(e.target.value)} aria-label="Value" /><button class="btn primary">Add rule</button></form>
    ${err && html`<div class="alert">${err}</div>`}
    ${res.data && !rows.length && html`<p class="muted">No rules yet.</p>`}
    ${rows.length > 0 && html`<div class="tscroll"><table class="t"><thead><tr><th>Type</th><th>Value</th><th></th></tr></thead><tbody>${rows.map((r) => html`<tr><td>${(SHIELDS.find((s) => s[0] === r.type) || [0, r.type])[1]}</td><td class="mono">${r.type === 'country' ? `${r.value} · ${countryName(r.value)}` : r.value}</td>
      <td class="right"><button class="btn sm ghost danger" onClick=${async () => { await api(`/sites/${site.domain}/shields/${r.id}`, { method: 'DELETE' }); res.reload(); }}>Delete</button></td></tr>`)}</tbody></table></div>`}<//>`;
}

export function SiteSettings({ domain, tab }) {
  useDocTitle(domain + ' settings');
  const res = useApi(`/sites/${domain}`, null, [domain]);
  const s = res.data && res.data.data;
  if (res.error) return html`<main class="main"><${ErrorBox} error=${res.error} /></main>`;
  if (!s) return html`<main class="main"><${Loading} /></main>`;
  if (!s.can_manage) return html`<main class="main"><${Empty} title="No access" icon="lock">Only site owners and admins can change settings.<//></main>`;
  // a few sections moved between tabs; older links and bookmarks still land in the right place
  const tabs = s.rollup ? [['sites', 'Sites'], ['conversions', 'Conversions'], ['access', 'Sharing'], ['data', 'Data']] : [['tracking', 'Tracking'], ['conversions', 'Conversions'], ['access', 'Sharing and access'], ['data', 'Data']];
  const moved = { general: s.rollup ? 'data' : 'tracking', shields: 'tracking', goals: 'conversions', funnels: 'conversions', visibility: 'access', branding: 'access', people: 'access' };
  const t = [tab, moved[tab]].find((k) => tabs.some((x) => x[0] === k)) || tabs[0][0];
  const Body = { tracking: Tracking, conversions: Conversions, access: Access, data: DataTab, sites: RollupSites }[t];
  return html`<${SettingsShell} title=${`${domain} settings`} back=${[`/${domain}`, 'Dashboard']} tabs=${tabs} tab=${t} base=${`/${domain}/settings`}><${Body} site=${s} reload=${res.reload} /><//>`;
}

// -------------------------------------------------------------------------------------- account settings
function Preferences() {
  const { user, setUser } = useContext(UserCtx);
  const [f, setF] = useState({ name: user.name, email: user.email, timezone: user.timezone || 'UTC' });
  const [err, setErr] = useState(null);
  const save = async (e) => { e.preventDefault(); setErr(null); try { const r = await api('/me', { method: 'POST', body: f }); setUser({ ...user, ...r.user }); toast('Saved'); } catch (x) { setErr(firstError(x)); } };
  return html`<${Section} title="Profile"><form class="form" onSubmit=${save}>${err && html`<div class="alert">${err}</div>`}
      <${Field} label="Name"><input required value=${f.name} onInput=${(e) => setF({ ...f, name: e.target.value })} /><//>
      <${Field} label="Email"><input type="email" required value=${f.email} onInput=${(e) => setF({ ...f, email: e.target.value })} /><//>
      <${Field} label="Timezone"><select value=${f.timezone} onChange=${(e) => setF({ ...f, timezone: e.target.value })}>${zones().map((z) => html`<option value=${z} selected=${z === f.timezone}>${z}</option>`)}</select><//>
      <div><button class="btn primary">Save changes</button></div></form><//>
    <${Section} title="Appearance" help="Follows your system by default."><div><button class="btn" onClick=${toggleTheme}><${Icon} name="sun" size=${14} /> Switch light / dark</button></div><//>`;
}

function Security() {
  const { user, setUser } = useContext(UserCtx);
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [err, setErr] = useState(null);
  const [setup, setSetup] = useState(null);
  const [code, setCode] = useState('');
  const [disable, setDisable] = useState(false);
  const [dpw, setDpw] = useState('');
  const changePw = async (e) => { e.preventDefault(); setErr(null); try { await api('/me/password', { method: 'POST', body: pw }); setPw({ current_password: '', password: '', password_confirmation: '' }); toast('Password updated'); } catch (x) { setErr(firstError(x)); } };
  const refresh = async () => setUser((await api('/me')).user);
  return html`<${Section} title="Password"><form class="form" onSubmit=${changePw}>${err && html`<div class="alert">${err}</div>`}
      <${Field} label="Current password"><input type="password" required autocomplete="current-password" value=${pw.current_password} onInput=${(e) => setPw({ ...pw, current_password: e.target.value })} /><//>
      <${Field} label="New password" help="At least 8 characters."><input type="password" required minlength="8" autocomplete="new-password" value=${pw.password} onInput=${(e) => setPw({ ...pw, password: e.target.value })} /><//>
      <${Field} label="Confirm new password"><input type="password" required autocomplete="new-password" value=${pw.password_confirmation} onInput=${(e) => setPw({ ...pw, password_confirmation: e.target.value })} /><//>
      <div><button class="btn primary">Update password</button></div></form><//>
    <${Section} title="Two-factor authentication" help="Require a code from an authenticator app when signing in.">
      ${user.two_factor ? html`<div class="row spread wrap"><span class="pill good"><${Icon} name="shield-check" size=${12} /> Enabled</span><button class="btn danger" onClick=${() => setDisable(true)}>Disable</button></div>`
        : setup ? html`<div class="form"><p>Add this secret to your authenticator app, then enter the 6-digit code it shows.</p><${CodeBlock} text=${setup.secret} /><a class="muted" href=${setup.uri}>Open in authenticator app</a>
          <${Field} label="Code"><input inputmode="numeric" autocomplete="one-time-code" value=${code} onInput=${(e) => setCode(e.target.value)} /><//>
          <div><button class="btn primary" onClick=${async () => { try { await api('/me/two-factor/confirm', { method: 'POST', body: { code } }); setSetup(null); await refresh(); toast('Two-factor enabled'); } catch (x) { toast(firstError(x), 'err'); } }}>Confirm and enable</button></div></div>`
        : html`<div><button class="btn" onClick=${async () => setSetup(await api('/me/two-factor', { method: 'POST' }))}>Set up two-factor</button></div>`}<//>
    ${disable && html`<${Modal} title="Disable two-factor" onClose=${() => setDisable(false)} footer=${html`<button class="btn danger" onClick=${async () => { try { await api('/me/two-factor', { method: 'DELETE', body: { password: dpw } }); setDisable(false); await refresh(); } catch (x) { toast(firstError(x), 'err'); } }}>Disable</button>`}>
      <${Field} label="Confirm your password"><input type="password" autofocus value=${dpw} onInput=${(e) => setDpw(e.target.value)} /><//><//>`}`;
}

function ApiKeys() {
  const res = useApi('/me/keys', null, []);
  const [name, setName] = useState('');
  const [token, setToken] = useState(null);
  const create = async (e) => { e.preventDefault(); const r = await api('/me/keys', { method: 'POST', body: { name } }); setToken(r.token); setName(''); res.reload(); };
  return html`<${Section} title="API keys" help="Use a key as a Bearer token with the Stats API (/api/v1/stats/…). Keys have the same access as you."><form class="row wrap" onSubmit=${create}><input class="grow" required placeholder="Key name, e.g. Reporting script" aria-label="Key name" value=${name} onInput=${(e) => setName(e.target.value)} /><button class="btn primary">Create key</button></form>
    ${token && html`<div class="notice"><${Icon} name="key" size=${16} /><div><b>Copy your key now — it won't be shown again.</b><${CodeBlock} text=${token} /></div></div>`}
    ${res.data && !res.data.data.length && html`<p class="muted">No keys yet.</p>`}
    ${res.data && res.data.data.length > 0 && html`<div class="tscroll"><table class="t"><thead><tr><th>Name</th><th>Last used</th><th></th></tr></thead><tbody>${res.data.data.map((k) => html`<tr><td>${k.name}</td><td class="muted">${k.last_used_at ? ago(k.last_used_at.replace('T', ' ').slice(0, 19)) : 'Never'}</td><td class="right"><button class="btn sm ghost danger" onClick=${async () => { await api('/me/keys/' + k.id, { method: 'DELETE' }); res.reload(); }}>Revoke</button></td></tr>`)}</tbody></table></div>`}<//>`;
}

function Users() {
  const { user } = useContext(UserCtx);
  const res = useApi('/admin/users', null, []);
  const [f, setF] = useState({ name: '', email: '', password: '', is_admin: false });
  const [err, setErr] = useState(null);
  const [del, setDel] = useState(null);
  const add = async (e) => { e.preventDefault(); setErr(null); try { await api('/admin/users', { method: 'POST', body: f }); setF({ name: '', email: '', password: '', is_admin: false }); res.reload(); toast('User created'); } catch (x) { setErr(firstError(x)); } };
  return html`<${Section} title="Add a user"><form class="form" onSubmit=${add}>${err && html`<div class="alert">${err}</div>`}
      <div class="grid2"><${Field} label="Name"><input required value=${f.name} onInput=${(e) => setF({ ...f, name: e.target.value })} /><//><${Field} label="Email"><input type="email" required value=${f.email} onInput=${(e) => setF({ ...f, email: e.target.value })} /><//></div>
      <${Field} label="Temporary password" help="At least 8 characters."><input type="password" required minlength="8" autocomplete="new-password" value=${f.password} onInput=${(e) => setF({ ...f, password: e.target.value })} /><//>
      <${Toggle} label="Administrator" help="Can manage users and every site." checked=${f.is_admin} onChange=${(v) => setF({ ...f, is_admin: v })} /><div><button class="btn primary">Create user</button></div></form><//>
    <${Section} title="Users">${res.data && html`<div class="tscroll"><table class="t"><thead><tr><th>User</th><th>Role</th><th class="right">Sites</th><th></th></tr></thead><tbody>${res.data.data.map((u) => html`<tr><td><b>${u.name}</b><div class="muted">${u.email}</div></td><td>${u.is_admin ? html`<span class="pill brand">Admin</span>` : 'Member'}</td><td class="right">${u.sites_count}</td>
      <td class="right">${u.id !== user.id && html`<button class="btn sm ghost danger" onClick=${() => setDel(u)}>Delete</button>`}</td></tr>`)}</tbody></table></div>`}<//>
    ${del && html`<${Confirm} title=${`Delete ${del.name}?`} message="Their account and API keys are removed. Sites they own keep their data." onClose=${() => setDel(null)} onConfirm=${async () => { await api('/admin/users/' + del.id, { method: 'DELETE' }); res.reload(); }} />`}`;
}

const mb = (n) => (n / 1048576).toFixed(n < 10485760 ? 1 : 0) + ' MB';
const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); }));

function Updates() {
  const { setUser } = useContext(UserCtx);
  const refreshUser = async () => { try { setUser((await api('/me')).user); } catch (e) { /* keep the current state */ } };
  const res = useApi('/admin/update', null, []);
  const info = res.data;
  const [progress, setProgress] = useState(null);
  const [staged, setStaged] = useState(null);
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(null);
  const [fetching, setFetching] = useState(false);
  const check = useApi('/admin/update/check', null, []);
  const latest = check.data && check.data.latest;
  const pending = staged || (info && info.staged);

  const upload = async (file) => {
    if (!file) return;
    setErr(null); setStaged(null); setDone(null);
    const size = info.limits.chunk, total = Math.max(1, Math.ceil(file.size / size)), id = uuid();
    try {
      for (let i = 0; i < total; i++) {
        setProgress(Math.round((i / total) * 100));
        const fd = new FormData();
        fd.append('id', id); fd.append('index', i); fd.append('total', total);
        fd.append('chunk', file.slice(i * size, (i + 1) * size), 'chunk.bin');
        const r = await api('/admin/update/chunk', { method: 'POST', body: fd });
        if (r.done) setStaged(r.staged);
      }
    } catch (x) { setErr(x.body && x.body.error ? x.body.error : firstError(x)); }
    setProgress(null);
  };
  const install = async (force) => {
    setBusy(true); setErr(null);
    try {
      const r = await api('/admin/update/apply', { method: 'POST', body: { force } });
      const after = await api('/admin/update'); // the first request after the copy runs the migrations
      setDone({ ...r.result, last: after.last });
      setStaged(null);
      res.reload();
      refreshUser();
    } catch (x) { setErr(x.body && x.body.error ? x.body.error : firstError(x)); }
    setBusy(false);
  };
  const downloadAndInstall = async () => {
    setFetching(true); setErr(null);
    try {
      const r = await api('/admin/update/download', { method: 'POST' });
      const after = await api('/admin/update');
      setDone({ ...r.result, last: after.last });
      res.reload();
      refreshUser();
    } catch (x) { setErr(x.body && x.body.error ? x.body.error : firstError(x)); }
    setFetching(false);
  };
  const discard = async () => { await api('/admin/update', { method: 'DELETE' }); setStaged(null); res.reload(); };

  if (!info) return html`<${Loading} />`;
  return html`<${Section} title="Software updates" help="Upload a Tidemark release zip to update this installation. Your data, settings and .env file are never touched.">
      <div class="row spread wrap"><div><span class="muted">Installed version</span><div style="font-size:22px;font-weight:620;letter-spacing:-0.02em">${info.version}</div></div></div>
      ${!info.zip && html`<div class="alert">PHP's zip extension is missing on this server, so updates can't be installed here.</div>`}
      ${info.zip && !info.writable && html`<div class="alert">The application folder isn't writable by PHP. Fix the permissions, or upload the new release over FTP instead.</div>`}
      ${err && html`<div class="alert" role="alert">${err}</div>`}
      ${done && html`<div class="notice"><${Icon} name="circle-check" size=${16} /><div><b>Updated from ${done.from} to ${done.to}.</b> ${done.files} files replaced${done.removed ? `, ${done.removed} obsolete files removed` : ''}.
        ${done.last && done.last.ok === false ? html`<div class="alert" style="margin-top:8px">Migrations failed: ${done.last.error}. A backup of the previous version is in storage/app/updates/backups.</div>` : html`<span> Database migrations ran.</span>`}
        <div style="margin-top:8px"><button class="btn sm" onClick=${() => location.reload()}>Reload to use the new version</button></div></div></div>`}
      ${info.zip && info.writable && !done && !pending && html`<div class="autoupdate">${check.loading && !latest ? html`<span class="muted">Checking GitHub for updates…</span>`
        : latest && latest.newer ? html`<div class="row spread wrap"><div class="grow"><b>Tidemark ${latest.version} is available</b>${latest.published_at && html`<span class="muted"> · ${new Date(latest.published_at).toLocaleDateString()}</span>`}
            ${latest.notes && html`<pre class="notes">${latest.notes.slice(0, 1200)}</pre>`}<a class="muted" href=${latest.url} target="_blank" rel="noopener">Release notes on GitHub</a></div>
            <button class="btn primary" disabled=${fetching} onClick=${downloadAndInstall}>${fetching ? 'Downloading…' : 'Download and install'}</button></div>`
        : latest ? html`<div class="row spread wrap"><span><${Icon} name="circle-check" size=${14} class="check" /> You're on the latest version (${latest.version}).</span><button class="btn sm" disabled=${check.loading} onClick=${() => api('/admin/update/check', { params: { fresh: 1 } }).then(check.reload)}>Check again</button></div>`
        : html`<div class="row spread wrap"><span class="muted">${(check.data && check.data.error) || 'Could not check for updates.'}</span><button class="btn sm" onClick=${check.reload}>Try again</button></div>`}</div>`}
      ${info.zip && info.writable && !done && (pending ? html`<div class="stage"><div class="grow"><b>Tidemark ${pending.version}</b> is ready to install.<div class="muted">${pending.newer ? `You're on ${pending.current}.` : `You're already on ${pending.current}; this is not a newer release.`} A backup of the current files is made first.</div></div>
          <div class="row"><button class="btn" disabled=${busy} onClick=${discard}>Discard</button><button class="btn primary" disabled=${busy} onClick=${() => install(!pending.newer)}>${busy ? 'Installing…' : pending.newer ? 'Install update' : 'Install anyway'}</button></div></div>`
        : html`<label class="dropzone ${progress !== null ? 'busy' : ''}"><${Icon} name="download" size=${20} /><b>${progress !== null ? `Uploading… ${progress}%` : 'Or upload a release zip yourself'}</b><span class="muted">tidemark-x.y.z.zip · sent in ${mb(info.limits.chunk)} pieces, so your host's ${mb(info.limits.upload || info.limits.chunk)} upload limit is fine</span>
          <input type="file" accept=".zip,application/zip" disabled=${progress !== null} onChange=${(e) => upload(e.target.files[0])} />${progress !== null && html`<span class="bar"><i style=${`width:${progress}%`}></i></span>`}</label>`)}
      ${info.auto_check && html`<p class="muted small">Tidemark looks for new releases every few hours${info.last_check && !info.last_check.error ? `, last on ${new Date(info.last_check.checked_at * 1000).toLocaleDateString()}` : ''}, and shows a dot next to your profile when one is available. Only the latest version number is requested from GitHub. Turn this off with <code>TIDEMARK_UPDATE_CHECK=false</code>.</p>`}
      ${info.last && !done && html`<p class="muted">Last update: ${info.last.from} → ${info.last.to}, ${new Date(info.last.finished_at * 1000).toLocaleString()}.</p>`}
    <//>`;
}

export function AccountSettings({ tab }) {
  useDocTitle('Settings');
  const { user } = useContext(UserCtx);
  const extra = registry.accountTabs.filter((x) => !x.visible || x.visible(user));
  const tabs = [['preferences', 'Preferences'], ['security', 'Security'], ['keys', 'API keys'], ...extra.map((x) => [x.key, x.label, !!(x.dot && x.dot(user))]), ...(user.is_admin ? [['users', 'Users'], ...(user.updater_enabled === false ? [] : [['updates', 'Updates', !!user.update]])] : [])];
  const t = tabs.find((x) => x[0] === tab) ? tab : 'preferences';
  const Body = { preferences: Preferences, security: Security, keys: ApiKeys, users: Users, updates: Updates, ...Object.fromEntries(extra.map((x) => [x.key, x.component])) }[t];
  return html`<${SettingsShell} title="Account settings" back=${['/sites', 'Your sites']} tabs=${tabs} tab=${t} base="/settings"><${Body} /><//>`;
}
