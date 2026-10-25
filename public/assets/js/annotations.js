// Notes on the graph: the list for a day, and the form to add or change one.
import { html, useState, api, Icon, toast } from './lib.js';
import { Modal, Field, Confirm } from './ui.js';

const WHO = { private: 'Only me', team: 'Everyone on this site', public: 'Anyone who can see this dashboard' };
const firstError = (x) => (x.errors && Object.values(x.errors)[0][0]) || x.message;
const fmtDate = (d) => new Date(d + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
export const todayIn = (tz) => { try { return new Date().toLocaleDateString('en-CA', { timeZone: tz }); } catch (e) { return new Date().toISOString().slice(0, 10); } };

/**
 * `start`: { date, list } — the notes on the clicked day (or none, to write the first one). Anyone with access can keep private notes;
 * owners and admins can also share a note with the site, or show it on public dashboards.
 */
export function AnnotationModal({ site, start, onClose, onChanged }) {
  const [list, setList] = useState(start.list);
  const [editing, setEditing] = useState(start.list.length ? undefined : null); // undefined: showing the list, null: a new note, object: changing one
  const [date, setDate] = useState(start.date);
  const [note, setNote] = useState('');
  const [visibility, setVisibility] = useState('team');
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const [del, setDel] = useState(null);
  const options = site.can_manage ? ['team', 'public', 'private'] : ['private'];

  const open = (n) => { setErr(null); setEditing(n); setDate(n ? n.date : start.date); setNote(n ? n.note : ''); setVisibility(n ? n.visibility : options[0]); };
  const save = async (e) => {
    e.preventDefault(); setErr(null); setBusy(true);
    try {
      const body = { date, note, visibility };
      if (editing) await api(`/sites/${site.domain}/annotations/${editing.id}`, { method: 'PATCH', body });
      else await api(`/sites/${site.domain}/annotations`, { method: 'POST', body });
      toast('Saved'); onChanged(); onClose();
    } catch (x) { setErr(firstError(x)); setBusy(false); }
  };
  const remove = async () => {
    try {
      await api(`/sites/${site.domain}/annotations/${del.id}`, { method: 'DELETE' });
      const rest = list.filter((n) => n.id !== del.id);
      setList(rest); setDel(null); onChanged(); toast('Note removed');
      if (!rest.length) onClose();
    } catch (x) { setErr(firstError(x)); setDel(null); }
  };

  if (editing !== undefined) {
    return html`<${Modal} title=${editing ? 'Change note' : 'Add a note'} onClose=${onClose} footer=${html`<div class="row" style="justify-content:flex-end;gap:8px;width:100%">
      ${list.length > 0 && html`<button class="btn" type="button" onClick=${() => open(undefined)}>Back</button>`}<button class="btn primary" form="note-form" disabled=${busy || !note.trim()}>Save</button></div>`}>
      <form id="note-form" class="stack" onSubmit=${save}>
        <${Field} label="Date"><input type="date" required value=${date} onInput=${(e) => setDate(e.target.value)} style="max-width:200px" /><//>
        <${Field} label="Note" help=${`${note.length}/280. Shown on the graph on that day, for example “Launched v2” or “Newsletter went out”.`}><textarea required maxlength="280" rows="3" value=${note} onInput=${(e) => setNote(e.target.value)} autofocus></textarea><//>
        ${options.length > 1 ? html`<${Field} label="Who can see it"><select value=${visibility} onChange=${(e) => setVisibility(e.target.value)}>${options.map((o) => html`<option value=${o} selected=${visibility === o}>${WHO[o]}</option>`)}</select><//>`
          : html`<p class="muted small">Only you can see this note.</p>`}
        ${err && html`<div class="alert">${err}</div>`}
      </form><//>`;
  }
  return html`<${Modal} title=${list.length > 1 ? `Notes on ${fmtDate(list[0].date)}` : 'Note'} onClose=${onClose} footer=${site.role && html`<button class="btn" onClick=${() => open(null)}><${Icon} name="plus" size=${14} /> Add a note</button>`}>
    <ul class="rows" aria-label="Notes">${list.map((n) => html`<li class="rowitem">
      <span class="ri-ico" aria-hidden="true"><${Icon} name=${n.visibility === 'private' ? 'lock' : 'bookmark'} size=${16} /></span>
      <div class="grow ri-main"><b style="white-space:normal">${n.note}</b><span class="muted">${fmtDate(n.date)} · ${WHO[n.visibility]}</span></div>
      ${n.editable && html`<button class="btn ghost icon sm" aria-label="Change note" title="Change" onClick=${() => open(n)}><${Icon} name="pencil" size=${15} /></button>
        <button class="btn ghost icon sm danger" aria-label="Delete note" title="Delete" onClick=${() => setDel(n)}><${Icon} name="trash-2" size=${15} /></button>`}</li>`)}</ul>
    ${err && html`<div class="alert">${err}</div>`}
    ${del && html`<${Confirm} title="Delete this note?" message=${`“${del.note}” will be removed from the graph.`} confirmLabel="Delete" onClose=${() => setDel(null)} onConfirm=${remove} />`}
  <//>`;
}
