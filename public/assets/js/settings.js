// Site settings (General, Visibility, Branding, Goals, People, Shields) and account settings (Preferences, Security, API keys, Users).
import { html, useState, useEffect, useRef, useApi, api, navigate, Link, Icon, toast, copy, toggleTheme, setTheme, currentTheme, UserCtx, useContext, useDocTitle, useLocation, ago, countryName, flag, BASE } from './lib.js';
import { Loading, Empty, ErrorBox, Field, Confirm, TypedConfirm, Modal, Toggle, CodeBlock, Breadcrumb, Badge, Avatar } from './ui.js';
import { squareImage } from './image.js';
import { Snippet } from './dashboard.js';
import { useScope } from './org.js';
import { CreateOrganization } from './shell.js';
import { registry } from './extensions.js';
import { accountTabs } from './shell.js';
import { brandTheme } from './brand.js';
import { importPlausible, removeImported } from './data-import.js';

const zones = () => { try { return ['UTC', ...Intl.supportedValuesOf('timeZone')]; } catch (e) { return ['UTC']; } };
const firstError = (x) => (x.errors && Object.values(x.errors)[0][0]) || x.message;

function SettingsShell({ title, back, tabs, tab, base, children, scope, dup }) {
  // on phones the section list scrolls sideways: bring the active section into view
  useEffect(() => { const a = document.querySelector('.subnav a.active'); if (a && a.scrollIntoView) a.scrollIntoView({ block: 'nearest', inline: 'center' }); }, [tab]);
  const label = (tabs.find((x) => x[0] === tab) || [])[1];
  return html`<main class="main"><header class="page-head"><div class="grow"><${Breadcrumb} items=${[{ label: scope || back[1], to: back[0] }, { label: 'Settings' }]} /><h1 style="margin-top:8px">${title}</h1></div></header>
    <div class=${'settings' + (dup ? ' dup' : '')}><nav class="subnav" aria-label="Settings sections">${tabs.map(([k, l, dot]) => html`<${Link} to=${base + (k === tabs[0][0] ? '' : '/' + k)} class=${k === tab ? 'active' : ''} aria-current=${k === tab ? 'page' : undefined}>${l}${dot && html`<span class="notif inline" aria-hidden="true"></span><span class="sr-only"> (update available)</span>`}<//>`)}</nav>
      <div class="stack" aria-label=${label}>${children}</div></div></main>`;
}
/** The body of a section a plan doesn't include: blurred and out of reach, with a lock and a way to upgrade (Cloud only; self-hosted installs are never locked). */
const ProLock = ({ children, note }) => html`<div class="prolock"><div class="prolock-body" inert aria-hidden="true">${children}</div>
  <div class="prolock-over" role="note"><span class="prolock-ico" aria-hidden="true"><${Icon} name="lock" size=${22} /></span><b>Upgrade to unlock</b>
    <p>To access this feature, <${Link} to="/settings/billing">upgrade your plan<//>.${note ? ' ' + note : ''}</p></div></div>`;
/** A setting card: title, description, body and an optional footer (helper text left, Save right). */
const Section = ({ title, help, children, danger, locked, lockNote, footer }) => html`<section class=${'card setting ' + (danger ? 'danger-zone' : '')}><div class="setting-h"><h2>${title}</h2>${help && html`<p>${help}</p>`}</div>${children && html`<div class="setting-b">${locked ? html`<${ProLock} note=${lockNote}>${children}<//>` : children}</div>`}${footer && !locked && html`<div class="setting-f">${footer}</div>`}</section>`;
const SaveFooter = ({ help, dirty, onSave, busy, label = 'Save' }) => html`<span class="help">${dirty ? 'You have unsaved changes.' : help}</span><button class="btn primary sm" type="button" disabled=${!dirty || busy} onClick=${onSave}>${label}</button>`;

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
  const scrubChoice = (site.features || {}).pii_scrub !== false; // a host can require masking and hide the setting
  const masks = useApi(`/sites/${site.domain}/pii-masks`, null, [], { enabled: scrubChoice });
  const kinds = { email: ['email address', 'email addresses'], uuid: ['UUID', 'UUIDs'], number: ['long number', 'long numbers'], token: ['token', 'tokens'] };
  const masked = ((masks.data && masks.data.results) || []).map((r) => { const k = kinds[r.pattern] || [r.pattern, r.pattern]; return `${r.count.toLocaleString()} ${r.count === 1 ? k[0] : k[1]}`; }).join(', ');
  return html`<${Section} title="Privacy controls" help="Tidemark already sets no cookies, stores no IP addresses and drops query strings. These switches let you collect even less.">
    ${scrubChoice && html`<${Toggle} label="Mask personal data sent by accident" help="Email addresses, tokens, UUIDs and long numbers in page addresses, referrers, campaign values, event names and properties are replaced with a placeholder such as [email] before anything is stored. Ordinary page names and short ids are left alone." checked=${site.pii_scrub} onChange=${(v) => patch({ pii_scrub: v })} />`}
    ${scrubChoice && masked && html`<p class="muted small" style="margin:-4px 0 4px 48px">Masked in the last 30 days: ${masked}. Only these counts are kept, never the values.</p>`}
    <${Toggle} label="Respect Global Privacy Control" help="Visitors whose browser sends the Global Privacy Control signal are not counted." checked=${site.honor_gpc} onChange=${(v) => patch({ honor_gpc: v })} />
    <${Toggle} label="Respect Do Not Track" help="Visitors whose browser sends Do Not Track are not counted." checked=${site.honor_dnt} onChange=${(v) => patch({ honor_dnt: v })} />
    <${Toggle} label="Keep only the referrer's domain" help="Record news.example.org instead of news.example.org/story/42." checked=${site.referrer_domain_only} onChange=${(v) => patch({ referrer_domain_only: v })} />
    <${Field} label="Location detail" help="Where visitors are. Changing this affects visits recorded from now on."><select value=${site.location_precision || 'city'} onChange=${(e) => patch({ location_precision: e.target.value })} style="max-width:320px">${places.map(([k, l]) => html`<option value=${k} selected=${(site.location_precision || 'city') === k}>${l}</option>`)}</select><//>
    <${Field} label="Query parameters to keep" help="Page addresses are stored without their query string. List the parameters you want kept (for example page, lang), separated by commas, to see them in the Pages report. Names that look like they carry a secret or personal data, such as email or token, are refused."><div class="row"><input class="grow" placeholder="page, lang" value=${params} onInput=${(e) => setParams(e.target.value)} maxlength="2000" style="max-width:420px" /><button class="btn" type="button" disabled=${params.trim() === saved} onClick=${() => patch({ url_params: params.trim() || null })}>Save</button></div><//>
    ${err && html`<div class="alert">${err}</div>`}<//>`;
}

/** Crawlers, scripts, data center traffic, referrer spam and floods: left out of the numbers and counted here. */
function BotFiltering({ site, reload }) {
  const filtered = useApi(`/sites/${site.domain}/filtered`, null, []);
  const [err, setErr] = useState(null);
  const patch = async (body) => { setErr(null); try { await api(`/sites/${site.domain}`, { method: 'PATCH', body }); reload(); toast('Saved'); } catch (x) { setErr(firstError(x)); } };
  const names = { crawler: 'crawlers and known bots', headless: 'headless browsers and scripts', referrer_spam: 'referrer spam', datacenter: 'data center traffic', flood: 'floods from one visitor' };
  const rows = (filtered.data && filtered.data.results) || [];
  const total = rows.reduce((n, r) => n + r.count, 0);
  return html`<${Section} title="Bot and spam filtering" help="Hits that don't come from a person's browser are left out of your numbers, using only what the request already carries (never stored): known crawlers, headless browsers, addresses of cloud and hosting providers, referrer spam and sudden floods from a single visitor. Lists of providers and spam domains are updated with each release.">
    <${Toggle} label="Filter bots and spam" help="Turn it off to count everything, for example while testing from a script or a server." checked=${site.bot_filter !== false} onChange=${(v) => patch({ bot_filter: v })} />
    ${err && html`<div class="alert">${err}</div>`}
    ${filtered.data && html`<p class="muted small" style="margin:-4px 0 4px 48px">${total === 0 ? 'Nothing was filtered in the last 30 days.' : `Filtered in the last 30 days: ${total.toLocaleString()} (${rows.map((r) => `${r.count.toLocaleString()} ${names[r.reason] || r.reason}`).join(', ')}). Only these counts are kept.`}</p>`}
  <//>`;
}

/** How visits get in: the snippet, its extensions and the rules that drop traffic you don't want counted. */
function Tracking({ site, reload }) {
  const patch = async (body) => { try { await api(`/sites/${site.domain}`, { method: 'PATCH', body }); reload(); toast('Saved'); } catch (x) { toast(firstError(x), 'err'); } };
  return html`<${Section} title="Tracking snippet" help="Place this in the <head> of every page."><${Snippet} site=${site} /><//>
    <${Section} title="Tracking extras" help="Each one adds a small extension to the script and a matching goal.">
      <${Toggle} label="Outbound link clicks" checked=${site.track_outbound} onChange=${(v) => patch({ track_outbound: v })} />
      <${Toggle} label="File downloads" checked=${site.track_downloads} onChange=${(v) => patch({ track_downloads: v })} />
      <${Toggle} label="Form submissions" checked=${site.track_forms} onChange=${(v) => patch({ track_forms: v })} />
      <${Toggle} label="Scroll depth and time on page" help="One extra event per page view as a visitor leaves it: how far they scrolled and how long they were active. It does not change bounce rate." checked=${site.track_engagement} onChange=${(v) => patch({ track_engagement: v })} /><//>
    ${!site.rollup && site.can_manage && html`<${PrivacyControls} site=${site} reload=${reload} />`}
    ${!site.rollup && site.can_manage && html`<${BotFiltering} site=${site} reload=${reload} />`}
    <${Shields} site=${site} />`;
}

/** What the dashboard counts as a conversion. */
const Conversions = ({ site }) => html`<${Goals} site=${site} /><${Funnels} site=${site} /><${EventDocs} site=${site} />`;

/** Who can see the site, and how it looks to them. */
const Access = ({ site, reload }) => html`${!site.rollup && html`<${People} site=${site} />`}<${Visibility} site=${site} reload=${reload} /><${Branding} site=${site} reload=${reload} />${!site.rollup && site.is_owner && html`<${MoveSite} site=${site} reload=${reload} />`}`;

