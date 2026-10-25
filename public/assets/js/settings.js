// Site settings (General, Visibility, Branding, Goals, People, Shields) and account settings (Preferences, Security, API keys, Users).
import { html, useState, useEffect, useApi, api, navigate, Link, Icon, toast, copy, toggleTheme, setTheme, currentTheme, UserCtx, useContext, useDocTitle, ago, countryName, flag, BASE } from './lib.js';
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
  return html`<main class="main"><header class="page-head"><div class="grow"><div class="crumb crumb-back"><${Link} to=${back[0]}><${Icon} name="chevron-left" size=${14} /> ${back[1]}<//></div><h1>${title}</h1></div></header>
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

/** Collect even less: per-site switches that go beyond the defaults (no cookies, no stored IP addresses, no query strings). */
function PrivacyControls({ site, reload }) {
  const saved = (site.url_params || '').split(',').filter(Boolean).join(', ');
  const [params, setParams] = useState(saved);
  const [err, setErr] = useState(null);
  const patch = async (body) => { setErr(null); try { await api(`/sites/${site.domain}`, { method: 'PATCH', body }); reload(); toast('Saved'); } catch (x) { setErr(firstError(x)); } };
  const places = [['city', 'Country, region and city'], ['region', 'Country and region'], ['country', 'Country only']];
  return html`<${Section} title="Privacy controls" help="Tidemark already sets no cookies, stores no IP addresses and drops query strings. These switches let you collect even less.">
    <${Toggle} label="Respect Global Privacy Control" help="Visitors whose browser sends the Global Privacy Control signal are not counted." checked=${site.honor_gpc} onChange=${(v) => patch({ honor_gpc: v })} />
    <${Toggle} label="Respect Do Not Track" help="Visitors whose browser sends Do Not Track are not counted." checked=${site.honor_dnt} onChange=${(v) => patch({ honor_dnt: v })} />
    <${Toggle} label="Keep only the referrer's domain" help="Record news.example.org instead of news.example.org/story/42." checked=${site.referrer_domain_only} onChange=${(v) => patch({ referrer_domain_only: v })} />
    <${Field} label="Location detail" help="Where visitors are. Changing this affects visits recorded from now on."><select value=${site.location_precision || 'city'} onChange=${(e) => patch({ location_precision: e.target.value })} style="max-width:320px">${places.map(([k, l]) => html`<option value=${k} selected=${(site.location_precision || 'city') === k}>${l}</option>`)}</select><//>
    <${Field} label="Query parameters to keep" help="Page addresses are stored without their query string. List the parameters you want kept (for example page, lang), separated by commas, to see them in the Pages report. Names that look like they carry a secret or personal data, such as email or token, are refused."><div class="row"><input class="grow" placeholder="page, lang" value=${params} onInput=${(e) => setParams(e.target.value)} maxlength="2000" style="max-width:420px" /><button class="btn" type="button" disabled=${params.trim() === saved} onClick=${() => patch({ url_params: params.trim() || null })}>Save</button></div><//>
    ${err && html`<div class="alert">${err}</div>`}<//>`;
}

/** How visits get in: the snippet, its extensions and the rules that drop traffic you don't want counted. */
function Tracking({ site, reload }) {
  const patch = async (body) => { try { await api(`/sites/${site.domain}`, { method: 'PATCH', body }); reload(); toast('Saved'); } catch (x) { toast(firstError(x), 'err'); } };
  return html`<${Section} title="Tracking snippet" help="Place this in the <head> of every page."><${Snippet} site=${site} /><//>
    <${Section} title="Tracking extras" help="Each one adds a small extension to the script and a matching goal.">
      <${Toggle} label="Outbound link clicks" checked=${site.track_outbound} onChange=${(v) => patch({ track_outbound: v })} />
      <${Toggle} label="File downloads" checked=${site.track_downloads} onChange=${(v) => patch({ track_downloads: v })} />
      <${Toggle} label="Form submissions" checked=${site.track_forms} onChange=${(v) => patch({ track_forms: v })} /><//>
    ${!site.rollup && site.can_manage && html`<${PrivacyControls} site=${site} reload=${reload} />`}
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
      ${!adding && html`<div class="section-actions"><button class="btn primary" onClick=${() => setAdding(true)}><${Icon} name="plus" size=${14} /> New shared link</button></div>`}
      ${adding && html`<${NewLink} site=${site} onClose=${() => setAdding(false)} onDone=${() => { setAdding(false); links.reload(); toast('Shared link created'); }} />`}
      ${links.data && links.data.data.length === 0 && !adding && html`<div class="empty-inline"><${Icon} name="link" size=${18} /><div><b>No shared links yet</b><span>Create one to give someone read-only access without making the dashboard public. You can protect it with a password and delete it any time.</span></div></div>`}
      ${links.data && links.data.data.length > 0 && html`<ul class="rows" aria-label="Shared links">${links.data.data.map((l) => html`<li class="rowitem">
        <span class="ri-ico" aria-hidden="true"><${Icon} name=${l.password_protected ? 'lock' : 'link'} size=${16} /></span>
        <div class="grow ri-main"><b class="trunc">${l.name} ${l.password_protected && html`<span class="pill warn"><${Icon} name="lock" size=${11} /> Password</span>`}</b><span class="mono muted trunc">${url(l)}</span></div>
        <button class="btn sm" onClick=${() => copy(url(l))}><${Icon} name="copy" size=${13} /> Copy</button>
        <button class="btn ghost icon sm danger" aria-label=${'Delete shared link ' + l.name} title="Delete link" onClick=${() => setDel(l)}><${Icon} name="trash-2" size=${15} /></button></li>`)}</ul>`}
    <//>
    ${del && html`<${Confirm} title="Delete shared link?" message=${`Anyone using "${del.name}" will lose access immediately.`} onClose=${() => setDel(null)} onConfirm=${async () => { await api(`/sites/${site.domain}/shared-links/${del.id}`, { method: 'DELETE' }); links.reload(); }} />`}`;
}

