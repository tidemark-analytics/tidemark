// Site settings (General, Visibility, Goals, People, Shields) and account settings (Preferences, Security, API keys, Users).
import { html, useState, useEffect, useApi, api, navigate, Link, Icon, toast, copy, toggleTheme, UserCtx, useContext, useDocTitle, ago, countryName, BASE } from './lib.js';
import { Loading, Empty, ErrorBox, Field, Confirm, Modal, Toggle, CodeBlock } from './ui.js';
import { Snippet } from './dashboard.js';
import { registry } from './extensions.js';

const zones = () => { try { return ['UTC', ...Intl.supportedValuesOf('timeZone')]; } catch (e) { return ['UTC']; } };
const firstError = (x) => (x.errors && Object.values(x.errors)[0][0]) || x.message;

function SettingsShell({ title, back, tabs, tab, base, children }) {
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

function General({ site, reload }) {
  const [tz, setTz] = useState(site.timezone);
  const [confirm, setConfirm] = useState(null);
  const patch = async (body) => { try { await api(`/sites/${site.domain}`, { method: 'PATCH', body }); reload(); toast('Saved'); } catch (x) { toast(firstError(x), 'err'); } };
  return html`<${Section} title="Tracking snippet" help="Place this in the <head> of every page."><${Snippet} site=${site} /><//>
    <${Section} title="Tracking extras" help="Each one adds a small extension to the script and a matching goal.">
      <${Toggle} label="Outbound link clicks" checked=${site.track_outbound} onChange=${(v) => patch({ track_outbound: v })} />
      <${Toggle} label="File downloads" checked=${site.track_downloads} onChange=${(v) => patch({ track_downloads: v })} />
      <${Toggle} label="Form submissions" checked=${site.track_forms} onChange=${(v) => patch({ track_forms: v })} /><//>
    <${Section} title="Reporting timezone" help="Days start and end at midnight in this timezone."><div class="row"><select value=${tz} onChange=${(e) => setTz(e.target.value)} style="max-width:320px">${zones().map((z) => html`<option value=${z} selected=${z === tz}>${z}</option>`)}</select><button class="btn" disabled=${tz === site.timezone} onClick=${() => patch({ timezone: tz })}>Save</button></div><//>
    ${site.can_manage && html`<${MoveData} site=${site} reload=${reload} />`}
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

function NewLink({ site, onClose, onDone }) {
  const [f, setF] = useState({ name: '', password: '' });
  const [err, setErr] = useState(null);
  const save = async () => { try { await api(`/sites/${site.domain}/shared-links`, { method: 'POST', body: { name: f.name, password: f.password || null } }); onDone(); } catch (x) { setErr(firstError(x)); } };
  return html`<${Modal} title="New shared link" onClose=${onClose} footer=${html`<button class="btn" onClick=${onClose}>Cancel</button><button class="btn primary" disabled=${!f.name} onClick=${save}>Create link</button>`}>
    <div class="form">${err && html`<div class="alert">${err}</div>`}<${Field} label="Name" help="Only you will see this."><input autofocus value=${f.name} onInput=${(e) => setF({ ...f, name: e.target.value })} placeholder="Marketing team" /><//>
    <${Field} label="Password (optional)"><input type="password" autocomplete="new-password" value=${f.password} onInput=${(e) => setF({ ...f, password: e.target.value })} /><//></div><//>`;
}

function Goals({ site }) {
  const res = useApi(`/sites/${site.domain}/goals`, null, []);
  const [type, setType] = useState('event');
  const [value, setValue] = useState('');
  const [err, setErr] = useState(null);
  const add = async (e) => {
    e.preventDefault(); setErr(null);
    try { await api(`/sites/${site.domain}/goals`, { method: 'POST', body: { type, value } }); setValue(''); res.reload(); } catch (x) { setErr(firstError(x)); }
  };
  return html`<${Section} title="Goals" help="A goal counts how many visitors did something: triggered a custom event, or visited a page.">
      <form class="row wrap goalform" onSubmit=${add}><select value=${type} onChange=${(e) => setType(e.target.value)} aria-label="Goal type" style="width:auto"><option value="event">Custom event</option><option value="page">Pageview</option></select>
        <input class="grow" required placeholder=${type === 'event' ? 'Event name, e.g. Signup' : 'Page path, e.g. /thanks or /blog/*'} value=${value} onInput=${(e) => setValue(e.target.value)} aria-label="Goal value" /><button class="btn primary">Add goal</button></form>
      ${err && html`<div class="alert">${err}</div>`}
      ${res.data && res.data.data.length === 0 && html`<p class="muted">No goals yet.</p>`}
      ${res.data && res.data.data.length > 0 && html`<div class="tscroll"><table class="t"><thead><tr><th>Goal</th><th>Type</th><th></th></tr></thead><tbody>${res.data.data.map((g) => html`<tr><td>${g.name}</td><td class="muted">${g.type === 'event' ? 'Custom event' : 'Pageview'}</td>
        <td class="right"><button class="btn sm ghost danger" onClick=${async () => { await api(`/sites/${site.domain}/goals/${g.id}`, { method: 'DELETE' }); res.reload(); }}>Delete</button></td></tr>`)}</tbody></table></div>`}
    <//>
    <${Section} title="Sending custom events" help="Call this from your site's code to record a goal conversion.">
      <${CodeBlock} text="window.plausible('Signup', { props: { plan: 'pro' } })" /><p class="muted">Or tag an element without code: add <code>class="plausible-event-name=Signup"</code>, with the <code>tagged-events</code> script variant.</p><//>`;
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
  const tabs = [['general', 'General'], ['visibility', 'Visibility'], ['goals', 'Goals'], ['people', 'People'], ['shields', 'Shields']];
  const t = tabs.find((x) => x[0] === tab) ? tab : 'general';
  const Body = { general: General, visibility: Visibility, goals: Goals, people: People, shields: Shields }[t];
  return html`<${SettingsShell} title=${`${domain} settings`} back=${[`/${domain}`, 'Dashboard']} tabs=${tabs} tab=${t} base=${`/${domain}/settings`}><${Body} site=${s} reload=${res.reload} /><//>`;
}

// -------------------------------------------------------------------------------------- account settings
function Preferences() {
  const { user, setUser } = useContext(UserCtx);
  const [f, setF] = useState({ name: user.name, email: user.email, timezone: user.timezone || 'UTC' });
  const [err, setErr] = useState(null);
  const save = async (e) => { e.preventDefault(); setErr(null); try { const r = await api('/me', { method: 'POST', body: f }); setUser(r.user); toast('Saved'); } catch (x) { setErr(firstError(x)); } };
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