function DataTab({ site, reload }) {
  const [tz, setTz] = useState(site.timezone);
  const [confirm, setConfirm] = useState(null);
  const patch = async (body) => { try { await api(`/sites/${site.domain}`, { method: 'PATCH', body }); reload(); toast('Saved'); } catch (x) { toast(firstError(x), 'err'); } };
  const tzCard = html`<${Section} title="Reporting timezone" help=${site.rollup ? 'Days start and end at midnight in this timezone. It starts as the timezone most of the rollup\'s sites use.' : 'Days start and end at midnight in this timezone.'}
    footer=${html`<${SaveFooter} help="Changing it re-draws the whole history in the new timezone." dirty=${tz !== site.timezone} onSave=${() => patch({ timezone: tz })} />`}>
    <select value=${tz} onChange=${(e) => setTz(e.target.value)} aria-label="Reporting timezone" style="max-width:360px">${zones().map((z) => html`<option value=${z} selected=${z === tz}>${z}</option>`)}</select><//>`;
  if (site.rollup) return html`${tzCard}
    ${site.is_owner && html`<${Section} danger title="Danger zone" help="Removing a rollup never touches the sites it adds up.">
      <div class="dz-row"><div><b>Delete rollup</b><span class="muted">Removes this dashboard and its goals.</span></div><button class="btn danger" onClick=${() => setConfirm('delete')}>Delete rollup</button></div><//>`}
    ${confirm === 'delete' && html`<${TypedConfirm} title=${`Delete ${site.domain}?`} phrase=${site.domain} message="The rollup and its goals will be deleted. The sites it adds up are not affected." confirmLabel="Delete rollup" onClose=${() => setConfirm(null)} onConfirm=${async () => { await api(`/sites/${site.domain}`, { method: 'DELETE' }); navigate('/sites'); }} />`}`;
  return html`${tzCard}
    ${site.can_manage && html`<${MoveData} site=${site} reload=${reload} />`}
    ${site.can_manage && html`<${ImportPlausible} site=${site} reload=${reload} />`}
    ${site.is_owner && html`<${Section} danger title="Danger zone" help="These actions cannot be undone.">
      <div class="dz-row"><div><b>Reset statistics</b><span class="muted">Deletes all collected data but keeps the site.</span></div><button class="btn danger" onClick=${() => setConfirm('reset')}>Reset statistics</button></div>
      <div class="dz-row"><div><b>Delete site</b><span class="muted">Removes the site and all its data permanently.</span></div><button class="btn danger" onClick=${() => setConfirm('delete')}>Delete site</button></div><//>`}
    ${confirm === 'reset' && html`<${TypedConfirm} title="Reset statistics?" phrase=${site.domain} message=${`All collected data for ${site.domain} will be deleted. This can't be undone.`} confirmLabel="Reset statistics" onClose=${() => setConfirm(null)} onConfirm=${async () => { await api(`/sites/${site.domain}/reset`, { method: 'POST' }); toast('Statistics reset'); reload(); }} />`}
    ${confirm === 'delete' && html`<${TypedConfirm} title=${`Delete ${site.domain}?`} phrase=${site.domain} message="The site and all of its data will be permanently deleted." confirmLabel="Delete site" onClose=${() => setConfirm(null)} onConfirm=${async () => { await api(`/sites/${site.domain}`, { method: 'DELETE' }); navigate('/sites'); }} />`}`;
}


const names = (list) => (list.length > 3 ? list.slice(0, 3).join(', ') + ` and ${list.length - 3} more` : list.join(', '));

/** Site settings → Sharing and access → Move to another organization. Shows who gains and loses access before anything happens. */
function MoveSite({ site, reload }) {
  const { user } = useContext(UserCtx);
  const scope = useScope(user);
  const res = useApi(`/sites/${site.domain}/move`, null, [site.domain, site.organization_id]);
  const [to, setTo] = useState('');
  const [confirm, setConfirm] = useState(false);
  const d = res.data;
  if (!d) return res.error ? null : html`<${Section} title="Move to another organization"><${Loading} /><//>`;
  const dest = d.destinations.find((x) => x.id === to);
  const i = dest && dest.impact;
  const label = (o) => (o.personal ? 'Personal' : o.name);
  const lines = i ? [
    i.owner_changes && `${i.new_owner} becomes the owner, so the site counts against their plan from now on.`,
    i.gains_access.length > 0 && `${names(i.gains_access)} gain${i.gains_access.length === 1 ? 's' : ''} access as organization admins.`,
    i.loses_access.length > 0 && `${names(i.loses_access)} lose${i.loses_access.length === 1 ? 's' : ''} the access they had through ${label(d.organization)}.`,
    i.teams.length > 0 && `Shared with ${names(i.teams.map((t) => `${t.name} (${t.members} ${t.members === 1 ? 'person' : 'people'})`))}: unshared, because teams belong to an organization.`,
    i.direct_people_kept > 0 && `The ${i.direct_people_kept} ${i.direct_people_kept === 1 ? 'person' : 'people'} you added to this site directly keep their access.`,
    'Statistics, goals, shared links and alerts move with it.',
  ].filter(Boolean) : [];
  return html`<${Section} title="Move to another organization" help=${`This site is in ${label(d.organization)}. Moving it keeps its data and the people you added to it directly; access that came from the organization (its admins and teams) changes.`}>
    ${d.destinations.length === 0 ? html`<div class="empty-inline"><${Icon} name="shield" size=${18} /><div><b>Nowhere to move it</b><span>You can move a site between organizations you own or administer. Moving it out of an organization with a different owner takes that organization's owner.</span></div></div>`
      : html`<form class="goal-add-row" onSubmit=${(e) => { e.preventDefault(); setConfirm(true); }}><select class="grow" aria-label="Destination organization" value=${to} onChange=${(e) => setTo(e.target.value)}><option value="">Move to…</option>${d.destinations.map((o) => html`<option value=${o.id} selected=${to === o.id}>${label(o)}</option>`)}</select><button class="btn" disabled=${!to}>Review move</button></form>
        ${dest && html`<ul class="muted small" style="margin:0;padding-left:18px;display:grid;gap:4px">${lines.map((l) => html`<li>${l}</li>`)}</ul>`}`}
  <//>
  ${confirm && dest && html`<${Confirm} title=${`Move ${site.domain} to ${label(dest)}?`} confirmLabel="Move site" destructive=${false} message=${lines.join(' ')} onClose=${() => setConfirm(false)} onConfirm=${async () => { await api(`/sites/${site.domain}/move`, { method: 'POST', body: { organization: dest.id } }); scope.set(dest.id); setTo(''); reload(); res.reload(); toast(`Moved to ${label(dest)}`); }} />`}`;
}

/** Leave small groups out of what public dashboards and shared links list. */
function SmallGroups({ site, reload }) {
  const [n, setN] = useState(String(site.min_group_size || 0));
  const [err, setErr] = useState(null);
  const dirty = (parseInt(n, 10) || 0) !== (site.min_group_size || 0);
  const save = async () => { setErr(null); try { await api(`/sites/${site.domain}`, { method: 'PATCH', body: { min_group_size: parseInt(n, 10) || 0 } }); reload(); toast('Saved'); } catch (x) { setErr(firstError(x)); } };
  return html`<${Section} title="Small groups" help="With few visitors, one visit from a rare country or to an obscure page can point at one person. Set a minimum and public dashboards and shared links leave out rows with fewer visitors than that. You and the people on this site still see everything."
    footer=${html`<${SaveFooter} help="Totals and the graph still count everyone. A filter that would narrow a public view down to a smaller group shows no data." dirty=${dirty} onSave=${save} />`}>
    ${err && html`<div class="alert">${err}</div>`}
    <div class="row"><label class="sr-only" for="min-group">Smallest group to show</label><input id="min-group" type="number" min="0" max="1000" value=${n} onInput=${(e) => setN(e.target.value)} style="width:96px" /><span class="muted">visitors (0 turns it off, 5 is a common choice)</span></div><//>`;
}

function Visibility({ site, reload }) {
  const links = useApi(`/sites/${site.domain}/shared-links`, null, []);
  const [adding, setAdding] = useState(false);
  const [del, setDel] = useState(null);
  const setPublic = async (v) => { await api(`/sites/${site.domain}`, { method: 'PATCH', body: { public: v } }); reload(); };
  const url = (l) => `${window.SD.origin}/share/${site.domain}?auth=${l.slug}`;
  return html`<${Section} title="Public dashboard" help="Anyone with the address can view this site's stats, no sign-in needed."><${Toggle} label="Make the dashboard public" checked=${site.public} onChange=${setPublic} />
      ${site.public && html`<${CodeBlock} text=${`${window.SD.origin}/${site.domain}`} />`}<//>
    <${SmallGroups} site=${site} reload=${reload} />
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
  const accent = valid && color ? ((dark ? shown.dark : shown.light) || color) : dark ? '#4fc3be' : '#0e7c86';
  const logo = site.brand && site.brand.logo;
  const off = !b.allowed;
  return html`<${Section} title="Branding" locked=${off} footer=${html`<${SaveFooter} help="Applies to the public dashboard and shared links." dirty=${changed && valid} busy=${busy} onSave=${save} label="Save changes" />`} help="Show your own logo, name and color to people who open the public dashboard or a shared link. A small “Analytics by Tidemark” link always stays in the footer.">
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
  return html`<${Section} title="Funnels" locked=${!allowed} help="A funnel follows visitors through a series of goals, in order, and shows where they drop off. Chain two to eight goals.">
      ${!form && html`<div class="section-actions"><button class="btn primary" disabled=${!allowed || list.length < 2} onClick=${() => open(null)}><${Icon} name="plus" size=${14} /> New funnel</button>${needGoals && html`<span class="muted small">Add at least two goals first.</span>`}</div>`}
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
  return html`<${Section} title="Sites in this rollup" footer=${html`<${SaveFooter} help=${`${count} ${count === 1 ? 'site' : 'sites'} in this rollup.`} dirty=${dirty && (all || chosen.length > 0)} onSave=${save} label="Save changes" />`} help="A rollup adds up the visits of several of your sites on one dashboard. Your sites themselves are never changed.">
    ${err && html`<div class="alert">${err}</div>`}
    <div class="opt-cards" role="radiogroup" aria-label="Which sites">${OPTIONS.map(([v, t, i, d]) => html`<button type="button" role="radio" aria-checked=${all === v} class=${'opt-card' + (all === v ? ' on' : '')} onClick=${() => { setAll(v); if (!v && !chosen.length) setChosen(list.map((x) => x.domain)); }}>
      <span class="ri-ico" aria-hidden="true"><${Icon} name=${i} size=${16} /></span><span class="oc-t"><b>${t}</b><span class="muted">${d}</span></span><span class="oc-dot" aria-hidden="true"></span></button>`)}</div>
    ${all ? html`<ul class="rows" aria-label="Sites in this rollup">${(list.length ? list.map((x) => x.domain) : members).map((d) => html`<li class="rowitem"><span class="ri-ico" aria-hidden="true"><${Icon} name="globe" size=${16} /></span><div class="grow ri-main"><b class="trunc">${d}</b></div><${Icon} name="check" size=${15} class="tick" /></li>`)}</ul>`
      : html`<div class="row spread wrap"><span class="muted small">${chosen.length} of ${list.length} selected</span><div class="row"><button type="button" class="btn sm ghost" onClick=${() => setChosen(list.map((x) => x.domain))}>Select all</button><button type="button" class="btn sm ghost" disabled=${!chosen.length} onClick=${() => setChosen([])}>Clear</button></div></div>
      <ul class="rows" role="group" aria-label="Sites in this rollup">${list.map((x) => html`<li class="rowitem pick"><label class="grow"><input type="checkbox" checked=${chosen.includes(x.domain)} onChange=${(e) => toggle(x.domain, e.target.checked)} /><span class="ri-ico" aria-hidden="true"><${Icon} name="globe" size=${16} /></span><b class="trunc">${x.domain}</b></label></li>`)}</ul>
      ${!list.length && cands.data && html`<div class="empty-inline"><${Icon} name="globe" size=${18} /><div><b>No sites to choose from</b><span>Add a site first, then come back to include it here.</span></div></div>`}`}
    ${dirty && html`<div class="notice"><${Icon} name="info" size=${16} /><div>Changing the list changes the whole history: a site you take out disappears from this dashboard's past as well as its future, and the other way round.</div></div>`}
  <//>`;
}