const BRAND_PRESETS = ['#0e7c86', '#2563eb', '#7c3aed', '#db2777', '#dc2626', '#ea580c', '#16a34a', '#0f172a'];
function Branding({ site, reload }) {
  const b = site.branding || { allowed: true, name: '', color: null, has_logo: false };
  const [name, setName] = useState(b.name || '');
  const [color, setColor] = useState(b.color || '');
  const [busy, setBusy] = useState(false);
  const valid = color === '' || /^#[0-9a-fA-F]{6}$/.test(color);
  const [hideName, setHideName] = useState(!!b.hide_name);
  const changed = name !== (b.name || '') || color !== (b.color || '') || hideName !== !!b.hide_name;
  const shown = (() => { if (!valid || !color) return { changed: false }; const l = brandTheme(color, false); const d = brandTheme(color, true); return { changed: l.changed || d.changed, light: l.accent, dark: d.accent }; })();
  const run = async (fn, ok) => { setBusy(true); try { await fn(); reload(); toast(ok); } catch (x) { toast(firstError(x), 'err'); } setBusy(false); };
  const save = () => run(() => api(`/sites/${site.domain}/branding`, { method: 'PATCH', body: { brand_name: name.trim(), brand_color: color, brand_hide_name: hideName } }), 'Saved');
  const upload = (e) => {
    const f = e.target.files && e.target.files[0];
    e.target.value = '';
    if (!f) return;
    const body = new FormData();
    body.append('logo', f);
    run(() => api(`/sites/${site.domain}/branding/logo`, { method: 'POST', body }), 'Logo updated');
  };
  const dark = document.documentElement.dataset.theme === 'dark' || (!document.documentElement.dataset.theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
  const accent = valid && color ? ((dark ? shown.dark : shown.light) || color) : '#0e7c86';
  const logo = site.brand && site.brand.logo;
  const off = !b.allowed;
  return html`<${Section} title="Branding" help="Show your own logo, name and color to people who open the public dashboard or a shared link. A small “Analytics by Tidemark” link always stays in the footer.">
      ${off && html`<div class="notice"><${Icon} name="info" size=${16} /><div>Custom branding is included with Pro. <${Link} to="/settings">Open your account settings<//> to upgrade.</div></div>`}
      <div class="brand-grid">
        <div class="brand-form">
          <div class="bf-field"><span class="lbl">Logo</span>
            <div class="logo-row">
              <span class="logo-tile">${logo ? html`<img src=${logo} alt="Current logo" />` : html`<${Icon} name="globe" size=${20} />`}</span>
              <div class="stack sm"><div class="row wrap">
                <label class=${'btn sm' + (off || busy ? ' disabled' : '')}>${b.has_logo ? 'Replace logo' : 'Upload logo'}<input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden disabled=${off || busy} onChange=${upload} /></label>
                ${b.has_logo && html`<button class="btn sm ghost danger" disabled=${off || busy} onClick=${() => run(() => api(`/sites/${site.domain}/branding/logo`, { method: 'DELETE' }), 'Logo removed')}>Remove</button>`}</div>
                <span class="help">PNG, JPEG, WebP or SVG, up to 100 KB. A wide logo about 28 pixels tall works best.</span></div></div></div>
          <${Field} label="Display name" help=${hideName && b.has_logo ? 'Not shown in the header. Visitors who use a screen reader or have images turned off get it as the logo’s description.' : 'Shown in the header next to (or instead of) the logo. Leave empty to keep the Tidemark logo.'}><input value=${name} maxlength="60" disabled=${off} onInput=${(e) => setName(e.target.value)} placeholder=${site.domain} /><//>
          <div class="bf-field"><label class="check"><input type="checkbox" checked=${hideName && !!b.has_logo} disabled=${off || !b.has_logo} onChange=${(e) => setHideName(e.target.checked)} />Show only the logo, not the name</label>
            <span class="help">${b.has_logo ? 'For a wordmark that already includes your name. The name above becomes the logo’s alt text.' : 'Upload a logo first. Without one, the name is what visitors see.'}</span></div>
          <div class="bf-field"><span class="lbl">Brand color</span>
            <div class="swatches" role="group" aria-label="Preset colors">${BRAND_PRESETS.map((c) => html`<button type="button" class=${'swatch' + (color.toLowerCase() === c ? ' on' : '')} style=${`--sw:${c}`} disabled=${off} aria-label=${'Use ' + c} title=${c} onClick=${() => setColor(c)}></button>`)}</div>
            <div class="row">
              <input type="color" value=${valid && color ? color : '#0e7c86'} disabled=${off} onInput=${(e) => setColor(e.target.value)} class="color-pick" aria-label="Pick a color" />
              <input value=${color} disabled=${off} onInput=${(e) => setColor(e.target.value.trim())} placeholder="#0e7c86" style="max-width:140px" class="mono" aria-label="Color hex code" />
              ${color && html`<button class="btn sm ghost" disabled=${off} onClick=${() => setColor('')}>Reset</button>`}</div>
            <span class="help">Used for buttons, links and the chart.</span>
            ${!valid && html`<span class="help" style="color:var(--bad)">Use six hex digits, like #0e7c86.</span>`}
            ${valid && color && shown.changed && html`<span class="help">So text stays readable (WCAG AA contrast), visitors see this as <b class="mono">${shown.light}</b> on light pages and <b class="mono">${shown.dark}</b> on dark ones, the closest shades that pass.</span>`}</div>
          <div class="row"><button class="btn primary" disabled=${off || busy || !changed || !valid} onClick=${save}>Save changes</button>${changed && html`<span class="muted small">Unsaved changes</span>`}</div>
        </div>
        <div class="brand-side"><span class="lbl">Preview</span>
          <div class="bp" style=${`--bc:${accent}`} aria-hidden="true">
            <div class="bp-head">${logo ? html`<img src=${logo} alt="" />` : ''}<b>${hideName && logo ? '' : (name.trim() || (logo ? '' : site.domain))}</b><span class="bp-btn">Sign in</span></div>
            <div class="bp-body"><div class="bp-k"><em>Unique visitors</em><b>3,254</b></div>
              <svg viewBox="0 0 200 56" preserveAspectRatio="none"><path d="M0 44 L20 38 L40 40 L60 26 L80 30 L100 18 L120 24 L140 12 L160 20 L180 8 L200 14 L200 56 L0 56Z" class="a"/><path d="M0 44 L20 38 L40 40 L60 26 L80 30 L100 18 L120 24 L140 12 L160 20 L180 8 L200 14" class="l"/></svg></div>
            <div class="bp-foot">Analytics by Tidemark</div></div>
          ${!site.public && html`<p class="muted small">Branding shows on the public dashboard and shared links. This site's dashboard is private, so only shared links use it. <${Link} to=${`/${site.domain}/settings/access`}>Sharing settings<//></p>`}</div>
      </div>
    <//>`;
}

function NewLink({ site, onClose, onDone }) {
  const [f, setF] = useState({ name: '', password: '' });
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const save = async (e) => { e.preventDefault(); setErr(null); setBusy(true); try { await api(`/sites/${site.domain}/shared-links`, { method: 'POST', body: { name: f.name, password: f.password || null } }); onDone(); } catch (x) { setErr(firstError(x)); setBusy(false); } };
  return html`<form class="goal-add" onSubmit=${save}>
    ${err && html`<div class="alert">${err}</div>`}
    <div class="fields2">
      <${Field} label="Name" help="Only you will see this."><input autofocus required value=${f.name} onInput=${(e) => setF({ ...f, name: e.target.value })} placeholder="Marketing team" /><//>
      <${Field} label="Password (optional)" help="Visitors enter it before they see the dashboard."><input type="password" autocomplete="new-password" value=${f.password} onInput=${(e) => setF({ ...f, password: e.target.value })} /><//>
    </div>
    <div class="row"><button class="btn primary" disabled=${!f.name || busy}>Create link</button><button type="button" class="btn" onClick=${onClose}>Cancel</button></div>
  </form>`;
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
  const members = site.rollup.sites.map((x) => x.domain);
  const count = all ? list.length || site.rollup.count : chosen.length;
  const toggle = (d, on) => setChosen(on ? [...chosen, d] : chosen.filter((x) => x !== d));
  const OPTIONS = [[true, 'All the sites I own', 'layers', 'Includes the sites you add later.'], [false, 'Only the sites I choose', 'funnel', 'Pick exactly which sites to add up.']];
  return html`<${Section} title="Sites in this rollup" help="A rollup adds up the visits of several of your sites on one dashboard. Your sites themselves are never changed.">
    ${err && html`<div class="alert">${err}</div>`}
    <div class="opt-cards" role="radiogroup" aria-label="Which sites">${OPTIONS.map(([v, t, i, d]) => html`<button type="button" role="radio" aria-checked=${all === v} class=${'opt-card' + (all === v ? ' on' : '')} onClick=${() => { setAll(v); if (!v && !chosen.length) setChosen(list.map((x) => x.domain)); }}>
      <span class="ri-ico" aria-hidden="true"><${Icon} name=${i} size=${16} /></span><span class="oc-t"><b>${t}</b><span class="muted">${d}</span></span><span class="oc-dot" aria-hidden="true"></span></button>`)}</div>
    ${all ? html`<ul class="rows" aria-label="Sites in this rollup">${(list.length ? list.map((x) => x.domain) : members).map((d) => html`<li class="rowitem"><span class="ri-ico" aria-hidden="true"><${Icon} name="globe" size=${16} /></span><div class="grow ri-main"><b class="trunc">${d}</b></div><${Icon} name="check" size=${15} class="tick" /></li>`)}</ul>`
      : html`<div class="row spread wrap"><span class="muted small">${chosen.length} of ${list.length} selected</span><div class="row"><button type="button" class="btn sm ghost" onClick=${() => setChosen(list.map((x) => x.domain))}>Select all</button><button type="button" class="btn sm ghost" disabled=${!chosen.length} onClick=${() => setChosen([])}>Clear</button></div></div>
      <ul class="rows" role="group" aria-label="Sites in this rollup">${list.map((x) => html`<li class="rowitem pick"><label class="grow"><input type="checkbox" checked=${chosen.includes(x.domain)} onChange=${(e) => toggle(x.domain, e.target.checked)} /><span class="ri-ico" aria-hidden="true"><${Icon} name="globe" size=${16} /></span><b class="trunc">${x.domain}</b></label></li>`)}</ul>
      ${!list.length && cands.data && html`<div class="empty-inline"><${Icon} name="globe" size=${18} /><div><b>No sites to choose from</b><span>Add a site first, then come back to include it here.</span></div></div>`}`}
    ${dirty && html`<div class="notice"><${Icon} name="info" size=${16} /><div>Changing the list changes the whole history: a site you take out disappears from this dashboard's past as well as its future, and the other way round.</div></div>`}
    <div class="row"><button class="btn primary" disabled=${!dirty || (!all && !chosen.length)} onClick=${save}>Save changes</button>${dirty && html`<span class="muted small">${count} ${count === 1 ? 'site' : 'sites'} after saving</span>`}</div>
  <//>`;
}

function People({ site }) {
  const res = useApi(`/sites/${site.domain}/people`, null, []);
  const [f, setF] = useState({ email: '', role: 'viewer' });
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const people = (res.data && res.data.data) || [];
  const run = async (fn) => { setErr(null); try { await fn(); res.reload(); } catch (x) { setErr(firstError(x)); } };
  const invite = async (e) => {
    e.preventDefault(); setErr(null); setBusy(true);
    try { await api(`/sites/${site.domain}/people`, { method: 'POST', body: f }); setF({ ...f, email: '' }); res.reload(); toast('Added'); } catch (x) { setErr(firstError(x)); }
    setBusy(false);
  };
  const ROLES = [['viewer', 'Viewer', 'eye'], ['admin', 'Admin', 'settings']];
  return html`<${Section} title="People" help="Add existing users of this Tidemark install to this site.">
    <form class="goal-add" onSubmit=${invite}>
      <div class="seg" role="radiogroup" aria-label="Role">${ROLES.map(([k, l, i]) => html`<button type="button" role="radio" aria-checked=${f.role === k} class=${f.role === k ? 'on' : ''} onClick=${() => setF({ ...f, role: k })}><${Icon} name=${i} size=${14} />${l}</button>`)}</div>
      <div class="goal-add-row"><input class="grow" type="email" required placeholder="Email address of an existing user" aria-label="Email" value=${f.email} onInput=${(e) => setF({ ...f, email: e.target.value })} /><button class="btn primary" disabled=${!f.email || busy}>Add person</button></div>
      <span class="muted small">${f.role === 'admin' ? 'Admins can see stats and change this site\'s settings.' : 'Viewers can see stats but not change anything.'}</span>
    </form>
    ${err && html`<div class="alert">${err}</div>`}
    ${people.length > 0 && html`<ul class="rows" aria-label="People with access">${people.map((u) => html`<li class="rowitem">
      <span class="avatar" aria-hidden="true">${(u.name || u.email || '?').slice(0, 1).toUpperCase()}</span>
      <div class="grow ri-main"><b class="trunc">${u.name}</b><span class="muted trunc">${u.email}</span></div>
      ${u.role === 'owner' ? html`<span class="pill brand">Owner</span>` : html`<select class="mini-select" value=${u.role} aria-label=${'Role of ' + u.name} onChange=${(e) => run(() => api(`/sites/${site.domain}/people/${u.id}`, { method: 'PATCH', body: { role: e.target.value } }))}><option value="viewer" selected=${u.role === 'viewer'}>Viewer</option><option value="admin" selected=${u.role === 'admin'}>Admin</option></select>`}
      ${u.role !== 'owner' ? html`<button class="btn ghost icon sm danger" aria-label=${'Remove ' + u.name} title="Remove from this site" onClick=${() => run(() => api(`/sites/${site.domain}/people/${u.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button>` : html`<span class="ri-spacer" aria-hidden="true"></span>`}</li>`)}</ul>`}
  <//>`;
}

const SHIELDS = [
  ['ip', 'IP address', '203.0.113.7 or 203.0.113.0/24', 'monitor', 'Visits from this address or range are not counted. Add your own to keep your visits out.'],
  ['country', 'Country', 'Two-letter code, e.g. DE', 'globe', 'Visits from this country are not counted.'],
  ['page', 'Page', '/admin/*', 'link', 'Views of matching pages are not counted. Use * as a wildcard.'],
  ['hostname', 'Hostname', 'example.com', 'shield-check', 'Only visits on these hostnames are counted, which helps if someone copies your script onto another site.'],
];
function Shields({ site }) {
  const res = useApi(`/sites/${site.domain}/shields`, null, []);
  const [type, setType] = useState('ip');
  const [value, setValue] = useState('');
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const add = async (e) => { e.preventDefault(); setErr(null); setBusy(true); try { await api(`/sites/${site.domain}/shields`, { method: 'POST', body: { type, value } }); setValue(''); res.reload(); } catch (x) { setErr(firstError(x)); } setBusy(false); };
  const remove = async (r) => { setErr(null); try { await api(`/sites/${site.domain}/shields/${r.id}`, { method: 'DELETE' }); res.reload(); } catch (x) { setErr(firstError(x)); } };
  const rows = res.data ? res.data.data : [];
  const kind = (t) => SHIELDS.find((x) => x[0] === t) || [t, t, '', 'shield', ''];
  const cur = kind(type);
  return html`<${Section} title="Shields" help="Keep traffic you don't want out of your numbers. Matching hits are dropped before they're stored.">
    <form class="goal-add" onSubmit=${add}>
      <div class="seg wrapseg" role="radiogroup" aria-label="Rule type">${SHIELDS.map(([k, l, , i]) => html`<button type="button" role="radio" aria-checked=${type === k} class=${type === k ? 'on' : ''} onClick=${() => setType(k)}><${Icon} name=${i} size=${14} />${l}</button>`)}</div>
      <div class="goal-add-row"><input class="grow" required placeholder=${cur[2]} value=${value} onInput=${(e) => setValue(e.target.value)} aria-label=${cur[1]} /><button class="btn primary" disabled=${!value.trim() || busy}>Add rule</button></div>
      <span class="muted small">${cur[4]}</span>
    </form>
    ${err && html`<div class="alert">${err}</div>`}
    ${res.data && !rows.length && html`<div class="empty-inline"><${Icon} name="shield" size=${18} /><div><b>No rules yet</b><span>Your site counts every visit. Add your own IP address to keep your visits out, or block a path like /admin/*.</span></div></div>`}
    ${rows.length > 0 && html`<ul class="rows" aria-label="Shield rules">${rows.map((r) => { const k = kind(r.type); return html`<li class="rowitem">
      <span class="ri-ico" aria-hidden="true"><${Icon} name=${k[3]} size=${16} /></span>
      <div class="grow ri-main"><b class="mono trunc">${r.type === 'country' ? `${flag(r.value)} ${countryName(r.value)} · ${r.value}` : r.value}</b><span class="muted">${r.type === 'hostname' ? 'Only count this hostname' : 'Blocked ' + k[1].toLowerCase()}</span></div>
      <button class="btn ghost icon sm danger" aria-label=${'Delete rule ' + r.value} title="Delete rule" onClick=${() => remove(r)}><${Icon} name="trash-2" size=${15} /></button></li>`; })}</ul>`}
  <//>`;
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
const initial = (u) => (u.name || u.email || '?').slice(0, 1).toUpperCase();

function Profile() {
  const { user, setUser } = useContext(UserCtx);
  const [f, setF] = useState({ name: user.name, email: user.email, timezone: user.timezone || 'UTC' });
  const [err, setErr] = useState(null);
  const [theme, setThemeState] = useState(currentTheme());
  const changed = f.name !== user.name || f.email !== user.email || f.timezone !== (user.timezone || 'UTC');
  const save = async (e) => { e.preventDefault(); setErr(null); try { const r = await api('/me', { method: 'POST', body: f }); setUser({ ...user, ...r.user }); toast('Saved'); } catch (x) { setErr(firstError(x)); } };
  const pick = (t) => { setTheme(t); setThemeState(t); };
  const THEMES = [['light', 'Light'], ['dark', 'Dark'], ['system', 'System']];
  return html`<${Section} title="Profile" help="Your name and email are used to sign in and are shown to people you share sites with.">
      <form class="form wide" onSubmit=${save}>
        <div class="profile-head"><span class="avatar big" aria-hidden="true">${initial(f)}</span><div class="ri-main"><b>${f.name || 'Your name'}</b><span class="muted">${f.email}</span></div></div>
        ${err && html`<div class="alert">${err}</div>`}
        <div class="fields2"><${Field} label="Name"><input required value=${f.name} onInput=${(e) => setF({ ...f, name: e.target.value })} /><//>
          <${Field} label="Email"><input type="email" required value=${f.email} onInput=${(e) => setF({ ...f, email: e.target.value })} /><//></div>
        <${Field} label="Timezone" help="Used for dates in your own views. Each site's reports use that site's own timezone." class="tzfield"><select value=${f.timezone} onChange=${(e) => setF({ ...f, timezone: e.target.value })}>${zones().map((z) => html`<option value=${z} selected=${z === f.timezone}>${z}</option>`)}</select><//>
        <div class="row"><button class="btn primary" disabled=${!changed}>Save changes</button>${changed && html`<span class="muted small">Unsaved changes</span>`}</div></form><//>
    <${Section} title="Appearance" help="System follows your device and switches with it.">
      <div class="theme-opts" role="radiogroup" aria-label="Theme">${THEMES.map(([k, l]) => html`<button type="button" role="radio" aria-checked=${theme === k} class=${'theme-opt ' + k + (theme === k ? ' on' : '')} onClick=${() => pick(k)}><span class="tp" aria-hidden="true"><i></i><i></i><i></i></span><span>${l}</span></button>`)}</div><//>`;
}

function Security() {
  const { user, setUser } = useContext(UserCtx);
  const blank = { current_password: '', password: '', password_confirmation: '' };
  const [pw, setPw] = useState(blank);
  const [err, setErr] = useState(null);
  const [setup, setSetup] = useState(null);
  const [code, setCode] = useState('');
  const [disabling, setDisabling] = useState(false);
  const [dpw, setDpw] = useState('');
  const changePw = async (e) => { e.preventDefault(); setErr(null); try { await api('/me/password', { method: 'POST', body: pw }); setPw(blank); toast('Password updated'); } catch (x) { setErr(firstError(x)); } };
  const refresh = async () => setUser((await api('/me')).user);
  const mismatch = pw.password_confirmation !== '' && pw.password !== pw.password_confirmation;
  return html`<${Section} title="Password" help="Use at least 8 characters.">
      <form class="form wide" onSubmit=${changePw}>${err && html`<div class="alert">${err}</div>`}
        <${Field} label="Current password" class="tzfield"><input type="password" required autocomplete="current-password" value=${pw.current_password} onInput=${(e) => setPw({ ...pw, current_password: e.target.value })} /><//>
        <div class="fields2"><${Field} label="New password"><input type="password" required minlength="8" autocomplete="new-password" value=${pw.password} onInput=${(e) => setPw({ ...pw, password: e.target.value })} /><//>
          <${Field} label="Confirm new password" help=${mismatch ? 'The passwords don\'t match yet.' : ''}><input type="password" required autocomplete="new-password" value=${pw.password_confirmation} onInput=${(e) => setPw({ ...pw, password_confirmation: e.target.value })} /><//></div>
        <div><button class="btn primary" disabled=${!pw.current_password || !pw.password || mismatch}>Update password</button></div></form><//>
    <${Section} title="Two-factor authentication" help="Require a code from an authenticator app when you sign in.">
      ${user.two_factor ? html`<div class="rowitem boxed"><span class="ri-ico good" aria-hidden="true"><${Icon} name="shield-check" size=${16} /></span><div class="grow ri-main"><b>Two-factor is on</b><span class="muted">You'll be asked for a code from your authenticator app at sign-in.</span></div>
          ${!disabling && html`<button class="btn sm danger" onClick=${() => setDisabling(true)}>Turn off</button>`}</div>
        ${disabling && html`<form class="goal-add" onSubmit=${async (e) => { e.preventDefault(); try { await api('/me/two-factor', { method: 'DELETE', body: { password: dpw } }); setDisabling(false); setDpw(''); await refresh(); toast('Two-factor turned off'); } catch (x) { toast(firstError(x), 'err'); } }}>
          <${Field} label="Confirm your password to turn it off"><input type="password" autofocus required value=${dpw} onInput=${(e) => setDpw(e.target.value)} /><//>
          <div class="row"><button class="btn danger" disabled=${!dpw}>Turn off two-factor</button><button type="button" class="btn" onClick=${() => { setDisabling(false); setDpw(''); }}>Cancel</button></div></form>`}`
        : setup ? html`<div class="goal-add"><p>Add this secret to your authenticator app, then enter the 6-digit code it shows.</p><${CodeBlock} text=${setup.secret} /><a class="muted small" href=${setup.uri}>Open in authenticator app</a>
          <${Field} label="Code" class="tzfield"><input inputmode="numeric" autocomplete="one-time-code" placeholder="123456" value=${code} onInput=${(e) => setCode(e.target.value)} /><//>
          <div class="row"><button class="btn primary" disabled=${!code.trim()} onClick=${async () => { try { await api('/me/two-factor/confirm', { method: 'POST', body: { code } }); setSetup(null); setCode(''); await refresh(); toast('Two-factor enabled'); } catch (x) { toast(firstError(x), 'err'); } }}>Confirm and turn on</button><button class="btn" onClick=${() => { setSetup(null); setCode(''); }}>Cancel</button></div></div>`
        : html`<div class="rowitem boxed"><span class="ri-ico" aria-hidden="true"><${Icon} name="shield" size=${16} /></span><div class="grow ri-main"><b>Two-factor is off</b><span class="muted">Add a second step to protect your account if your password leaks.</span></div>
          <button class="btn sm primary" onClick=${async () => setSetup(await api('/me/two-factor', { method: 'POST' }))}>Set up</button></div>`}<//>
    <${ApiKeys} />`;
}

function ApiKeys() {
  const res = useApi('/me/keys', null, []);
  const [name, setName] = useState('');
  const [token, setToken] = useState(null);
  const [err, setErr] = useState(null);
  const keys = (res.data && res.data.data) || [];
  const create = async (e) => { e.preventDefault(); setErr(null); try { const r = await api('/me/keys', { method: 'POST', body: { name } }); setToken(r.token); setName(''); res.reload(); } catch (x) { setErr(firstError(x)); } };
  const revoke = async (k) => { setErr(null); try { await api('/me/keys/' + k.id, { method: 'DELETE' }); res.reload(); } catch (x) { setErr(firstError(x)); } };
  return html`<${Section} title="API keys" help="Use a key as a Bearer token with the Stats API (/api/v1/stats/…). A key has the same access as you.">
    <form class="goal-add" onSubmit=${create}><div class="goal-add-row"><input class="grow" required placeholder="Key name, e.g. Reporting script" aria-label="Key name" value=${name} onInput=${(e) => setName(e.target.value)} /><button class="btn primary" disabled=${!name.trim()}>Create key</button></div></form>
    ${token && html`<div class="notice"><${Icon} name="key" size=${16} /><div><b>Copy your key now. It won't be shown again.</b><${CodeBlock} text=${token} /></div></div>`}
    ${err && html`<div class="alert">${err}</div>`}
    ${res.data && !keys.length && html`<div class="empty-inline"><${Icon} name="key" size=${18} /><div><b>No keys yet</b><span>Create one to read your stats from a script or another tool.</span></div></div>`}
    ${keys.length > 0 && html`<ul class="rows" aria-label="API keys">${keys.map((k) => html`<li class="rowitem">
      <span class="ri-ico" aria-hidden="true"><${Icon} name="key" size=${16} /></span>
      <div class="grow ri-main"><b class="trunc">${k.name}</b><span class="muted">${k.last_used_at ? 'Last used ' + ago(k.last_used_at.replace('T', ' ').slice(0, 19)) : 'Never used'}</span></div>
      <button class="btn ghost icon sm danger" aria-label=${'Revoke key ' + k.name} title="Revoke key" onClick=${() => revoke(k)}><${Icon} name="trash-2" size=${15} /></button></li>`)}</ul>`}
  <//>`;
}

export function Users() {
  const { user } = useContext(UserCtx);
  const res = useApi('/admin/users', null, []);
  const blank = { name: '', email: '', password: '', is_admin: false };
  const [f, setF] = useState(blank);
  const [adding, setAdding] = useState(false);
  const [err, setErr] = useState(null);
  const [del, setDel] = useState(null);
  const users = (res.data && res.data.data) || [];
  const add = async (e) => { e.preventDefault(); setErr(null); try { await api('/admin/users', { method: 'POST', body: f }); setF(blank); setAdding(false); res.reload(); toast('User created'); } catch (x) { setErr(firstError(x)); } };
  return html`<${Section} title="Users" help="People who can sign in to this Tidemark install. Give them access to a site under that site's Sharing and access settings.">
      ${!adding && html`<div class="section-actions"><button class="btn primary" onClick=${() => setAdding(true)}><${Icon} name="plus" size=${14} /> New user</button></div>`}
      ${adding && html`<form class="goal-add" onSubmit=${add}>${err && html`<div class="alert">${err}</div>`}
        <div class="fields2"><${Field} label="Name"><input required autofocus value=${f.name} onInput=${(e) => setF({ ...f, name: e.target.value })} /><//><${Field} label="Email"><input type="email" required value=${f.email} onInput=${(e) => setF({ ...f, email: e.target.value })} /><//></div>
        <${Field} label="Temporary password" help="At least 8 characters. They can change it after signing in." class="tzfield"><input type="password" required minlength="8" autocomplete="new-password" value=${f.password} onInput=${(e) => setF({ ...f, password: e.target.value })} /><//>
        <${Toggle} label="Administrator" help="Can manage users and every site." checked=${f.is_admin} onChange=${(v) => setF({ ...f, is_admin: v })} />
        <div class="row"><button class="btn primary">Create user</button><button type="button" class="btn" onClick=${() => { setAdding(false); setErr(null); setF(blank); }}>Cancel</button></div></form>`}
      ${users.length > 0 && html`<ul class="rows" aria-label="Users">${users.map((u) => html`<li class="rowitem">
        <span class="avatar" aria-hidden="true">${initial(u)}</span>
        <div class="grow ri-main"><b class="trunc">${u.name} ${u.id === user.id && html`<span class="muted">(you)</span>`}</b><span class="muted trunc">${u.email}</span></div>
        <span class="muted small nowrap">${u.sites_count} ${u.sites_count === 1 ? 'site' : 'sites'}</span>
        ${u.is_admin ? html`<span class="pill brand">Admin</span>` : html`<span class="pill">Member</span>`}
        ${u.id !== user.id ? html`<button class="btn ghost icon sm danger" aria-label=${'Delete ' + u.name} title="Delete user" onClick=${() => setDel(u)}><${Icon} name="trash-2" size=${15} /></button>` : html`<span class="ri-spacer" aria-hidden="true"></span>`}</li>`)}</ul>`}
    <//>
    ${del && html`<${Confirm} title=${`Delete ${del.name}?`} message="Their account and API keys are removed. Sites they own keep their data." onClose=${() => setDel(null)} onConfirm=${async () => { await api('/admin/users/' + del.id, { method: 'DELETE' }); res.reload(); }} />`}`;
}

const mb = (n) => (n / 1048576).toFixed(n < 10485760 ? 1 : 0) + ' MB';
const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); }));

export function Updates() {
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
  const tabs = [['profile', 'Profile'], ['security', 'Security'], ...extra.map((x) => [x.key, x.label, !!(x.dot && x.dot(user))]), ...(user.is_admin && !registry.adminUi ? [['users', 'Users'], ...(user.updater_enabled === false ? [] : [['updates', 'Updates', !!user.update]])] : [])];
  // administration lives in its own interface when an extension provides one
  if (registry.adminUi && user.is_admin && ['users', 'updates', 'customers'].includes(tab)) { window.location.replace(registry.adminUi.href + (tab === 'customers' ? '' : '/' + tab)); return null; }
  const moved = { preferences: 'profile', keys: 'security' }; // older links still land in the right place
  const t = [tab, moved[tab]].find((k) => tabs.some((x) => x[0] === k)) || 'profile';
  const Body = { profile: Profile, security: Security, users: Users, updates: Updates, ...Object.fromEntries(extra.map((x) => [x.key, x.component])) }[t];
  return html`<${SettingsShell} title="Account settings" back=${['/sites', 'Your sites']} tabs=${tabs} tab=${t} base="/settings"><${Body} /><//>`;
}
