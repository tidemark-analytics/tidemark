// Campaign links for a site: a UTM builder, short redirect links and tracking pixels.
import { html, useState, useApi, api, Icon, toast, copy } from './lib.js';
import { Section, Field, Badge, Confirm, Empty, Loading } from './ui.js';
import { firstError } from './settings-common.js';

const UTM = [['utm_source', 'Source', 'newsletter'], ['utm_medium', 'Medium', 'email'], ['utm_campaign', 'Campaign', 'spring-launch'], ['utm_content', 'Content', 'header-button'], ['utm_term', 'Term', 'running shoes']];

/** The address with the UTM values added; any the address already had are replaced. */
function withUtm(address, values) {
  try {
    const url = new URL(address);
    UTM.forEach(([k]) => { if (values[k] && values[k].trim()) url.searchParams.set(k, values[k].trim()); });
    return url.toString();
  } catch (e) { return ''; }
}

function Builder({ site, allowed, onCreated }) {
  const [form, setForm] = useState({ name: '', destination: `https://${site.domain}/`, utm_source: '', utm_medium: '', utm_campaign: '', utm_content: '', utm_term: '' });
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const built = withUtm(form.destination, form);
  const create = async (kind) => {
    setBusy(true);
    try {
      await api(`/sites/${site.domain}/campaign-links`, { method: 'POST', body: { ...form, kind, name: form.name.trim() || form.utm_campaign.trim() || 'Untitled' } });
      toast(kind === 'pixel' ? 'Tracking pixel created' : 'Short link created');
      onCreated();
    } catch (x) { toast(firstError(x), 'err'); }
    setBusy(false);
  };
  return html`<${Section} title="Link builder" locked=${!allowed} help="Add UTM values to any page of this site so visits from a campaign show up under Sources and Campaigns. Copy the long address, or make a short link that counts clicks."
    footer=${html`<span class="help">A tracking pixel only needs a campaign name.</span>
      <div class="row"><button class="btn sm" disabled=${busy || !built} onClick=${() => copy(built)}>Copy long address</button>
        <button class="btn sm" disabled=${busy || !form.utm_campaign.trim()} onClick=${() => create('pixel')}>Create pixel</button>
        <button class="btn primary sm" disabled=${busy || !built} onClick=${() => create('link')}>Create short link</button></div>`}>
    <div class="fields2"><${Field} label="Page address"><input value=${form.destination} onInput=${set('destination')} placeholder=${`https://${site.domain}/pricing`} /><//>
      <${Field} label="Name" help="Only you see it."><input value=${form.name} onInput=${set('name')} placeholder="Spring launch email" /><//></div>
    <div class="fields2">${UTM.map(([k, label, ph]) => html`<${Field} label=${label}><input value=${form[k]} onInput=${set(k)} placeholder=${ph} /><//>`)}</div>
    <${Field} label="Result"><input readonly value=${built || 'Enter a full address, starting with https://'} aria-label="Result" onFocus=${(e) => e.target.select()} class="mono" /><//>
  <//>`;
}

export function Campaigns({ site }) {
  const res = useApi(`/sites/${site.domain}/campaign-links`, null, [site.domain]);
  const [del, setDel] = useState(null);
  if (!res.data) return res.error ? html`<${Section} title="Campaign links"><p class="muted">${res.error.message}</p><//>` : html`<${Section} title="Campaign links"><${Loading} /><//>`;
  const allowed = res.data.allowed;
  const links = res.data.data;
  const snippet = (l) => `<img src="${l.url}" width="1" height="1" alt="" style="display:none">`;
  return html`<${Builder} site=${site} allowed=${allowed} onCreated=${res.reload} />
    <${Section} title="Short links and pixels" help="A short link counts a click, then forwards with its UTM values. A pixel counts a view when a newsletter loads it. Both keep only a daily total: no cookies, no addresses. Opens are approximate, because mail apps may preload or block images.">
      ${links.length === 0 ? html`<${Empty} title="No links yet" icon="link">Make a short link or a pixel above.<//>` : html`<div class="card-flush"><table class="t stack-sm"><thead><tr><th>Name</th><th>Address</th><th>Campaign</th><th class="right">Last 30 days</th><th class="right">Total</th><th></th></tr></thead>
        <tbody>${links.map((l) => html`<tr><td data-label="Name"><b>${l.name}</b> <${Badge}>${l.kind === 'pixel' ? 'Pixel' : 'Link'}<//></td>
          <td data-label="Address" class="mono trunc" style="max-width:260px">${l.url.replace(/^https?:\/\//, '')}</td><td data-label="Campaign" class="muted">${l.utm_campaign || '–'}</td>
          <td data-label="Last 30 days" class="right num">${l.last_30_days.toLocaleString()} ${l.kind === 'pixel' ? 'views' : 'clicks'}</td><td data-label="Total" class="right num">${l.total.toLocaleString()}</td>
          <td class="right nowrap"><button class="btn sm" onClick=${() => copy(l.kind === 'pixel' ? snippet(l) : l.url)}>${l.kind === 'pixel' ? 'Copy image tag' : 'Copy link'}</button>
            <button class="btn ghost icon sm" aria-label=${'Delete ' + l.name} onClick=${() => setDel(l)}><${Icon} name="trash-2" size=${14} /></button></td></tr>`)}</tbody></table></div>`}
    <//>
    ${del && html`<${Confirm} title=${`Delete ${del.name}?`} message=${del.kind === 'link' ? 'The short link stops working, so anywhere it was shared will show a not-found page. Its counts are removed.' : 'The pixel stops counting. Its counts are removed.'} confirmLabel="Delete"
      onClose=${() => setDel(null)} onConfirm=${async () => { await api(`/sites/${site.domain}/campaign-links/${del.id}`, { method: 'DELETE' }); toast('Deleted'); res.reload(); }} />`}`;
}