/** One field to give access: type a name to find one of the organization's teams, or an e-mail address to add or invite a person. */
function AccessAdd({ teams, canInvite, onTeam, onEmail }) {
  const [q, setQ] = useState('');
  const [role, setRole] = useState('viewer');
  const [open, setOpen] = useState(false);
  const [sel, setSel] = useState(0);
  const [busy, setBusy] = useState(false);
  const wrap = useRef();
  const text = q.trim();
  const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text);
  const options = [
    ...teams.filter((t) => !text || t.name.toLowerCase().includes(text.toLowerCase())).slice(0, 6).map((t) => ({ key: 't' + t.id, icon: 'users', title: t.name, sub: `Team · ${t.members} ${t.members === 1 ? 'person' : 'people'} · all of them get access`, run: () => onTeam(t.id, role) })),
    ...(isEmail ? [{ key: 'e', icon: 'mail', title: (canInvite ? 'Add or invite ' : 'Add ') + text, sub: canInvite ? 'Access now, or an e-mail invitation if they have no account' : 'Must already have an account on this install', run: () => onEmail(text, role) }] : []),
  ];
  useEffect(() => {
    if (!open) return;
    const out = (e) => wrap.current && !wrap.current.contains(e.target) && setOpen(false);
    document.addEventListener('mousedown', out);
    return () => document.removeEventListener('mousedown', out);
  }, [open]);
  useEffect(() => setSel(0), [q]);
  const go = async (o) => { if (!o || busy) return; setBusy(true); const ok = await o.run(); if (ok !== false) { setQ(''); setOpen(false); } setBusy(false); };
  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setOpen(true); setSel((n) => Math.min(options.length - 1, n + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setSel((n) => Math.max(0, n - 1)); }
    else if (e.key === 'Escape') setOpen(false);
    else if (e.key === 'Enter') { e.preventDefault(); if (text) go(options[sel]); }
  };
  return html`<div class="access-add"><div class="goal-add-row">
      <div class="pop-wrap grow" ref=${wrap}>
        <input type="text" role="combobox" aria-expanded=${open && options.length > 0} aria-controls="access-opts" autocomplete="off" spellcheck="false" placeholder=${teams.length ? 'Add a person by e-mail, or a team by name' : 'Add a person by e-mail address'} aria-label="Add a person or a team" value=${q} onFocus=${() => setOpen(true)} onInput=${(e) => { setQ(e.target.value); setOpen(true); }} onKeyDown=${onKey} />
        ${open && options.length > 0 && html`<div class="switch-pop" style="width:100%" role="listbox" id="access-opts"><div class="cmd-list">${options.map((o, i) => html`<button type="button" role="option" aria-selected=${i === sel} class=${'cmd-item' + (i === sel ? ' on' : '')} style="min-height:44px" onMouseMove=${() => setSel(i)} onClick=${() => go(o)}>
          <${Icon} name=${o.icon} size=${15} /><span class="ri-main grow"><b class="trunc">${o.title}</b><span class="muted small trunc">${o.sub}</span></span></button>`)}</div></div>`}
      </div>
      <select class="fit" aria-label="Role to give" value=${role} onChange=${(e) => setRole(e.target.value)}><option value="viewer" selected=${role === 'viewer'}>Viewer</option><option value="admin" selected=${role === 'admin'}>Admin</option></select>
      <button type="button" class="btn primary" disabled=${!text || !options.length || busy} onClick=${() => go(options[sel])}>${isEmail && !teams.some((t) => t.name.toLowerCase().includes(text.toLowerCase())) ? (canInvite ? 'Add or invite' : 'Add') : options.length && !isEmail ? 'Add team' : 'Add'}</button>
    </div>
    <span class="muted small">${role === 'admin' ? 'Admins can see stats and change this site\'s settings.' : 'Viewers can see stats but not change anything.'}</span></div>`;
}

