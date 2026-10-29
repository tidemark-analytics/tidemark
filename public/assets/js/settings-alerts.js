// Alert rules and webhooks for a site.
import { html, useState, useApi, api, Icon, toast, ago } from './lib.js';
import { Section, Field, Toggle, CodeBlock } from './ui.js';
import { firstError } from './settings-common.js';

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

export function Alerts({ site }) {
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