/** Site settings → Sharing and access → People with access: one list of everyone who can open the site and how they got access. */
function People({ site }) {
  const res = useApi(`/sites/${site.domain}/people`, null, []);
  const [err, setErr] = useState(null);
  const [open, setOpen] = useState({});
  const d = res.data || {};
  const people = d.data || [];
  const invites = d.invites || [];
  const teams = d.teams || [];
  const orgAdmins = d.organization_admins || [];
  const available = d.available_teams || [];
  const canInvite = !!d.invites_enabled;
  const isOwner = !!site.is_owner;
  const owner = people.find((u) => u.role === 'owner');
  const direct = people.filter((u) => u.role !== 'owner');
  const total = people.length + teams.length + orgAdmins.length + invites.length;
  const run = async (fn) => { setErr(null); try { await fn(); res.reload(); return true; } catch (x) { setErr(firstError(x)); return false; } };
  const left = (iso) => { const h = Math.max(0, Math.round((new Date(iso) - Date.now()) / 3600000)); return h >= 2 ? `expires in ${h} hours` : 'expires within the hour'; };
  const roleSelect = (value, label, onChange) => html`<select class="mini-select" value=${value} aria-label=${label} onChange=${(e) => onChange(e.target.value)}><option value="viewer" selected=${value === 'viewer'}>Viewer</option><option value="admin" selected=${value === 'admin'}>Admin</option></select>`;
  const avatar = (u) => html`<${Avatar} name=${u.name || u.email} url=${u.avatar_url} />`;
  const tag = (t) => html`<span class="pill">${t}</span>`;
  const gap = html`<span class="ri-spacer" aria-hidden="true"></span>`;
  return html`<${Section} title="People with access" help=${res.data ? `${total} ${total === 1 ? 'person or group' : 'people and groups'} can open this site. ${canInvite ? 'Someone without an account gets an e-mail invitation that is valid for 72 hours.' : 'People need an account on this Tidemark install.'}` : 'Who can open this site, and how they got access.'}>
    <${AccessAdd} teams=${isOwner ? available : []} canInvite=${canInvite}
      onEmail=${(email, role) => run(async () => { const r = await api(`/sites/${site.domain}/people`, { method: 'POST', body: { email, role } }); toast(r.invited ? 'Invitation sent' : 'Added'); })}
      onTeam=${(id, role) => run(async () => { await api(`/sites/${site.domain}/teams`, { method: 'POST', body: { team_id: id, role } }); toast('Shared with the team'); })} />
    ${err && html`<div class="alert" role="alert">${err}</div>`}
    ${res.data && html`<ul class="rows" aria-label="People with access">
      ${owner && html`<li class="rowitem">${avatar(owner)}<div class="grow ri-main"><b class="trunc">${owner.name}</b><span class="muted trunc">${owner.email}</span></div><span class="pill brand">Owner</span>${gap}</li>`}
      ${orgAdmins.map((u) => html`<li class="rowitem">${avatar(u)}<div class="grow ri-main"><b class="trunc">${u.name}</b><span class="muted trunc">${u.email} · manages the whole organization</span></div>${tag('Organization admin')}${gap}</li>`)}
      ${teams.map((t) => html`<li class="rowitem access-team" style="flex-wrap:wrap">
        <span class="avatar" aria-hidden="true"><${Icon} name="users" size=${14} /></span>
        <div class="grow ri-main"><b class="trunc">${t.name}</b><span class="muted trunc">Team · ${t.members} ${t.members === 1 ? 'person' : 'people'}</span></div>
        <button class="btn ghost sm" aria-expanded=${!!open[t.id]} onClick=${() => setOpen({ ...open, [t.id]: !open[t.id] })}>${open[t.id] ? 'Hide' : 'Show'} people</button>
        ${isOwner ? html`${roleSelect(t.role, 'Role of team ' + t.name, (v) => run(() => api(`/sites/${site.domain}/teams/${t.id}`, { method: 'PATCH', body: { role: v } })))}
          <button class="btn ghost icon sm danger" aria-label=${'Stop sharing with ' + t.name} title="Stop sharing with this team" onClick=${() => run(() => api(`/sites/${site.domain}/teams/${t.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button>`
          : html`${tag(t.role === 'admin' ? 'Admin' : 'Viewer')}${gap}`}
        ${open[t.id] && html`<ul class="muted small" style="flex-basis:100%;margin:0 0 6px 40px;padding:0;list-style:none;display:flex;flex-wrap:wrap;gap:4px 14px">${t.member_names.length ? t.member_names.map((n) => html`<li>${n}</li>`) : html`<li>Nobody on this team yet.</li>`}${t.members > t.member_names.length && html`<li>and ${t.members - t.member_names.length} more</li>`}</ul>`}</li>`)}
      ${direct.map((u) => html`<li class="rowitem">${avatar(u)}<div class="grow ri-main"><b class="trunc">${u.name}</b><span class="muted trunc">${u.email}</span></div>
        ${roleSelect(u.role, 'Role of ' + u.name, (v) => run(() => api(`/sites/${site.domain}/people/${u.id}`, { method: 'PATCH', body: { role: v } })))}
        <button class="btn ghost icon sm danger" aria-label=${'Remove ' + u.name} title="Remove from this site" onClick=${() => run(() => api(`/sites/${site.domain}/people/${u.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button></li>`)}
      ${invites.map((i) => html`<li class="rowitem"><span class="avatar" aria-hidden="true"><${Icon} name="mail" size=${14} /></span><div class="grow ri-main"><b class="trunc">${i.email}</b><span class="muted trunc">Invited as ${i.role === 'admin' ? 'admin' : 'viewer'} · ${left(i.expires_at)}</span></div>
        <button class="btn ghost sm" disabled=${!canInvite} onClick=${() => run(async () => { await api(`/sites/${site.domain}/invites/${i.id}/resend`, { method: 'POST' }); toast('Invitation sent again'); })}>Resend</button>
        <button class="btn ghost icon sm danger" aria-label=${'Cancel invitation for ' + i.email} title="Cancel invitation" onClick=${() => run(() => api(`/sites/${site.domain}/invites/${i.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button></li>`)}
    </ul>
    <p class="muted small" style="margin:0">Someone who gets access in more than one way (directly, through a team or as an organization admin) has the strongest role they were given.${isOwner ? ' Manage teams under Settings → Teams.' : ''}</p>`}
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
  const tabs = s.rollup ? [['sites', 'Sites'], ['conversions', 'Conversions'], ['access', 'Sharing'], ['data', 'Data']] : [['tracking', 'Tracking'], ['conversions', 'Conversions'], ['alerts', 'Alerts'], ['access', 'Sharing and access'], ['data', 'Data']];
  const moved = { general: s.rollup ? 'data' : 'tracking', shields: 'tracking', goals: 'conversions', funnels: 'conversions', visibility: 'access', branding: 'access', people: 'access' };
  const t = [tab, moved[tab]].find((k) => tabs.some((x) => x[0] === k)) || tabs[0][0];
  const Body = { tracking: Tracking, conversions: Conversions, alerts: Alerts, access: Access, data: DataTab, sites: RollupSites }[t];
  return html`<${SettingsShell} title=${`${domain} settings`} back=${[`/${domain}`, 'Dashboard']} tabs=${tabs} tab=${t} base=${`/${domain}/settings`}><${Body} site=${s} reload=${res.reload} /><//>`;
}

// -------------------------------------------------------------------------------------- alerts
const ALERT_TYPES = {
  traffic_spike: { label: 'Traffic spike', icon: 'trending-up', help: 'Visitors so far today are well above the usual for this weekday and time of day.' },
  traffic_drop: { label: 'Traffic drop', icon: 'trending-down', help: 'Visitors so far today are well below the usual for this weekday and time of day (after 06:00).' },
  goal_milestone: { label: 'Goal milestone', icon: 'target', help: 'A goal reaches a number of conversions today. Once a day at most.' },
  goal_conversions: { label: 'New conversions', icon: 'mouse-pointer-click', help: 'A goal was reached since the last check (every five minutes), with totals only: no visitor details.' },
  new_top_referrer: { label: 'New traffic source', icon: 'globe', help: 'A source that sent nothing in the last 28 days is suddenly sending visitors.' },
};
const ALERT_DEFAULTS = { traffic_spike: { percent: 100, min_visitors: 50 }, traffic_drop: { percent: 50, min_visitors: 50 }, goal_milestone: { count: 10 }, goal_conversions: {}, new_top_referrer: { min_visitors: 20 } };

function describeRule(r, goals) {
  const c = r.config || {};
  const goal = (goals.find((g) => g.id === c.goal_id) || { name: 'a deleted goal' }).name;
  return {
    traffic_spike: `Visitors ${c.percent}% above the usual, at least ${c.min_visitors} more`,
    traffic_drop: `Visitors ${c.percent}% below the usual, when the usual is ${c.min_visitors}+ visitors`,
    goal_milestone: `“${goal}” reaches ${c.count} conversions in a day`,
    goal_conversions: `New conversions of “${goal}”`,
    new_top_referrer: `A new source sends ${c.min_visitors}+ visitors in a day`,
  }[r.type];
}

function RuleForm({ site, goals, hooks, mail, rule, onSaved, onCancel }) {
  const [f, setF] = useState(rule ? { ...rule, quiet: !!rule.quiet_start } : { type: 'traffic_spike', config: ALERT_DEFAULTS.traffic_spike, email: mail, webhook_ids: [], quiet: false, quiet_start: '22:00', quiet_end: '07:00', enabled: true });
  const [err, setErr] = useState(null);
  const c = f.config;
  const setC = (k, v) => setF({ ...f, config: { ...c, [k]: v } });
  const num = (k, label, help, min, max) => html`<${Field} label=${label} help=${help}><input type="number" min=${min} max=${max} required value=${c[k]} onInput=${(e) => setC(k, e.target.value === '' ? '' : Number(e.target.value))} /><//>`;
  const needsGoal = f.type === 'goal_milestone' || f.type === 'goal_conversions';
  const toggleHook = (id, on) => setF({ ...f, webhook_ids: on ? [...f.webhook_ids, id] : f.webhook_ids.filter((x) => x !== id) });
  const save = async (e) => {
    e.preventDefault(); setErr(null);
    const body = { type: f.type, config: c, email: f.email, webhook_ids: f.webhook_ids, quiet_start: f.quiet ? f.quiet_start : null, quiet_end: f.quiet ? f.quiet_end : null, enabled: f.enabled };
    try { await api(`/sites/${site.domain}/alert-rules${rule ? '/' + rule.id : ''}`, { method: rule ? 'PATCH' : 'POST', body }); toast('Alert saved'); onSaved(); } catch (x) { setErr(firstError(x)); }
  };
  return html`<form class="funnel-form" onSubmit=${save}>
    ${err && html`<div class="alert">${err}</div>`}
    <div class="steps-edit"><span class="lbl">When</span>
      <div class="seg wrapseg" role="radiogroup" aria-label="Alert type">${Object.entries(ALERT_TYPES).map(([k, t]) => html`<button type="button" role="radio" aria-checked=${f.type === k} class=${f.type === k ? 'on' : ''} disabled=${!!rule && f.type !== k} onClick=${() => setF({ ...f, type: k, config: ALERT_DEFAULTS[k] })}><${Icon} name=${t.icon} size=${14} />${t.label}</button>`)}</div>
      <span class="muted small">${ALERT_TYPES[f.type].help}</span></div>
    ${needsGoal && html`<${Field} label="Goal"><select required value=${c.goal_id || ''} onChange=${(e) => setC('goal_id', e.target.value)}><option value="" selected=${!c.goal_id}>Choose a goal</option>${goals.map((g) => html`<option value=${g.id} selected=${g.id === c.goal_id}>${g.name}</option>`)}</select><//>`}
    <div class="alert-nums">
      ${(f.type === 'traffic_spike' || f.type === 'traffic_drop') && num('percent', f.type === 'traffic_spike' ? 'Percent above the usual' : 'Percent below the usual', null, 10, f.type === 'traffic_spike' ? 1000 : 95)}
      ${f.type === 'traffic_spike' && num('min_visitors', 'At least this many more visitors', null, 1, 100000)}
      ${f.type === 'traffic_drop' && num('min_visitors', 'Only when the usual is at least', null, 1, 100000)}
      ${f.type === 'goal_milestone' && num('count', 'Conversions in a day', null, 1, 1000000)}
      ${f.type === 'new_top_referrer' && num('min_visitors', 'Visitors from the source today', null, 1, 100000)}
    </div>
    <div class="steps-edit"><span class="lbl">Send it to</span>
      <${Toggle} label="Email" help=${mail ? 'The site\'s owners and admins' : 'Email isn\'t set up on this installation yet'} checked=${f.email} disabled=${!mail && !f.email} onChange=${(v) => setF({ ...f, email: v })} />
      ${hooks.map((h) => html`<${Toggle} key=${h.id} label=${h.name} help=${({ generic: 'Signed JSON webhook', slack: 'Slack', discord: 'Discord' })[h.kind]} checked=${f.webhook_ids.includes(h.id)} onChange=${(v) => toggleHook(h.id, v)} />`)}
      ${hooks.length === 0 && html`<span class="muted small">Add a webhook below to send alerts to Slack, Discord or your own service.</span>`}</div>
    <div class="steps-edit alert-quiet"><span class="lbl">Quiet hours</span><${Toggle} label="Don't send during set hours" help="In the site's timezone. Anything still true afterwards is sent then." checked=${f.quiet} onChange=${(v) => setF({ ...f, quiet: v })} />
      ${f.quiet && html`<div class="alert-nums"><${Field} label="From"><input type="time" required value=${f.quiet_start} onInput=${(e) => setF({ ...f, quiet_start: e.target.value })} /><//><${Field} label="Until"><input type="time" required value=${f.quiet_end} onInput=${(e) => setF({ ...f, quiet_end: e.target.value })} /><//></div>`}</div>
    <div class="row"><button class="btn primary">Save alert</button><button type="button" class="btn" onClick=${onCancel}>Cancel</button></div></form>`;
}

function Alerts({ site }) {
  const res = useApi(`/sites/${site.domain}/alerts`, null, []);
  const goalsRes = useApi(`/sites/${site.domain}/goals`, null, []);
  const [form, setForm] = useState(null); // 'new' or the rule being edited
  const [hook, setHook] = useState({ name: '', kind: 'generic', url: '' });
  const [secret, setSecret] = useState(null);
  const [err, setErr] = useState(null);
  const [testing, setTesting] = useState(null);
  const d = res.data;
  const locked = !!d && d.allowed === false;
  const rules = (d && d.rules) || [];
  const hooks = (d && d.webhooks) || [];
  const goals = (goalsRes.data && goalsRes.data.data) || [];
  const base = `/sites/${site.domain}`;
  const act = async (fn, msg) => { setErr(null); try { await fn(); if (msg) toast(msg); res.reload(); } catch (x) { setErr(firstError(x)); } };
  const addHook = (e) => { e.preventDefault(); act(async () => { const r = await api(`${base}/alert-webhooks`, { method: 'POST', body: hook }); setHook({ name: '', kind: 'generic', url: '' }); setSecret(r.data.secret ? { name: r.data.name, value: r.data.secret } : null); }, 'Webhook added'); };
  const test = async (h) => { setTesting(h.id); setErr(null); try { const r = await api(`${base}/alert-webhooks/${h.id}/test`, { method: 'POST' }); toast(r.ok ? 'Test alert delivered' : 'Not delivered: ' + (r.error || 'HTTP ' + r.status), r.ok ? undefined : 'err'); res.reload(); } catch (x) { setErr(firstError(x)); } setTesting(null); };
  return html`<${Section} title="Alerts" locked=${locked} lockNote=${rules.length ? 'Your existing alerts are paused until then.' : ''} help="Get told when something is worth a look: a spike or drop in visitors, a goal reaching a number, or a brand new traffic source. Alerts carry totals only, never anything about a visitor. Rules are checked every five minutes.">
      ${d && !d.mail && !locked && html`<div class="notice"><${Icon} name="info" size=${16} /><div>Email isn't set up on this installation, so alerts can only go to webhooks for now.</div></div>`}
      ${err && html`<div class="alert">${err}</div>`}
      ${form ? html`<${RuleForm} site=${site} goals=${goals} hooks=${hooks} mail=${!!(d && d.mail)} rule=${form === 'new' ? null : form} onSaved=${() => { setForm(null); res.reload(); }} onCancel=${() => setForm(null)} />`
        : html`<div class="section-actions"><button class="btn primary" disabled=${locked} onClick=${() => setForm('new')}><${Icon} name="plus" size=${14} /> New alert</button></div>`}
      ${locked && rules.length === 0 && html`<ul class="rows">${[['trending-up', 'Traffic spike', 'Visitors 100% above the usual'], ['target', 'Goal milestone', 'A goal reaches 25 conversions in a day']].map(([i, t, m]) => html`<li class="rowitem"><span class="ri-ico"><${Icon} name=${i} size=${16} /></span><div class="grow ri-main"><b>${t}</b><span class="muted">${m}</span></div></li>`)}</ul>`}
      ${d && rules.length === 0 && !form && !locked && html`<div class="empty-inline"><${Icon} name="bell" size=${18} /><div><b>No alerts yet</b><span>Create one to be told when traffic jumps or drops, or when a goal is reached.</span></div></div>`}
      ${rules.length > 0 && html`<ul class="rows" aria-label="Alerts">${rules.map((r) => html`<li class="rowitem" key=${r.id}>
        <span class="ri-ico" aria-hidden="true"><${Icon} name=${ALERT_TYPES[r.type].icon} size=${16} /></span>
        <div class="grow ri-main"><b class="trunc">${ALERT_TYPES[r.type].label}</b><span class="muted">${describeRule(r, goals)} · ${[r.email && 'email', ...r.webhook_ids.map((id) => (hooks.find((h) => h.id === id) || {}).name).filter(Boolean)].filter(Boolean).join(', ') || 'no destination'}${r.quiet_start ? ` · quiet ${r.quiet_start}–${r.quiet_end}` : ''}${r.last_fired_at ? ' · last sent ' + ago(r.last_fired_at) : ''}</span></div>
        <select class="mini-select" style="width:auto" aria-label=${'Turn ' + ALERT_TYPES[r.type].label + ' on or off'} onChange=${(e) => act(() => api(`${base}/alert-rules/${r.id}`, { method: 'PATCH', body: { enabled: e.target.value === 'on' } }))}><option value="on" selected=${r.enabled}>On</option><option value="off" selected=${!r.enabled}>Off</option></select>
        <button class="btn ghost icon sm" disabled=${locked} aria-label="Edit alert" title="Edit" onClick=${() => setForm(r)}><${Icon} name="pencil" size=${15} /></button>
        <button class="btn ghost icon sm danger" aria-label="Delete alert" title="Delete" onClick=${() => act(() => api(`${base}/alert-rules/${r.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button></li>`)}</ul>`}
    <//>
    <${Section} title="Webhooks" locked=${locked} help="Where alerts go besides email. Slack and Discord take their incoming-webhook address. Your own service gets a JSON POST signed with a secret, so you can check it came from Tidemark.${d && d.private_allowed ? '' : ' Addresses must be public https URLs.'}">
      <form class="goal-add" onSubmit=${addHook}><fieldset disabled=${locked} style="border:0;padding:0;margin:0;display:contents">
        <div class="seg" role="radiogroup" aria-label="Webhook type">${[['generic', 'Signed JSON'], ['slack', 'Slack'], ['discord', 'Discord']].map(([k, l]) => html`<button type="button" role="radio" aria-checked=${hook.kind === k} class=${hook.kind === k ? 'on' : ''} onClick=${() => setHook({ ...hook, kind: k })}>${l}</button>`)}</div>
        <div class="goal-add-row">
          <input required maxlength="80" placeholder="Name, e.g. Team chat" style="width:180px" value=${hook.name} onInput=${(e) => setHook({ ...hook, name: e.target.value })} aria-label="Webhook name" />
          <input class="grow" required type="url" placeholder=${({ generic: 'https://example.com/hooks/tidemark', slack: 'https://hooks.slack.com/services/…', discord: 'https://discord.com/api/webhooks/…' })[hook.kind]} value=${hook.url} onInput=${(e) => setHook({ ...hook, url: e.target.value })} aria-label="Webhook address" />
          <button class="btn primary">Add webhook</button></div></fieldset></form>
      ${secret && html`<div class="notice"><${Icon} name="info" size=${16} /><div><b>Signing secret for ${secret.name}</b>: copy it now, it is not shown again.<${CodeBlock} text=${secret.value} />
        <p class="muted small">Each request has a <code>Tidemark-Signature: t=timestamp,v1=signature</code> header. The signature is the hex HMAC-SHA256 of <code>timestamp.body</code> with this secret.</p>
        <button class="btn sm" onClick=${() => setSecret(null)}>Done</button></div></div>`}
      ${hooks.length > 0 && html`<ul class="rows" aria-label="Webhooks">${hooks.map((h) => html`<li class="rowitem" key=${h.id}>
        <span class="ri-ico" aria-hidden="true"><${Icon} name="send" size=${16} /></span>
        <div class="grow ri-main"><b class="trunc">${h.name}</b><span class="muted trunc">${({ generic: 'Signed JSON', slack: 'Slack', discord: 'Discord' })[h.kind]} · ${h.url}${h.last_at ? (h.last_error ? ` · last delivery failed (${h.last_error})` : ' · last delivery ok ' + ago(h.last_at)) : ''}</span></div>
        <button class="btn sm" disabled=${locked || testing === h.id} onClick=${() => test(h)}>Send test</button>
        <button class="btn ghost icon sm danger" aria-label=${'Delete webhook ' + h.name} title="Delete" onClick=${() => act(() => api(`${base}/alert-webhooks/${h.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button></li>`)}</ul>`}
    <//>`;
}

// -------------------------------------------------------------------------------------- account settings
const initial = (u) => (u.name || u.email || '?').slice(0, 1).toUpperCase();

/** A picture with Upload and Remove: a profile photo (round) or an organization logo (square). The page crops it to a square and shrinks it first. */
function ImageField({ label, name, url, shape, path, type, onChange, hint }) {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const pick = async (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    setBusy(true); setErr(null);
    try {
      const body = new FormData();
      body.append('image', await squareImage(file, { type }), 'image');
      const r = await api(path, { method: 'POST', body });
      onChange(r.avatar_url !== undefined ? r.avatar_url : r.logo_url);
      toast(label + ' updated');
    } catch (x) { setErr(firstError(x)); }
    setBusy(false);
  };
  const remove = async () => { setBusy(true); setErr(null); try { const r = await api(path, { method: 'DELETE' }); onChange(r.avatar_url !== undefined ? r.avatar_url : r.logo_url); toast(label + ' removed'); } catch (x) { setErr(firstError(x)); } setBusy(false); };
  return html`<div class="image-field-wrap"><div class="image-field"><${Avatar} size="huge" name=${name} url=${url} shape=${shape} />
      <div class="ri-main"><b>${label}</b>
        <div class="row"><label class=${'btn sm' + (busy ? ' disabled' : '')}>${url ? 'Replace' : 'Upload'}<input type="file" accept="image/png,image/jpeg,image/webp" hidden disabled=${busy} onChange=${pick} /></label>${url && html`<button type="button" class="btn sm ghost danger" disabled=${busy} onClick=${remove}>Remove</button>`}</div></div></div>
    <span class="help">${hint}</span>${err && html`<span class="help" style="color:var(--destructive)">${err}</span>`}</div>`;
}

function Profile() {
  const { user, setUser } = useContext(UserCtx);
  const [f, setF] = useState({ name: user.name, email: user.email, timezone: user.timezone || 'UTC' });
  const [err, setErr] = useState(null);
  const [theme, setThemeState] = useState(currentTheme());
  const changed = f.name !== user.name || f.email !== user.email || f.timezone !== (user.timezone || 'UTC');
  const save = async (e) => { e.preventDefault(); setErr(null); try { const r = await api('/me', { method: 'POST', body: f }); setUser({ ...user, ...r.user }); toast('Saved'); } catch (x) { setErr(firstError(x)); } };
  const pick = (t) => { setTheme(t); setThemeState(t); };
  const THEMES = [['light', 'Light'], ['dark', 'Dark'], ['system', 'System']];
  return html`<form onSubmit=${save}><${Section} title="Profile" help="Your name and email are used to sign in and are shown to people you share sites with."
      footer=${html`<span class="help">${changed ? 'You have unsaved changes.' : 'Your email is also where password resets are sent.'}</span><button class="btn primary sm" disabled=${!changed}>Save changes</button>`}>
        <${ImageField} label="Profile photo" name=${f.name} url=${user.avatar_url} shape="user" path="/me/avatar" type="image/jpeg" onChange=${(u) => setUser({ ...user, avatar_url: u })} hint="A square picture works best; it is cropped to a circle. PNG, JPEG or WebP. People you share sites with, and your Personal organization, show it." />
        ${err && html`<div class="alert">${err}</div>`}
        <div class="fields2"><${Field} label="Name"><input required value=${f.name} onInput=${(e) => setF({ ...f, name: e.target.value })} /><//>
          <${Field} label="Email"><input type="email" required value=${f.email} onInput=${(e) => setF({ ...f, email: e.target.value })} /><//></div>
        <${Field} label="Timezone" help="Used for dates in your own views. Each site's reports use that site's own timezone." class="tzfield"><select value=${f.timezone} onChange=${(e) => setF({ ...f, timezone: e.target.value })}>${zones().map((z) => html`<option value=${z} selected=${z === f.timezone}>${z}</option>`)}</select><//>
      <//></form>
    <${Section} title="Appearance" help="System follows your device and switches with it.">
      <div class="theme-opts" role="radiogroup" aria-label="Theme">${THEMES.map(([k, l]) => html`<button type="button" role="radio" aria-checked=${theme === k} class=${'theme-opt ' + k + (theme === k ? ' on' : '')} onClick=${() => pick(k)}><span class="tp" aria-hidden="true"><i></i><i></i><i></i></span><span>${l}</span></button>`)}</div><//>`;
}

function EmailReports() {
  const res = useApi('/me/reports', null, []);
  const [busy, setBusy] = useState(null);
  const sites = (res.data && res.data.data) || [];
  const choose = async (s, frequency) => {
    setBusy(s.id);
    try { await api('/me/reports/' + s.id, { method: 'PUT', body: { frequency } }); res.reload(); toast(frequency === 'off' ? 'Reports turned off for ' + s.domain : (frequency === 'weekly' ? 'Weekly' : 'Monthly') + ' reports on for ' + s.domain); } catch (x) { toast(firstError(x), 'err'); }
    setBusy(null);
  };
  return html`<${Section} title="Email reports" help="A short summary of each site: visitors, pageviews, top pages, sources and countries, goals and the notes on your graph, compared with the period before. Weekly reports arrive on Monday morning and monthly ones on the 1st, at 08:00 in each site's timezone.">
      ${res.data && !res.data.mail && html`<div class="alert">This installation can't send email yet. Reports will start going out once outgoing email is set up (see MAIL_MAILER in the environment file).</div>`}
      ${res.loading && !res.data ? html`<p class="muted">Loading…</p>` : sites.length === 0 ? html`<p class="muted">You don't have any sites yet.</p>`
        : html`<div class="rows">${sites.map((s) => html`<div class="rowitem boxed" key=${s.id}><span class="ri-ico" aria-hidden="true"><${Icon} name="mail" size=${16} /></span>
          <div class="grow ri-main"><b>${s.domain}</b><span class="muted">${s.frequency === 'weekly' ? 'Every Monday' : s.frequency === 'monthly' ? 'On the 1st of each month' : 'No email'}</span></div>
          <select style="width:auto;flex:none" aria-label=${'Email reports for ' + s.domain} disabled=${busy === s.id} value=${s.frequency || 'off'} onChange=${(e) => choose(s, e.target.value)}>
            <option value="off" selected=${!s.frequency}>Off</option><option value="weekly" selected=${s.frequency === 'weekly'}>Weekly</option><option value="monthly" selected=${s.frequency === 'monthly'}>Monthly</option></select></div>`)}</div>`}
      <p class="muted small">The emails hold only totals, with no tracking pixels or tracked links, and every one has an unsubscribe link.</p><//>`;
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
  return html`<form onSubmit=${changePw}><${Section} title="Password" help="Use at least 8 characters."
      footer=${html`<span class="help">${mismatch ? 'The passwords don\'t match yet.' : 'Other signed-in devices stay signed in.'}</span><button class="btn primary sm" disabled=${!pw.current_password || !pw.password || mismatch}>Update password</button>`}>
        ${err && html`<div class="alert">${err}</div>`}
        <${Field} label="Current password" class="tzfield"><input type="password" required autocomplete="current-password" value=${pw.current_password} onInput=${(e) => setPw({ ...pw, current_password: e.target.value })} /><//>
        <div class="fields2"><${Field} label="New password"><input type="password" required minlength="8" autocomplete="new-password" value=${pw.password} onInput=${(e) => setPw({ ...pw, password: e.target.value })} /><//>
          <${Field} label="Confirm new password"><input type="password" required autocomplete="new-password" value=${pw.password_confirmation} onInput=${(e) => setPw({ ...pw, password_confirmation: e.target.value })} /><//></div>
      <//></form>
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
`;
}

export function ApiKeys() {
  const res = useApi('/me/keys', null, []);
  const [name, setName] = useState('');
  const [scope, setScope] = useState('read');
  const [token, setToken] = useState(null);
  const [err, setErr] = useState(null);
  const keys = (res.data && res.data.data) || [];
  const origin = window.location.origin;
  const create = async (e) => { e.preventDefault(); setErr(null); try { const r = await api('/me/keys', { method: 'POST', body: { name, scope } }); setToken({ value: r.token, scope: r.data.scope }); setName(''); res.reload(); } catch (x) { setErr(firstError(x)); } };
  const revoke = async (k) => { setErr(null); try { await api('/me/keys/' + k.id, { method: 'DELETE' }); res.reload(); } catch (x) { setErr(firstError(x)); } };
  const mcp = JSON.stringify({ mcpServers: { tidemark: { url: origin + '/api/mcp', headers: { Authorization: 'Bearer ' + (token ? token.value : '<your key>') } } } }, null, 2);
  return html`<${Section} title="API keys" help="Use a key as a Bearer token with the Stats API (/api/v1/stats/…) or to connect an AI assistant through MCP. A read-only key can only read statistics; a full-access key has the same access as you.">
    <form class="goal-add" onSubmit=${create}>
      <div class="seg" role="radiogroup" aria-label="Key access">${[['read', 'Read-only', 'eye'], ['full', 'Full access', 'key']].map(([k, l, i]) => html`<button type="button" role="radio" aria-checked=${scope === k} class=${scope === k ? 'on' : ''} onClick=${() => setScope(k)}><${Icon} name=${i} size=${14} />${l}</button>`)}</div>
      <div class="goal-add-row"><input class="grow" required placeholder="Key name, e.g. Claude or Reporting script" aria-label="Key name" value=${name} onInput=${(e) => setName(e.target.value)} /><button class="btn primary" disabled=${!name.trim()}>Create key</button></div></form>
    ${token && html`<div class="notice"><${Icon} name="key" size=${16} /><div><b>Copy your key now. It won't be shown again.</b><${CodeBlock} text=${token.value} /></div></div>`}
    ${err && html`<div class="alert">${err}</div>`}
    ${res.data && !keys.length && html`<div class="empty-inline"><${Icon} name="key" size=${18} /><div><b>No keys yet</b><span>Create one to read your stats from a script or an AI assistant.</span></div></div>`}
    ${keys.length > 0 && html`<ul class="rows" aria-label="API keys">${keys.map((k) => html`<li class="rowitem">
      <span class="ri-ico" aria-hidden="true"><${Icon} name="key" size=${16} /></span>
      <div class="grow ri-main"><b class="trunc">${k.name}</b><span class="muted">${k.scope === 'read' ? 'Read-only' : 'Full access'} · ${k.last_used_at ? 'last used ' + ago(k.last_used_at.replace('T', ' ').slice(0, 19)) : 'never used'}</span></div>
      <button class="btn ghost icon sm danger" aria-label=${'Revoke key ' + k.name} title="Revoke key" onClick=${() => revoke(k)}><${Icon} name="trash-2" size=${15} /></button></li>`)}</ul>`}
  <//>
  <${Section} title="Connect an AI assistant" help="Tidemark has a read-only MCP server, so assistants such as Claude can answer questions about your stats. Add this to the assistant's MCP settings with a key from above. It can read totals, trends, top pages and sources, goals and funnels for your sites, and never anything about a single visitor.">
    <${CodeBlock} text=${mcp} />
    <p class="muted small">The Stats API and MCP are described in an <a href="/api/v1/openapi.json" target="_blank" rel="noopener">OpenAPI document</a> you can feed to any API client or code generator.</p>
  <//>`;
}

/** Settings → Teams. A list of teams; opening one shows its people and the sites it can open (the role is chosen per site). */
export function Teams() {
  const { user } = useContext(UserCtx);
  const scope = useScope(user);
  const { query } = useLocation();
  const usable = scope.org && !scope.personal && !scope.shared;
  const res = useApi('/teams', { organization: scope.id }, [scope.id], { enabled: !!usable });
  const teams = (res.data && res.data.data) || [];
  if (!usable) return html`<${NeedsOrganization} scope=${scope} title="Teams" what="Teams group the people in an organization so you can share a site with all of them at once." />`;
  const open = query.team && teams.find((t) => t.id === query.team);
  if (query.team && res.data && !open) navigate('/settings/teams', { replace: true });
  return open ? html`<${TeamDetail} key=${open.id} team=${open} owned=${res.data.owned_sites} canInvite=${!!res.data.invites_enabled} reload=${res.reload} />`
    : html`<${TeamList} res=${res} teams=${teams} org=${scope.org} />`;
}

/** Personal (and "Shared with me") have no admins or teams: say so, and offer the way out, creating an organization. */
function NeedsOrganization({ scope, title, what }) {
  const { setUser } = useContext(UserCtx);
  const [creating, setCreating] = useState(false);
  return html`<${Section} title=${title} help=${scope.shared ? 'Sites shared with you one by one are not part of an organization you belong to.' : 'This is your Personal organization: just you. It has no admins or teams and can\'t be renamed, transferred or deleted.'}>
    <p class="muted small" style="margin:0">${what} To work with other people, switch to an organization in the top bar or create one.</p>
    <div class="section-actions"><button class="btn primary" onClick=${() => setCreating(true)}><${Icon} name="plus" size=${14} /> Create organization</button></div>
  <//>
  ${creating && html`<${CreateOrganization} onClose=${() => setCreating(false)} onCreated=${async (id) => { try { setUser((await api('/me')).user); } catch (e) { /* refreshed on next load */ } scope.set(id); setCreating(false); navigate('/settings/organization'); }} />`}`;
}

const peopleCount = (t) => t.members.length;

function TeamList({ res, teams, org }) {
  const [adding, setAdding] = useState(false);
  const [name, setName] = useState('');
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const create = async (e) => {
    e.preventDefault(); setErr(null); setBusy(true);
    try { const r = await api('/teams', { method: 'POST', body: { name, organization_id: org.id } }); setName(''); setAdding(false); navigate('/settings/teams?team=' + r.data.id); } catch (x) { setErr(firstError(x)); setBusy(false); }
  };
  return html`<${Section} title="Teams" help=${`Teams in ${org.name}: group people once, then share a site with the whole group as viewers or admins. Adding someone to a team gives them access to every site the team can open.`}>
    ${!adding && html`<div class="section-actions"><button class="btn primary" onClick=${() => setAdding(true)}><${Icon} name="plus" size=${14} /> New team</button></div>`}
    ${adding && html`<form class="goal-add" onSubmit=${create}>${err && html`<div class="alert">${err}</div>`}
      <${Field} label="Team name"><input required autofocus maxlength="80" placeholder="For example Marketing or Client: Acme" value=${name} onInput=${(e) => setName(e.target.value)} /><//>
      <div class="row"><button class="btn primary" disabled=${!name.trim() || busy}>Create team</button><button type="button" class="btn" onClick=${() => { setAdding(false); setErr(null); setName(''); }}>Cancel</button></div></form>`}
    ${res.data && teams.length === 0 && !adding && html`<div class="empty-inline"><${Icon} name="users" size=${18} /><div><b>No teams yet</b><span>Create a team for the people who need the same sites, such as a marketing team or a client's staff, then share your sites with it in one step.</span></div></div>`}
    ${teams.length > 0 && html`<ul class="rows" aria-label="Teams">${teams.map((t) => html`<li class="rowitem pick"><${Link} to=${'/settings/teams?team=' + t.id} class="team-row">
      <span class="avatar" aria-hidden="true"><${Icon} name="users" size=${14} /></span>
      <div class="grow ri-main"><b class="trunc">${t.name}</b><span class="muted trunc">${peopleCount(t)} ${peopleCount(t) === 1 ? 'person' : 'people'} · ${t.sites.length ? `${t.sites.length} ${t.sites.length === 1 ? 'site' : 'sites'}` : 'no sites yet'}${t.invites.length ? ` · ${t.invites.length} waiting to accept` : ''}</span></div>
      <${Icon} name="chevron-right" size=${16} class="muted" /><//></li>`)}</ul>`}
  <//>`;
}

function TeamDetail({ team, owned, canInvite, reload }) {
  const [email, setEmail] = useState('');
  const [name, setName] = useState(team.name);
  const [share, setShare] = useState({ domain: '', role: 'viewer' });
  const [err, setErr] = useState(null);
  const [del, setDel] = useState(false);
  const run = async (fn, ok) => { setErr(null); try { await fn(); reload(); if (ok) toast(ok); } catch (x) { setErr(firstError(x)); } };
  const left = (iso) => { const h = Math.max(0, Math.round((new Date(iso) - Date.now()) / 3600000)); return h >= 2 ? `expires in ${h} hours` : 'expires within the hour'; };
  const available = owned.filter((s) => s.organization_id === team.organization_id && !team.sites.some((x) => x.domain === s.domain)).map((s) => s.domain);
  const add = (e) => { e.preventDefault(); run(async () => { const r = await api(`/teams/${team.id}/members`, { method: 'POST', body: { email } }); setEmail(''); toast(r.invited ? 'Invitation sent' : 'Added to the team'); }); };
  const rename = (e) => { e.preventDefault(); run(() => api('/teams/' + team.id, { method: 'PATCH', body: { name } }), 'Team renamed'); };
  const addSite = (e) => { e.preventDefault(); run(async () => { await api(`/sites/${share.domain}/teams`, { method: 'POST', body: { team_id: team.id, role: share.role } }); setShare({ ...share, domain: '' }); }, 'Shared with the team'); };
  const ROLES = [['viewer', 'Viewer'], ['admin', 'Admin']];
  return html`<${Link} to="/settings/teams" class="side-back" style="align-self:flex-start"><${Icon} name="chevron-left" size=${15} /><span>All teams</span><//>
    ${err && html`<div class="alert" role="alert">${err}</div>`}
    <${Section} title="Team name" help=${`${peopleCount(team)} ${peopleCount(team) === 1 ? 'person' : 'people'}, ${team.sites.length ? `${team.sites.length} ${team.sites.length === 1 ? 'site' : 'sites'}` : 'no sites yet'}.`}>
      <form class="goal-add-row" onSubmit=${rename}><input class="grow" required maxlength="80" aria-label="Team name" value=${name} onInput=${(e) => setName(e.target.value)} /><button class="btn" disabled=${!name.trim() || name.trim() === team.name}>Rename</button></form>
    <//>
    <${Section} title="People" help=${canInvite ? 'Everyone here can open the sites below. Someone without an account gets an e-mail invitation that is valid for 72 hours.' : 'Everyone here can open the sites below. Add people who already have an account on this Tidemark install.'}>
      <form class="goal-add" onSubmit=${add}><div class="goal-add-row"><input class="grow" type="email" required placeholder=${canInvite ? 'Email address' : 'Email address of an existing user'} aria-label="Email" value=${email} onInput=${(e) => setEmail(e.target.value)} /><button class="btn primary" disabled=${!email}>${canInvite ? 'Add or invite' : 'Add person'}</button></div></form>
      ${team.members.length + team.invites.length === 0 ? html`<div class="empty-inline"><${Icon} name="user-plus" size=${18} /><div><b>Nobody on this team yet</b><span>Add the people who should get access to the same sites.</span></div></div>`
        : html`<ul class="rows" aria-label=${'People in ' + team.name}>
        ${team.members.map((u) => html`<li class="rowitem"><${Avatar} name=${u.name || u.email} url=${u.avatar_url} /><div class="grow ri-main"><b class="trunc">${u.name}</b><span class="muted trunc">${u.email}</span></div>
          <button class="btn ghost icon sm danger" aria-label=${'Remove ' + u.name + ' from the team'} title="Remove from team" onClick=${() => run(() => api(`/teams/${team.id}/members/${u.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button></li>`)}
        ${team.invites.map((i) => html`<li class="rowitem"><span class="avatar" aria-hidden="true"><${Icon} name="mail" size=${14} /></span><div class="grow ri-main"><b class="trunc">${i.email}</b><span class="muted trunc">Waiting to accept · ${left(i.expires_at)}</span></div>
          <button class="btn ghost sm" disabled=${!canInvite} onClick=${() => run(() => api(`/teams/${team.id}/invites/${i.id}/resend`, { method: 'POST' }), 'Invitation sent again')}>Resend</button>
          <button class="btn ghost icon sm danger" aria-label=${'Cancel invitation for ' + i.email} title="Cancel invitation" onClick=${() => run(() => api(`/teams/${team.id}/invites/${i.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button></li>`)}</ul>`}
    <//>
    <${Section} title="Site access" help="What this team can open. Viewers see statistics; admins can also change the site's settings. Someone in several teams, or added to a site directly, gets the strongest role they have.">
      ${available.length > 0 ? html`<form class="goal-add" onSubmit=${addSite}><div class="goal-add-row"><select class="grow" aria-label="Site" value=${share.domain} onChange=${(e) => setShare({ ...share, domain: e.target.value })}><option value="">Share a site with this team…</option>${available.map((d) => html`<option value=${d} selected=${share.domain === d}>${d}</option>`)}</select>
        <div class="seg" role="radiogroup" aria-label="Role">${ROLES.map(([k, l]) => html`<button type="button" role="radio" aria-checked=${share.role === k} class=${share.role === k ? 'on' : ''} onClick=${() => setShare({ ...share, role: k })}>${l}</button>`)}</div>
        <button class="btn primary" disabled=${!share.domain}>Share</button></div></form>`
        : owned.filter((s) => s.organization_id === team.organization_id).length === 0 ? html`<div class="empty-inline"><${Icon} name="globe" size=${18} /><div><b>No sites in this organization yet</b><span>Add a site to the organization, then share it with the team.</span></div></div>` : null}
      ${team.sites.length === 0 && owned.some((s) => s.organization_id === team.organization_id) && html`<div class="empty-inline"><${Icon} name="globe" size=${18} /><div><b>Not shared with any site yet</b><span>Choose a site above to give the team access to it.</span></div></div>`}
      ${team.sites.length > 0 && html`<ul class="rows" aria-label="Sites this team can open">${team.sites.map((s) => html`<li class="rowitem"><span class="ri-ico" aria-hidden="true"><${Icon} name="globe" size=${16} /></span><b class="grow trunc">${s.domain}</b>
        <select class="mini-select" value=${s.role} aria-label=${'Role of ' + team.name + ' on ' + s.domain} onChange=${(e) => run(() => api(`/sites/${s.domain}/teams/${team.id}`, { method: 'PATCH', body: { role: e.target.value } }))}>${ROLES.map(([k, l]) => html`<option value=${k} selected=${s.role === k}>${l}</option>`)}</select>
        <button class="btn ghost icon sm danger" aria-label=${'Stop sharing ' + s.domain} title="Stop sharing this site" onClick=${() => run(() => api(`/sites/${s.domain}/teams/${team.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button></li>`)}</ul>`}
    <//>
    <${Section} title="Delete team" danger help="Its members lose the access they only had through this team. Access they have another way stays, and nobody's account is deleted.">
      <div class="section-actions"><button class="btn danger" onClick=${() => setDel(true)}>Delete this team</button></div>
    <//>
    ${del && html`<${Confirm} title=${`Delete the ${team.name} team?`} message="Its members lose the access they only had through this team. Access they have in another way stays." onClose=${() => setDel(false)} onConfirm=${async () => { await api('/teams/' + team.id, { method: 'DELETE' }); navigate('/settings/teams'); }} />`}`;
}

/** Settings → Organization, for the organization chosen in the switcher: name, admins, ownership and deletion for its owner; a read-only view for admins. */
export function Organization() {
  const { user, setUser } = useContext(UserCtx);
  const scope = useScope(user);
  const offers = useApi('/organizations', null, []);
  const usable = scope.org && !scope.personal && !scope.shared;
  const res = useApi(usable ? '/organizations/' + scope.id : '', null, [scope.id], { enabled: !!usable });
  const org = res.data;
  const incoming = (offers.data && offers.data.incoming_transfers) || [];
  const [name, setName] = useState(null);
  const [email, setEmail] = useState('');
  const [err, setErr] = useState(null);
  const [transfer, setTransfer] = useState(false);
  const [del, setDel] = useState(false);
  const [leave, setLeave] = useState(false);
  const refreshMe = async () => { try { setUser((await api('/me')).user); } catch (e) { /* refreshed on next load */ } };
  const run = async (fn, ok) => { setErr(null); try { await fn(); res.reload(); if (ok) toast(ok); } catch (x) { setErr(firstError(x)); } };
  const left = (iso) => { const h = Math.max(0, Math.round((new Date(iso) - Date.now()) / 3600000)); return h >= 2 ? `expires in ${h} hours` : 'expires within the hour'; };
  const base = '/organizations/' + scope.id;
  const add = (e) => { e.preventDefault(); run(async () => { const r = await api(base + '/admins', { method: 'POST', body: { email } }); setEmail(''); toast(r.invited ? 'Invitation sent' : 'Added as an admin'); }); };
  const offerBanners = incoming.map((o) => html`<div class="notice"><${Icon} name="info" size=${16} /><div><b>${o.from} wants to make you the owner of ${o.name}.</b> You would hold its plan and billing and decide who its admins are; ${o.from} stays on as an admin. This offer ${left(o.expires_at)}.
    <div class="row" style="margin-top:8px"><button class="btn primary sm" onClick=${() => run(async () => { await api(`/organizations/${o.id}/transfer/accept`, { method: 'POST' }); await refreshMe(); offers.reload(); }, 'You are the owner now')}>Accept ownership</button>
    <button class="btn sm" onClick=${() => run(async () => { await api(`/organizations/${o.id}/transfer/decline`, { method: 'POST' }); offers.reload(); }, 'Offer declined')}>Decline</button></div></div></div>`);
  if (!usable) return html`${offerBanners}<${NeedsOrganization} scope=${scope} title="Organization" what="Organizations have a name, an owner, admins and teams; they hold sites for a company, a team or a client." />`;
  if (res.loading && !res.data) return html`<${Loading} />`;
  if (!org) return html`${offerBanners}<${ErrorBox} error=${res.error} />`;
  const owner = org.role === 'owner';
  const canInvite = !!org.invites_enabled;
  return html`${err && html`<div class="alert" role="alert">${err}</div>`}${offerBanners}
    ${owner ? html`<${Section} title="Organization name" help="Shown to the people you invite and to anyone you give access to. You are its owner.">
      <${ImageField} label="Logo" name=${org.name} url=${org.logo_url} shape="org" path=${base + '/logo'} type="image/png" onChange=${async () => { await refreshMe(); res.reload(); }} hint="Helps you and your colleagues tell organizations apart in the switcher. Cropped to a square; PNG, JPEG or WebP." />
      <form class="goal-add-row" onSubmit=${(e) => { e.preventDefault(); run(async () => { await api(base, { method: 'PATCH', body: { name: name === null ? org.name : name } }); await refreshMe(); }, 'Name saved'); }}><input class="grow" required maxlength="100" aria-label="Organization name" value=${name === null ? org.name : name} onInput=${(e) => setName(e.target.value)} /><button class="btn" disabled=${name === null || !name.trim() || name.trim() === org.name}>Save</button></form>
    <//>` : html`<${Section} title=${org.name} help=${`You are an admin of this organization. You see and change every site in it, add sites and manage its teams. ${org.owner ? org.owner.name : 'The owner'} keeps billing, the admin list and the organization itself.`}>
      <div class="section-actions"><button class="btn danger" onClick=${() => setLeave(true)}>Leave organization</button></div>
    <//>`}
    <${Section} title="Admins" help=${owner ? 'Admins help you run the organization. They can see and change every site in it, add and delete sites, and manage its teams. Billing, the organization itself and who else is an admin stay with you, the owner.' + (canInvite ? ' Someone without an account gets an e-mail invitation that is valid for 72 hours.' : '') : 'People who help run this organization with its owner.'}>
      ${owner && html`<form class="goal-add" onSubmit=${add}><div class="goal-add-row"><input class="grow" type="email" required placeholder=${canInvite ? 'Email address' : 'Email address of an existing user'} aria-label="Email" value=${email} onInput=${(e) => setEmail(e.target.value)} /><button class="btn primary" disabled=${!email}>${canInvite ? 'Add or invite' : 'Add person'}</button></div></form>`}
      ${org.admins.length + org.invites.length === 0 && html`<div class="empty-inline"><${Icon} name="shield" size=${18} /><div><b>${owner ? 'Only you manage this organization' : 'No other admins'}</b>${owner && html`<span>Add a colleague to give them the same view of every site, without sharing each one.</span>`}</div></div>`}
      ${org.admins.length + org.invites.length > 0 && html`<ul class="rows" aria-label="Organization admins">
        ${org.admins.map((u) => html`<li class="rowitem"><${Avatar} name=${u.name || u.email} url=${u.avatar_url} /><div class="grow ri-main"><b class="trunc">${u.name}</b>${(u.email || (org.transfer && org.transfer.to.id === u.id)) && html`<span class="muted trunc">${u.email || ''}${org.transfer && org.transfer.to.id === u.id ? ' · ownership offered' : ''}</span>`}</div>
          ${owner && html`<button class="btn ghost icon sm danger" aria-label=${'Remove ' + u.name + ' as an admin'} title="Remove as admin" onClick=${() => run(() => api(`${base}/admins/${u.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button>`}</li>`)}
        ${org.invites.map((i) => html`<li class="rowitem"><span class="avatar" aria-hidden="true"><${Icon} name="mail" size=${14} /></span><div class="grow ri-main"><b class="trunc">${i.email}</b><span class="muted trunc">Waiting to accept · ${left(i.expires_at)}</span></div>
          <button class="btn ghost sm" disabled=${!canInvite} onClick=${() => run(() => api(`${base}/invites/${i.id}/resend`, { method: 'POST' }), 'Invitation sent again')}>Resend</button>
          <button class="btn ghost icon sm danger" aria-label=${'Cancel invitation for ' + i.email} title="Cancel invitation" onClick=${() => run(() => api(`${base}/invites/${i.id}`, { method: 'DELETE' }))}><${Icon} name="trash-2" size=${15} /></button></li>`)}</ul>`}
    <//>
    ${owner && html`<${Section} title="Transfer ownership" danger help="Hand the organization to one of its admins. They have to accept, and you stay on as an admin. They then hold the plan and billing and decide who the admins are.">
      ${org.transfer ? html`<div class="notice"><${Icon} name="info" size=${16} /><div>Waiting for <b>${org.transfer.to.name}</b> to accept. The offer ${left(org.transfer.expires_at)}.</div></div>
        <div class="section-actions"><button class="btn" onClick=${() => run(() => api(base + '/transfer', { method: 'DELETE' }), 'Offer cancelled')}>Cancel the offer</button></div>`
        : org.admins.length === 0 ? html`<p class="muted small">Add an admin above first: ownership can only go to one of the organization's admins.</p>`
        : html`<div class="section-actions"><button class="btn danger" onClick=${() => setTransfer(true)}>Transfer ownership…</button></div>`}
    <//>
    <${Section} title="Delete organization" danger help="Removes the organization, its teams and its admin list. Its sites have to be deleted or moved first, so nothing goes by accident.">
      <div class="section-actions"><button class="btn danger" onClick=${() => setDel(true)}>Delete organization…</button></div>
    <//>`}
    ${transfer && html`<${TransferDialog} org=${org} base=${base} onClose=${() => setTransfer(false)} onDone=${() => { setTransfer(false); res.reload(); toast('Offer sent'); }} />`}
    ${del && html`<${TypedConfirm} title=${`Delete ${org.name}?`} message=${org.sites ? `It still has ${org.sites} ${org.sites === 1 ? 'site' : 'sites'}: delete them first.` : 'Its teams and admin list are removed. This cannot be undone.'} phrase=${org.name} confirmLabel="Delete organization" onClose=${() => setDel(false)} onConfirm=${async () => { await api(base, { method: 'DELETE', body: { confirm: org.name } }); await refreshMe(); scope.set(''); navigate('/sites'); }} />`}
    ${leave && html`<${Confirm} title=${`Leave ${org.name}?`} message="You lose access to its sites unless they were also shared with you directly." confirmLabel="Leave" onClose=${() => setLeave(false)} onConfirm=${async () => { await api(base + '/leave', { method: 'POST' }); await refreshMe(); scope.set(''); navigate('/sites'); }} />`}`;
}

function TransferDialog({ org, base, onClose, onDone }) {
  const [to, setTo] = useState(org.admins[0].id);
  const [confirm, setConfirm] = useState('');
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const send = async (e) => { e.preventDefault(); setErr(null); setBusy(true); try { await api(base + '/transfer', { method: 'POST', body: { user_id: to, confirm } }); onDone(); } catch (x) { setErr(firstError(x)); setBusy(false); } };
  return html`<${Modal} title="Transfer ownership" onClose=${onClose} footer=${html`<button class="btn" onClick=${onClose}>Cancel</button><button class="btn destructive" form="transfer-form" disabled=${busy || confirm.trim().toLowerCase() !== org.name.toLowerCase()}>${busy ? 'Sending…' : 'Send the offer'}</button>`}>
    <form id="transfer-form" class="stack" onSubmit=${send}>
      ${err && html`<div class="alert" role="alert">${err}</div>`}
      <p style="line-height:1.55;color:var(--muted-foreground)">Nothing changes until they accept. After that they own <b>${org.name}</b> and hold its plan and billing; you stay on as an admin and can be removed by them.</p>
      <${Field} label="New owner"><select value=${to} onChange=${(e) => setTo(e.target.value)}>${org.admins.map((u) => html`<option value=${u.id} selected=${to === u.id}>${u.name} (${u.email})</option>`)}</select><//>
      <${Field} label=${`Type ${org.name} to confirm`}><input autocomplete="off" value=${confirm} onInput=${(e) => setConfirm(e.target.value)} /><//>
    </form><//>`;
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
  const tabs = accountTabs(user);
  // administration lives in its own interface when an extension provides one
  if (registry.adminUi && user.is_admin && ['users', 'updates', 'customers'].includes(tab)) { window.location.replace(registry.adminUi.href + (tab === 'customers' ? '' : '/' + tab)); return null; }
  const moved = { preferences: 'profile' }; // older links still land in the right place
  const t = [tab, moved[tab]].find((k) => tabs.some((x) => x[0] === k)) || 'profile';
  const Body = { profile: Profile, reports: EmailReports, security: Security, keys: ApiKeys, teams: Teams, organization: Organization, users: Users, updates: Updates, ...Object.fromEntries(extra.map((x) => [x.key, x.component])) }[t];
  return html`<${SettingsShell} title="Settings" back=${['/sites', 'Your sites']} scope="Sites" dup tabs=${tabs} tab=${t} base="/settings"><${Body} /><//>`;
}
